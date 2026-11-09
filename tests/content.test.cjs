const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const {parseHTML}=require('linkedom');
const client=fs.readFileSync('public/js/content-api.js','utf8');
const publicCode=fs.readFileSync('public/js/public-content.js','utf8');
function context(path='/', file='public/index.html', fetch=async()=>{throw Error('offline')}) {
  const {window,document}=parseHTML(fs.readFileSync(file,'utf8'));
  const ctx=vm.createContext({window,document,fetch,URL,AbortSignal,console,location:{pathname:path}});
  vm.runInContext(client,ctx);ctx.RelatoContent=window.RelatoContent;return ctx;
}
test('URL pública rechaza javascript, data, credenciales, rutas de protocolo y backslash',()=>{
  const c=context().RelatoContent;
  for(const s of ['javascript:alert(1)','data:text/html,test','//evil.test','/\\evil','https://a:b@evil.test'])assert.equal(c.safeURL(s),'');
  assert.equal(c.safeURL('/rodrigo.webp'),'/rodrigo.webp');assert.equal(c.safeURL('https://example.com/a'),'https://example.com/a');
});
test('lecturas públicas sin cookies y respuesta contractual obligatoria',async()=>{
  let request;const c=context('/',undefined,async(url,options)=>{request={url,options};return{ok:true,json:async()=>({ok:true,posts:[]})}}).RelatoContent;
  await c.get('posts');assert.equal(request.options.credentials,'omit');assert.equal(request.url,'https://api.relatoestrategico.cl/v1/public/posts');
  assert.throws(()=>c.postRows([{}]));assert.throws(()=>c.postRows([{title:'x',slug:'<script>'}]));
});
test('fallo de API preserva copy, imágenes y CSS aprobados',async()=>{
  const c=context();const before=[...c.document.querySelectorAll('h1,h2,h3,p,img,style')].map(n=>n.outerHTML);
  await vm.runInContext(publicCode,c);
  assert.deepEqual([...c.document.querySelectorAll('h1,h2,h3,p,img,style')].map(n=>n.outerHTML),before);
});
test('ediciones usan binding estable; ocultación, orden y texto seguro',async()=>{
  const c=context('/servicios','public/servicios/index.html',async(url)=>({ok:true,json:async()=>url.includes('/pages/servicios')?{ok:true,page:{blocks:[{template_key:'servicios-2',content:{title:'Nuevo <script>texto</script>'}},{template_key:'servicios-0',content:{description:'Descripción editada'}}],disabled_keys:['servicios-1']}}:url.includes('/pages/')?{ok:true,page:{blocks:[],disabled_keys:[]}}:{ok:true,settings:{}}}));
  await vm.runInContext(publicCode,c);
  const cards=[...c.document.querySelectorAll('.home-service-card')];
  assert.equal(cards[0].querySelector('h3').textContent,'Nuevo <script>texto</script>');
  assert.equal(cards[0].querySelector('script'),null);
  assert.equal(cards[1].hidden,true);
  assert.equal(cards[2].querySelector('p').textContent,'Descripción editada');
});
test('no hay persistencia administrativa en localStorage/sessionStorage',()=>{
  const s=fs.readFileSync('public/admin/app.js','utf8');assert.doesNotMatch(s,/localStorage|sessionStorage/);
  assert.match(s,/credentials: "include"/);assert.match(s,/X-CSRF-Token/);
});
test('todos los HTML con script inline tienen sintaxis válida',()=>{
  const {execFileSync}=require('node:child_process');
  const files=execFileSync('rg',['--files','-g','*.html'],{encoding:'utf8'}).trim().split('\n');
  for(const f of files){const{document}=parseHTML(fs.readFileSync(f,'utf8'));for(const s of document.querySelectorAll('script:not([src])')) new vm.Script(s.textContent,{filename:f});}
});

test('contrato real de página: IDs estables, semilla intacta, ocultos ausentes',()=>{
 const c=context().RelatoContent;
 const page={key:'home',blocks:[{id:3,sort_order:1,title:'Servicios editados',subtitle:'',description:'',button:'',url:'',image_url:''},{id:1,sort_order:2,title:'Hero: Tu experiencia tiene valor',subtitle:'',description:'',button:'',url:'',image_url:''}]};
 const n=c.normalizePage(page);
 assert.equal(n.blocks[0].template_key,'home-2');assert.equal(n.blocks[0].content.title,'Servicios editados');
 assert.deepEqual(Object.keys(n.blocks[1].content),[]);assert.ok(n.disabled_keys.includes('home-1'));
});
test('paginación real: no pierde publicaciones posteriores a la primera página',async()=>{
 let calls=0;
 const c=context('/',undefined,async(url)=>({ok:true,json:async()=>{calls++;const offset=Number(new URL(url).searchParams.get('offset'));return{ok:true,posts:offset===0?[{id:1},{id:2}]:[{id:3}],pagination:{limit:2,offset,count:offset===0?2:1}};}})).RelatoContent;
 const rows=await c.posts();assert.equal(rows.length,3);assert.equal(calls,2);
});

test('semilla de contacto no reordena el diseño aprobado; orden explícito sí',async()=>{
 const pages=require('./fixtures/public-pages.json');
 const c=context('/',undefined,async(url)=>({ok:true,json:async()=>url.includes('/pages/')?{ok:true,page:pages[new URL(url).pathname.split('/').pop()]}:url.includes('/posts')?{ok:true,posts:[]}:{ok:true,settings:{}}}));
 assert.equal(c.RelatoContent.normalizePage(pages.contacto).order_changed,false);
 const reversed={...pages.contacto,blocks:[...pages.contacto.blocks].reverse()};
 assert.equal(c.RelatoContent.normalizePage(reversed).order_changed,true);
 await vm.runInContext(publicCode,c);
 assert.match(c.document.querySelector('.contact-methods > div').textContent,/Correo/);
 const firstBio=c.document.querySelector('.rodrigo-new').firstElementChild;
 assert.ok(firstBio.matches('figure'),'preserva fotografía antes de biografía');
});
