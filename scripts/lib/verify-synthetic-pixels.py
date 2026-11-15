"""Independent fail-closed verifier for explicit synthetic screenshots; not the text sanitizer."""
import hashlib, hmac, json, os, pathlib, re, stat, struct, subprocess, sys, tempfile, zlib
from urllib.parse import urlsplit
POLICY = json.loads(pathlib.Path(__file__).with_name('synthetic-pixel-policy.json').read_text())
ALLOWED = {f'{n}-{v["width"]}.png': v for n in POLICY['names'] for v in POLICY['viewports'].values()}
def require(ok, message):
    if not ok: raise ValueError(message)
def regular(p, maximum):
    s = p.lstat()
    require(stat.S_ISREG(s.st_mode) and s.st_nlink == 1 and p.resolve() == p and s.st_size <= maximum, 'unsafe file')
    return p.read_bytes()
def chunk(kind, data):
    return struct.pack('!I', len(data))+kind+data+struct.pack('!I', zlib.crc32(kind+data)&0xffffffff)
def png_checked(data, width, height):
    require(len(data) <= POLICY['max_file_bytes'] and data[:8] == b'\x89PNG\r\n\x1a\n', 'PNG signature/size')
    pos=8; ihdr=None; packed=[]; ended=False; seen_data=False; count=0
    while pos < len(data):
        require(pos+12<=len(data), 'truncated chunk')
        size=struct.unpack('!I',data[pos:pos+4])[0]; kind=data[pos+4:pos+8]; end=pos+12+size
        require(end<=len(data) and size<=POLICY['max_file_bytes'], 'chunk bounds')
        body=data[pos+8:pos+8+size]; crc=struct.unpack('!I',data[pos+8+size:end])[0]
        require(crc==(zlib.crc32(kind+body)&0xffffffff), 'chunk CRC')
        count+=1; require(count<=4096 and not ended, 'chunk count/trailing data')
        if kind==b'IHDR':
            require(ihdr is None and pos==8 and size==13, 'IHDR order')
            w,h,depth,color,compression,filtering,interlace=struct.unpack('!IIBBBBB',body)
            require((w,h)==(width,height) and depth==8 and color in (2,6) and (compression,filtering,interlace)==(0,0,0), 'PNG format/dimensions')
            ihdr=body; bpp=3 if color==2 else 4
        elif kind==b'IDAT':
            require(ihdr is not None, 'missing header'); packed.append(body); seen_data=True
        elif kind==b'IEND':
            require(size==0 and seen_data and end==len(data), 'IEND/trailing payload'); ended=True
        else:
            # Reject metadata, animation, unknown ancillary data and polyglot payloads outright.
            require(kind==b'sRGB' and not seen_data and size==1 and body[0]<=3, 'forbidden PNG chunk')
        pos=end
    require(ended and ihdr is not None, 'incomplete PNG')
    expected=(1+width*bpp)*height; decoder=zlib.decompressobj(); raw=decoder.decompress(b''.join(packed),expected+1)
    require(len(raw)==expected and decoder.eof and not decoder.unused_data and not decoder.unconsumed_tail, 'PNG decompression bounds')
    require(all(raw[i]<=4 for i in range(0,expected,1+width*bpp)), 'invalid scanline filter')
    return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',ihdr)+chunk(b'IDAT',b''.join(packed))+chunk(b'IEND',b'')
def verify(workspace, target, sha):
    workspace=pathlib.Path(workspace).absolute(); target=pathlib.Path(target).absolute()
    require(re.fullmatch('[0-9a-f]{40}',sha), 'source SHA')
    source=workspace/'.local/synthetic-pixel-evidence'; keypath=workspace/'.local/synthetic-pixel-private.key'
    require(source.is_dir() and source.resolve()==source, 'source directory')
    key=regular(keypath,32); require(len(key)==32 and keypath.stat().st_mode&0o077==0, 'key permissions')
    names=set(p.name for p in source.iterdir()); images=sorted(n for n in names if n.endswith('.png'))
    require(images and set(images)<=ALLOWED.keys(), 'no images/unknown filenames')
    require(names==set(images)|{n[:-4]+'.json' for n in images}, 'unpaired/unexpected files')
    require(not target.exists() and target.parent.resolve()==target.parent and not target.is_symlink(), 'unsafe/existing output')
    verified=[]; exports=[]; total=0
    fields={'version','file','source_sha','fixture_sha256','origin','synthetic','server_marker_verified','browser_scope','mask_policy','width','height','bytes','sha256','review_status'}
    for name in images:
        envelope=json.loads(regular(source/(name[:-4]+'.json'),8192)); require(set(envelope)=={'payload','signature'},'envelope fields')
        payload=envelope['payload']; require(isinstance(payload,str) and isinstance(envelope['signature'],str),'envelope types')
        require(hmac.compare_digest(hmac.new(key,payload.encode(),hashlib.sha256).hexdigest(),envelope['signature']),'signature mismatch')
        r=json.loads(payload); require(set(r)==fields,'record fields'); v=ALLOWED[name]
        require(r['version']==1 and r['file']==name and r['source_sha']==sha and re.fullmatch('[0-9a-f]{64}',r['fixture_sha256']), 'record source')
        u=urlsplit(r['origin']); require(u.scheme=='http' and u.hostname in ('127.0.0.1','localhost','::1') and not u.username and not u.password and not u.path and not u.query and not u.fragment,'origin')
        require(r['synthetic'] is True and r['server_marker_verified'] is True and r['browser_scope']=='ephemeral-fixture-browser' and r['mask_policy']==POLICY['mask_policy'] and r['review_status']=='NOT_VISUALLY_REVIEWED','capture guards')
        require((r['width'],r['height'])==(v['width'],v['height']), 'viewport')
        data=regular(source/name,POLICY['max_file_bytes']); total+=len(data)
        require(total<=POLICY['max_total_bytes'] and r['bytes']==len(data) and r['sha256']==hashlib.sha256(data).hexdigest(),'hash/size')
        clean=png_checked(data,v['width'],v['height']); exports.append((name,clean))
        verified.append({'file':name,'sha256':hashlib.sha256(clean).hexdigest(),'width':v['width'],'height':v['height'],'mask_policy':r['mask_policy'],'fixture_sha256':r['fixture_sha256']})
    target.parent.mkdir(parents=True,exist_ok=True)
    staging=pathlib.Path(tempfile.mkdtemp(prefix='.verified-pixels-',dir=target.parent))
    try:
        for name,data in exports: (staging/name).write_bytes(data)
        manifest={'version':1,'source_sha':sha,'synthetic_only':True,'review_status':'NOT_VISUALLY_REVIEWED','coverage':'Captured allowlisted views only; not a claim all browser tests passed.','files':verified}
        (staging/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
        (staging/'VERIFIED_SYNTHETIC_PIXELS').write_text('Guarded synthetic pixels; manual visual review required.\n')
        for p in staging.iterdir(): p.chmod(0o600)
        staging.rename(target)
    except Exception:
        import shutil; shutil.rmtree(staging); raise
    return manifest
if __name__=='__main__':
    try:
        root=pathlib.Path(__file__).resolve().parents[2]
        sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip()
        result=verify(root,sys.argv[1],sha)
        print(f'Verified {len(result["files"])} synthetic PNG files; visual review still required.')
    except Exception as error:
        print(f'Synthetic pixel export refused: {error}',file=sys.stderr); sys.exit(1)
