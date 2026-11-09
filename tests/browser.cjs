/* Pruebas contractuales en navegador. No toca la API real ni MariaDB. */
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--no-zygote','--single-process','--disable-gpu','--use-gl=angle','--use-angle=swiftshader']}: {})});
 let server;
 if (!process.env.BASE_URL) {
  server=require('node:http').createServer((req,res)=>{
   let name=decodeURIComponent(new URL(req.url,'http://localhost').pathname);
   if(name.endsWith('/')) name+='index.html';
   const file=path.resolve('public','.'+name);
   if(!file.startsWith(path.resolve('public')+path.sep)){res.writeHead(403).end();return;}
   fs.readFile(file,(e,b)=>{if(e){res.writeHead(404).end();return;}res.setHeader('Content-Type',file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':file.endsWith('.html')?'text/html':'application/octet-stream');res.end(b);});
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 }
 const base=process.env.BASE_URL||'http://127.0.0.1:'+server.address().port;
 const out=process.env.TEST_OUTPUT||'/tmp/relato-tests';fs.mkdirSync(out,{recursive:true});
 let passed=0;
 const ok=label=>{passed++;console.log('PASS '+label)};
 const context=await browser.newContext();
 let role="admin",session=false,offline=false,posts=[],nextId=1,logoutFails=false;
 const pages=[{key:'home',label:'Inicio',description:'Página inicial',blocks:[{id:1,template_key:'home-0',title:'Portada',subtitle:'',description:'',button:'',url:'',image_url:'',visible:true,sort_order:1}]}];
 await context.route('**/js/content-api.js',route=>route.fulfill({status:200,contentType:'application/javascript',body:fs.readFileSync('public/js/content-api.js','utf8').replace('const publicationSource = "approved-static";','const publicationSource = "api";')}));
 const calls=[];const publicPages=require('./fixtures/public-pages.json');
 await context.route('https://api.relatoestrategico.cl/**',async route=>{
  const req=route.request(),url=new URL(req.url()),p=url.pathname,m=req.method();
  calls.push([m,p]);
  if(offline)return route.abort();
  const send=(status,data)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
  const good=data=>send(200,{ok:true,...data});
  if(p==='/v1/auth/me')return session?good({user:{id:1,email:'test@relato.test',role},csrf:'session-token'}):send(401,{ok:false,error:{message:'Sesión no activa'}});
  if(p==='/v1/auth/csrf')return good({csrf:'form-token'});
  if(p==='/v1/auth/login'){assert.equal(req.headers()['x-csrf-token'],'form-token');session=true;return good({user:{id:1,email:'test@relato.test',role},csrf:'session-token'});}
  if(p==='/v1/auth/logout'){if(logoutFails)return send(500,{ok:false,error:{message:'No disponible'}});session=false;return good({});}
  if(p.startsWith('/v1/public/')){
   if(p==='/v1/public/posts')return good({posts:posts.filter(x=>x.status==='publicado').map(({body,...post})=>post),pagination:{limit:50,offset:0,count:posts.filter(x=>x.status==='publicado').length}});
   if(p.startsWith('/v1/public/posts/')){const post=posts.find(x=>x.slug===decodeURIComponent(p.split('/').pop())&&x.status==='publicado');return post?good({post}):send(404,{ok:false,error:{code:'not_found',message:'La publicación no existe.'}});} 
   if(p.endsWith('/settings'))return good({settings:{}});
   return good({page:publicPages[p.split('/').pop()]||{key:p.split('/').pop(),blocks:[]}});
  }
  if(!session)return send(401,{ok:false,error:{message:'Sesión no activa'}});
  if(m!=='GET')assert.equal(req.headers()['x-csrf-token'],'session-token');
  if(p==='/v1/pages')return good({pages});
  if(p==='/v1/pages/home')return good({page:pages[0]});
  if(p==='/v1/pages/home/blocks/1'){Object.assign(pages[0].blocks[0],req.postDataJSON());return good({page:pages[0]});}
  if(p==='/v1/media')return good({media:[]});
  if(p==='/v1/settings')return good({settings:{siteName:'Relato Estratégico'}});
  if(p==='/v1/users')return good({users:[{id:1,email:'test@relato.test',role:'admin',active:true}]});
  if(p==='/v1/backup')return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({format:'relato-backup',version:1,pages,posts,settings:{},media:[]})});
  if(p==='/v1/posts'&&m==='POST'){const post={...req.postDataJSON(),id:nextId++,published_at:'2026-11-09 12:00:00'};posts.push(post);return send(201,{ok:true,post});}
  if(p==='/v1/posts')return good({posts});
  if(/^\/v1\/posts\/\d+$/.test(p)){
   const post=posts.find(x=>x.id===Number(p.split('/').pop()));if(m==='PUT')Object.assign(post,req.postDataJSON());return good({post});
  }
  return send(404,{ok:false,error:{message:'Ruta contractual no implementada en fixture: '+p}});
 });
 const p=await context.newPage();const errors=[];p.on('pageerror',e=>errors.push(e.message));
 await p.goto(base+'/admin/');await p.locator('#login-form').waitFor();
 assert.equal(await p.locator('#screen').count(),0);ok('ruta administrativa exige sesión');
 await p.locator('[name=email]').fill('test@relato.test');await p.locator('[name=password]').fill('dato-sintetico');await p.locator('#login-btn').click();await p.locator('[data-nav=posts]').waitFor();ok('login con CSRF y sesión contractual');
 await p.locator('[data-nav=posts]').click();await p.locator('#new-post').click();
 await p.locator('[name=title]').fill('Artículo contractual');await p.locator('[name=slug]').fill('articulo-contractual');await p.locator('[name=body]').fill('Texto persistido en fixture. <script>alert(1)</script>');await p.locator('[name=status]').selectOption('publicado');await p.locator('#save').click();await p.getByText('Artículo creado.',{exact:true}).waitFor();
 assert.equal(posts.length,1);ok('crear y publicar artículo (fixture)');
 await p.locator('[name=title]').fill('Artículo editado');await p.locator('#save').click();await p.getByText('Cambios guardados.',{exact:true}).waitFor();assert.equal(posts[0].title,'Artículo editado');
 await p.reload();await p.locator('[data-nav=posts]').click();await p.getByText('Artículo editado',{exact:true}).waitFor();ok('edición y recarga recuperan API (fixture)');
 const pub=await context.newPage();await pub.goto(base+'/blog/?articulo=articulo-contractual');await pub.getByRole('heading',{name:'Artículo editado',exact:true}).waitFor();assert.match(await pub.locator('.article-body').innerText(),/<script>/);assert.equal(await pub.locator('.article-body script').count(),0);ok('contenido publicado visible y escapado en blog');
 posts[0].status='borrador';await pub.reload();await pub.getByRole('heading',{name:'Artículo no encontrado',exact:true}).waitFor();assert.equal(await pub.locator('.article-body').count(),0);posts[0].status='publicado';ok('borrador retirado del sitio y detalle 404 sin resucitar respaldo');
 await p.locator('[data-nav=pages]').click();await p.locator('[data-page=home]').click();
 await p.locator('[data-edit="1"]').click();await p.locator('[name=title]').fill('Título editado');await p.locator('#save').click();await p.locator('.item strong').filter({hasText:'Título editado'}).waitFor();assert.equal(pages[0].blocks[0].title,'Título editado');ok('editar bloque con payload real');
 for(const nav of ['media','settings','users','backup']){await p.locator('[data-nav='+nav+']').click();await p.locator('#screen').waitFor();await p.waitForTimeout(100);assert.equal(await p.locator('#screen .alert.error').count(),0);}ok('medios, ajustes, usuarios y respaldos cargan contrato');
 for(const width of [390,1440]){
  await p.setViewportSize({width,height:900});
  for(const nav of ['home','pages','posts','media','settings','users','backup']){
   if(width===390)await p.locator('#toggle').click();
   await p.locator('[data-nav='+nav+']').click();await p.waitForTimeout(100);
   assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'admin overflow '+nav+' '+width);
   await p.screenshot({path:path.join(out,'admin-'+nav+'-'+width+'.png'),fullPage:true,animations:"disabled"});
  }
 }ok('7 vistas administrativas sin overflow en mobile y desktop');
 offline=true;await p.locator('[data-nav=posts]').click();await p.getByRole('alert').waitFor();assert.match(await p.getByRole('alert').innerText(),/conectar/);offline=false;ok('error de conexión visible sin falso guardado');
 logoutFails=true;await p.locator('#logout').click();await p.getByText('No se pudo cerrar la sesión en el servidor. Intenta nuevamente.',{exact:true}).waitFor();assert.equal(await p.locator('#logout').count(),1);logoutFails=false;
 await p.locator('#logout').click();await p.locator('#login-form').waitFor();assert.equal(session,false);await p.reload();await p.locator('#login-form').waitFor();ok('logout confirma servidor y protege recarga');
 role='reader';session=true;await p.reload();await p.locator('[data-nav=posts]').click();await p.locator('#screen .list').waitFor();assert.equal(await p.locator('#new-post').count(),0);assert.equal(await p.locator('[data-nav=users]').count(),0);ok('lector sin controles de escritura ni usuarios');
 session=false;await p.locator('[data-nav=pages]').click();await p.locator('#login-form').waitFor();ok('sesión vencida retira panel');role='admin';
 for(const width of [390,1440]){
  await p.setViewportSize({width,height:900});await p.screenshot({path:path.join(out,'admin-login-'+width+'.png'),fullPage:true,animations:"disabled"});
  for(const route of ['/','/servicios/','/blog/','/sobre-nosotros/','/resultados/','/como-trabajamos/','/contacto/','/preguntas/','/soluciones/']){
   await pub.setViewportSize({width,height:900});await pub.goto(base+route);await pub.waitForTimeout(150);
   assert.equal(await pub.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false,'overflow '+route+' '+width);
   await pub.screenshot({path:path.join(out,(route==='/'?'home':route.replaceAll('/',''))+'-'+width+'.png'),fullPage:true,animations:"disabled"});
  }
 }ok('9 páginas públicas sin overflow en 390 y 1440 px; capturas');
 offline=true;await pub.goto(base+'/');await pub.waitForTimeout(150);assert.match(await pub.locator('h1').innerText(),/Tu tarea es ser experto/);ok('respaldo público ante desconexión');
 assert.deepEqual(errors,[]);console.log(JSON.stringify({passed,calls:calls.length,output:out}));await browser.close();if(server)server.close();
})().catch(e=>{console.error(e);process.exit(1)});
