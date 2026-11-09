/* Prueba integrada: frontend real + PHP entregado + MariaDB local. Sin respuestas contractuales simuladas.
   Transporte del navegador redirigido exclusivamente a loopback; toda otra red se bloquea. */
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),net=require('node:net'),assert=require('node:assert/strict'),vm=require('node:vm');
const {spawn,spawnSync}=require('node:child_process');const {chromium}=require('playwright');
const FRONT='https://www.relatoestrategico.cl',API='https://api.relatoestrategico.cl';
const backend=path.resolve(process.env.RELATO_TEST_BACKEND_DIR||'../integrated-backend'),runtime=path.resolve(process.env.RELATO_TEST_RUNTIME_DIR||'../isolated-runtime'),state=path.resolve(process.env.RELATO_TEST_STATE||'../isolated-state');
const app=path.join(backend,'relato-app'),apiRoot=path.join(backend,'public_html/relato-api');
if(!fs.existsSync(path.join(app,'src/Pages.php'))||!fs.existsSync(path.join(state,'db/mysql')))throw Error('Falta preparar el paquete y el datadir aislado.');
const php=process.env.RELATO_TEST_PHP||path.join(runtime,'usr/bin/php8.3'),dbbin=process.env.RELATO_TEST_MARIADBD||path.join(runtime,'usr/sbin/mariadbd');
const env={...process.env,LD_LIBRARY_PATH:path.join(runtime,'usr/lib/x86_64-linux-gnu'),RELATO_APP_DIR:app,RELATO_TEST_STATE:state,RELATO_CONFIG:path.join(state,'config.php'),RELATO_API_ROOT:apiRoot};
const ini=process.env.RELATO_TEST_PHP_INI||path.join(state,'php.ini');const phpArgs=fs.existsSync(ini)?['-c',ini]:[];
const database='relato_pr5_test_'+crypto.randomBytes(4).toString('hex');
const accounts=Object.fromEntries(['admin','editor','reader'].map(role=>[role,{email:role+'@pr5.test',password:crypto.randomBytes(20).toString('hex')+'Aa1!'}]));
const seed=require('../data/approved-posts.seed.json'),keys=require('../data/block-identity-bootstrap.json').bindings.map(x=>x.block_key);
const output=path.resolve(process.env.TEST_OUTPUT||'/tmp/relato-pr5-integrated');fs.mkdirSync(output,{recursive:true});
const results=[];let mysql,phpServer,browser,setupDone=false;let csrf='',activeContext;
const record=(name,details={})=>{results.push({name,result:'PASS',...details});console.log('PASS '+name)};
const fixture=(mode,body={})=>{
 const r=spawnSync(php,[...phpArgs,'scripts/validation/isolated_fixture.php',mode],{env,input:JSON.stringify({db:database,...body}),encoding:'utf8',maxBuffer:2000000});
 if(r.status!==0)throw Error('Fallo de soporte aislado '+mode+': '+r.stderr.trim());return JSON.parse(r.stdout);
};
const query=(sql,params=[])=>fixture('query',{sql,params});
const delay=ms=>new Promise(r=>setTimeout(r,ms));
async function freePort(){const s=net.createServer();await new Promise(r=>s.listen(0,'127.0.0.1',r));const p=s.address().port;await new Promise(r=>s.close(r));return p;}
(async()=>{
 try{
  fs.writeFileSync(path.join(state,'only-pr5-tests'),'Only isolated PR5 validation\n',{mode:0o600});
  const dbPort=await freePort();env.RELATO_TEST_DB_PORT=String(dbPort);
  mysql=spawn(dbbin,['--no-defaults','--basedir='+(process.env.RELATO_TEST_DB_BASEDIR||path.join(runtime,'usr')),'--datadir='+path.join(state,'db'),'--socket=','--bind-address=127.0.0.1','--port='+dbPort,'--user=root','--pid-file='+path.join(state,'run/mysql.pid'),'--log-error='+path.join(state,'logs/mysql.log'),'--innodb-use-native-aio=0'],{env,stdio:'ignore'});
  await delay(1200);
  const setup=fixture('setup',{db_pass:crypto.randomBytes(24).toString('hex'),secret:crypto.randomBytes(32).toString('hex'),accounts,api_root:apiRoot,approved_posts:seed.posts.slice(1)});setupDone=true;
  assert.equal(setup.blocks,39);assert.match(setup.version,/10\.11\./);record('PHP and MariaDB isolated fixture ready',{databaseVersion:setup.version,knownBlocks:39});
  const port=await freePort();
  const log=fs.openSync(path.join(state,'logs/php.log'),'a',0o600);
  phpServer=spawn(php,[...phpArgs,'-S','127.0.0.1:'+port,'-t',apiRoot,path.resolve('scripts/validation/isolated_router.php')],{env,stdio:['ignore',log,log]});fs.closeSync(log);
  const local='http://127.0.0.1:'+port;
  for(let i=0;i<60;i++){try{if((await fetch(local+'/v1/health')).ok)break;}catch{}await delay(100);}
  browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE,args:['--no-sandbox','--disable-dev-shm-usage','--no-zygote','--single-process','--disable-gpu','--use-gl=angle','--use-angle=swiftshader']});
  let outbound=0;const errors=[];
  async function context(mode='api'){
   const c=await browser.newContext({reducedMotion:'reduce'});
   await c.route('**/*',async route=>{
    const req=route.request(),u=new URL(req.url());
    if(u.origin===API){
     const headers={},originalHeaders=await req.allHeaders();for(const k of ['origin','cookie','content-type','x-csrf-token','accept'])if(originalHeaders[k])headers[k]=originalHeaders[k];
     const response=await fetch(local+u.pathname+u.search,{method:req.method(),headers,body:['GET','HEAD'].includes(req.method())?undefined:req.postDataBuffer(),redirect:'manual'});
     const h={};response.headers.forEach((v,k)=>{if(!['transfer-encoding','connection','content-length'].includes(k))h[k]=v;});
     await route.fulfill({status:response.status,headers:h,body:Buffer.from(await response.arrayBuffer())});return;
    }
    if(u.origin===FRONT){
     let name=decodeURIComponent(u.pathname);if(name.endsWith('/'))name+='index.html';const file=path.resolve('public','.'+name);
     if(!file.startsWith(path.resolve('public')+path.sep)||!fs.existsSync(file))return route.fulfill({status:404,body:''});
     let data=fs.readFileSync(file);if(name==='/js/content-api.js'&&mode==='api')data=Buffer.from(data.toString().replace('const publicationSource = "approved-static";','const publicationSource = "api";'));
     await route.fulfill({status:200,contentType:({'.js':'application/javascript','.html':'text/html','.css':'text/css','.png':'image/png','.webp':'image/webp','.jpeg':'image/jpeg'})[path.extname(file)]||'application/octet-stream',body:data});return;
    }
    outbound++;await route.abort(); // Todas las fuentes ajenas quedan fuera; ninguna API productiva recibe tráfico.
   });return c;
  }
  activeContext=await context();const p=await activeContext.newPage();p.on('pageerror',e=>errors.push(e.message));
  async function api(method,uri,body,auth=true){
   const headers={'Origin':FRONT};if(auth){const cookies=await activeContext.cookies(API);headers.Cookie=cookies.map(c=>c.name+'='+c.value).join('; ');}
   if(body!==undefined){headers['Content-Type']='application/json';headers['X-CSRF-Token']=csrf;}
   const r=await fetch(local+uri,{method,headers,body:body===undefined?undefined:JSON.stringify(body)});const raw=await r.text();let data;try{data=JSON.parse(raw)}catch{data=raw}return{status:r.status,data,headers:r.headers};
  }
  async function login(role){await p.goto(FRONT+'/admin/');await p.locator('#login-form').waitFor();await p.locator('[name=email]').fill(accounts[role].email);await p.locator('[name=password]').fill(accounts[role].password);await p.locator('#login-btn').click();await p.locator('[data-nav=pages]').waitFor();const me=await api('GET','/v1/auth/me');assert.equal(me.status,200);assert.equal(me.data.user.role,role);csrf=me.data.csrf;}
  async function logout(){await p.locator('#logout').click();await p.locator('#login-form').waitFor();assert.equal((await api('GET','/v1/auth/me')).status,401);csrf='';}
  await p.goto(FRONT+'/admin/');await p.locator('[name=email]').fill(accounts.admin.email);await p.locator('[name=password]').fill('PR5-wrong-password');await p.locator('#login-btn').click();await p.locator('#login-msg [role=alert]').waitFor();assert.equal(await p.locator('#layout').count(),0);record('invalid credentials rejected by real PHP; panel remains protected');
  await login('admin');const cookie=(await activeContext.cookies(API)).find(c=>c.name==='__Host-rs_session');assert.ok(cookie?.httpOnly&&cookie.secure&&cookie.sameSite==='Strict');record('browser login real PHP session and secure cookie');
  const anonymous=await context(),pub=await anonymous.newPage();pub.on('pageerror',e=>errors.push(e.message));
  const normalize=(code,page)=>{const window={};const c=vm.createContext({window,URL,AbortSignal});vm.runInContext(code,c);return window.RelatoContent.normalizePage(page);};
  const client=fs.readFileSync('public/js/content-api.js','utf8'),baseline=fs.readFileSync(path.join(state,'baseline-content-api.js'),'utf8');
  const initial=(await api('GET','/v1/pages')).data.pages;
  assert.deepEqual(initial.flatMap(x=>x.blocks.map(b=>b.block_key)).sort(),[...keys].sort());
  for(const page of initial){const raw=(await api('GET','/v1/public/pages/'+page.key,undefined,false)).data.page;assert.equal(raw.identity_schema,'relato.block-key.v1');assert.equal(normalize(client,raw).disabled_keys.length,0);}
  record('all 39 real PHP block keys match frontend catalog');
  const go=async view=>{if(p.viewportSize().width<=760 && !await p.locator('#layout.menu-open').count())await p.locator('#toggle').click();await p.locator('[data-nav='+view+']').click();};
  async function blockEditor(key){const rows=query('SELECT id,page_key FROM page_blocks WHERE block_key = ?',[key]);assert.equal(rows.length,1);await go('pages');await p.locator('[data-page='+rows[0].page_key+']').click();await p.locator('[data-edit="'+rows[0].id+'"]').click();await p.locator('#block-form').waitFor();}
  async function saveBlock(){await p.locator('#save').click();await p.locator('#page-msg').waitFor({state:'attached'});}
  await go('pages');await p.locator('[data-page=servicios]').click();const startId=query('SELECT id FROM page_blocks WHERE block_key=?',['servicios.linkedin-start'])[0].id;
  await Promise.all([p.waitForResponse(r=>r.url()===API+'/v1/pages/servicios/order'&&r.request().method()==='PUT'),p.locator('[data-move="'+startId+'"][data-dir="1"]').click()]);
  assert.equal(query('SELECT block_key FROM page_blocks WHERE page_key=? ORDER BY sort_order',['servicios'])[0].block_key,'servicios.linkedin-pro');
  await pub.goto(FRONT+'/servicios/',{waitUntil:'networkidle'});assert.equal(await pub.locator('.home-service-card h3').first().innerText(),'LinkedIn Pro');record('panel reorder persists and real public page follows semantic keys');
  await blockEditor('home.hero');await p.locator('[name=title]').fill('PR5 edición aislada de portada');await saveBlock();
  assert.equal(query('SELECT title FROM page_blocks WHERE block_key = ?',['home.hero'])[0].title,'PR5 edición aislada de portada');
  await pub.goto(FRONT+'/');await pub.getByRole('heading',{name:'PR5 edición aislada de portada',exact:true}).waitFor();record('panel edits actual PHP and MariaDB; public block reflects persisted title');
  await p.locator('#add').click();await p.locator('[name=title]').fill('PR5 bloque personalizado aislado');await p.locator('[name=description]').fill('Contenido sintético solo de la base aislada.');await saveBlock();
  const custom=query("SELECT id,block_key FROM page_blocks WHERE block_key LIKE 'home.custom.%'")[0];assert.match(custom.block_key,/^home\.custom\.[a-f0-9]{16}$/);
  const customPage=(await api('GET','/v1/public/pages/home',undefined,false)).data.page;
  assert.throws(()=>normalize(baseline,customPage));assert.doesNotThrow(()=>normalize(client,customPage));
  await pub.reload();await pub.getByRole('heading',{name:'PR5 bloque personalizado aislado',exact:true}).waitFor();record('custom hex16 key accepted; a6 baseline incompatibility reproduced');
  await p.locator('[data-toggle="'+custom.id+'"]').click();await p.getByText('Bloque oculto en el sitio.',{exact:true}).waitFor();assert.equal(query('SELECT visible FROM page_blocks WHERE block_key=?',[custom.block_key])[0].visible,0);
  await pub.reload({waitUntil:'networkidle'});assert.equal(await pub.getByRole('heading',{name:'PR5 bloque personalizado aislado',exact:true}).count(),0);assert.ok(!(await api('GET','/v1/public/pages/home',undefined,false)).data.page.blocks.some(b=>b.block_key===custom.block_key));record('panel visibility change removes block from real anonymous response and DOM');
  await go('backup');const downloadEvent=p.waitForEvent('download');await p.locator('#backup-export').click();const download=await downloadEvent;const backup=JSON.parse(fs.readFileSync(await download.path(),'utf8'));
  assert.equal(backup.identity_schema,'relato.block-key.v1');for(const account of Object.values(accounts))assert.ok(!JSON.stringify(backup).includes(account.password));
  const exported=backup.pages.flatMap(p=>p.blocks).map(b=>({key:b.block_key,title:b.title,visible:b.visible,id:b.id,sort_order:b.sort_order}));
  await p.locator('#backup-file').setInputFiles({name:'pr5-valid-isolated.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(backup))});p.once('dialog',d=>d.accept());await p.locator('#backup-restore').click();await p.getByText(/^Restaurado:/).waitFor();
  const restored=query('SELECT id,block_key,title,visible,sort_order FROM page_blocks');
  for(const old of exported){const fresh=restored.find(b=>b.block_key===old.key);assert.ok(fresh);assert.notEqual(fresh.id,old.id);assert.equal(fresh.title,old.title);assert.equal(Boolean(fresh.visible),Boolean(old.visible));assert.equal(fresh.sort_order,old.sort_order);}
  await pub.reload();await pub.getByRole('heading',{name:'PR5 edición aislada de portada',exact:true}).waitFor();record('panel export and restore against MariaDB preserve 39 keys plus custom despite changed IDs');
  await blockEditor('home.hero');assert.equal(await p.locator('[name=title]').inputValue(),'PR5 edición aislada de portada');await p.locator('[name=title]').fill('PR5 edición posterior a restaurar');await saveBlock();assert.equal(query('SELECT title FROM page_blocks WHERE block_key=?',['home.hero'])[0].title,'PR5 edición posterior a restaurar');record('panel can navigate and edit after IDs change on restore');
  await go('backup');const legacy=structuredClone(backup);delete legacy.identity_schema;legacy.pages.forEach(p=>p.blocks.forEach(b=>delete b.block_key));
  const stateBefore=query('SELECT id,block_key,title FROM page_blocks ORDER BY id');await p.locator('#backup-file').setInputFiles({name:'pr5-legacy-isolated.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(legacy))});p.once('dialog',d=>d.accept());await p.locator('#backup-restore').click();await p.locator('#backup-msg [role=alert]').waitFor();assert.match(await p.locator('#backup-msg').innerText(),/no declara|no incluye claves/);assert.deepEqual(query('SELECT id,block_key,title FROM page_blocks ORDER BY id'),stateBefore);record('legacy backup rejection visible in panel and MariaDB unchanged');
  // Crear desde el panel un resumen aprobado; los otros cinco son fixture de borradores.
  await go('posts');await p.locator('#new-post').click();const first=seed.posts[0];
  for(const field of ['title','slug','category','excerpt','body','image_url'])await p.locator('[name='+field+']').fill(first[field]);
  await p.locator('#save').click();await p.locator('#delete').waitFor();assert.equal(query('SELECT COUNT(*) AS count FROM posts WHERE slug=?',[first.slug])[0].count,1);
  await p.locator('[name=title]').fill('PR5 edición no publicada');await p.locator('#save').click();await p.waitForTimeout(100);assert.equal(query('SELECT title FROM posts WHERE slug=?',[first.slug])[0].title,'PR5 edición no publicada');
  await p.locator('[name=title]').fill(first.title);await p.locator('#save').click();await p.waitForTimeout(100);
  const approved=query('SELECT title,slug,category,excerpt,body,image_url,status FROM posts');assert.deepEqual(require('../scripts/validation/verify_approved_posts.cjs').verify(approved),[]);record('panel creation and edit persisted; all six approved summaries match exact text and image URLs');
  await p.locator('[name=status]').selectOption('publicado');await p.locator('#save').click();await p.waitForTimeout(100);assert.equal(query('SELECT status FROM posts WHERE slug=?',[first.slug])[0].status,'publicado');
  await pub.goto(FRONT+'/blog/?articulo='+first.slug);await pub.getByRole('heading',{name:first.title,exact:true}).waitFor();assert.match(await pub.locator('.note').innerText(),/resumen editorial/);record('panel publication reaches anonymous PHP detail without invented article body');
  await p.locator('[name=status]').selectOption('borrador');await p.locator('#save').click();await p.waitForTimeout(100);await pub.reload();await pub.getByRole('heading',{name:'Artículo no encontrado',exact:true}).waitFor();assert.equal((await api('GET','/v1/public/posts/'+first.slug,undefined,false)).status,404);record('panel retirement persists and public PHP detail returns 404');
  await go('media');
  const png=spawnSync(php,[...phpArgs,'-r','$i=imagecreatetruecolor(8,8); imagepng($i);'],{env}).stdout;
  await p.locator('#upload').setInputFiles({name:'PR5-isolated.png',mimeType:'image/png',buffer:png});await p.locator('#upload-btn').click();await p.locator('.media-card img').waitFor();
  const medium=(await api('GET','/v1/media')).data.media[0];assert.match(medium.preview,/^\/v1\/media\/[a-f0-9]{32}\.png\/file$/);
  await p.waitForFunction(()=>{const i=document.querySelector('.media-card img');return i&&i.complete&&i.naturalWidth===8});
  assert.equal((await api('GET',new URL(medium.url).pathname,undefined,false)).status,404);assert.equal((await api('GET',medium.preview,undefined,false)).status,401);assert.equal(query('SELECT COUNT(*) AS count FROM media')[0].count,1);
  record('real multipart upload persists and private preview renders; unpublished public URL unavailable');
  await p.screenshot({path:path.join(output,'media-private-preview.png'),animations:'disabled'});
  await blockEditor(custom.block_key);assert.equal(await p.locator('[name=visible]').inputValue(),'false');await p.locator('[name=visible]').selectOption('true');await p.locator('[name=image_url]').fill(medium.url);await p.waitForFunction(()=>{const i=document.querySelector('#preview img');return i&&i.complete&&i.naturalWidth===8});await saveBlock();
  fixture('sync');await pub.goto(FRONT+'/');const publicImage=pub.locator('img[src="'+medium.url+'"]');await publicImage.scrollIntoViewIfNeeded();await publicImage.evaluate(i=>i.decode());assert.equal(await publicImage.evaluate(i=>i.naturalWidth),8);record('supplied media sync publishes referenced image; anonymous browser renders it');
  await go('media');p.once('dialog',d=>d.accept());await p.locator('[data-delete="'+medium.id+'"]').click();await p.getByText(/La imagen está en uso/).waitFor();assert.equal(query('SELECT COUNT(*) AS count FROM media')[0].count,1);record('panel blocks deletion of referenced media with real 409');
  await logout();await login('reader');await go('media');await p.waitForFunction(()=>{const i=document.querySelector('.media-card img');return i&&i.complete&&i.naturalWidth===8});assert.equal(await p.locator('#upload').count(),0);assert.equal((await api('DELETE','/v1/media/'+medium.id,{})).status,403);assert.equal((await api('GET','/v1/backup')).status,403);record('reader real browser can preview but cannot write or export');
  await logout();await login('editor');assert.equal(await p.locator('[data-nav=users]').count(),0);assert.equal((await api('PUT','/v1/settings',{siteName:'PR5 forbidden'})).status,403);
  await blockEditor('home.hero');await p.locator('[name=description]').fill('Edición de editor en aislamiento.');await saveBlock();assert.equal(query('SELECT description FROM page_blocks WHERE block_key=?',['home.hero'])[0].description,'Edición de editor en aislamiento.');record('editor real browser writes blocks and backend denies settings');
  await logout();await login('admin');await blockEditor(custom.block_key);await p.locator('[name=image_url]').fill('');await saveBlock();fixture('sync');
  await go('media');p.once('dialog',d=>d.accept());await p.locator('[data-delete="'+medium.id+'"]').click();await p.getByText('Imagen eliminada.',{exact:true}).waitFor();assert.equal(query('SELECT COUNT(*) AS count FROM media')[0].count,0);assert.equal((await api('GET',medium.preview)).status,404);assert.ok(!fs.existsSync(path.join(state,'private-media',medium.stored_name)));assert.ok(!fs.existsSync(path.join(apiRoot,'media',medium.stored_name)));record('unlinked media deletes from panel, MariaDB and both filesystems');
  await go('settings');await p.locator('[name=siteName]').fill('PR5 Sitio aislado');await p.locator('#save-settings').click();await p.getByText('Ajustes guardados correctamente.',{exact:true}).waitFor();assert.equal(query('SELECT setting_value FROM site_settings WHERE setting_key=?',['siteName'])[0].setting_value,'PR5 Sitio aislado');assert.equal((await api('GET','/v1/public/settings',undefined,false)).data.settings.siteName,'PR5 Sitio aislado');record('admin settings persist in MariaDB and public PHP whitelist');
  const adminId=query('SELECT id FROM admin_users WHERE role=?',['admin'])[0].id;query('UPDATE admin_sessions SET expires_at = ? WHERE user_id = ?',['2000-01-01 00:00:00',adminId]);await go('pages');await p.locator('#login-form').waitFor();assert.equal((await api('GET','/v1/auth/me')).status,401);record('actual expired MariaDB session removes protected panel');
  await login('admin');
  // Comprobación visual integrada de los flujos afectados; no se repite la suite PHP de 50 pruebas.
  for(const width of [390,1440]){
   await p.setViewportSize({width,height:900});await go('pages');await p.locator('[data-page=home]').waitFor();assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);await p.screenshot({path:path.join(output,'panel-pages-'+width+'.png'),animations:'disabled'});
   await pub.setViewportSize({width,height:900});await pub.goto(FRONT+'/');await pub.getByRole('heading',{name:'PR5 edición posterior a restaurar',exact:true}).waitFor();assert.equal(await pub.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);await pub.screenshot({path:path.join(output,'public-home-'+width+'.png'),animations:'disabled'});
  }record('real PHP public pages and protected panel fit desktop and mobile');
  await logout();record('browser logout real PHP session invalidation');
  assert.deepEqual(errors,[]);record('no browser JavaScript errors');
 }finally{
  if(browser)await browser.close();
  if(phpServer)phpServer.kill('SIGTERM');
  if(setupDone){try{fixture('cleanup');record('isolated database and ephemeral config cleaned');}catch{results.push({name:'cleanup',result:'FAIL'});}}
  if(mysql)mysql.kill('SIGTERM');
  fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({scope:'frontend + supplied PHP + MariaDB 10.11; local transport only; no production API requests',results,passed:results.filter(x=>x.result==='PASS').length,failed:results.filter(x=>x.result==='FAIL').length},null,2)+'\n');
 }
})().catch(e=>{
 results.push({name:'integrated-run',result:'FAIL',type:e.name});
 fs.writeFileSync(path.join(output,'results.json'),JSON.stringify({scope:'isolated PHP/MariaDB integration',results,passed:results.filter(x=>x.result==='PASS').length,failed:1},null,2)+'\n');
 let message=String(e.message);for(const account of Object.values(accounts))message=message.split(account.password).join('[redacted]');console.error(message);process.exitCode=1;
});
