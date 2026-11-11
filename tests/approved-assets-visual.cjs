/* Solo visual pendiente: imágenes aprobadas capturadas por GET; API productiva bloqueada. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');const {chromium}=require('playwright');
(async()=>{
 const dir=process.env.APPROVED_ASSET_SNAPSHOT_DIR||'/tmp/relato-approved-images',assets=JSON.parse(fs.readFileSync(path.join(dir,'manifest.json'),'utf8')),seed=require('../data/approved-posts.seed.json');
 const out=process.env.TEST_OUTPUT||'/tmp/relato-pr5-approved-assets';fs.mkdirSync(out,{recursive:true});
 const b=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--no-zygote','--single-process','--disable-gpu','--use-gl=angle','--use-angle=swiftshader']});
 try{
  const c=await b.newContext({reducedMotion:'reduce'}),p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
  await c.route('**/*',async r=>{
   const u=new URL(r.request().url()),image=assets.find(a=>a.url===u.href);
   if(image)return r.fulfill({status:200,contentType:image.mime,body:fs.readFileSync(path.join(dir,image.file))});
   if(u.origin==='https://www.relatoestrategico.cl'){
    let name=u.pathname;if(name.endsWith('/'))name+='index.html';const file=path.resolve('public','.'+name);
    if(file.startsWith(path.resolve('public')+path.sep)&&fs.existsSync(file))return r.fulfill({status:200,contentType:({'.js':'application/javascript','.css':'text/css','.html':'text/html','.png':'image/png','.webp':'image/webp'})[path.extname(file)]||'application/octet-stream',body:fs.readFileSync(file)});
   }await r.abort();
  });
  const results=[];
  for(const width of [390,1440]){
   await p.setViewportSize({width,height:900});await p.goto('https://www.relatoestrategico.cl/blog/',{waitUntil:'networkidle'});
   await p.locator('#grid .card img').evaluateAll(images=>{images.forEach(i=>i.loading='eager');return Promise.all(images.map(i=>i.decode()))});
   const cards=await p.locator('#grid .card').all();assert.equal(cards.length,6);
   for(let i=0;i<6;i++){assert.equal(await cards[i].locator('h2').innerText(),seed.posts[i].title);assert.equal(await cards[i].locator('img').getAttribute('src'),seed.posts[i].image_url);assert.ok(await cards[i].locator('img').evaluate(i=>i.naturalWidth>0&&i.naturalHeight>0));}
   assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);await p.screenshot({path:path.join(out,'approved-blog-'+width+'.png'),fullPage:true,animations:'disabled'});results.push({width,imagesDecoded:6,result:'PASS'});console.log('PASS approved images decoded and matching at '+width);
  }
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'results.json'),JSON.stringify({scope:'approved static content + original image byte snapshots; production API blocked',results,assets:assets.map(({file,...meta})=>meta),consoleErrors:errors},null,2)+'\n');
 }finally{await b.close();}
})().catch(e=>{console.error(e.message);process.exitCode=1});
