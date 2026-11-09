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
test('ediciones usan binding semántico; ocultación, orden y texto seguro',async()=>{
  const pages=require('./fixtures/public-pages.json');
  const page={...pages.servicios,identity_schema:'relato.block-key.v1',blocks:[
    {...pages.servicios.blocks[2],block_key:'servicios.linkedin-elite',id:800,title:'Nuevo <script>texto</script>'},
    {...pages.servicios.blocks[0],block_key:'servicios.linkedin-start',id:900,description:'Descripción editada'},
    {...pages.servicios.blocks[3],block_key:'servicios.workshop',id:1000}
  ]};
  const c=context('/servicios','public/servicios/index.html',async(url)=>({ok:true,json:async()=>url.includes('/pages/servicios')?{ok:true,page}:url.includes('/pages/')?{ok:true,page:{blocks:[]}}:{ok:true,settings:{}}}));
  await vm.runInContext(publicCode,c);
  const cards=[...c.document.querySelectorAll('.home-service-card')];
  assert.equal(cards[0].querySelector('h3').textContent,'Nuevo <script>texto</script>');
  assert.equal(cards[0].querySelector('script'),null);assert.equal(cards[1].hidden,true);
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

test('contrato legado sin identidad estable se rechaza incluso con IDs y títulos conocidos',()=>{
 const c=context().RelatoContent;const pages=require('./fixtures/public-pages.json');
 assert.throws(()=>c.normalizePage(pages.home),/identidad estable/);
 assert.throws(()=>c.normalizePage({...pages.home,identity_schema:'relato.block-key.v1'}),/Identidad/);
});
test('paginación real: no pierde publicaciones posteriores a la primera página',async()=>{
 let calls=0;
 const c=context('/',undefined,async(url)=>({ok:true,json:async()=>{calls++;const offset=Number(new URL(url).searchParams.get('offset'));return{ok:true,posts:offset===0?[{id:1},{id:2}]:[{id:3}],pagination:{limit:2,offset,count:offset===0?2:1}};}})).RelatoContent;
 const rows=await c.posts();assert.equal(rows.length,3);assert.equal(calls,2);
});

test('semilla con claves estables conserva contacto y biografía; orden explícito sí',async()=>{
 const pages=require('./fixtures/public-pages.json');const manifest=require('../data/block-identity-bootstrap.json');
 const keyed=Object.fromEntries(Object.entries(pages).map(([k,p])=>[k,{...p,identity_schema:'relato.block-key.v1',blocks:p.blocks.map(b=>({...b,block_key:manifest.bindings.find(x=>x.bootstrap_observed_id===b.id).block_key}))}]));
 const c=context('/',undefined,async(url)=>({ok:true,json:async()=>url.includes('/pages/')?{ok:true,page:keyed[new URL(url).pathname.split('/').pop()]}:url.includes('/posts')?{ok:true,posts:[]}:{ok:true,settings:{}}}));
 assert.equal(c.RelatoContent.normalizePage(keyed.contacto).order_changed,false);
 assert.equal(c.RelatoContent.normalizePage({...keyed.contacto,blocks:[...keyed.contacto.blocks].reverse()}).order_changed,true);
 await vm.runInContext(publicCode,c);assert.match(c.document.querySelector('.contact-methods > div').textContent,/Correo/);
 assert.ok(c.document.querySelector('.rodrigo-new').firstElementChild.matches('figure'));
});
