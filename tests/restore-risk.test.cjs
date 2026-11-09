/* Regresión P1: solo el frontend/contrato propuesto. No simula una restauración SQL. */
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const pages=require('./fixtures/public-pages.json'),manifest=require('../data/block-identity-bootstrap.json');
function client(){const window={};const c=vm.createContext({window,URL,AbortSignal});vm.runInContext(fs.readFileSync('public/js/content-api.js','utf8'),c);return window.RelatoContent;}
function keyed(p){return {...p,identity_schema:'relato.block-key.v1',blocks:p.blocks.map(b=>({...b,block_key:manifest.bindings.find(x=>x.bootstrap_observed_id===b.id).block_key}))};}
test('P1: 39 claves se conservan con IDs nuevos, títulos editados y orden invertido',()=>{
 const c=client();let count=0;
 for(const p of Object.values(pages)){
  const original=keyed(p);const restored={...original,blocks:original.blocks.map((b,i)=>({...b,id:1000+i,title:'SINTETICO '+b.block_key,description:'Texto ficticio '+b.block_key})).reverse()};
  const n=c.normalizePage(restored);assert.equal(n.disabled_keys.length,0);
  for(const b of n.blocks){assert.equal(b.content.title,'SINTETICO '+b.block_key);count++;}
 }assert.equal(count,39);
});
test('P1: reutilizar IDs no cambia la identidad del bloque restaurado',()=>{
 const c=client(),p=keyed(pages.servicios);
 const restored={...p,blocks:[...p.blocks].reverse().map((b,i)=>({...b,id:p.blocks[i].id,title:'SINTETICO '+b.block_key}))};
 const n=c.normalizePage(restored);assert.equal(n.blocks[0].block_key,'servicios.workshop');assert.equal(n.blocks[0].content.title,'SINTETICO servicios.workshop');
});
test('P1: títulos intactos tampoco sustituyen claves ausentes',()=>{
 const c=client(),p=keyed(pages.home);delete p.blocks[0].block_key;
 assert.throws(()=>c.normalizePage(p),/Identidad/);
});
test('P1: claves duplicadas, de otra página o desconocidas rechazan toda la respuesta',()=>{
 const c=client(),p=keyed(pages.home);
 for(const key of [p.blocks[1].block_key,'servicios.workshop','home.desconocido']){
  const bad=structuredClone(p);bad.blocks[0].block_key=key;assert.throws(()=>c.normalizePage(bad),/Identidad/);
 }
});
