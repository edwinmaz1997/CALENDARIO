/* Registro del service worker y boton "Instalar app" (Android / Chrome) */
(function () {
  'use strict';
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {});
    });
  }

  var aviso = null;
  var botones = function () { return document.querySelectorAll('[data-instalar-app]'); };
  var instalada = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

  function mostrar(si) {
    botones().forEach(function (b) { b.classList.toggle('hidden', !si); });
  }

  window.addEventListener('beforeinstallprompt', function (ev) {
    ev.preventDefault();
    aviso = ev;
    if (!instalada) mostrar(true);
  });

  window.addEventListener('appinstalled', function () {
    aviso = null;
    mostrar(false);
  });

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-instalar-app]');
    if (!b || !aviso) return;
    ev.preventDefault();
    aviso.prompt();
    aviso.userChoice.then(function () { aviso = null; mostrar(false); });
  });
})();
