"use strict";

/* Panel administrativo de Relato Estratégico.
   Sin script inline (CSP sin unsafe-inline). Todos los permisos se validan en la API;
   aquí solo se ocultan o deshabilitan controles para cada rol. */

/* ---------- Configuración ---------- */
const API = "https://api.relatoestrategico.cl";
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
const MAX_RESTORE_BYTES = 10 * 1024 * 1024;
const ROLE_LABEL = { admin: "Administrador", editor: "Editor", reader: "Lector" };
const STATUS_LABEL = { borrador: "Borrador", publicado: "Publicado" };
const VIEW_TITLES = {
  home: "Inicio", pages: "Páginas", page: "Bloques de la página", block: "Editar bloque",
  posts: "Artículos del blog", post: "Artículo", media: "Multimedia", settings: "Ajustes del sitio",
  users: "Usuarios y roles", backup: "Respaldo y restauración",
};
const SETTING_FIELDS = [
  ["siteName", "Nombre del sitio", "text", ""],
  ["email", "Correo de contacto", "email", ""],
  ["whatsapp", "WhatsApp", "text", "Formato internacional, por ejemplo +56 9 …"],
  ["calendar", "Enlace de agenda", "url", "Debe comenzar con https://"],
  ["linkedin", "Enlace de LinkedIn", "url", "Debe comenzar con https://"],
  ["instagram", "Enlace de Instagram", "url", "Debe comenzar con https://"],
  ["location", "Ubicación", "text", ""],
  ["seoTitle", "Título SEO", "text", ""],
  ["seoDescription", "Descripción SEO", "textarea", ""],
  ["ogImage", "Imagen para compartir", "url", "Dirección https:// completa o ruta interna"],
];

const state = { user: null, csrf: "", view: "home", pageKey: null, blockId: null, postId: null, filters: { q: "", status: "" } };
const app = document.getElementById("app");
const $ = (sel, root = document) => root.querySelector(sel);

/* ---------- Utilidades ---------- */
function esc(value) {
  return String(value ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}
function fmtDate(value) {
  if (!value) return "";
  const [d, t] = String(value).split(" ");
  const [y, m, day] = d.split("-");
  return `${day}/${m}/${y}${t ? " " + t.slice(0, 5) : ""}`;
}
function fmtBytes(n) {
  if (n >= 1048576) return (n / 1048576).toFixed(1) + " MB";
  return Math.max(1, Math.round(n / 1024)) + " KB";
}
/** https completa o ruta interna que no empieza con "//". Coincide con la validación del servidor. */
function isSafeLink(url) {
  return /^https:\/\/[^\s<>"']+$/i.test(url) || /^\/(?![/\\])[A-Za-z0-9/_.-]*$/.test(url);
}
function alertBox(message, kind = "error") {
  if (!message) return "";
  return `<div class="alert ${kind}" role="${kind === "error" ? "alert" : "status"}">${esc(message)}</div>`;
}
function field(label, name, value = "", type = "text", extra = "", hint = "") {
  return `<label class="field"><span>${esc(label)}</span><input name="${esc(name)}" type="${esc(type)}" value="${esc(value)}" ${extra}>${hint ? `<small class="hint">${esc(hint)}</small>` : ""}</label>`;
}
function area(label, name, value = "", extra = "", cls = "", hint = "") {
  return `<label class="field"><span>${esc(label)}</span><textarea name="${esc(name)}" class="${cls}" ${extra}>${esc(value)}</textarea>${hint ? `<small class="hint">${esc(hint)}</small>` : ""}</label>`;
}
function toast(message) {
  const el = document.createElement("div");
  el.className = "toast";
  el.setAttribute("role", "status");
  el.textContent = message;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 3600);
}
function setBusy(button, busy, label) {
  if (!button) return;
  button.disabled = busy;
  if (label) button.textContent = label;
}
function isAdmin() { return state.user?.role === "admin"; }
function canWrite() { return state.user?.role === "admin" || state.user?.role === "editor"; }
function roleOptions(selected) {
  return Object.entries(ROLE_LABEL).map(([v, l]) => `<option value="${v}" ${v === selected ? "selected" : ""}>${l}</option>`).join("");
}
function navItems() {
  const items = [
    ["home", "⌂", "Inicio"],
    ["pages", "▤", "Páginas"],
    ["posts", "✎", "Artículos del blog"],
    ["media", "◫", "Multimedia"],
    ["settings", "⚙", "Ajustes del sitio"],
  ];
  if (isAdmin()) items.push(["users", "◉", "Usuarios y roles"], ["backup", "⟲", "Respaldo"]);
  return items;
}
function activeNav() {
  if (state.view === "page" || state.view === "block") return "pages";
  if (state.view === "post") return "posts";
  return state.view;
}

/* ---------- Comunicación con la API ---------- */
async function api(method, path, body, token) {
  const headers = {};
  let payload;
  if (body instanceof FormData) {
    payload = body;
  } else if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    payload = JSON.stringify(body);
  }
  if (method !== "GET") headers["X-CSRF-Token"] = token ?? state.csrf;

  let res;
  try {
    res = await fetch(API + path, {
      method, headers, credentials: "include", cache: "no-store", referrerPolicy: "no-referrer", body: payload,
    });
  } catch {
    throw new Error("No pudimos conectar con el servidor. Revisa tu conexión e intenta nuevamente.");
  }

  let data = null;
  try { data = await res.json(); } catch { data = null; }

  if (!res.ok || !data || data.ok !== true) {
    const message = data?.error?.message || "Ocurrió un error inesperado. Intenta nuevamente.";
    if (res.status === 401 && state.user) {
      showLogin("Tu sesión se cerró o expiró por inactividad. Inicia sesión nuevamente.");
    }
    const err = new Error(message);
    err.status = res.status;
    throw err;
  }
  return data;
}

/** Peticiones sin sesión (login y recuperación) usan un token CSRF de formulario recién emitido. */
async function anonPost(path, body) {
  const { csrf } = await api("GET", "/v1/auth/csrf");
  return api("POST", path, body, csrf);
}

/** Descarga el respaldo como archivo. Lanza error si la API lo rechaza. */
async function downloadBackup() {
  let res;
  try {
    res = await fetch(API + "/v1/backup", { credentials: "include", cache: "no-store", referrerPolicy: "no-referrer" });
  } catch {
    throw new Error("No pudimos conectar con el servidor para generar el respaldo.");
  }
  if (!res.ok) {
    let message = "No se pudo generar el respaldo.";
    try { message = (await res.json()).error.message; } catch { /* sin detalle */ }
    if (res.status === 401 && state.user) showLogin("Tu sesión expiró. Inicia sesión nuevamente.");
    throw new Error(message);
  }
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `relato-respaldo-${new Date().toISOString().slice(0, 10)}.json`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/* ---------- Acceso ---------- */
function showLogin(message = "", kind = "error") {
  state.user = null;
  state.csrf = "";
  state.view = "login";
  app.innerHTML = `
    <div class="login-wrap">
      <div class="card login-card">
        <span class="badge">ACCESO RESTRINGIDO</span>
        <h1>Relato Estratégico.</h1>
        <p class="muted">Panel de administración del sitio.</p>
        <div id="login-msg">${alertBox(message, kind)}</div>
        <form id="login-form" novalidate>
          <label class="field"><span>Correo electrónico</span>
            <input name="email" type="email" autocomplete="username" maxlength="254" required>
          </label>
          <label class="field"><span>Contraseña</span>
            <input name="password" type="password" autocomplete="current-password" maxlength="200" required>
          </label>
          <button class="btn" id="login-btn" type="submit">Ingresar</button>
        </form>
        <p class="link-row"><a href="#" id="forgot">¿Olvidaste tu contraseña?</a></p>
        <div class="note">Solo personas autorizadas pueden ingresar. Si no tienes acceso, contacta al administrador del sitio.</div>
        <a class="back-link" href="/">← Volver al sitio</a>
      </div>
    </div>`;

  $("#forgot").onclick = (e) => { e.preventDefault(); showForgot(); };
  $("#login-form").onsubmit = async (e) => {
    e.preventDefault();
    const f = e.currentTarget;
    const email = f.email.value.trim();
    const password = f.password.value;
    if (!email || !password) {
      return showLoginMessage("Escribe tu correo y tu contraseña.");
    }
    const btn = $("#login-btn");
    setBusy(btn, true, "Verificando…");
    try {
      const r = await anonPost("/v1/auth/login", { email, password });
      f.password.value = "";
      state.user = r.user;
      state.csrf = r.csrf;
      await renderShell();
    } catch (err) {
      f.password.value = "";
      setBusy(btn, false, "Ingresar");
      showLoginMessage(err.message);
    }
  };
}

function showLoginMessage(message, kind = "error") {
  const box = $("#login-msg");
  if (box) box.innerHTML = alertBox(message, kind);
}

function showForgot() {
  state.view = "forgot";
  app.innerHTML = `
    <div class="login-wrap">
      <div class="card login-card">
        <span class="badge">RECUPERAR ACCESO</span>
        <h1>Nueva contraseña</h1>
        <p class="muted">Escribe el correo de tu cuenta. Si está autorizada, recibirás un enlace para crear una contraseña nueva.</p>
        <div id="forgot-msg"></div>
        <form id="forgot-form" novalidate>
          <label class="field"><span>Correo electrónico</span>
            <input name="email" type="email" autocomplete="username" maxlength="254" required>
          </label>
          <button class="btn" id="forgot-btn" type="submit">Enviar enlace</button>
        </form>
        <a class="back-link" href="#" id="back">← Volver al ingreso</a>
      </div>
    </div>`;
  $("#back").onclick = (e) => { e.preventDefault(); showLogin(); };
  $("#forgot-form").onsubmit = async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const email = form.email.value.trim();
    if (!email) { $("#forgot-msg").innerHTML = alertBox("Escribe tu correo electrónico."); return; }
    const btn = $("#forgot-btn");
    setBusy(btn, true, "Enviando…");
    try {
      const r = await anonPost("/v1/auth/password-reset/request", { email });
      $("#forgot-msg").innerHTML = alertBox(r.message, "info");
      form.classList.add("hidden");
    } catch (err) {
      $("#forgot-msg").innerHTML = alertBox(err.message);
      setBusy(btn, false, "Enviar enlace");
    }
  };
}

function showResetConfirm(token) {
  state.view = "reset";
  app.innerHTML = `
    <div class="login-wrap">
      <div class="card login-card">
        <span class="badge">NUEVA CONTRASEÑA</span>
        <h1>Crea tu contraseña</h1>
        <p class="muted">Mínimo 12 caracteres, con al menos una letra y un número. El enlace solo puede usarse una vez.</p>
        <div id="reset-msg"></div>
        <form id="reset-form" novalidate>
          <label class="field"><span>Nueva contraseña</span>
            <input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="200" required>
          </label>
          <label class="field"><span>Repite la contraseña</span>
            <input name="confirm" type="password" autocomplete="new-password" minlength="12" maxlength="200" required>
          </label>
          <button class="btn" id="reset-btn" type="submit">Guardar contraseña</button>
        </form>
        <a class="back-link" href="#" id="back">← Volver al ingreso</a>
      </div>
    </div>`;
  $("#back").onclick = (e) => { e.preventDefault(); showLogin(); };
  $("#reset-form").onsubmit = async (e) => {
    e.preventDefault();
    const f = e.currentTarget;
    const password = f.password.value;
    if (password.length < 12 || !/[A-Za-z]/.test(password) || !/\d/.test(password)) {
      $("#reset-msg").innerHTML = alertBox("La contraseña debe tener al menos 12 caracteres, con letras y números.");
      return;
    }
    if (password !== f.confirm.value) {
      $("#reset-msg").innerHTML = alertBox("Las contraseñas no coinciden.");
      return;
    }
    const btn = $("#reset-btn");
    setBusy(btn, true, "Guardando…");
    try {
      const r = await anonPost("/v1/auth/password-reset/confirm", { token, password });
      showLogin(r.message, "ok");
    } catch (err) {
      $("#reset-msg").innerHTML = alertBox(err.message);
      setBusy(btn, false, "Guardar contraseña");
    }
  };
}

/* ---------- Estructura del panel ---------- */
async function renderShell() {
  if (!Object.keys(VIEW_TITLES).includes(state.view)) state.view = "home";
  app.innerHTML = `
    <div class="layout" id="layout">
      <aside class="side">
        <div class="brand">Relato<span>Estratégico.</span></div>
        <nav class="nav" aria-label="Secciones">
          ${navItems().map(([id, icon, label]) => `<button data-nav="${id}" class="${activeNav() === id ? "active" : ""}"><span aria-hidden="true">${icon}</span>${esc(label)}</button>`).join("")}
        </nav>
        <div class="side-bottom">
          <div>Sesión iniciada como</div>
          <strong>${esc(state.user.email)}</strong>
          <div>${esc(ROLE_LABEL[state.user.role] || "")}</div>
          <button class="btn secondary" id="logout" type="button">Cerrar sesión</button>
          <a href="/" target="_blank" rel="noopener">↗ Ver web pública</a>
        </div>
      </aside>
      <div class="main">
        <header class="top">
          <div class="actions">
            <button class="btn secondary mobile-toggle" id="toggle" type="button" aria-label="Abrir menú">☰</button>
            <div>
              <h1 id="title">Panel</h1>
              <small>Gestión de contenidos del sitio</small>
            </div>
          </div>
          <span class="pill">● Conectado</span>
        </header>
        <div class="content"><div id="screen"></div></div>
      </div>
    </div>`;

  document.querySelectorAll("[data-nav]").forEach((b) => b.onclick = () => go(b.dataset.nav));
  $("#logout").onclick = logout;
  $("#toggle").onclick = () => $("#layout").classList.toggle("menu-open");
  await show();
}

function go(view, arg = null) {
  state.view = view;
  if (view === "page") state.pageKey = arg;
  if (view === "block") state.blockId = arg;
  if (view === "post") state.postId = arg;
  document.querySelectorAll("[data-nav]").forEach((b) => b.classList.toggle("active", b.dataset.nav === activeNav()));
  $("#layout")?.classList.remove("menu-open");
  show();
}

async function logout() {
  try {
    await api("POST", "/v1/auth/logout", {});
  } catch { /* la sesión igualmente se considera cerrada en el navegador */ }
  showLogin("Cerraste sesión correctamente.", "ok");
}

async function show() {
  const screen = $("#screen");
  if (!screen) return;
  if (state.view === "post") $("#title").textContent = state.postId === "new" ? "Nuevo artículo" : "Editar artículo";
  else $("#title").textContent = VIEW_TITLES[state.view] || "Panel";
  screen.innerHTML = '<p class="muted">Cargando…</p>';
  try {
    if (state.view === "home") await renderHome(screen);
    else if (state.view === "pages") await renderPages(screen);
    else if (state.view === "page") await renderPage(screen);
    else if (state.view === "block") await renderBlockEditor(screen);
    else if (state.view === "posts") await renderPosts(screen);
    else if (state.view === "post") await renderPostEditor(screen);
    else if (state.view === "media") await renderMedia(screen);
    else if (state.view === "settings") await renderSettings(screen);
    else if (state.view === "users" && isAdmin()) await renderUsers(screen);
    else if (state.view === "backup" && isAdmin()) renderBackup(screen);
    else await renderHome(screen);
  } catch (err) {
    screen.innerHTML = alertBox(err.message);
  }
}

/* ---------- Inicio ---------- */
async function renderHome(root) {
  const [{ posts }, { pages }, { media }] = await Promise.all([
    api("GET", "/v1/posts"),
    api("GET", "/v1/pages"),
    api("GET", "/v1/media"),
  ]);
  const published = posts.filter((p) => p.status === "publicado").length;
  const blocks = pages.reduce((n, p) => n + p.blocks.length, 0);
  root.innerHTML = `
    <div class="stats">
      <div class="card stat"><span>Artículos</span><strong>${posts.length}</strong><span>${published} publicados</span></div>
      <div class="card stat"><span>Páginas</span><strong>${pages.length}</strong><span>${blocks} bloques en total</span></div>
      <div class="card stat"><span>Imágenes</span><strong>${media.length}</strong><span>En la biblioteca</span></div>
    </div>
    <div class="section-heading"><h2>Accesos rápidos</h2></div>
    <div class="grid">
      <div class="card"><h2>Páginas y bloques</h2><p>Edita textos, enlaces e imágenes, cambia el orden y oculta bloques sin borrarlos.</p><button class="btn" data-go="pages" type="button">Abrir páginas</button></div>
      <div class="card"><h2>Artículos del blog</h2><p>Crea, revisa y publica artículos. Los cambios se guardan en la base de datos del sitio.</p><button class="btn" data-go="posts" type="button">Abrir artículos</button></div>
      <div class="card"><h2>Multimedia</h2><p>Sube imágenes JPG, PNG o WebP y copia su dirección para usarla en páginas y artículos.</p><button class="btn secondary" data-go="media" type="button">Abrir multimedia</button></div>
      <div class="card"><h2>Ajustes del sitio</h2><p>Datos de contacto, enlaces sociales y textos SEO generales.</p><button class="btn secondary" data-go="settings" type="button">Abrir ajustes</button></div>
    </div>
    ${canWrite() ? "" : '<div class="note" style="margin-top:22px">Tu rol es de lector: puedes consultar la información, pero no modificarla.</div>'}`;
  root.querySelectorAll("[data-go]").forEach((b) => b.onclick = () => go(b.dataset.go));
}

/* ---------- Páginas y bloques ---------- */
async function renderPages(root) {
  const { pages } = await api("GET", "/v1/pages");
  root.innerHTML = `
    <div class="section-heading"><div><h2>Páginas del sitio</h2><p class="muted">Cada página se compone de bloques. Puedes reordenarlos, ocultarlos o editarlos.</p></div></div>
    <div class="grid">
      ${pages.map((p) => {
        const visible = p.blocks.filter((b) => b.visible).length;
        return `<div class="card">
          <h2>${esc(p.label)}</h2>
          <p>${esc(p.description)}</p>
          <p class="muted">${p.blocks.length} bloques · ${visible} visibles</p>
          <button class="btn" data-page="${esc(p.key)}" type="button">Editar bloques</button>
        </div>`;
      }).join("")}
    </div>`;
  root.querySelectorAll("[data-page]").forEach((b) => b.onclick = () => go("page", b.dataset.page));
}

async function renderPage(root) {
  const { page } = await api("GET", "/v1/pages/" + encodeURIComponent(state.pageKey));
  const writable = canWrite();
  const blocks = page.blocks;

  root.innerHTML = `
    <div class="section-heading">
      <div><h2>${esc(page.label)}</h2><p class="muted">${esc(page.description)}</p></div>
      <div class="actions">
        <button class="btn secondary" id="back" type="button">← Volver a páginas</button>
        ${writable ? '<button class="btn" id="add" type="button">+ Nuevo bloque</button>' : ""}
      </div>
    </div>
    ${writable ? "" : '<div class="note">Tu rol es de lector: puedes ver los bloques, pero no modificarlos.</div>'}
    <div id="page-msg"></div>
    <div class="list">
      ${blocks.length ? blocks.map((b, i) => `
        <div class="item ${b.visible ? "" : "muted-block"}">
          <div>
            <strong>${esc(b.title)}</strong>
            <small>Bloque ${i + 1} · <span class="tag ${b.visible ? "" : "off"}">${b.visible ? "Visible" : "Oculto"}</span>${b.url ? " · con enlace" : ""}${b.image_url ? " · con imagen" : ""}</small>
          </div>
          ${writable ? `<div class="actions">
            <button class="btn secondary small" data-move="${b.id}" data-dir="-1" type="button" aria-label="Subir bloque ${i + 1}" ${i === 0 ? "disabled" : ""}>↑</button>
            <button class="btn secondary small" data-move="${b.id}" data-dir="1" type="button" aria-label="Bajar bloque ${i + 1}" ${i === blocks.length - 1 ? "disabled" : ""}>↓</button>
            <button class="btn secondary small" data-toggle="${b.id}" type="button">${b.visible ? "Ocultar" : "Mostrar"}</button>
            <button class="btn secondary small" data-edit="${b.id}" type="button">Editar</button>
            <button class="btn danger small" data-delete="${b.id}" type="button">Eliminar</button>
          </div>` : `<div class="actions"><button class="btn secondary small" data-edit="${b.id}" type="button">Ver</button></div>`}
        </div>`).join("") : '<div class="empty">Esta página no tiene bloques todavía.</div>'}
    </div>`;

  $("#back", root).onclick = () => go("pages");
  const add = $("#add", root);
  if (add) add.onclick = () => go("block", "new");

  const msg = $("#page-msg", root);
  const payloadOf = (b, overrides = {}) => ({
    title: b.title, subtitle: b.subtitle, description: b.description, button: b.button,
    url: b.url, image_url: b.image_url, visible: b.visible, ...overrides,
  });

  root.querySelectorAll("[data-edit]").forEach((b) => b.onclick = () => go("block", b.dataset.edit));

  root.querySelectorAll("[data-move]").forEach((btn) => btn.onclick = async () => {
    const index = blocks.findIndex((b) => String(b.id) === btn.dataset.move);
    const target = index + Number(btn.dataset.dir);
    if (target < 0 || target >= blocks.length) return;
    const ids = blocks.map((b) => b.id);
    [ids[index], ids[target]] = [ids[target], ids[index]];
    try {
      await api("PUT", `/v1/pages/${encodeURIComponent(page.key)}/order`, { ids });
      show();
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
    }
  });

  root.querySelectorAll("[data-toggle]").forEach((btn) => btn.onclick = async () => {
    const block = blocks.find((b) => String(b.id) === btn.dataset.toggle);
    if (!block) return;
    try {
      await api("PUT", `/v1/pages/${encodeURIComponent(page.key)}/blocks/${block.id}`, payloadOf(block, { visible: !block.visible }));
      toast(block.visible ? "Bloque oculto en el sitio." : "Bloque visible en el sitio.");
      show();
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
    }
  });

  root.querySelectorAll("[data-delete]").forEach((btn) => btn.onclick = async () => {
    if (!confirm("¿Eliminar este bloque? Esta acción no se puede deshacer.")) return;
    try {
      await api("DELETE", `/v1/pages/${encodeURIComponent(page.key)}/blocks/${btn.dataset.delete}`);
      toast("Bloque eliminado.");
      show();
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
    }
  });
}

async function renderBlockEditor(root) {
  const isNew = state.blockId === "new";
  const writable = canWrite();
  const { page } = await api("GET", "/v1/pages/" + encodeURIComponent(state.pageKey));
  const block = isNew
    ? { title: "", subtitle: "", description: "", button: "", url: "", image_url: "", visible: true }
    : page.blocks.find((b) => String(b.id) === String(state.blockId));
  if (!block) {
    root.innerHTML = alertBox("El bloque no existe o fue eliminado.");
    return;
  }
  const dis = writable ? "" : "disabled";

  root.innerHTML = `
    <div class="section-heading">
      <h2>${isNew ? "Nuevo bloque" : esc(block.title)}</h2>
      <button class="btn secondary" id="back" type="button">← Volver a ${esc(page.label)}</button>
    </div>
    ${writable ? "" : '<div class="note">Tu rol es de lector: puedes ver el contenido, pero no modificarlo.</div>'}
    <div class="editor">
      <form class="card" id="block-form" novalidate>
        <div id="block-msg"></div>
        ${field("Título", "title", block.title, "text", `maxlength="200" required ${dis}`)}
        ${field("Subtítulo", "subtitle", block.subtitle, "text", `maxlength="300" ${dis}`)}
        ${area("Texto explicativo", "description", block.description, `maxlength="2000" ${dis}`)}
        ${field("Texto del botón", "button", block.button, "text", `maxlength="100" ${dis}`)}
        ${field("Enlace del botón", "url", block.url, "text", `maxlength="500" placeholder="https://… o /ruta-interna" ${dis}`)}
        ${field("Imagen", "image_url", block.image_url, "text", `maxlength="500" placeholder="https://… o /ruta-interna.webp" ${dis}`, "Puedes copiar la dirección desde Multimedia.")}
        <label class="field"><span>Visibilidad</span>
          <select name="visible" ${dis}>
            <option value="true" ${block.visible ? "selected" : ""}>Visible</option>
            <option value="false" ${block.visible ? "" : "selected"}>Oculto</option>
          </select>
        </label>
        ${writable ? `<div class="form-actions"><button class="btn" id="save" type="submit">Guardar bloque</button></div>` : ""}
      </form>
      <aside class="card">
        <h3>Vista previa</h3>
        <div class="preview" id="preview"></div>
        <p class="muted" style="margin-top:12px;font-size:12px">La vista previa muestra el texto tal como se guardará.</p>
      </aside>
    </div>`;

  const form = $("#block-form", root);
  const renderPreview = () => {
    const x = Object.fromEntries(new FormData(form));
    const box = $("#preview", root);
    box.replaceChildren();
    if (x.image_url && isSafeLink(x.image_url)) {
      const img = document.createElement("img");
      img.src = x.image_url;
      img.alt = "";
      img.referrerPolicy = "no-referrer";
      box.appendChild(img);
    }
    const h = document.createElement("h2");
    h.textContent = x.title || "Título del bloque";
    const sub = document.createElement("strong");
    sub.textContent = x.subtitle || "";
    const body = document.createElement("p");
    body.className = "body";
    body.textContent = x.description || "";
    box.append(h, sub, body);
    if (x.button) {
      const tag = document.createElement("span");
      tag.className = "tag";
      tag.textContent = x.button;
      box.appendChild(tag);
    }
  };
  form.addEventListener("input", renderPreview);
  renderPreview();

  $("#back", root).onclick = () => go("page", page.key);
  if (!writable) return;

  form.onsubmit = async (e) => {
    e.preventDefault();
    const x = Object.fromEntries(new FormData(form));
    const payload = {
      title: x.title.trim(), subtitle: x.subtitle.trim(), description: x.description,
      button: x.button.trim(), url: x.url.trim(), image_url: x.image_url.trim(), visible: x.visible === "true",
    };
    const msg = $("#block-msg", root);
    if (payload.title.length < 2) { msg.innerHTML = alertBox("El título debe tener al menos 2 caracteres."); return; }
    if (payload.url && !isSafeLink(payload.url)) { msg.innerHTML = alertBox("El enlace debe ser https:// completo o una ruta que empiece con / (sin // inicial)."); return; }
    if (payload.image_url && !isSafeLink(payload.image_url)) { msg.innerHTML = alertBox("La imagen debe ser https:// completa o una ruta que empiece con /."); return; }
    const btn = $("#save", root);
    setBusy(btn, true, "Guardando…");
    try {
      if (isNew) await api("POST", `/v1/pages/${encodeURIComponent(page.key)}/blocks`, payload);
      else await api("PUT", `/v1/pages/${encodeURIComponent(page.key)}/blocks/${block.id}`, payload);
      toast(isNew ? "Bloque creado." : "Cambios guardados.");
      go("page", page.key);
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
      setBusy(btn, false, "Guardar bloque");
    }
  };
}

/* ---------- Artículos ---------- */
async function renderPosts(root) {
  const q = state.filters.q;
  const st = state.filters.status;
  const params = new URLSearchParams();
  if (q) params.set("q", q);
  if (st) params.set("status", st);
  const { posts } = await api("GET", "/v1/posts" + (params.toString() ? "?" + params : ""));

  root.innerHTML = `
    <div class="section-heading">
      <div><h2>Artículos</h2><p class="muted">Crea, edita y publica artículos del blog.</p></div>
      ${canWrite() ? '<button class="btn" id="new-post" type="button">+ Nuevo artículo</button>' : ""}
    </div>
    <div class="toolbar">
      <input id="search" type="search" placeholder="Buscar por título…" value="${esc(q)}" maxlength="100" aria-label="Buscar artículos">
      <select id="status-filter" aria-label="Filtrar por estado">
        <option value="">Todos los estados</option>
        <option value="borrador" ${st === "borrador" ? "selected" : ""}>Borrador</option>
        <option value="publicado" ${st === "publicado" ? "selected" : ""}>Publicado</option>
      </select>
    </div>
    <div class="list">
      ${posts.length ? posts.map((p) => `
        <div class="item">
          <div>
            <strong>${esc(p.title)}</strong>
            <small>${esc(p.category || "Sin categoría")} · ${esc(fmtDate(p.updated_at))} · <span class="tag ${p.status === "borrador" ? "draft" : ""}">${esc(STATUS_LABEL[p.status] || p.status)}</span></small>
          </div>
          <div class="actions">
            <button class="btn secondary" data-open="${Number(p.id)}" type="button">${canWrite() ? "Editar" : "Ver"}</button>
          </div>
        </div>`).join("") : '<div class="empty">No hay artículos que coincidan con la búsqueda.</div>'}
    </div>`;

  const search = $("#search", root);
  let timer;
  search.oninput = () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      state.filters.q = search.value.trim().slice(0, 100);
      show();
    }, 350);
  };
  $("#status-filter", root).onchange = (e) => {
    state.filters.status = e.target.value;
    show();
  };
  root.querySelectorAll("[data-open]").forEach((b) => b.onclick = () => go("post", b.dataset.open));
  const newBtn = $("#new-post", root);
  if (newBtn) newBtn.onclick = () => go("post", "new");
}

async function renderPostEditor(root) {
  const isNew = state.postId === "new";
  const writable = canWrite();
  let post = { title: "", slug: "", category: "", excerpt: "", body: "", image_url: "", status: "borrador", published_at: null };
  if (!isNew) {
    post = (await api("GET", "/v1/posts/" + encodeURIComponent(state.postId))).post;
  }
  const dis = writable ? "" : "disabled";

  root.innerHTML = `
    <div class="section-heading">
      <h2>${isNew ? "Nuevo artículo" : esc(post.title)}</h2>
      <button class="btn secondary" id="back" type="button">← Volver a artículos</button>
    </div>
    ${writable ? "" : '<div class="note">Tu rol es de lector: puedes ver el contenido, pero no modificarlo.</div>'}
    <div class="editor">
      <form class="card" id="post-form" novalidate>
        <div id="post-msg"></div>
        <label class="field"><span>Título</span>
          <input name="title" maxlength="200" value="${esc(post.title)}" required ${dis}>
        </label>
        <label class="field"><span>Slug (dirección del artículo)</span>
          <input name="slug" maxlength="120" value="${esc(post.slug)}" placeholder="Se genera a partir del título" ${dis}>
          <small class="hint">Solo letras minúsculas, números y guiones.</small>
        </label>
        <div class="grid" style="gap:14px">
          <label class="field"><span>Categoría</span>
            <input name="category" maxlength="100" value="${esc(post.category)}" ${dis}>
          </label>
          <label class="field"><span>Estado</span>
            <select name="status" ${dis}>
              <option value="borrador" ${post.status === "borrador" ? "selected" : ""}>Borrador</option>
              <option value="publicado" ${post.status === "publicado" ? "selected" : ""}>Publicado</option>
            </select>
          </label>
        </div>
        ${area("Extracto", "excerpt", post.excerpt, `maxlength="500" ${dis}`)}
        ${area("Contenido", "body", post.body, `maxlength="50000" ${dis}`, "tall")}
        <label class="field"><span>Imagen de portada</span>
          <input name="image_url" maxlength="500" value="${esc(post.image_url)}" placeholder="https://… o /ruta-interna.webp" ${dis}>
        </label>
        <label class="field" style="display:flex;align-items:center;gap:12px;padding:14px;border:1px solid #d7e3ed;border-radius:10px;margin:12px 0">
          <input type="checkbox" name="featured" value="1" ${post.featured ? "checked" : ""} ${dis} style="width:18px;height:18px">
          <span><strong>Destacar como artículo principal</strong><small class="hint" style="display:block">Aparecerá primero y en tamaño grande en el blog. Solo para artículos publicados; sustituye al destacado anterior.</small></span>
        </label>
        ${writable ? `<div class="form-actions">
          <button class="btn" id="save" type="submit">Guardar artículo</button>
          ${isNew ? "" : '<button class="btn danger" id="delete" type="button">Eliminar</button>'}
        </div>` : ""}
      </form>
      <aside class="card">
        <h3>Vista previa</h3>
        <div class="preview" id="preview"></div>
        <p class="muted" style="margin-top:12px;font-size:12px">La vista previa muestra el texto tal como se guardará.</p>
      </aside>
    </div>`;

  const form = $("#post-form", root);
  const renderPreview = () => {
    const x = Object.fromEntries(new FormData(form));
    const box = $("#preview", root);
    box.replaceChildren();
    if (x.image_url && isSafeLink(x.image_url)) {
      const img = document.createElement("img");
      img.src = x.image_url;
      img.alt = "";
      img.referrerPolicy = "no-referrer";
      box.appendChild(img);
    }
    const cat = document.createElement("span");
    cat.className = "tag";
    cat.textContent = x.category || "Sin categoría";
    const h = document.createElement("h2");
    h.textContent = x.title || "Título del artículo";
    const ex = document.createElement("p");
    ex.textContent = x.excerpt || "";
    const body = document.createElement("div");
    body.className = "body";
    body.textContent = x.body || "";
    box.append(cat, h, ex, body);
  };
  form.addEventListener("input", renderPreview);
  renderPreview();

  $("#back", root).onclick = () => go("posts");
  if (!writable) return;

  form.onsubmit = async (e) => {
    e.preventDefault();
    const x = Object.fromEntries(new FormData(form));
    const payload = {
      title: x.title.trim(), slug: x.slug.trim(), category: x.category.trim(), excerpt: x.excerpt.trim(),
      body: x.body, image_url: x.image_url.trim(), status: x.status, featured: x.featured === "1",
    };
    if (payload.title.length < 3) { $("#post-msg", root).innerHTML = alertBox("El título debe tener al menos 3 caracteres."); return; }
    if (payload.image_url && !isSafeLink(payload.image_url)) {
      $("#post-msg", root).innerHTML = alertBox("La imagen debe ser una dirección https:// completa o una ruta que empiece con /.");
      return;
    }
    const btn = $("#save", root);
    setBusy(btn, true, "Guardando…");
    try {
      const r = isNew
        ? await api("POST", "/v1/posts", payload)
        : await api("PUT", "/v1/posts/" + encodeURIComponent(state.postId), payload);
      state.postId = String(r.post.id);
      toast(isNew ? "Artículo creado." : "Cambios guardados.");
      await show();
    } catch (err) {
      $("#post-msg", root).innerHTML = alertBox(err.message);
      setBusy(btn, false, "Guardar artículo");
    }
  };

  const del = $("#delete", root);
  if (del) {
    del.onclick = async () => {
      if (!confirm("¿Eliminar este artículo? Esta acción no se puede deshacer.")) return;
      setBusy(del, true, "Eliminando…");
      try {
        await api("DELETE", "/v1/posts/" + encodeURIComponent(state.postId));
        toast("Artículo eliminado.");
        go("posts");
      } catch (err) {
        $("#post-msg", root).innerHTML = alertBox(err.message);
        setBusy(del, false, "Eliminar");
      }
    };
  }
}

/* ---------- Multimedia ---------- */
async function renderMedia(root) {
  const { media } = await api("GET", "/v1/media");
  const writable = canWrite();

  root.innerHTML = `
    <div class="section-heading"><div><h2>Biblioteca multimedia</h2><p class="muted">Imágenes JPG, PNG o WebP de hasta 5 MB. Se guardan en el servidor del sitio.</p></div></div>
    ${writable ? `<div class="card" style="margin-bottom:18px">
      <h3>Subir imagen</h3>
      <div id="media-msg"></div>
      <form id="upload-form" class="toolbar" novalidate>
        <input id="upload" type="file" accept="image/jpeg,image/png,image/webp" aria-label="Seleccionar imagen">
        <button class="btn" id="upload-btn" type="submit" disabled>Subir imagen</button>
      </form>
      <p class="muted" style="font-size:12px">Las imágenes se re-codifican al subirlas: se eliminan metadatos y cualquier contenido añadido al archivo.</p>
    </div>` : '<div class="note">Tu rol es de lector: puedes ver y copiar las direcciones de las imágenes.</div>'}
    <div class="media-grid">
      ${media.length ? media.map((m) => `
        <div class="media-card">
          <img src="${esc(m.url)}" alt="${esc(m.original_name)}" loading="lazy" referrerpolicy="no-referrer">
          <div class="meta">
            <strong>${esc(m.original_name)}</strong>
            <span class="muted">${m.width}×${m.height} · ${esc(fmtBytes(m.size_bytes))}</span>
            <div class="actions">
              <button class="btn secondary small" data-copy="${esc(m.url)}" type="button">Copiar dirección</button>
              ${writable ? `<button class="btn danger small" data-delete="${Number(m.id)}" type="button">Eliminar</button>` : ""}
            </div>
          </div>
        </div>`).join("") : '<div class="empty" style="grid-column:1/-1">Todavía no hay imágenes en la biblioteca.</div>'}
    </div>`;

  if (writable) {
    const input = $("#upload", root);
    const btn = $("#upload-btn", root);
    input.onchange = () => { btn.disabled = !input.files?.length; };
    $("#upload-form", root).onsubmit = async (e) => {
      e.preventDefault();
      const file = input.files?.[0];
      if (!file) return;
      if (file.size > MAX_UPLOAD_BYTES) {
        $("#media-msg", root).innerHTML = alertBox("La imagen supera 5 MB. Reduce su tamaño e intenta nuevamente.");
        return;
      }
      const fd = new FormData();
      fd.append("file", file, file.name);
      setBusy(btn, true, "Subiendo…");
      try {
        await api("POST", "/v1/media", fd);
        toast("Imagen subida.");
        show();
      } catch (err) {
        $("#media-msg", root).innerHTML = alertBox(err.message);
        setBusy(btn, false, "Subir imagen");
      }
    };
  }

  root.querySelectorAll("[data-copy]").forEach((b) => b.onclick = () => {
    navigator.clipboard?.writeText(b.dataset.copy).then(
      () => toast("Dirección copiada."),
      () => toast("No se pudo copiar. Selecciona la dirección manualmente.")
    );
  });
  root.querySelectorAll("[data-delete]").forEach((b) => b.onclick = async () => {
    if (!confirm("¿Eliminar esta imagen? Si está en uso, el sistema lo impedirá.")) return;
    try {
      await api("DELETE", "/v1/media/" + Number(b.dataset.delete));
      toast("Imagen eliminada.");
      show();
    } catch (err) {
      $("#screen").insertAdjacentHTML("afterbegin", alertBox(err.message));
    }
  });
}

/* ---------- Ajustes ---------- */
async function renderSettings(root) {
  const { settings } = await api("GET", "/v1/settings");
  const editable = isAdmin();
  const dis = editable ? "" : "disabled";

  root.innerHTML = `
    <div class="section-heading"><div><h2>Ajustes generales</h2><p class="muted">Datos de contacto y textos generales que usa el sitio.</p></div></div>
    ${editable ? "" : '<div class="note">Solo el administrador puede modificar los ajustes. Puedes consultarlos aquí.</div>'}
    <form class="card" id="settings-form" novalidate style="max-width:820px">
      <div id="settings-msg"></div>
      ${SETTING_FIELDS.map(([key, label, type, hint]) => type === "textarea"
        ? area(label, key, settings[key] ?? "", `maxlength="300" ${dis}`, "", hint)
        : field(label, key, settings[key] ?? "", type, `maxlength="300" ${dis}`, hint)).join("")}
      ${editable ? '<div class="form-actions"><button class="btn" id="save-settings" type="submit">Guardar ajustes</button></div>' : ""}
    </form>`;

  if (!editable) return;

  $("#settings-form", root).onsubmit = async (e) => {
    e.preventDefault();
    const x = Object.fromEntries(new FormData(e.currentTarget));
    const payload = {};
    SETTING_FIELDS.forEach(([key]) => { payload[key] = String(x[key] ?? "").trim(); });
    const btn = $("#save-settings", root);
    setBusy(btn, true, "Guardando…");
    try {
      await api("PUT", "/v1/settings", payload);
      $("#settings-msg", root).innerHTML = alertBox("Ajustes guardados correctamente.", "ok");
    } catch (err) {
      $("#settings-msg", root).innerHTML = alertBox(err.message);
    } finally {
      setBusy(btn, false, "Guardar ajustes");
    }
  };
}

/* ---------- Usuarios y roles (solo administrador) ---------- */
function userRow(u) {
  const self = u.id === state.user.id;
  return `<div class="item">
    <div>
      <strong>${esc(u.email)}${self ? " (tú)" : ""}</strong>
      <small>${u.active ? "Activo" : "Desactivado"} · Último ingreso: ${esc(fmtDate(u.last_login_at)) || "nunca"}</small>
    </div>
    <div class="actions">
      <select data-role="${u.id}" aria-label="Rol de ${esc(u.email)}" ${self ? "disabled" : ""}>${roleOptions(u.role)}</select>
      <button class="btn secondary small" data-active="${u.id}" type="button" ${self ? "disabled" : ""}>${u.active ? "Desactivar" : "Activar"}</button>
      <button class="btn secondary small" data-invite="${u.id}" type="button" ${u.active ? "" : "disabled"}>Enviar enlace</button>
      <button class="btn danger small" data-delete="${u.id}" type="button" ${self ? "disabled" : ""}>Eliminar</button>
    </div>
  </div>`;
}

async function renderUsers(root) {
  const { users } = await api("GET", "/v1/users");
  root.innerHTML = `
    <div class="section-heading"><div><h2>Usuarios y roles</h2><p class="muted">Cada cuenta nueva recibe un enlace por correo para crear su contraseña. Los permisos se validan en el servidor.</p></div></div>
    <div class="card" style="margin-bottom:18px">
      <h3>Invitar usuario</h3>
      <div id="user-msg"></div>
      <form id="user-form" class="toolbar" novalidate>
        <input name="email" type="email" maxlength="254" placeholder="correo@dominio.cl" required aria-label="Correo del nuevo usuario">
        <select name="role" aria-label="Rol del nuevo usuario">${roleOptions("reader")}</select>
        <button class="btn" id="user-save" type="submit">Enviar invitación</button>
      </form>
    </div>
    <div class="list">${users.map(userRow).join("")}</div>`;

  const msg = $("#user-msg", root);
  $("#user-form", root).onsubmit = async (e) => {
    e.preventDefault();
    const x = Object.fromEntries(new FormData(e.currentTarget));
    const btn = $("#user-save", root);
    setBusy(btn, true, "Enviando…");
    try {
      const r = await api("POST", "/v1/users", { email: x.email.trim(), role: x.role });
      toast(r.invitation_sent ? "Invitación enviada." : "Usuario creado, pero el enlace no se envió: se alcanzó el límite. Usa «Enviar enlace» más tarde.");
      show();
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
      setBusy(btn, false, "Enviar invitación");
    }
  };

  const findUser = (id) => users.find((u) => String(u.id) === String(id));

  root.querySelectorAll("[data-role]").forEach((sel) => sel.onchange = async () => {
    try {
      await api("PUT", "/v1/users/" + Number(sel.dataset.role), { role: sel.value });
      toast("Rol actualizado. Las sesiones de esa cuenta se cerraron.");
      show();
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
      show();
    }
  });

  root.querySelectorAll("[data-active]").forEach((btn) => btn.onclick = async () => {
    const u = findUser(btn.dataset.active);
    if (!u) return;
    try {
      await api("PUT", "/v1/users/" + u.id, { active: !u.active });
      toast(u.active ? "Cuenta desactivada." : "Cuenta activada.");
      show();
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
    }
  });

  root.querySelectorAll("[data-invite]").forEach((btn) => btn.onclick = async () => {
    try {
      await api("POST", "/v1/users/" + Number(btn.dataset.invite) + "/reset");
      toast("Enlace enviado al correo de la cuenta.");
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
    }
  });

  root.querySelectorAll("[data-delete]").forEach((btn) => btn.onclick = async () => {
    const u = findUser(btn.dataset.delete);
    if (!u || !confirm(`¿Eliminar la cuenta ${u.email}? Se cerrarán sus sesiones y no podrá volver a entrar.`)) return;
    try {
      await api("DELETE", "/v1/users/" + u.id);
      toast("Cuenta eliminada.");
      show();
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
    }
  });
}

/* ---------- Respaldo y restauración (solo administrador) ---------- */
function renderBackup(root) {
  root.innerHTML = `
    <div class="section-heading"><div><h2>Respaldo y restauración</h2><p class="muted">Copia de páginas, bloques, ajustes y artículos, con la lista de imágenes.</p></div></div>
    <div id="backup-msg"></div>
    <div class="grid">
      <div class="card">
        <h2>Descargar respaldo</h2>
        <p>Genera un archivo JSON con páginas, bloques, ajustes y artículos. No incluye contraseñas, sesiones ni registros de auditoría.</p>
        <button class="btn" id="backup-export" type="button">Descargar respaldo JSON</button>
      </div>
      <div class="card">
        <h2>Restaurar respaldo</h2>
        <p>Reemplaza páginas, bloques, ajustes y artículos por los del archivo. Antes de restaurar se descarga automáticamente una copia del estado actual.</p>
        <input type="file" id="backup-file" accept="application/json,.json" aria-label="Archivo de respaldo">
        <div class="form-actions"><button class="btn danger" id="backup-restore" type="button" disabled>Restaurar desde archivo</button></div>
      </div>
      <div class="card">
        <h2>Restablecer valores iniciales</h2>
        <p>Vuelve páginas, bloques y ajustes a su versión inicial. Los artículos y las imágenes no se modifican.</p>
        <button class="btn danger" id="backup-reset" type="button">Restablecer páginas y ajustes</button>
      </div>
      <div class="card">
        <h2>Imágenes</h2>
        <p>Los archivos de imagen no viajan dentro del JSON. Al restaurar, solo se recuperan las imágenes que siguen en el servidor con el mismo contenido.</p>
      </div>
    </div>`;

  const msg = $("#backup-msg", root);
  const file = $("#backup-file", root);
  const restoreBtn = $("#backup-restore", root);
  file.onchange = () => { restoreBtn.disabled = !file.files?.length; };

  $("#backup-export", root).onclick = async (e) => {
    setBusy(e.currentTarget, true, "Generando…");
    try {
      await downloadBackup();
      toast("Respaldo descargado.");
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
    } finally {
      setBusy(e.currentTarget, false, "Descargar respaldo JSON");
    }
  };

  restoreBtn.onclick = async () => {
    const f = file.files?.[0];
    if (!f) return;
    if (f.size > MAX_RESTORE_BYTES) { msg.innerHTML = alertBox("El archivo supera 10 MB."); return; }
    let parsed;
    try { parsed = JSON.parse(await f.text()); } catch { msg.innerHTML = alertBox("El archivo no es un JSON válido."); return; }
    if (!confirm("¿Reemplazar páginas, bloques, ajustes y artículos actuales por los del archivo? Antes se descargará una copia del estado actual.")) return;
    setBusy(restoreBtn, true, "Restaurando…");
    try {
      await downloadBackup();
      const r = await api("POST", "/v1/backup/restore", { confirm: "RESTAURAR", backup: parsed });
      const c = r.restored;
      toast(`Restaurado: ${c.pages} páginas, ${c.posts} artículos, ${c.media_restored} imágenes (${c.media_skipped} no encontradas).`);
      show();
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
      setBusy(restoreBtn, false, "Restaurar desde archivo");
    }
  };

  $("#backup-reset", root).onclick = async () => {
    const typed = prompt("Para restablecer páginas y ajustes, escribe RESTABLECER.");
    if (typed !== "RESTABLECER") return;
    try {
      await api("POST", "/v1/backup/reset", { confirm: "RESTABLECER" });
      toast("Páginas y ajustes restablecidos.");
      show();
    } catch (err) {
      msg.innerHTML = alertBox(err.message);
    }
  };
}

/* ---------- Arranque ---------- */
async function boot() {
  const params = new URLSearchParams(location.search);
  const resetToken = params.get("reset");
  if (resetToken !== null) {
    history.replaceState(null, "", location.pathname);
    if (/^[a-f0-9]{64}$/.test(resetToken)) {
      showResetConfirm(resetToken);
      return;
    }
    showLogin("El enlace de recuperación no es válido. Solicita uno nuevo.");
    return;
  }
  try {
    const me = await api("GET", "/v1/auth/me");
    state.user = me.user;
    state.csrf = me.csrf;
    await renderShell();
  } catch {
    showLogin();
  }
}
boot();
