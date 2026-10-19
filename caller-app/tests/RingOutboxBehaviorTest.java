import pk.taxnest.callerid.RingOutbox;
import pk.taxnest.callerid.RingDeliveryPolicy;
import java.io.*;
import java.nio.file.*;
import java.util.*;
import java.util.concurrent.*;

public final class RingOutboxBehaviorTest {
    static final long NOW=1_790_000_000_000L;
    static final String A=RingOutbox.scope("fictional-account-A"), B=RingOutbox.scope("fictional-account-B");
    static int assertions;
    static void check(boolean ok,String message) { assertions++;if(!ok)throw new AssertionError(message); }
    public static void main(String[] args) throws Exception {
        File dir=Files.createTempDirectory("caller-recovery-behavior-").toFile();
        try {
            RingOutbox box=new RingOutbox(dir);
            RingOutbox.Event first=box.enqueue(A,"lan-a","03000000001","Fictional Caller","sim",NOW);
            // Simulated process death/lost response: a new instance reloads the persisted original event.
            RingOutbox reopened=new RingOutbox(dir);
            RingOutbox.Event saved=reopened.pending(A,NOW+1).get(0);
            check(saved.uuid.equals(first.uuid) && saved.at==NOW,"restart keeps UUID and occurrence time");
            check(reopened.enqueue(A,"lan-a","03000000001","Fictional Caller","sim",NOW+500).uuid.equals(first.uuid),"detector repeat reuses persisted UUID");
            check(reopened.pending(A,NOW+501).size()==1,"no duplicate pending row");
            check(saved.mayUseLan("lan-a",NOW+1000),"existing fresh LAN pairing works");
            check(!saved.mayUseLan("lan-b",NOW+1000),"repaired PC cannot receive old account's event");
            check(!saved.mayUseLan("lan-a",NOW+120_000),"stale events cannot use timestamp-free legacy LAN");
            reopened.acknowledge(A,saved.uuid,NOW+1001);
            check(new RingOutbox(dir).pending(A,NOW+1002).isEmpty(),"ack persists after restart");
            check(reopened.enqueue(A,"lan-a","03000000001","Fictional Caller","sim",NOW+1003).delivered,"ack marker preserves existing 60s detector dedupe");
            check(!reopened.enqueue(A,"lan-a","03000000001","Fictional Caller","sim",NOW+60_001).uuid.equals(first.uuid),"later independent call gets new UUID");
            check(new String(Files.readAllBytes(new File(dir,"caller-ring-outbox.v1").toPath()),"ISO-8859-1").indexOf("fictional-account-A")<0,"no raw credential stored");
            check(reopened.pending(B,NOW+60_002).isEmpty(),"second account cannot replay first account history");
            check(reopened.pending(A,NOW+60_003).isEmpty(),"account switch discards old credential queue");
            reopened.enqueue(B,"","03000000002","","whatsapp",NOW);
            check(reopened.pending(B,NOW+RingOutbox.RETENTION_MS).isEmpty(),"24h expiry bounded");
            reopened.enqueue(B,"","03000000002","","sim",NOW+1);
            RingOutbox.Event live=reopened.enqueue(B,"lan-a","03000000003","","sim",NOW+300_000);
            check(reopened.pending(B,NOW+300_001).get(0).uuid.equals(live.uuid),"fresh LAN call is prioritized ahead of stale cloud history");
            reopened.clear(B,NOW+300_002);
            // Parallel detector/job instances must not lose or duplicate pending snapshots.
            ExecutorService pool=Executors.newFixedThreadPool(6);
            List<Future<?>> futures=new ArrayList<>();
            for(int i=0;i<30;i++){ final int n=i; futures.add(pool.submit(()->{try{new RingOutbox(dir).enqueue(B,"","030000001"+n,"","sim",NOW+100_000);}catch(Exception e){throw new RuntimeException(e);}})); }
            for(Future<?> f:futures)f.get();pool.shutdown();
            check(reopened.pending(B,NOW+100_001).size()==30,"concurrent writers retain all independent events");
            reopened.clear(B,NOW+100_002);
            for(int i=0;i<500;i++) reopened.enqueue(B,"","030001"+i,"","sim",NOW+200_000);
            boolean full=false;try{reopened.enqueue(B,"","overflow","","sim",NOW+200_001);}catch(IOException e){full=true;}
            check(full && reopened.pending(B,NOW+200_002).size()==500,"capacity never evicts pending events silently");
            byte[] good=Files.readAllBytes(new File(dir,"caller-ring-outbox.v1").toPath());
            byte[] damaged=good.clone();damaged[12]^=1;Files.write(new File(dir,"caller-ring-outbox.v1").toPath(),damaged);
            boolean corrupt=false;try{reopened.pending(B,NOW+200_003);}catch(IOException e){corrupt=true;}
            check(corrupt && Arrays.equals(damaged,Files.readAllBytes(new File(dir,"caller-ring-outbox.v1").toPath())),"corruption fails visibly without wiping data");
            policy(200,true,true,"",RingDeliveryPolicy.Result.DELIVERED);
            policy(200,true,false,"duplicate",RingDeliveryPolicy.Result.DELIVERED);
            policy(200,true,false,"plan_locked",RingDeliveryPolicy.Result.DENIED);
            policy(200,true,false,"disabled",RingDeliveryPolicy.Result.DENIED);
            policy(200,true,false,"unknown_refusal",RingDeliveryPolicy.Result.DENIED);
            policy(200,true,null,"",RingDeliveryPolicy.Result.RETRY);
            policy(200,false,true,"",RingDeliveryPolicy.Result.RETRY);
            policy(401,false,null,"",RingDeliveryPolicy.Result.UNAUTHORIZED);
            policy(403,false,null,"",RingDeliveryPolicy.Result.DENIED);
            policy(422,false,null,"",RingDeliveryPolicy.Result.DROP);
            policy(429,false,null,"",RingDeliveryPolicy.Result.RETRY);
            policy(500,false,null,"",RingDeliveryPolicy.Result.RETRY);
            policy(-1,false,null,"",RingDeliveryPolicy.Result.RETRY);
            System.out.println("PASS: "+assertions+" behavioral assertions; real production outbox filesystem, restart, concurrency, account isolation, expiry, capacity, corruption and delivery policy");
        } finally { try(java.util.stream.Stream<Path> paths=Files.walk(dir.toPath())){ paths.sorted(Comparator.reverseOrder()).forEach(p->{try{Files.delete(p);}catch(Exception e){throw new RuntimeException(e);}}); } }
    }
    static void policy(int code,boolean ok,Boolean accepted,String reason,RingDeliveryPolicy.Result expected) {
        check(RingDeliveryPolicy.cloud(code,ok,accepted,reason)==expected,"transport response "+code+"/"+reason+" must be "+expected);
    }
}
