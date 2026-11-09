"use strict";
/* Anónimo: nunca envía cookies ni llama rutas administrativas. */
window.RelatoContent = (() => {
  const blockCatalog = {"home.hero":{"page":"home","order":0,"seed":{"title":"Hero: Tu experiencia tiene valor","subtitle":"","description":"","button":"","url":"","image_url":""}},"home.solutions":{"page":"home","order":1,"seed":{"title":"Soluciones según objetivo","subtitle":"","description":"","button":"","url":"","image_url":""}},"home.services":{"page":"home","order":2,"seed":{"title":"Cuatro servicios","subtitle":"","description":"","button":"","url":"","image_url":""}},"home.results":{"page":"home","order":3,"seed":{"title":"Resultados destacados","subtitle":"","description":"","button":"","url":"","image_url":""}},"home.about":{"page":"home","order":4,"seed":{"title":"Rodrigo Barría","subtitle":"","description":"","button":"","url":"","image_url":""}},"home.blog":{"page":"home","order":5,"seed":{"title":"Noticias, tendencias y tips de LinkedIn","subtitle":"","description":"","button":"","url":"","image_url":""}},"home.contact":{"page":"home","order":6,"seed":{"title":"Contacto y agenda","subtitle":"","description":"","button":"","url":"","image_url":""}},"preguntas.audience":{"page":"preguntas","order":0,"seed":{"title":"¿Para quién es la asesoría de marca personal en LinkedIn?","subtitle":"","description":"","button":"","url":"","image_url":""}},"preguntas.self-service":{"page":"preguntas","order":1,"seed":{"title":"¿Puedo construir mi marca personal en LinkedIn por mi cuenta?","subtitle":"","description":"","button":"","url":"","image_url":""}},"preguntas.pricing":{"page":"preguntas","order":2,"seed":{"title":"¿Cuánto cuestan los servicios?","subtitle":"","description":"","button":"","url":"","image_url":""}},"preguntas.dedication":{"page":"preguntas","order":3,"seed":{"title":"¿Cuánto tiempo debo dedicar si trabajan conmigo?","subtitle":"","description":"","button":"","url":"","image_url":""}},"preguntas.voice":{"page":"preguntas","order":4,"seed":{"title":"¿Los contenidos serán genéricos? ¿Sonarán como yo?","subtitle":"","description":"","button":"","url":"","image_url":""}},"preguntas.timeframe":{"page":"preguntas","order":5,"seed":{"title":"¿En cuánto tiempo se ven resultados?","subtitle":"","description":"","button":"","url":"","image_url":""}},"preguntas.followers":{"page":"preguntas","order":6,"seed":{"title":"¿Garantizan más seguidores en LinkedIn?","subtitle":"","description":"","button":"","url":"","image_url":""}},"preguntas.opportunities":{"page":"preguntas","order":7,"seed":{"title":"¿Puedo conseguir empleo o negocios gracias a LinkedIn?","subtitle":"","description":"","button":"","url":"","image_url":""}},"servicios.linkedin-start":{"page":"servicios","order":0,"seed":{"title":"Perfil Estratégico","subtitle":"","description":"Diagnóstico e informe; optimización completa del perfil con SEO; pilares de contenido y guía de uso de LinkedIn. Pago único.","button":"","url":"","image_url":""}},"servicios.linkedin-pro":{"page":"servicios","order":1,"seed":{"title":"Presencia Constante","subtitle":"","description":"Ocho contenidos mensuales, networking estratégico, gestión de interacciones y métricas. Ciclos renovables de tres meses.","button":"","url":"","image_url":""}},"servicios.linkedin-elite":{"page":"servicios","order":2,"seed":{"title":"LinkedIn Elite","subtitle":"","description":"Ghostwriting ejecutivo (12–16 contenidos), liderazgo de opinión, gestión reputacional, artículos, newsletter y medios. Cupos limitados.","button":"","url":"","image_url":""}},"servicios.workshop":{"page":"servicios","order":3,"seed":{"title":"Taller Embajadores LinkedIn","subtitle":"","description":"Capacitación para equipos: marca personal, optimización de perfiles, técnicas de crecimiento y ejercicios prácticos.","button":"","url":"","image_url":""}},"nosotros.biography":{"page":"nosotros","order":0,"seed":{"title":"Rodrigo Barría, periodista senior y director editorial","subtitle":"","description":"Investiga, escribe y transforma trayectorias profesionales en relatos estratégicos para LinkedIn.","button":"","url":"","image_url":""}},"nosotros.portrait":{"page":"nosotros","order":1,"seed":{"title":"Fotografía: /rodrigo.webp","subtitle":"","description":"Fotografía existente en el repositorio y utilizada en Home y Sobre nosotros.","button":"","url":"","image_url":"/rodrigo.webp"}},"nosotros.ruta-review":{"page":"nosotros","order":2,"seed":{"title":"Método RUTA: Revisar","subtitle":"","description":"Diagnóstico de perfil, trayectoria, industria y objetivos.","button":"","url":"","image_url":""}},"nosotros.ruta-unify":{"page":"nosotros","order":3,"seed":{"title":"Método RUTA: Unificar","subtitle":"","description":"Ordenar el relato y optimizar el perfil.","button":"","url":"","image_url":""}},"nosotros.ruta-translate":{"page":"nosotros","order":4,"seed":{"title":"Método RUTA: Traducir","subtitle":"","description":"Convertir la experiencia en contenidos relevantes.","button":"","url":"","image_url":""}},"nosotros.ruta-activate":{"page":"nosotros","order":5,"seed":{"title":"Método RUTA: Activar","subtitle":"","description":"Publicar, medir y ajustar la estrategia.","button":"","url":"","image_url":""}},"soluciones.career":{"page":"soluciones","order":0,"seed":{"title":"Mejorar oportunidades laborales","subtitle":"","description":"","button":"","url":"","image_url":""}},"soluciones.business":{"page":"soluciones","order":1,"seed":{"title":"Conseguir oportunidades de negocio","subtitle":"","description":"","button":"","url":"","image_url":""}},"soluciones.authority":{"page":"soluciones","order":2,"seed":{"title":"Posicionarme como referente","subtitle":"","description":"","button":"","url":"","image_url":""}},"resultados.interactions":{"page":"resultados","order":0,"seed":{"title":"Interacciones","subtitle":"","description":"","button":"","url":"","image_url":""}},"resultados.impressions":{"page":"resultados","order":1,"seed":{"title":"+2.135 % impresiones","subtitle":"","description":"","button":"","url":"","image_url":""}},"resultados.followers":{"page":"resultados","order":2,"seed":{"title":"+24,1 % seguidores","subtitle":"","description":"","button":"","url":"","image_url":""}},"resultados.recognition":{"page":"resultados","order":3,"seed":{"title":"+57,4 % impresiones anuales","subtitle":"","description":"Indicador mostrado en la web. Validar evidencia y período antes de usarlo comercialmente.","button":"","url":"","image_url":""}},"resultados.evidence":{"page":"resultados","order":4,"seed":{"title":"Gráficos y evidencias","subtitle":"","description":"","button":"","url":"","image_url":""}},"contacto.whatsapp":{"page":"contacto","order":0,"seed":{"title":"WhatsApp: +56 9 5085 9889","subtitle":"","description":"","button":"","url":"","image_url":""}},"contacto.email":{"page":"contacto","order":1,"seed":{"title":"Correo: contacto@relatoestrategico.cl","subtitle":"","description":"","button":"","url":"","image_url":""}},"contacto.calendar":{"page":"contacto","order":2,"seed":{"title":"Agendamiento: Google Calendar","subtitle":"","description":"","button":"","url":"https://calendar.app.google/K8EH7bzSrJ4ApRGr5","image_url":""}},"contacto.linkedin":{"page":"contacto","order":3,"seed":{"title":"LinkedIn: Rodrigo Barría Reyes","subtitle":"","description":"","button":"","url":"","image_url":""}},"contacto.instagram":{"page":"contacto","order":4,"seed":{"title":"Instagram: @relatoestrategico","subtitle":"","description":"","button":"","url":"","image_url":""}},"contacto.location":{"page":"contacto","order":5,"seed":{"title":"Santiago, Chile · Atención remota y presencial","subtitle":"","description":"","button":"","url":"","image_url":""}}};
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
      const row = [p.slug, p.category || "LinkedIn", p.title, p.excerpt || "", safeURL(p.image_url)];
      if (typeof p.body === "string" && p.body.length) row.push(p.body);
      return row;
    });
  }
  function normalizePage(page) {
    if (!page || page.identity_schema !== "relato.block-key.v1" || !Array.isArray(page.blocks)) {
      throw new Error("Falta el contrato de identidad estable; se conserva el sitio aprobado");
    }
    const baseline = Object.entries(blockCatalog).filter(([,entry])=>entry.page===page.key);
    if (!baseline.length) throw new Error("Página no reconocida");
    const active = new Set();
    const blocks = page.blocks.map(block => {
      const key = block.block_key;
      const entry = blockCatalog[key];
      const custom = typeof key === "string" && key.startsWith(page.key + ".custom.") && /^[a-f0-9]{16}$/.test(key.slice((page.key + ".custom.").length));
      if ((!entry && !custom) || (entry && entry.page !== page.key) || active.has(key)) throw new Error("Identidad de bloque no válida");
      active.add(key);
      const content = {};
      for (const field of ["title","subtitle","description","button","url","image_url"]) {
        if (typeof block[field] !== "string") throw new Error("Contrato de bloque incompleto");
        if (!entry || block[field] !== entry.seed[field]) content[field] = block[field];
      }
      return {block_key:key, sort_order:block.sort_order, content};
    });
    const knownOrder = blocks.filter(b=>blockCatalog[b.block_key]).map(b=>blockCatalog[b.block_key].order);
    const order_changed = knownOrder.some((index,i)=>i>0 && index<knownOrder[i-1]);
    return {...page, blocks, order_changed, disabled_keys:baseline.map(([key])=>key).filter(key=>!active.has(key))};
  }
  // Cambiar a "api" solo después de verificar la carga aprobada y autorizar el corte.
  // Es una decisión de esta versión, no depende de red, cantidad de posts ni caché.
  const publicationSource = "approved-static";
  async function usePublicAPI() { return publicationSource === "api"; }
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
  return {get, posts, normalizePage, usePublicAPI, apiMode:publicationSource === "api", safeURL, escape, postRows};
})();
