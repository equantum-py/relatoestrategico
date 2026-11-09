/* Render de la PR con respuestas públicas reales capturadas solo por GET.
   No llama la API real, no autentica, no modifica producción. */
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
(async()=>{
 const dir=process.env.PUBLIC_SNAPSHOT_DIR;
 if(!dir)throw Error('Define PUBLIC_SNAPSHOT_DIR con respuestas GET públicas; no exportes sesiones.');
 const snapshot={};for(const name of ['home','soluciones','servicios','resultados','nosotros','preguntas','contacto','posts','settings'])snapshot[name]=JSON.parse(fs.readFileSync(path.join(dir,name+'.json'),'utf8'));
 const output=process.env.TEST_OUTPUT||'/tmp/relato-pr5-public-real-snapshot';fs.mkdirSync(output,{recursive:true});
 const server=http.createServer((req,res)=>{
  let name=new URL(req.url,'http://localhost').pathname;if(name.endsWith('/'))name+='index.html';
  const file=path.resolve('public','.'+name);if(!file.startsWith(path.resolve('public')+path.sep)){res.writeHead(403).end();return;}
  fs.readFile(file,(e,b)=>{if(e){res.writeHead(404).end();return;}res.setHeader('Content-Type',({'.html':'text/html','.js':'application/javascript','.css':'text/css','.webp':'image/webp','.png':'image/png','.jpeg':'image/jpeg'})[path.extname(file)]||'application/octet-stream');res.end(b);});
 });await new Promise(r=>server.listen(0,'127.0.0.1',r));
 let browser;
 try{
  browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--no-zygote','--single-process','--disable-gpu','--use-gl=angle','--use-angle=swiftshader']}: {})});
  const context=await browser.newContext({reducedMotion:'reduce'}),p=await context.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
  let served=0;
  await context.route('https://api.relatoestrategico.cl/**',async route=>{
   assert.equal(route.request().method(),'GET');const u=new URL(route.request().url());assert.ok(u.pathname.startsWith('/v1/public/'));
   const key=u.pathname.split('/').pop(),data=snapshot[key];served++;
   await route.fulfill({status:data?200:404,contentType:'application/json',body:JSON.stringify(data||{ok:false,error:{code:'not_found'}})});
  });
  const results=[];
  for(const width of [390,1440])for(const route of ['/','/servicios/','/resultados/','/sobre-nosotros/','/como-trabajamos/','/soluciones/','/preguntas/','/contacto/','/blog/']){
   await p.setViewportSize({width,height:900});const before=served;
   await p.goto('http://127.0.0.1:'+server.address().port+route,{waitUntil:'networkidle'});
   assert.ok(served>before,'debe consumir API pública');
   const overflow=await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1);assert.equal(overflow,false);
   // Activa lazy-loading antes de inspeccionar las imágenes de toda la página.
   await p.evaluate(async()=>{for(let y=0;y<document.documentElement.scrollHeight;y+=700){scrollTo(0,y);await new Promise(r=>requestAnimationFrame(r));}scrollTo(0,0);});
   await p.locator('img').evaluateAll(images=>Promise.race([Promise.all(images.map(img=>img.decode().catch(()=>{}))),new Promise(r=>setTimeout(r,2000))]));
   if(route==='/')assert.match(await p.locator('.contact-methods > div').first().innerText(),/Correo/i);
   const name=(route==='/'?'home':route.replaceAll('/',''))+'-'+width;
   await p.screenshot({path:path.join(output,name+'.png'),fullPage:true,animations:'disabled'});
   results.push({route,width,overflow:false,heading:await p.locator('h1').first().innerText()});console.log('PASS snapshot '+route+' '+width);
   if(route==='/'){assert.equal(await p.locator('.editorial-card').count(),0);assert.match(await p.locator('.editorial-grid').innerText(),/Próximamente/);}
   if(route==='/blog/'){assert.equal(await p.locator('#grid .card').count(),0);}
  }
  assert.deepEqual(errors,[]);
  fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({scope:'local PR + real public GET snapshot; not deployed/authenticated acceptance',views:results,served,consoleErrors:errors,observedRisk:'API contains zero published posts; approved static blog cards disappear on successful API response'},null,2));
  console.log(JSON.stringify({views:results.length,served,consoleErrors:errors.length,output,blogEmpty:true}));
 }finally{if(browser)await browser.close();server.close();}
})().catch(e=>{console.error(e);process.exit(1)});
