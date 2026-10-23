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

  // Ocultar mensajes flash despues de un tiempo
  setTimeout(function () {
    document.querySelectorAll('.flash').forEach(function (el) {
      el.style.transition = 'opacity .4s'; el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 400);
    });
  }, 6000);
})();
