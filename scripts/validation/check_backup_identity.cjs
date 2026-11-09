/* Preflight local de recuperación: no consulta ni modifica la API o MariaDB. */
const fs=require('node:fs'),vm=require('node:vm');
function check(backup){
 if(!backup||backup.format!=='relato-backup'||backup.version!==1||backup.identity_schema!=='relato.block-key.v1'||!Array.isArray(backup.pages)||!backup.pages.length)return{result:'FAIL',reason:'legacy_or_invalid_identity_schema'};
 const window={},ctx=vm.createContext({window,URL,AbortSignal});vm.runInContext(fs.readFileSync('public/js/content-api.js','utf8'),ctx);
 const pages=new Set(),keys=new Set();
 try{
  for(const page of backup.pages){
   if(pages.has(page.key))throw Error('duplicate_page');pages.add(page.key);
   const checked=window.RelatoContent.normalizePage({...page,identity_schema:backup.identity_schema});
   for(const block of checked.blocks){if(keys.has(block.block_key))throw Error('duplicate_key');keys.add(block.block_key);}
  }
  return{result:'PASS',pages:pages.size,blocks:keys.size,scope:'frontend identity only; not a database restore or file integrity check'};
 }catch{return{result:'FAIL',reason:'unsupported_or_duplicate_block_identity'};}
}
module.exports={check};
if(require.main===module){try{const r=check(JSON.parse(fs.readFileSync(process.argv[2],'utf8')));console.log(JSON.stringify(r));process.exitCode=r.result==='PASS'?0:1;}catch{console.error('FAIL: indica un respaldo JSON local legible.');process.exitCode=2;}}
