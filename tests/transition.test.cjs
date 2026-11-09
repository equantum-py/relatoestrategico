const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
function client(fetch,source='approved-static'){const window={};const c=vm.createContext({window,URL,AbortSignal,fetch});vm.runInContext(fs.readFileSync('public/js/content-api.js','utf8').replace('const publicationSource = "approved-static";',`const publicationSource = "${source}";`),c);return window.RelatoContent;}
test('transición es una decisión explícita de versión, no depende de red ni cantidad de posts',async()=>{
 for(const source of ['approved-static','api']){
  const c=client(async()=>{throw Error('no debe consultar la red para decidir el corte')},source);
  assert.equal(await c.usePublicAPI(),source==='api');assert.equal(c.apiMode,source==='api');
 }
});
test('artículos aprobados: seis resúmenes conservan exactamente título, texto e imagen',()=>{
 const seed=require('../data/approved-posts.seed.json');const html=fs.readFileSync('public/blog/index.html','utf8');
 const original=JSON.parse(html.split('const fallbackPosts=')[1].split(';const app=')[0]);assert.equal(seed.posts.length,6);
 seed.posts.forEach((p,i)=>{assert.equal(p.slug,original[i][0]);assert.equal(p.category,original[i][1]);assert.equal(p.title,original[i][2]);assert.equal(p.excerpt,original[i][3]);assert.equal(p.image_url,original[i][4]);assert.equal(p.body,'');assert.equal(p.status,'borrador');});
});
test('artículos aprobados: un resumen importado sin cuerpo conserva la presentación de resumen',()=>{
 const c=client();const seed=require('../data/approved-posts.seed.json');const rows=c.postRows(seed.posts);assert.equal(rows[0].length,5);
 assert.equal(c.postRows([{...seed.posts[0],body:'Texto existente completo'}])[0].length,6);
});
test('carga de artículos aprobados: verificador detecta omisión, duplicación y cambios de texto o imagen',()=>{
 const {verify}=require('../scripts/validation/verify_approved_posts.cjs'),seed=require('../data/approved-posts.seed.json');
 assert.deepEqual(verify(seed.posts),[]);
 assert.ok(verify(seed.posts.slice(1)).length);
 assert.ok(verify([...seed.posts,seed.posts[0]]).length);
 for(const field of ['title','excerpt','body','image_url']){const altered=structuredClone(seed.posts);altered[0][field]='CAMBIO NO APROBADO';assert.ok(verify(altered).some(x=>x.endsWith(':'+field)));}
});
