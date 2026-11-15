import importlib.util, pathlib, tempfile, unittest, struct, zlib, json, hashlib, hmac, os
spec=importlib.util.spec_from_file_location('pixels',pathlib.Path(__file__).parents[1]/'lib/verify-synthetic-pixels.py'); m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
class Pixels(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory(); self.root=pathlib.Path(self.tmp.name); self.src=self.root/'.local/synthetic-pixel-evidence'; self.src.mkdir(parents=True); self.key=b'x'*32
  key=self.root/'.local/synthetic-pixel-private.key'; key.write_bytes(self.key); key.chmod(0o600)
  self.name='school-fee-cash-receipt-390.png'; self.sha='a'*40
  self.png=b'\x89PNG\r\n\x1a\n'+m.chunk(b'IHDR',struct.pack('!IIBBBBB',390,844,8,2,0,0,0))+m.chunk(b'IDAT',zlib.compress(bytes((390*3+1)*844)))+m.chunk(b'IEND',b'')
  self.r=dict(version=1,fixture_kind='standalone-browser',file=self.name,source_sha=self.sha,fixture_sha256='b'*64,origin='http://127.0.0.1:1234',synthetic=True,server_marker_verified=True,browser_scope='ephemeral-fixture-browser',mask_policy=m.POLICY['mask_policy'],width=390,height=844,bytes=len(self.png),sha256=hashlib.sha256(self.png).hexdigest(),review_status='NOT_VISUALLY_REVIEWED'); self.save()
 def tearDown(self): self.tmp.cleanup()
 def save(self):
  (self.src/self.name).write_bytes(self.png); p=json.dumps(self.r); (self.src/(self.name[:-4]+'.json')).write_text(json.dumps(dict(payload=p,signature=hmac.new(self.key,p.encode(),hashlib.sha256).hexdigest())))
 def run_verify(self): return m.verify(self.root,self.root/'out',self.sha)
 def test_valid_and_no_key_export(self):
  self.assertEqual(len(self.run_verify()['files']),1); self.assertEqual(set(p.name for p in (self.root/'out').iterdir()),{self.name,'manifest.json','VERIFIED_SYNTHETIC_PIXELS'})
 def test_bad_records(self):
  for key,val in [('fixture_kind','hospital-erp-demo'),('origin','https://taxnest.pk'),('origin','http://u:p@localhost'),('source_sha','c'*40),('synthetic',False),('server_marker_verified',False),('mask_policy','none'),('width',1280),('fixture_sha256','x'),('review_status','PASS')]:
   old=self.r[key]; self.r[key]=val; self.save()
   with self.subTest(key=key,val=val),self.assertRaises(ValueError): self.run_verify()
   self.r[key]=old
 def test_signature(self):
  p=self.src/(self.name[:-4]+'.json'); e=json.loads(p.read_text()); e['signature']='0'*64; p.write_text(json.dumps(e))
  with self.assertRaises(ValueError): self.run_verify()
 def test_hash(self):
  (self.src/self.name).write_bytes(b'other')
  with self.assertRaises(ValueError): self.run_verify()
 def test_unexpected_file(self):
  (self.src/'secret.png').write_bytes(self.png)
  with self.assertRaises(ValueError): self.run_verify()
 def test_symlink(self):
  p=self.src/self.name; p.unlink(); q=self.root/'other'; q.write_bytes(self.png); p.symlink_to(q)
  with self.assertRaises(ValueError): self.run_verify()
 def test_hardlink(self):
  os.link(self.src/self.name,self.root/'other')
  with self.assertRaises(ValueError): self.run_verify()
 def test_key_permissions(self):
  (self.root/'.local/synthetic-pixel-private.key').chmod(0o644)
  with self.assertRaises(ValueError): self.run_verify()
 def test_png_negatives(self):
  bad=[b'not png',self.png+b'PK\x03\x04zip',self.png[:-12],self.png[:33]+m.chunk(b'tEXt',b'secret')+self.png[33:],self.png[:33]+m.chunk(b'IDAT',zlib.compress(b'x'*2000000))+m.chunk(b'IEND',b''),self.png[:40]+b'x'+self.png[41:]]
  for data in bad:
   with self.subTest(length=len(data)),self.assertRaises(ValueError): m.png_checked(data,390,844)
 def test_wrong_dimensions(self):
  with self.assertRaises(ValueError): m.png_checked(self.png,1366,900)
if __name__=='__main__': unittest.main()
