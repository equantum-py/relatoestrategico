/* Solo compara una exportación JSON local del contenido de prueba. No conecta ni escribe a bases/API. */
const fs=require('node:fs');
const seed=require('../../data/approved-posts.seed.json');
function verify(rows,expectedStatus='borrador'){
 const failures=[];
 if(!Array.isArray(rows))return ['posts_not_array'];
 for(const expected of seed.posts){
  const matches=rows.filter(p=>p?.slug===expected.slug);
  if(matches.length!==1){failures.push(expected.slug+':missing_or_duplicate');continue;}
  const actual=matches[0];
  for(const key of ['title','category','excerpt','body','image_url'])if(actual[key]!==expected[key])failures.push(expected.slug+':'+key);
  if(actual.status!==expectedStatus)failures.push(expected.slug+':status');
 }
 return failures;
}
module.exports={verify};
if(require.main===module){
 const file=process.argv[2],status=process.argv[3]||'borrador';
 if(!file||!['borrador','publicado'].includes(status)){console.error('Uso: node scripts/validation/verify_approved_posts.cjs exportacion-aislada.json [borrador|publicado]');process.exit(2);}
 try{
  const data=JSON.parse(fs.readFileSync(file,'utf8')),failures=verify(data.posts||data,status);
  console.log(JSON.stringify({result:failures.length?'FAIL':'PASS',scope:'local exported rows only; no database persistence proof',expected:6,failures}));process.exitCode=failures.length?1:0;
 }catch{console.error('FAIL: no se pudo leer la exportación JSON local.');process.exitCode=2;}
}
