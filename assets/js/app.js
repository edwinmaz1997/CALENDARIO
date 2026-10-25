(function () {
  'use strict';

  // Iconos
  if (window.lucide) { window.lucide.createIcons(); }

  // Menu lateral movil
  var menu = document.getElementById('menu');
  var fondo = document.getElementById('menu-fondo');
  function abrir() { if (!menu) return; menu.classList.add('abierto'); fondo.classList.remove('hidden'); document.body.classList.add('menu-abierto'); }
  function cerrar() { if (!menu) return; menu.classList.remove('abierto'); fondo.classList.add('hidden'); document.body.classList.remove('menu-abierto'); }
  document.querySelectorAll('[data-abrir-menu]').forEach(function (b) { b.addEventListener('click', abrir); });
  document.querySelectorAll('[data-cerrar-menu]').forEach(function (b) { b.addEventListener('click', cerrar); });
  if (fondo) fondo.addEventListener('click', cerrar);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') cerrar(); });

  // Confirmaciones en formularios / botones
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (ev.defaultPrevented) return;
    var msg = f.getAttribute('data-confirmar');
    if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
    var btn = f.querySelector('[type=submit]');
    if (btn && !f.hasAttribute('data-sin-bloqueo')) {
      setTimeout(function () { btn.setAttribute('disabled', 'disabled'); }, 0);
    }
  });

  // Desplegables (<details>): abrir el que coincide con el #ancla de la URL
  function abrirPorHash() {
    var id = (window.location.hash || '').slice(1);
    if (!id) return;
    var el = document.getElementById(id);
    if (el && el.tagName === 'DETAILS') {
      el.open = true;
      setTimeout(function () { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
    }
  }
  abrirPorHash();
  window.addEventListener('hashchange', abrirPorHash);
  document.querySelectorAll('[data-abrir]').forEach(function (a) {
    a.addEventListener('click', function () {
      var el = document.getElementById(a.getAttribute('data-abrir'));
      if (el && el.tagName === 'DETAILS') el.open = true;
    });
  });

  // Sin zoom en movil (iOS ignora user-scalable=no)
  document.addEventListener('gesturestart', function (e) { e.preventDefault(); }, { passive: false });
  document.addEventListener('touchmove', function (e) { if (e.touches && e.touches.length > 1) e.preventDefault(); }, { passive: false });
  var ultimoToque = 0;
  document.addEventListener('touchend', function (e) {
    var ahora = Date.now();
    if (ahora - ultimoToque < 300 && !e.target.closest('input, textarea, select, button, a, label')) e.preventDefault();
    ultimoToque = ahora;
  }, { passive: false });

  // Campanita de alertas
  var camp = document.getElementById('campana');
  if (camp) {
    var btnC = camp.querySelector('[data-campana]'), panel = camp.querySelector('[data-campana-panel]');
    btnC.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var abrirC = panel.classList.contains('hidden');
      panel.classList.toggle('hidden', !abrirC);
      btnC.setAttribute('aria-expanded', abrirC ? 'true' : 'false');
    });
    document.addEventListener('click', function (ev) { if (!camp.contains(ev.target)) panel.classList.add('hidden'); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') panel.classList.add('hidden'); });
  }

  // Enlace directo al chat de WhatsApp del cliente: marca la cotizacion como enviada sin bloquear la apertura
  document.querySelectorAll('[data-marcar-enviada]').forEach(function (a) {
    a.addEventListener('click', function () {
      var c = document.querySelector('#envio-cliente input[name=csrf]');
      var fd = new FormData(); fd.append('csrf', c ? c.value : ''); fd.append('accion', 'enviada_ajax');
      var url = window.location.pathname + window.location.search;
      if (!(navigator.sendBeacon && navigator.sendBeacon(url, fd))) {
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {});
      }
      // Al regresar a la app se actualiza el estado
      var alVolver = function () { if (document.visibilityState === 'visible') { document.removeEventListener('visibilitychange', alVolver); window.location.reload(); } };
      document.addEventListener('visibilitychange', alVolver);
      setTimeout(function () { if (document.visibilityState === 'visible') window.location.reload(); }, 2500);
    });
  });

  // Compartir el PDF de la cotizacion (WhatsApp u otra app) y marcarla como enviada
  var btnPdf = document.getElementById('btn-compartir-pdf');
  if (btnPdf) {
    var archivoPdf = null, cargando = null;
    var csrfIn = document.querySelector('#envio-cliente input[name=csrf]');
    function cargarPdf() {
      if (archivoPdf) return Promise.resolve(archivoPdf);
      if (!cargando) {
        cargando = fetch(btnPdf.getAttribute('data-pdf'), { credentials: 'same-origin' })
          .then(function (r) { if (!r.ok) throw new Error('pdf'); return r.blob(); })
          .then(function (b) { archivoPdf = new File([b], btnPdf.getAttribute('data-nombre'), { type: 'application/pdf' }); return archivoPdf; })
          .catch(function (e) { cargando = null; throw e; });
      }
      return cargando;
    }
    function marcarEnviada() {
      var fd = new FormData(); fd.append('csrf', csrfIn ? csrfIn.value : ''); fd.append('accion', 'enviada_ajax');
      return fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd, credentials: 'same-origin' });
    }
    var puedeCompartir = !!(navigator.canShare && navigator.share);
    if (puedeCompartir) cargarPdf().catch(function () {}); // se descarga antes para compartir al instante
    else {
      var ay = document.getElementById('ayuda-compartir');
      if (ay) ay.textContent = 'En computadora se descarga el PDF y se abre el chat de WhatsApp del cliente para que lo adjuntes. Se marca como enviada.';
    }
    btnPdf.addEventListener('click', function () {
      var html = btnPdf.innerHTML;
      btnPdf.disabled = true; btnPdf.textContent = 'Preparando PDF...';
      cargarPdf().then(function (f) {
        var datos = { files: [f], title: f.name, text: btnPdf.getAttribute('data-texto') };
        if (puedeCompartir && navigator.canShare({ files: [f] })) {
          return navigator.share(datos).then(function () {
            return marcarEnviada().then(function () { window.location.reload(); });
          });
        }
        // Sin compartir nativo: descargar y abrir el chat
        var url = URL.createObjectURL(f), a = document.createElement('a');
        a.href = url; a.download = f.name; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
        var wa = btnPdf.getAttribute('data-wa');
        if (wa) window.open(wa, '_blank', 'noopener');
        return marcarEnviada().then(function () { setTimeout(function () { window.location.reload(); }, 800); });
      }).catch(function (e) {
        if (!e || e.name !== 'AbortError') alert('No se pudo preparar el PDF. Revisa tu conexion e intenta de nuevo.');
      }).then(function () {
        btnPdf.disabled = false; btnPdf.innerHTML = html; if (window.lucide) window.lucide.createIcons();
      });
    });
  }

  // Ocultar mensajes flash despues de un tiempo
  setTimeout(function () {
    document.querySelectorAll('.flash').forEach(function (el) {
      el.style.transition = 'opacity .4s'; el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 400);
    });
  }, 6000);
})();
