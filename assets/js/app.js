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

  // Ocultar mensajes flash despues de un tiempo
  setTimeout(function () {
    document.querySelectorAll('.flash').forEach(function (el) {
      el.style.transition = 'opacity .4s'; el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 400);
    });
  }, 6000);
})();
