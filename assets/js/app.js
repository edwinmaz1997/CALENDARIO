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
    if (!el) return;
    var abrio = false;
    for (var d = el; d; d = d.parentElement) { if (d.tagName === 'DETAILS' && !d.open) { d.open = true; abrio = true; } }
    if (abrio || el.tagName === 'DETAILS') setTimeout(function () { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
  }
  // En escritorio los apartados marcados inician abiertos
  if (window.matchMedia && window.matchMedia('(min-width: 1024px)').matches) {
    document.querySelectorAll('details[data-abierto-pc]').forEach(function (d) { d.open = true; });
  }
  // Al abrir la conversacion, bajar al ultimo mensaje
  document.querySelectorAll('details').forEach(function (d) {
    d.addEventListener('toggle', function () { if (!d.open) return; var l = d.querySelector('#chat-lista'); if (l) l.scrollTop = l.scrollHeight; });
  });
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

  // Recordatorio a cada proveedor por chat de WhatsApp: registra el envio sin bloquear la apertura
  document.querySelectorAll('[data-wa-proveedor]').forEach(function (a) {
    a.addEventListener('click', function () {
      var c = document.querySelector('input[name=csrf]');
      var fd = new FormData(); fd.append('csrf', c ? c.value : ''); fd.append('accion', 'prov_chat_ajax');
      fd.append('proveedor_id', a.getAttribute('data-wa-proveedor'));
      var url = window.location.pathname + window.location.search;
      if (!(navigator.sendBeacon && navigator.sendBeacon(url, fd))) {
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(function () {});
      }
      var li = a.closest('[data-prov-rec]'), est = li && li.querySelector('[data-estado]');
      if (est) { est.textContent = 'Enviado'; est.className = 'shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700'; }
      a.lastChild.textContent = 'Reenviar por WhatsApp';
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
    var ay = document.getElementById('ayuda-compartir');
    if (puedeCompartir) {
      cargarPdf().catch(function () {}); // se descarga antes para compartir al instante
      if (ay) { ay.textContent = 'Se abre el menu de compartir con el PDF y el mensaje: elige WhatsApp y luego el chat de ' + (ay.getAttribute('data-cliente') || 'tu cliente') + '.'; ay.classList.remove('hidden'); }
    } else if (ay) {
      ay.textContent = 'Se descarga el PDF y se abre el chat de WhatsApp del cliente con el mensaje; solo adjunta el archivo descargado.';
      ay.classList.remove('hidden');
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

// Conservar la posicion del menu lateral y mostrar la pestana activa (Mi empresa)
(function () {
  var nav = document.getElementById('menu-nav');
  if (nav) {
    try {
      var y = sessionStorage.getItem('menuScroll');
      if (y !== null) nav.scrollTop = +y;
    } catch (e) {}
    var activo = nav.querySelector('.nav-on');
    if (activo) {
      var r = activo.getBoundingClientRect(), rn = nav.getBoundingClientRect();
      if (r.top < rn.top || r.bottom > rn.bottom) activo.scrollIntoView({ block: 'center' });
    }
    var guardar = function () { try { sessionStorage.setItem('menuScroll', nav.scrollTop); } catch (e) {} };
    nav.addEventListener('scroll', guardar, { passive: true });
    nav.addEventListener('click', guardar);
  }
  var tabs = document.querySelector('[data-tabs-scroll]');
  var on = tabs && tabs.querySelector('[data-tab-on]');
  if (on) {
    try { var x = sessionStorage.getItem('tabsScroll'); if (x !== null) tabs.scrollLeft = +x; } catch (e) {}
    var a = on.getBoundingClientRect(), t = tabs.getBoundingClientRect();
    if (a.left < t.left || a.right > t.right) tabs.scrollLeft += a.left - t.left - (t.width - a.width) / 2;
    tabs.addEventListener('click', function () { try { sessionStorage.setItem('tabsScroll', tabs.scrollLeft); } catch (e) {} });
  }
})();

// Copiar al portapapeles
document.addEventListener('click', function (ev) {
  var b = ev.target.closest('[data-copiar]'); if (!b) return;
  var t = b.getAttribute('data-copiar'), orig = b.textContent;
  var listo = function () { b.textContent = 'Copiado'; setTimeout(function () { b.textContent = orig; }, 1600); };
  if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(listo, function () { window.prompt('Copia el enlace:', t); }); }
  else { window.prompt('Copia el enlace:', t); }
});

// Conversacion con el cliente (vista de la cotizacion)
(function () {
  var sec = document.querySelector('[data-chat-poll]'); if (!sec) return;
  var lista = sec.querySelector('#chat-lista'), form = sec.querySelector('#chat-form'), texto = sec.querySelector('#chat-texto');
  var ultimo = +sec.getAttribute('data-chat-ultimo') || 0, cliente = sec.getAttribute('data-chat-cliente') || 'Cliente';
  lista.scrollTop = lista.scrollHeight;
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var empresa = sec.getAttribute('data-chat-empresa') || 'Tu negocio';
  function burbuja(m) { return window.burbujaChat(m, 'negocio', cliente, empresa); }
  function agregar(m) {
    if (m.id <= ultimo) return; ultimo = m.id;
    var v = sec.querySelector('#chat-vacio'); if (v) v.classList.add('hidden');
    lista.insertAdjacentHTML('beforeend', burbuja(m)); lista.scrollTop = lista.scrollHeight;
  }
  if (form && texto) {
    texto.addEventListener('input', function () { texto.style.height = 'auto'; texto.style.height = Math.min(texto.scrollHeight, 128) + 'px'; });
    texto.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) form.requestSubmit ? form.requestSubmit() : form.submit(); });
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (!texto.value.trim()) return;
      var fd = new FormData(form); fd.append('ajax', '1');
      var btn = form.querySelector('button'); btn.disabled = true;
      // Aviso por WhatsApp: se abre en el mismo toque (si no, el navegador bloquea la ventana)
      var chkWa = form.querySelector('#avisar-wa');
      if (chkWa && chkWa.checked) {
        var tel = form.getAttribute('data-wa-tel') || '';
        var txt = (form.getAttribute('data-wa-inicio') || '') + '"' + texto.value.trim() + '"' + (form.getAttribute('data-wa-fin') || '');
        window.open('https://wa.me/' + tel + '?text=' + encodeURIComponent(txt), '_blank');
      }
      fetch(form.getAttribute('action') || location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          btn.disabled = false;
          if (r.ok) { texto.value = ''; texto.style.height = ''; agregar(r.mensaje); }
          if (r.correo && r.correo !== 'ok') alert('El mensaje se guardo, pero no se pudo avisar por correo (' + r.correo.replace(/^error: /, '') + ').');
        })
        .catch(function () { btn.disabled = false; form.submit(); });
    });
  }
  var estado = null;
  setInterval(function () {
    if (document.hidden) return;
    fetch(sec.getAttribute('data-chat-poll') + '&despues=' + ultimo, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        (r.mensajes || []).forEach(agregar);
        if (estado && r.estado !== estado && !(texto && texto.value.trim())) location.reload();
        estado = r.estado;
      }).catch(function () {});
  }, 20000);
})();

// Boton fijo para volver arriba
(function () {
  var b = document.getElementById('btn-arriba'); if (!b) return;
  var f = function () { b.classList.toggle('visible', window.scrollY > 400); };
  window.addEventListener('scroll', f, { passive: true }); f();
  b.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
})();
