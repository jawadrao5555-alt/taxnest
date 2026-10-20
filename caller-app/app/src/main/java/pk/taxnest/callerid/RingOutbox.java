package pk.taxnest.callerid;

import java.io.*;
import java.nio.channels.FileLock;
import java.nio.file.Files;
import java.nio.file.StandardCopyOption;
import java.security.MessageDigest;
import java.util.*;
import java.util.zip.CRC32;

/** Private no-backup snapshot. File + process locks cover concurrent detectors/jobs. */
public final class RingOutbox {
    public static final long RETENTION_MS = 24 * 60 * 60 * 1000L;
    public static final long LIVE_MS = 120_000L;
    private static final long DEDUPE_MS = 60_000L;
    private static final int LIMIT = 500, MAGIC = 0x544e4301;
    private static final Object LOCK = new Object();
    private final File file;

    public static final class Event {
        public final String uuid, scope, lanScope, phone, name, source;
        public final long at;
        public final boolean delivered;
        Event(String uuid, String scope, String lanScope, String phone, String name,
              String source, long at, boolean delivered) {
            this.uuid=uuid; this.scope=scope; this.lanScope=lanScope; this.phone=phone;
            this.name=name; this.source=source; this.at=at; this.delivered=delivered;
        }
        Event acknowledged() { return new Event(uuid,scope,lanScope,phone,name,source,at,true); }
        public boolean mayUseLan(String currentLanScope, long now) {
            return !lanScope.isEmpty() && lanScope.equals(currentLanScope) && now >= at && now-at < LIVE_MS;
        }
    }
    public RingOutbox(File privateDirectory) { file = new File(privateDirectory,"caller-ring-outbox.v1"); }
    public static String scope(String value) {
        try {
            byte[] bytes=MessageDigest.getInstance("SHA-256").digest(value.getBytes("UTF-8"));
            StringBuilder out=new StringBuilder();
            for(byte b:bytes) out.append(String.format(Locale.ROOT,"%02x",b & 255));
            return out.toString();
        } catch(Exception e) { throw new IllegalStateException(e); }
    }
    private interface Operation<T> { T run(List<Event> events) throws IOException; }
    private <T> T change(String scope,long now,Operation<T> operation) throws IOException {
        synchronized(LOCK) {
            File parent=file.getParentFile();
            if (!parent.isDirectory() && !parent.mkdirs()) throw new IOException("Outbox directory unavailable");
            try(RandomAccessFile lock=new RandomAccessFile(new File(parent,"caller-ring-outbox.lock"),"rw");
                FileLock held=lock.getChannel().lock()) {
                List<Event> events=read();
                // Token-scoped: logout/account rotation never sends history as another account.
                events.removeIf(e -> !e.scope.equals(scope) || e.at > now+300_000L ||
                    now-e.at >= RETENTION_MS || (e.delivered && now-e.at >= DEDUPE_MS));
                T result=operation.run(events);
                write(events);
                return result;
            }
        }
    }
    public Event enqueue(String scope,String lanScope,String phone,String name,String source,long now) throws IOException {
        return change(scope,now,events -> {
            for(Event e:events) if(e.phone.equals(phone) && e.name.equals(name) &&
                now >= e.at && now-e.at < DEDUPE_MS) return e;
            if(events.size()>=LIMIT) throw new IOException("Caller outbox full; pending calls retained");
            Event e=new Event(UUID.randomUUID().toString(),scope,lanScope,phone,name,source,now,false);
            events.add(e); return e;
        });
    }
    public List<Event> pending(String scope,long now) throws IOException {
        return change(scope,now,events -> {
            List<Event> pending=new ArrayList<>();
            for(Event e:events) if(!e.delivered) pending.add(e);
            // Fresh LAN-capable calls must not sit behind an old cloud-only retry.
            pending.sort((a,b) -> Long.compare(b.at,a.at));
            return pending;
        });
    }
    public void acknowledge(String scope,String uuid,long now) throws IOException {
        change(scope,now,events -> {
            for(int i=0;i<events.size();i++) if(events.get(i).uuid.equals(uuid)) events.set(i,events.get(i).acknowledged());
            return null;
        });
    }
    public void clear(String scope,long now) throws IOException { change(scope,now,events -> { events.clear(); return null; }); }
    private List<Event> read() throws IOException {
        if(!file.exists()) return new ArrayList<>();
        if(file.length()>2_000_000L) throw new IOException("Caller outbox oversized");
        byte[] payload;
        try(DataInputStream in=new DataInputStream(new FileInputStream(file))) {
            if(in.readInt()!=MAGIC) throw new IOException("Caller outbox format invalid");
            int length=in.readInt();
            if(length<4 || length>1_900_000) throw new IOException("Caller outbox size invalid");
            payload=new byte[length]; in.readFully(payload);
            CRC32 crc=new CRC32(); crc.update(payload);
            if(in.readLong()!=crc.getValue() || in.read()!=-1) throw new IOException("Caller outbox checksum invalid");
        }
        List<Event> events=new ArrayList<>();
        try(DataInputStream in=new DataInputStream(new ByteArrayInputStream(payload))) {
            int count=in.readInt();
            if(count<0 || count>LIMIT) throw new IOException("Caller outbox count invalid");
            for(int i=0;i<count;i++) events.add(new Event(in.readUTF(),in.readUTF(),in.readUTF(),in.readUTF(),in.readUTF(),in.readUTF(),in.readLong(),in.readBoolean()));
            if(in.read()!=-1) throw new IOException("Caller outbox trailing payload");
        }
        return events;
    }
    private void write(List<Event> events) throws IOException {
        ByteArrayOutputStream bytes=new ByteArrayOutputStream();
        try(DataOutputStream out=new DataOutputStream(bytes)) {
            out.writeInt(events.size());
            for(Event e:events) { out.writeUTF(e.uuid);out.writeUTF(e.scope);out.writeUTF(e.lanScope);
                out.writeUTF(e.phone);out.writeUTF(e.name);out.writeUTF(e.source);out.writeLong(e.at);out.writeBoolean(e.delivered); }
        }
        byte[] payload=bytes.toByteArray(); CRC32 crc=new CRC32();crc.update(payload);
        File temporary=new File(file.getParentFile(),file.getName()+".tmp");
        try(FileOutputStream stream=new FileOutputStream(temporary);DataOutputStream out=new DataOutputStream(stream)) {
            out.writeInt(MAGIC);out.writeInt(payload.length);out.write(payload);out.writeLong(crc.getValue());out.flush();stream.getFD().sync();
        }
        Files.move(temporary.toPath(),file.toPath(),StandardCopyOption.ATOMIC_MOVE,StandardCopyOption.REPLACE_EXISTING);
    }
}
