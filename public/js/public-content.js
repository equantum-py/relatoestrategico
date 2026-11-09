"use strict";
(async () => {
  const C = window.RelatoContent;
  if (!C) return;
  const $ = (s, n = document) => n.querySelector(s);
  const all = (s, n = document) => [...n.querySelectorAll(s)];
  const bindings = new Map();
  function bind(key, node, fields = {}) {
    if (!node) return;
    bindings.set(key, {node, fields});
  }
  function group(key, selector, offset = 0) {
    all(selector).forEach((n, i) => bind(`${key}-${i + offset}`, n));
  }
  bind("home-0", $(".intro"));
  ["soluciones", "servicios", "resultados", "nosotros", "blog", "contacto"].forEach((id, i) => bind(`home-${i + 1}`, $("#" + id)));
  group("servicios", ".home-service-card");
  group("preguntas", ".faq-list details");
  group("resultados", ".result-proof, .published-slide");
  bind("resultados-4", $(".results-footer"));
  const bio = $("#nosotros .rodrigo-new > div, #rodrigo .rodrigo-new > div");
  bind("nosotros-0", bio);
  bind("nosotros-1", $(".rodrigo-photo"), {image_url: "img", title: false, description: "figcaption"});
  group("nosotros", ".ruta-grid > article, .method-flow > .method-item", 2);
  // Soluciones conserva el diseño de dos columnas; cada unidad es un encabezado y su texto.
  all(".problem-layout h3").slice(0, 3).forEach((h, i) => {
    const wrap = document.createElement("div");
    h.before(wrap); wrap.append(h); if (wrap.nextElementSibling?.tagName === "P") wrap.append(wrap.nextElementSibling);
    bind(`soluciones-${i}`, wrap);
  });
  // Datos de contacto usan nodos propios para no alterar todos los enlaces de una sección.
  const contactNodes = all(".contact-methods > div");
  const contacts = [[1, 0, 'a[href*="wa.me/"]'], [0, 1, 'a[href^="mailto:"]'], [2, 3, 'a[href*="linkedin.com/in/"]'], [3, 4, 'a[href*="instagram.com/"]']];
  for (const [index, slot, selector] of contacts) {
    const node = contactNodes[index] || $("main " + selector)?.closest("p");
    bind(`contacto-${slot}`, node, {title: "a", description: false, url: "a", button: "a"});
  }
  bind("contacto-5", contactNodes[4] || $(".contact-card:last-child > p:last-child"), {title: contactNodes[4] ? "span:last-child" : ":self", description: false});
  // Agenda no está entre los cinco datos de Home: enlaza su CTA sin reescribir el contenedor.
  const agenda = $("#contacto .contact-ctas a, .contact-actions a");
  bind("contacto-2", agenda, {title: false, description: false, button: ":self", url: ":self"});

  function resolve(node, selector) { return selector === ":self" ? node : selector === false ? null : $(selector, node); }
  function patch(binding, content) {
    const {node, fields} = binding;
    const selectors = {title:"h1,h2,h3,summary",subtitle:".kicker,.eyebrow,.tag",description:"p",button:"a.button,a.text-link,a:last-of-type",url:"a.button,a.text-link,a:last-of-type",image_url:"img", ...fields};
    for (const [name, value] of Object.entries(content)) {
      if (!Object.hasOwn(selectors, name) || typeof value !== "string") continue;
      let target = resolve(node, selectors[name]);
      if (!target && value && !["url","image_url"].includes(name) && selectors[name] !== false) {
        target = document.createElement(name === "button" ? "a" : "p");
        node.append(target);
      }
      if (!target && name === "image_url" && C.safeURL(value)) {
        target = document.createElement("img"); target.alt = ""; target.loading = "lazy"; target.style.maxWidth = "100%"; node.prepend(target);
      }
      if (!target) continue;
      if (name === "url" || name === "image_url") {
        const safe = C.safeURL(value);
        if (value && !safe) continue;
        if (name === "image_url") { if (safe) target.src = safe; else target.removeAttribute("src"); }
        else if (safe) target.href = safe; else target.removeAttribute("href");
      } else target.textContent = value;
    }
  }
  function applyPage(page) {
    if (!page || !Array.isArray(page.blocks) || !Array.isArray(page.disabled_keys)) throw new Error("Contrato de página inválido");
    for (const key of page.disabled_keys) {
      const target = bindings.get(key)?.node;
      // hidden gana a estilos flex/grid declarados por el diseño.
      if (target) { target.hidden = true; target.style.setProperty("display", "none", "important"); }
    }
    const groups = new Map();
    for (const block of page.blocks) {
      let binding = bindings.get(block.template_key);
      if (!binding && block.template_key === null) {
        const node = document.createElement("article"); node.className = "card";
        const container = $("main .section-inner, main");
        if (!container) continue;
        container.append(node); binding = {node, fields:{}};
      }
      if (!binding) continue;
      patch(binding, block.content || {});
      const parent = binding.node.parentElement;
      if (!groups.has(parent)) groups.set(parent, []);
      groups.get(parent).push(binding.node);
    }
    // Reordenar solo hermanos administrados: conserva títulos, controles y estructura.
    for (const [parent, ordered] of groups) {
      if (page.order_changed === false) continue; // La semilla no reordena el diseño aprobado.
      if (ordered.length < 2) continue;
      const nodes = new Set(ordered), slots = [...parent.children].filter(n => nodes.has(n));
      const markers = slots.map(n => { const marker = document.createComment("content-slot"); n.replaceWith(marker); return marker; });
      ordered.forEach((n, i) => markers[i].replaceWith(n));
    }
  }
  function applySettings(settings) {
    if (!settings || typeof settings !== "object") return;
    const links = [
      ["calendar", 'a[href*="calendar.app.google"]'], ["linkedin", 'a[href*="linkedin.com/in/"]'], ["instagram", 'a[href*="instagram.com/"]'],
    ];
    for (const [key, selector] of links) {
      const url = C.safeURL(settings[key]);
      if (url) all(selector).forEach(a => a.href = url);
    }
    if (/^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/.test(settings.email || "")) all('a[href^="mailto:"]').forEach(a => {a.href = "mailto:" + settings.email; a.textContent = settings.email;});
    const phone = String(settings.whatsapp || "").replace(/\D/g, "");
    if (/^\d{8,15}$/.test(phone)) all('a[href*="wa.me/"]').forEach(a => {
      const url = new URL(a.href); url.pathname = "/" + phone; a.href = url.href;
      if (/\d/.test(a.textContent)) a.textContent = settings.whatsapp;
    });
    for (const [key, selector] of [["seoDescription",'meta[name="description"]'],["ogImage",'meta[property="og:image"]']]) {
      const value = settings[key];
      if (!value || (key === "ogImage" && !C.safeURL(value))) continue;
      let meta = $(selector);
      if (!meta) { meta = document.createElement("meta"); meta.setAttribute(key === "ogImage" ? "property" : "name", key === "ogImage" ? "og:image" : "description"); document.head.append(meta); }
      meta.content = value;
    }
    if (settings.seoTitle && location.pathname === "/") document.title = settings.seoTitle;
    if (settings.siteName) all(".site-footer-home").forEach(n=>n.textContent = settings.siteName);
    if (settings.location) all(".site-footer-column > span, .contact-methods > div:last-child > span:last-child, .contact-card:last-child > p:last-child").forEach(n=>n.textContent=settings.location);
  }
  async function homePosts() {
    const grid = $(".editorial-grid"); if (!grid) return;
    const posts = await C.posts();
    const rows = C.postRows(posts).slice(0, 3);
    const templates = all(".editorial-card", grid);
    const fragment = document.createDocumentFragment();
    for (const [i,p] of rows.entries()) {
      const a = templates[Math.min(i, templates.length - 1)]?.cloneNode(true) || document.createElement("a");
      a.href = "/blog/?articulo=" + encodeURIComponent(p[0]); a.setAttribute("aria-label", "Leer artículo: " + p[2]);
      const image = $("img", a); if (image) { if (p[4]) image.src = p[4]; else image.remove(); }
      const set = (q, value) => { const node = $(q,a); if (node) node.textContent = value; };
      set("h3",p[2]); set("p",p[3]); set(".editorial-tag",p[1]); fragment.append(a);
    }
    if (!rows.length) { const p = document.createElement("p"); p.textContent = "Próximamente nuevos artículos."; fragment.append(p); }
    grid.replaceChildren(fragment);
  }
  const path = location.pathname.replace(/\/+$/, "") || "/";
  const pageKeys = path === "/" || path === "/index.html" ? ["home","servicios","resultados","nosotros","preguntas","contacto"] : {
    "/servicios":["servicios","nosotros"],"/resultados":["resultados"],"/sobre-nosotros":["nosotros"],"/como-trabajamos":["nosotros"],"/soluciones":["soluciones"],"/preguntas":["preguntas"],"/contacto":["contacto"],
  }[path] || [];
  // Un fallo conserva el HTML aprobado, sin copiar respuestas privadas al sitio.
  await Promise.allSettled([
    C.get("settings").then(r=>applySettings(r.settings)),
    ...pageKeys.map(key=>C.get("pages/" + key).then(r=>applyPage(C.normalizePage(r.page)))), homePosts(),
  ]);
})();
