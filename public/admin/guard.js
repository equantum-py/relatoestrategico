/* Guardia de arranque del panel administrativo.
   Si app.js no llega a cargar (por ejemplo, 404 o bloqueo), el placeholder inicial
   nunca se reemplaza. Este archivo se carga por una ruta absoluta propia y muestra
   un mensaje claro en lugar de "Cargando…" indefinido. Sin código inline (CSP). */
(function () {
  var TIMEOUT_MS = 20000; // app.js puede tardar hasta 15 s en /v1/auth/me (AbortSignal)

  function showStartupError() {
    var placeholder = document.querySelector("[data-boot]");
    if (!placeholder) return; // el panel ya arrancó y reemplazó el placeholder
    var root = document.getElementById("app");
    if (!root) return;
    var wrap = document.createElement("div");
    wrap.className = "login-wrap";
    var card = document.createElement("div");
    card.className = "card login-card";
    var alert = document.createElement("div");
    alert.className = "alert error";
    alert.setAttribute("role", "alert");
    alert.textContent = "No se pudo iniciar el panel. Recarga la página; si el problema continúa, avisa a Dirección.";
    card.appendChild(alert);
    wrap.appendChild(card);
    root.replaceChildren(wrap);
    console.error("[admin] El panel no se inicializó dentro del tiempo esperado.");
  }

  window.setTimeout(showStartupError, TIMEOUT_MS);
})();
