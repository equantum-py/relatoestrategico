/* Diagnóstico aislado del requisito 5. Las aserciones reproducen riesgos;
   PASS del diagnóstico NO significa aceptación de restauraciones ni prueba MariaDB. */
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const pages=require('./fixtures/public-pages.json');
function client(){const window={};const c=vm.createContext({window,URL,AbortSignal});vm.runInContext(fs.readFileSync('public/js/content-api.js','utf8'),c);return window.RelatoContent;}
function edit(page){return {...page,blocks:page.blocks.map((b,i)=>({...b,title:`SINTETICO ${page.key} ${i}`,description:`Texto ficticio ${i}`}))};}
test('control: los 39 bloques editados mantienen vínculo si se conservan IDs',()=>{
 const c=client();let count=0;
 for(const p of Object.values(pages)){
  const edited=edit(p);edited.blocks.reverse();
  const n=c.normalizePage(edited);
  assert.equal(n.disabled_keys.length,0);
  for(const b of n.blocks){assert.notEqual(b.template_key,null);count++;}
 }
 assert.equal(count,39);
});
test('DIAGNÓSTICO P1: IDs nuevos + títulos editados pierden los 39 vínculos',()=>{
 const c=client();let lost=0,disabled=0;
 for(const p of Object.values(pages)){
  const restored=edit(p);restored.blocks.forEach(b=>b.id+=1000);
  const n=c.normalizePage(restored);
  lost+=n.blocks.filter(b=>b.template_key===null).length;disabled+=n.disabled_keys.length;
 }
 assert.equal(lost,39);assert.equal(disabled,39);
});
test('DIAGNÓSTICO P1: reutilizar IDs al restaurar puede vincular el bloque equivocado',()=>{
 const c=client();const original=edit(pages.servicios);
 const restored={...original,blocks:[...original.blocks].reverse().map((b,i)=>({...b,id:pages.servicios.blocks[i].id}))};
 const n=c.normalizePage(restored);
 assert.equal(n.blocks[0].content.title,'SINTETICO servicios 3');
 assert.equal(n.blocks[0].template_key,'servicios-0'); // contenido 3 aplicado a plantilla 0: incorrecto
 assert.notEqual(n.blocks[0].template_key,'servicios-3');
});
test('control: semilla con títulos intactos permite recuperar IDs regenerados',()=>{
 const c=client();let recovered=0;
 for(const p of Object.values(pages)){
  const restored={...p,blocks:p.blocks.map(b=>({...b,id:b.id+1000}))};
  const n=c.normalizePage(restored);recovered+=n.blocks.filter(b=>b.template_key!==null).length;
 }
 assert.equal(recovered,39);
});
