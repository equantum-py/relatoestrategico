#!/usr/bin/env python3
"""Captura temporal de las seis imágenes públicas ya aprobadas; solo GET HTTPS, sin credenciales."""
import concurrent.futures,hashlib,json,pathlib,sys,urllib.request,urllib.parse
seed=json.loads(pathlib.Path('data/approved-posts.seed.json').read_text())['posts']
out=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/relato-approved-images');out.mkdir(parents=True,exist_ok=True)
def capture(post):
    url=post['image_url']
    if urllib.parse.urlparse(url).hostname!='images.unsplash.com' or not url.startswith('https://'):raise ValueError('Origen no aprobado')
    with urllib.request.urlopen(url,timeout=20) as r:
        body=r.read(5000001);mime=r.headers.get_content_type()
        if r.status!=200 or len(body)>5000000 or mime not in ['image/jpeg','image/png','image/webp']:raise ValueError('Imagen no válida')
    name=hashlib.sha256(url.encode()).hexdigest()+'.bin';(out/name).write_bytes(body)
    return {'url':url,'file':name,'mime':mime,'size':len(body),'sha256':hashlib.sha256(body).hexdigest()}
with concurrent.futures.ThreadPoolExecutor(max_workers=3) as ex:rows=list(ex.map(capture,seed))
(out/'manifest.json').write_text(json.dumps(rows,indent=2)+'\n');print(json.dumps({'captured':len(rows),'scope':'approved public image GET only'}))
