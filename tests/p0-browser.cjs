/* Solo flujos afectados por P1 y transición: respuestas aisladas; sin autenticación ni escrituras reales. */
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
const pages=require('./fixtures/public-pages.json'),manifest=require('../data/block-identity-bootstrap.json'),seed=require('../data/approved-posts.seed.json');
(async()=>{
 const output=process.env.TEST_OUTPUT||'/tmp/relato-pr5-p0';fs.mkdirSync(output,{recursive:true});
 const server=http.createServer((req,res)=>{
  let name=new URL(req.url,'http://localhost').pathname;if(name.endsWith('/'))name+='index.html';
  const file=path.resolve('public','.'+name);if(!file.startsWith(path.resolve('public')+path.sep)){res.writeHead(403).end();return;}
  fs.readFile(file,(e,b)=>{if(e){res.writeHead(404).end();return;}res.setHeader('Content-Type',({'.html':'text/html','.js':'application/javascript','.json':'application/json','.css':'text/css','.png':'image/png','.webp':'image/webp','.jpeg':'image/jpeg'})[path.extname(file)]||'application/octet-stream');res.end(b);});
 });await new Promise(r=>server.listen(0,'127.0.0.1',r));
 let browser;
 try{
  browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--no-zygote','--single-process','--disable-gpu','--use-gl=angle','--use-angle=swiftshader']}: {})});
  const context=await browser.newContext({reducedMotion:'reduce'}),p=await context.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
  let source='approved-static',stable=false,posts=[],apiPostCalls=0,postsUnavailable=false;
  await context.route('**/js/content-api.js',r=>r.fulfill({status:200,contentType:'application/javascript',body:fs.readFileSync('public/js/content-api.js','utf8').replace('const publicationSource = "approved-static";',`const publicationSource = "${source}";`)}));
  await context.route('https://api.relatoestrategico.cl/**',async route=>{
   const request=route.request();assert.equal(request.method(),'GET');const u=new URL(request.url());assert.ok(u.pathname.startsWith('/v1/public/'));
   if(postsUnavailable && u.pathname.startsWith('/v1/public/posts'))return route.abort();
   let data,status=200;
   if(u.pathname==='/v1/public/posts'){apiPostCalls++;data={ok:true,posts,pagination:{limit:50,offset:0,count:posts.length}};}
   else if(u.pathname.startsWith('/v1/public/posts/')){const post=posts.find(x=>x.slug===u.pathname.split('/').pop());status=post?200:404;data=post?{ok:true,post}:{ok:false,error:{code:'not_found'}};}
   else if(u.pathname==='/v1/public/settings')data={ok:true,settings:{}};
   else{
    const key=u.pathname.split('/').pop();const page=structuredClone(pages[key]);
    if(stable){page.identity_schema='relato.block-key.v1';page.blocks=page.blocks.map(b=>({...b,block_key:manifest.bindings.find(x=>x.bootstrap_observed_id===b.id).block_key,id:5000+b.id}));
     if(key==='servicios'){page.blocks[3].title='Taller restaurado con clave estable';page.blocks[3].id=11;page.blocks=page.blocks.filter(b=>b.block_key!=='servicios.linkedin-pro');}
    }data={ok:true,page};
   }
   await route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
  });
  let passed=0;const results=[];
  async function open(route){await p.goto('http://127.0.0.1:'+server.address().port+route,{waitUntil:'networkidle'});assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);}
  async function record(name,width){await p.screenshot({path:path.join(output,name+'-'+width+'.png'),fullPage:true,animations:'disabled'});results.push({name,width,result:'PASS'});passed++;console.log('PASS '+name+' '+width);}
  for(const width of [390,1440]){
   await p.setViewportSize({width,height:900});source='approved-static';stable=false;posts=[];postsUnavailable=false;
   const before=apiPostCalls;await open('/blog/');assert.equal(await p.locator('#grid .card').count(),6);
   for(let i=0;i<6;i++){const card=p.locator('#grid .card').nth(i);assert.equal(await card.locator('h2').innerText(),seed.posts[i].title);assert.equal(await card.locator('p').innerText(),seed.posts[i].excerpt);assert.equal(await card.locator('img').getAttribute('src'),seed.posts[i].image_url);}
   assert.equal(apiPostCalls,before);await record('static-approved-six-exact-summaries',width);
   await open('/');assert.equal(await p.locator('.editorial-card').count(),3);await record('home-preserved-before-cutover',width);
   await open('/servicios/');assert.equal(await p.locator('.home-service-card').filter({has:p.getByRole('heading',{name:'LinkedIn Pro',exact:true})}).count(),1);assert.equal(await p.locator('.home-service-card[hidden]').count(),0);await record('legacy-contract-keeps-approved-design',width);
   stable=true;await open('/servicios/');await p.getByRole('heading',{name:'Taller restaurado con clave estable',exact:true}).waitFor();assert.equal(await p.locator('.home-service-card[hidden]').count(),1);assert.equal(await p.locator('.home-service-card').nth(3).locator('h3').innerText(),'Taller restaurado con clave estable');await record('semantic-key-survives-new-and-reused-ids',width);
   source='api';posts=seed.posts.map(x=>({...x,status:'publicado'}));await open('/blog/');assert.equal(await p.locator('#grid .card').count(),6);await record('api-seed-six-summaries-after-cutover',width);
   await open('/blog/?articulo='+seed.posts[0].slug);await p.getByRole('heading',{name:seed.posts[0].title,exact:true}).waitFor();assert.match(await p.locator('.note').innerText(),/resumen editorial/);await record('seed-summary-does-not-invent-body',width);
   posts=[];await open('/blog/');assert.equal(await p.locator('#grid .card').count(),0);await record('api-empty-is-authoritative-after-cutover',width);
   await open('/blog/?articulo='+seed.posts[0].slug);await p.getByRole('heading',{name:'Artículo no encontrado',exact:true}).waitFor();await record('withdrawn-post-does-not-resurrect',width);
   postsUnavailable=true;await open('/blog/');await p.getByRole('heading',{name:'Artículos no disponibles temporalmente',exact:true}).waitFor();assert.equal(await p.locator('#grid .card').count(),0);await record('api-outage-does-not-resurrect-static-posts',width);
  }
  assert.deepEqual(errors,[]);const report={scope:'isolated frontend fixtures only; proposed stable backend contract; no SQL/login/production writes',passed,results,consoleErrors:errors.length};fs.writeFileSync(path.join(output,'results.json'),JSON.stringify(report,null,2)+'\n');console.log(JSON.stringify({passed,output,consoleErrors:errors.length}));
 }finally{if(browser)await browser.close();server.close();}
})().catch(e=>{console.error(e);process.exit(1)});
