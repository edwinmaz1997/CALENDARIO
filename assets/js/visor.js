/* Visor de imagenes, PDF y archivos dentro de la app.
 * Uso: <a href="URL" data-visor="imagen|pdf|archivo" data-titulo="..." data-descarga="URL">...</a>
 * Se abre encima de la pagina con boton Cerrar; el boton "atras" del celular tambien lo cierra.
 */
(function () {
  'use strict';
  var BASE_PDFJS = 'assets/vendor/pdfjs/';
  var capa = null, cuerpo = null, tituloEl = null, descargaEl = null, abierto = false, tareaPdf = null, zoom = 1, anchoBase = 0, zoomEl = null;

  function esc(s) { return String(s || '').replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function iconos() { if (window.lucide) window.lucide.createIcons(); }

  function crear() {
    if (capa) return;
    capa = document.createElement('div');
    capa.className = 'visor-capa';
    capa.setAttribute('role', 'dialog');
    capa.setAttribute('aria-modal', 'true');
    capa.innerHTML =
      '<div class="visor-barra">' +
        '<button type="button" class="visor-btn" data-visor-cerrar aria-label="Cerrar"><i data-lucide="arrow-left"></i><span>Cerrar</span></button>' +
        '<p class="visor-titulo"></p>' +
        '<div class="visor-zoom"><button type="button" class="visor-btn" data-zoom="-1" aria-label="Alejar"><i data-lucide="zoom-out"></i></button>' +
        '<button type="button" class="visor-btn" data-zoom="1" aria-label="Acercar"><i data-lucide="zoom-in"></i></button></div>' +
        '<a class="visor-btn" data-visor-descargar href="#" aria-label="Descargar"><i data-lucide="download"></i><span class="visor-txt">Descargar</span></a>' +
      '</div>' +
      '<div class="visor-cuerpo"></div>';
    document.body.appendChild(capa);
    cuerpo = capa.querySelector('.visor-cuerpo');
    tituloEl = capa.querySelector('.visor-titulo');
    descargaEl = capa.querySelector('[data-visor-descargar]');
    capa.querySelector('[data-visor-cerrar]').addEventListener('click', function () { cerrar(true); });
    zoomEl = capa.querySelector('.visor-zoom');
    capa.querySelectorAll('[data-zoom]').forEach(function (b) {
      b.addEventListener('click', function () {
        zoom = Math.max(1, Math.min(3, zoom + (+b.getAttribute('data-zoom')) * 0.5));
        aplicarZoom();
      });
    });
    iconos();
  }

  function aplicarZoom() {
    var img = cuerpo.querySelector('.visor-imagen img');
    if (img) {
      img.style.maxWidth = zoom === 1 ? '' : 'none';
      img.style.maxHeight = zoom === 1 ? '' : 'none';
      img.style.width = zoom === 1 ? '' : (Math.round(cuerpo.clientWidth * zoom) - 24) + 'px';
      cuerpo.querySelector('.visor-imagen').classList.toggle('ampliada', zoom > 1);
    }
    cuerpo.querySelectorAll('.visor-pagina').forEach(function (c) {
      c.style.width = Math.floor(anchoBase * zoom) + 'px';
    });
    cuerpo.querySelector('.visor-paginas') && cuerpo.querySelector('.visor-paginas').classList.toggle('ampliada', zoom > 1);
  }

  function cargarPdfjs() {
    return new Promise(function (ok, mal) {
      if (window.pdfjsLib) return ok(window.pdfjsLib);
      var s = document.createElement('script');
      s.src = BASE_PDFJS + 'pdf.min.js';
      s.onload = function () {
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = BASE_PDFJS + 'pdf.worker.min.js';
        ok(window.pdfjsLib);
      };
      s.onerror = mal;
      document.head.appendChild(s);
    });
  }

  function mostrarPdf(url) {
    cuerpo.innerHTML = '<div class="visor-cargando">Cargando PDF...</div>';
    cargarPdfjs().then(function (pdfjsLib) {
      tareaPdf = pdfjsLib.getDocument({ url: url, withCredentials: true });
      return tareaPdf.promise;
    }).then(function (doc) {
      if (!abierto) return;
      cuerpo.innerHTML = '<div class="visor-paginas"></div>';
      var cont = cuerpo.querySelector('.visor-paginas');
      var ancho = Math.min(cuerpo.clientWidth - 24, 900);
      anchoBase = ancho;
      // Se dibuja con mas resolucion para que el zoom se vea nitido
      var dpr = Math.min((window.devicePixelRatio || 1) * 1.5, 3);
      var cadena = Promise.resolve();
      for (var n = 1; n <= doc.numPages; n++) {
        (function (num) {
          cadena = cadena.then(function () {
            if (!abierto) return;
            return doc.getPage(num).then(function (pagina) {
              var base = pagina.getViewport({ scale: 1 });
              var escala = ancho / base.width;
              var vp = pagina.getViewport({ scale: escala * dpr });
              var canvas = document.createElement('canvas');
              canvas.width = vp.width; canvas.height = vp.height;
              canvas.style.width = Math.floor(ancho * zoom) + 'px';
              canvas.className = 'visor-pagina';
              cont.appendChild(canvas);
              return pagina.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
            });
          });
        })(n);
      }
      return cadena;
    }).catch(function () {
      if (!abierto) return;
      cuerpo.innerHTML = '<div class="visor-archivo"><p>No se pudo mostrar el PDF aqui.</p>' +
        '<a class="btn btn-primario" href="' + esc(descargaEl.getAttribute('href')) + '"><i data-lucide="download"></i>Descargar PDF</a></div>';
      iconos();
    });
  }

  function abrir(tipo, url, titulo, descarga) {
    crear();
    abierto = true;
    zoom = 1;
    zoomEl.style.display = (tipo === 'imagen' || tipo === 'pdf') ? '' : 'none';
    tituloEl.textContent = titulo || '';
    descargaEl.setAttribute('href', descarga || url);
    document.documentElement.classList.add('visor-activo');
    capa.classList.add('abierto');
    if (tipo === 'imagen') {
      cuerpo.innerHTML = '<div class="visor-imagen"><img src="' + esc(url) + '" alt=""></div>';
    } else if (tipo === 'pdf') {
      mostrarPdf(url);
    } else {
      cuerpo.innerHTML = '<div class="visor-archivo"><i data-lucide="file" class="h-14 w-14"></i>' +
        '<p>Este tipo de archivo no se puede ver aqui.</p>' +
        '<a class="btn btn-primario" href="' + esc(descarga || url) + '"><i data-lucide="download"></i>Descargar</a></div>';
      iconos();
    }
    // Entrada en el historial para que "atras" cierre el visor
    try { history.pushState({ visor: true }, ''); } catch (e) {}
  }

  function cerrar(desdeBoton) {
    if (!abierto) return;
    abierto = false;
    if (tareaPdf && tareaPdf.destroy) { try { tareaPdf.destroy(); } catch (e) {} }
    tareaPdf = null;
    capa.classList.remove('abierto');
    document.documentElement.classList.remove('visor-activo');
    cuerpo.innerHTML = '';
    if (desdeBoton && history.state && history.state.visor) {
      history.back();
    }
  }

  window.addEventListener('popstate', function () { if (abierto) cerrar(false); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && abierto) cerrar(true); });

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('[data-visor]');
    if (!a) return;
    if (ev.ctrlKey || ev.metaKey || ev.shiftKey) return; // permitir abrir en otra pestana en escritorio
    ev.preventDefault();
    abrir(a.getAttribute('data-visor'), a.getAttribute('href'), a.getAttribute('data-titulo'), a.getAttribute('data-descarga'));
  });
})();
