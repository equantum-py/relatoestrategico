"use strict";
/* Anónimo: nunca envía cookies ni llama rutas administrativas. */
window.RelatoContent = (() => {
  const pageBaselines = {"home":[{"id":1,"sort_order":1,"title":"Hero: Tu experiencia tiene valor","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":2,"sort_order":2,"title":"Soluciones según objetivo","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":3,"sort_order":3,"title":"Cuatro servicios","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":4,"sort_order":4,"title":"Resultados destacados","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":5,"sort_order":5,"title":"Rodrigo Barría","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":6,"sort_order":6,"title":"Noticias, tendencias y tips de LinkedIn","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":7,"sort_order":7,"title":"Contacto y agenda","subtitle":"","description":"","button":"","url":"","image_url":""}],"preguntas":[{"id":26,"sort_order":1,"title":"¿Para quién es la asesoría de marca personal en LinkedIn?","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":27,"sort_order":2,"title":"¿Puedo construir mi marca personal en LinkedIn por mi cuenta?","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":28,"sort_order":3,"title":"¿Cuánto cuestan los servicios?","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":29,"sort_order":4,"title":"¿Cuánto tiempo debo dedicar si trabajan conmigo?","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":30,"sort_order":5,"title":"¿Los contenidos serán genéricos? ¿Sonarán como yo?","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":31,"sort_order":6,"title":"¿En cuánto tiempo se ven resultados?","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":32,"sort_order":7,"title":"¿Garantizan más seguidores en LinkedIn?","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":33,"sort_order":8,"title":"¿Puedo conseguir empleo o negocios gracias a LinkedIn?","subtitle":"","description":"","button":"","url":"","image_url":""}],"servicios":[{"id":11,"sort_order":1,"title":"Perfil Estratégico","subtitle":"","description":"Diagnóstico e informe; optimización completa del perfil con SEO; pilares de contenido y guía de uso de LinkedIn. Pago único.","button":"","url":"","image_url":""},{"id":12,"sort_order":2,"title":"Presencia Constante","subtitle":"","description":"Ocho contenidos mensuales, networking estratégico, gestión de interacciones y métricas. Ciclos renovables de tres meses.","button":"","url":"","image_url":""},{"id":13,"sort_order":3,"title":"LinkedIn Elite","subtitle":"","description":"Ghostwriting ejecutivo (12–16 contenidos), liderazgo de opinión, gestión reputacional, artículos, newsletter y medios. Cupos limitados.","button":"","url":"","image_url":""},{"id":14,"sort_order":4,"title":"Taller Embajadores LinkedIn","subtitle":"","description":"Capacitación para equipos: marca personal, optimización de perfiles, técnicas de crecimiento y ejercicios prácticos.","button":"","url":"","image_url":""}],"nosotros":[{"id":20,"sort_order":1,"title":"Rodrigo Barría, periodista senior y director editorial","subtitle":"","description":"Investiga, escribe y transforma trayectorias profesionales en relatos estratégicos para LinkedIn.","button":"","url":"","image_url":""},{"id":21,"sort_order":2,"title":"Fotografía: /rodrigo.webp","subtitle":"","description":"Fotografía existente en el repositorio y utilizada en Home y Sobre nosotros.","button":"","url":"","image_url":"/rodrigo.webp"},{"id":22,"sort_order":3,"title":"Método RUTA: Revisar","subtitle":"","description":"Diagnóstico de perfil, trayectoria, industria y objetivos.","button":"","url":"","image_url":""},{"id":23,"sort_order":4,"title":"Método RUTA: Unificar","subtitle":"","description":"Ordenar el relato y optimizar el perfil.","button":"","url":"","image_url":""},{"id":24,"sort_order":5,"title":"Método RUTA: Traducir","subtitle":"","description":"Convertir la experiencia en contenidos relevantes.","button":"","url":"","image_url":""},{"id":25,"sort_order":6,"title":"Método RUTA: Activar","subtitle":"","description":"Publicar, medir y ajustar la estrategia.","button":"","url":"","image_url":""}],"soluciones":[{"id":8,"sort_order":1,"title":"Mejorar oportunidades laborales","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":9,"sort_order":2,"title":"Conseguir oportunidades de negocio","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":10,"sort_order":3,"title":"Posicionarme como referente","subtitle":"","description":"","button":"","url":"","image_url":""}],"resultados":[{"id":15,"sort_order":1,"title":"Interacciones","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":16,"sort_order":2,"title":"+2.135 % impresiones","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":17,"sort_order":3,"title":"+24,1 % seguidores","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":18,"sort_order":4,"title":"+57,4 % impresiones anuales","subtitle":"","description":"Indicador mostrado en la web. Validar evidencia y período antes de usarlo comercialmente.","button":"","url":"","image_url":""},{"id":19,"sort_order":5,"title":"Gráficos y evidencias","subtitle":"","description":"","button":"","url":"","image_url":""}],"contacto":[{"id":34,"sort_order":1,"title":"WhatsApp: +56 9 5085 9889","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":35,"sort_order":2,"title":"Correo: contacto@relatoestrategico.cl","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":36,"sort_order":3,"title":"Agendamiento: Google Calendar","subtitle":"","description":"","button":"","url":"https://calendar.app.google/K8EH7bzSrJ4ApRGr5","image_url":""},{"id":37,"sort_order":4,"title":"LinkedIn: Rodrigo Barría Reyes","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":38,"sort_order":5,"title":"Instagram: @relatoestrategico","subtitle":"","description":"","button":"","url":"","image_url":""},{"id":39,"sort_order":6,"title":"Santiago, Chile · Atención remota y presencial","subtitle":"","description":"","button":"","url":"","image_url":""}]};
  const origin = "https://api.relatoestrategico.cl";
  const safeURL = value => {
    if (typeof value !== "string" || !value) return "";
    if (/^\/(?![/\\])[^\s<>"']*$/.test(value)) return value;
    try { const u = new URL(value); return u.protocol === "https:" && !u.username && !u.password ? u.href : ""; } catch { return ""; }
  };
  const escape = value => String(value ?? "").replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
  async function get(path) {
    const response = await fetch(origin + "/v1/public/" + path, {
      credentials: "omit", cache: "no-store", referrerPolicy: "no-referrer", signal: AbortSignal.timeout(8000),
    });
    const data = await response.json();
    if (!response.ok || data.ok !== true) { const error = new Error("Contenido no disponible"); error.status = response.status; throw error; }
    return data;
  }
  function postRows(posts) {
    if (!Array.isArray(posts)) throw new Error("Contrato de artículos inválido");
    return posts.map(p => {
      if (!p || typeof p.slug !== "string" || typeof p.title !== "string" || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(p.slug)) throw new Error("Artículo inválido");
      return [p.slug, p.category || "LinkedIn", p.title, p.excerpt || "", safeURL(p.image_url), p.body || ""];
    });
  }
  function normalizePage(page) {
    if (!page || !Array.isArray(page.blocks)) throw new Error("Contrato de página inválido");
    // Bindings por ID observado, independientes del título y del orden. Un reset que
    // regenere IDs puede recuperar la semilla por título; restauraciones editadas
    // necesitan conservar IDs o actualizar este baseline (ver verificaciones).
    if (Array.isArray(page.disabled_keys)) return page;
    const baseline = pageBaselines[page.key] || [];
    const active = new Set();
    const blocks = page.blocks.map(block => {
      const index = baseline.findIndex(seed => seed.id === block.id);
      const fallback = index < 0 ? baseline.findIndex(seed => seed.title === block.title) : index;
      const seed = baseline[fallback];
      const template_key = seed ? `${page.key}-${fallback}` : null;
      if (template_key) active.add(template_key);
      const content = {};
      for (const field of ["title","subtitle","description","button","url","image_url"]) {
        if (typeof block[field] === "string" && (!seed || block[field] !== seed[field])) content[field] = block[field];
      }
      return {template_key, sort_order:block.sort_order, content};
    });
    const knownOrder = blocks.filter(b=>b.template_key !== null).map(b=>Number(b.template_key.split('-').pop()));
    const order_changed = knownOrder.some((index,i)=>i>0 && index<knownOrder[i-1]);
    return {...page, blocks, order_changed, disabled_keys:baseline.map((_,i)=>`${page.key}-${i}`).filter(k=>!active.has(k))};
  }
  async function posts() {
    const result = []; let offset = 0;
    for (let page = 0; page < 200; page++) {
      const data = await get(`posts?limit=50&offset=${offset}`);
      if (!Array.isArray(data.posts)) throw new Error("Contrato de artículos inválido");
      result.push(...data.posts);
      const limit = Number(data.pagination?.limit || 50);
      if (!data.pagination || data.posts.length < limit || data.posts.length === 0) return result;
      offset += data.posts.length;
    }
    throw new Error("Se excedió el límite de páginas de artículos");
  }
  return {get, posts, normalizePage, safeURL, escape, postRows};
})();
