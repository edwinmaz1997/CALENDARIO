(function () {
  'use strict';
  var D = window.COT || { clientes: [], productos: [], lineas: [] };
  var $ = function (s, ctx) { return (ctx || document).querySelector(s); };

  function num(v) { var n = parseFloat(String(v).replace(/,/g, '')); return isNaN(n) ? 0 : n; }
  function fmt(n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function iconos() { if (window.lucide) window.lucide.createIcons(); }

  /* ================= Combobox generico ================= */
  function combo(input, lista, fuente, render, alElegir) {
    var activos = [], idx = -1;
    function pintar() {
      var q = norm(input.value.trim());
      activos = fuente().filter(function (it) { return !q || norm(it._busca).indexOf(q) !== -1; }).slice(0, 50);
      idx = activos.length ? 0 : -1;
      if (!activos.length) {
        lista.innerHTML = '<p class="px-4 py-3 text-sm text-slate-400">Sin resultados</p>';
      } else {
        lista.innerHTML = activos.map(function (it, i) {
          return '<button type="button" data-i="' + i + '" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-slate-50' + (i === idx ? ' bg-slate-50' : '') + '">' + render(it) + '</button>';
        }).join('');
      }
      lista.classList.remove('hidden');
    }
    function marcar() {
      Array.prototype.forEach.call(lista.querySelectorAll('[data-i]'), function (b, i) { b.classList.toggle('bg-slate-50', i === idx); if (i === idx) b.scrollIntoView({ block: 'nearest' }); });
    }
    function cerrar() { lista.classList.add('hidden'); }
    input.addEventListener('focus', pintar);
    input.addEventListener('input', pintar);
    input.addEventListener('keydown', function (ev) {
      if (lista.classList.contains('hidden')) return;
      if (ev.key === 'ArrowDown') { ev.preventDefault(); idx = Math.min(idx + 1, activos.length - 1); marcar(); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); idx = Math.max(idx - 1, 0); marcar(); }
      else if (ev.key === 'Enter') { ev.preventDefault(); if (activos[idx]) { alElegir(activos[idx]); cerrar(); } }
      else if (ev.key === 'Escape') { cerrar(); }
    });
    lista.addEventListener('mousedown', function (ev) { ev.preventDefault(); });
    lista.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-i]'); if (!b) return;
      alElegir(activos[+b.getAttribute('data-i')]); cerrar();
    });
    input.addEventListener('blur', function () { setTimeout(cerrar, 120); });
    return { pintar: pintar };
  }

  /* ================= Cliente ================= */
  D.clientes.forEach(function (c) { c._busca = [c.nombre, c.nit, c.telefono].join(' '); });
  var inCliente = $('#cliente_buscar'), hidCliente = $('#cliente_id'), boxSel = $('#cliente_sel'), comboCli = $('#combo-cliente');

  function elegirCliente(c) {
    hidCliente.value = c ? c.id : '';
    if (c) {
      $('#cliente_sel_nombre').textContent = c.nombre;
      $('#cliente_sel_info').textContent = ['NIT: ' + (c.nit || 'CF'), c.telefono].filter(Boolean).join(' · ');
      boxSel.classList.remove('hidden'); boxSel.classList.add('flex');
      comboCli.classList.add('hidden');
      inCliente.value = '';
    } else {
      boxSel.classList.add('hidden'); boxSel.classList.remove('flex');
      comboCli.classList.remove('hidden');
      inCliente.focus();
    }
  }
  combo(inCliente, $('#cliente_lista'), function () { return D.clientes; }, function (c) {
    return '<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-600">' + esc(c.nombre.charAt(0).toUpperCase()) + '</span>' +
      '<span class="min-w-0"><span class="block truncate font-semibold text-slate-800">' + esc(c.nombre) + '</span><span class="block truncate text-xs text-slate-500">NIT: ' + esc(c.nit || 'CF') + (c.telefono ? ' · ' + esc(c.telefono) : '') + '</span></span>';
  }, elegirCliente);
  $('#cliente_quitar').addEventListener('click', function () { elegirCliente(null); });
  if (D.clienteId) {
    var ini = D.clientes.filter(function (c) { return c.id === D.clienteId; })[0];
    if (ini) elegirCliente(ini);
  }

  /* Modal nuevo cliente */
  var modal = $('#modal-cliente'), formCli = $('#form-cliente'), errCli = $('#modal-error');
  function abrirModal() {
    formCli.reset(); errCli.classList.add('hidden');
    if (inCliente.value.trim()) formCli.nombre.value = inCliente.value.trim();
    modal.classList.remove('hidden'); modal.classList.add('flex');
    setTimeout(function () { formCli.nombre.focus(); }, 50);
  }
  function cerrarModal() { modal.classList.add('hidden'); modal.classList.remove('flex'); }
  $('#btn-nuevo-cliente').addEventListener('click', abrirModal);
  Array.prototype.forEach.call(modal.querySelectorAll('[data-cerrar-modal]'), function (b) { b.addEventListener('click', cerrarModal); });
  modal.addEventListener('click', function (ev) { if (ev.target === modal) cerrarModal(); });
  formCli.addEventListener('submit', function (ev) {
    ev.preventDefault(); ev.stopPropagation();
    var fd = new FormData(formCli); fd.append('csrf', D.csrf);
    var btn = formCli.querySelector('[type=submit]'); btn.disabled = true;
    fetch('clientes.php?accion=rapido', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        btn.disabled = false;
        if (!r.ok) { errCli.textContent = r.error || 'No se pudo guardar.'; errCli.classList.remove('hidden'); return; }
        var c = r.cliente; c.id = +c.id; c._busca = [c.nombre, c.nit, c.telefono].join(' ');
        D.clientes.push(c); D.clientes.sort(function (a, b) { return a.nombre.localeCompare(b.nombre); });
        elegirCliente(c); cerrarModal();
      })
      .catch(function () { btn.disabled = false; errCli.textContent = 'Error de conexion. Intenta de nuevo.'; errCli.classList.remove('hidden'); });
  });

  /* ================= Lineas ================= */
  var cont = $('#lineas'), tpl = $('#tpl-linea'), vacio = $('#lineas-vacio');

  function recalcular() {
    var total = 0, filas = cont.querySelectorAll('.linea-item');
    Array.prototype.forEach.call(filas, function (f) {
      var sub = Math.round(num($('[data-cant]', f).value) * num($('[data-precio]', f).value) * 100) / 100;
      $('[data-sub]', f).textContent = fmt(sub);
      total += sub;
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-total]'), function (el) { el.textContent = D.moneda + ' ' + fmt(total); });
    vacio.classList.toggle('hidden', filas.length > 0);
  }

  function agregarLinea(l, enfocar) {
    var frag = tpl.content.cloneNode(true), f = frag.querySelector('.linea-item');
    f.querySelector('[name="producto_id[]"]').value = l.producto_id || '';
    f.querySelector('[name="unidad[]"]').value = l.unidad || '';
    $('[data-desc]', f).value = l.descripcion || '';
    $('[data-cant]', f).value = l.cantidad != null ? l.cantidad : 1;
    $('[data-precio]', f).value = (l.precio != null ? num(l.precio) : 0).toFixed(2);
    $('[data-meta]', f).textContent = l.producto_id ? ('Producto del catalogo' + (l.unidad ? ' · ' + l.unidad : '')) : 'Linea libre';
    cont.appendChild(frag);
    iconos(); recalcular();
    if (enfocar) { var el = enfocar === 'desc' ? $('[data-desc]', f) : $('[data-cant]', f); el.focus(); el.select(); }
  }

  cont.addEventListener('input', function (ev) { if (ev.target.matches('[data-cant],[data-precio]')) recalcular(); });
  cont.addEventListener('focusout', function (ev) {
    if (ev.target.matches('[data-precio]')) ev.target.value = num(ev.target.value).toFixed(2);
  });
  cont.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-quitar]'); if (!b) return;
    b.closest('.linea-item').remove(); recalcular();
  });

  $('#btn-linea-libre').addEventListener('click', function () { agregarLinea({ cantidad: 1, precio: 0 }, 'desc'); });

  /* Buscador de productos */
  D.productos.forEach(function (p) { p._busca = [p.nombre, p.codigo, p.descripcion].join(' '); });
  var inProd = $('#prod_buscar');
  if (inProd && !inProd.disabled) {
    combo(inProd, $('#prod_lista'), function () { return D.productos; }, function (p) {
      return '<span class="min-w-0 flex-1"><span class="block truncate font-semibold text-slate-800">' + esc(p.nombre) + '</span>' +
        '<span class="block truncate text-xs text-slate-500">' + esc([p.codigo, p.unidad].filter(Boolean).join(' · ')) + '</span></span>' +
        '<span class="shrink-0 font-bold text-slate-700">' + esc(D.moneda) + ' ' + fmt(p.precio) + '</span>';
    }, function (p) {
      // Si ya esta agregado, suma uno a la cantidad
      var existente = null;
      Array.prototype.forEach.call(cont.querySelectorAll('.linea-item'), function (f) {
        if (+f.querySelector('[name="producto_id[]"]').value === p.id && num($('[data-precio]', f).value) === p.precio) existente = f;
      });
      if (existente) {
        var c = $('[data-cant]', existente); c.value = num(c.value) + 1; recalcular();
        existente.classList.add('bg-amber-50'); setTimeout(function () { existente.classList.remove('bg-amber-50'); }, 600);
      } else {
        agregarLinea({ producto_id: p.id, descripcion: p.nombre, unidad: p.unidad, cantidad: 1, precio: p.precio }, 'cant');
      }
      inProd.value = '';
    });
  }

  D.lineas.forEach(function (l) { agregarLinea(l); });
  recalcular();

  /* ================= Adjuntos ================= */
  var adjCont = $('#adj-filas'), adjTpl = $('#tpl-adj');
  function agregarAdj() {
    var frag = adjTpl.content.cloneNode(true);
    adjCont.appendChild(frag); iconos();
    var filas = adjCont.querySelectorAll('.adj-fila');
    return filas[filas.length - 1];
  }
  if (adjCont && adjTpl) {
    agregarAdj();
    $('#btn-adj-mas').addEventListener('click', function () { $('input', agregarAdj()).focus(); });
    adjCont.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-quitar-adj]'); if (!b) return;
      b.closest('.adj-fila').remove();
      if (!adjCont.querySelector('.adj-fila')) agregarAdj();
    });
    // Si eligen archivo sin titulo, sugerir el nombre del archivo
    adjCont.addEventListener('change', function (ev) {
      if (!ev.target.matches('input[type=file]')) return;
      var fila = ev.target.closest('.adj-fila'), t = fila.querySelector('[name="adj_titulo[]"]');
      if (ev.target.files[0] && !t.value) t.placeholder = 'Titulo para ' + ev.target.files[0].name;
    });
  }

  /* Validacion antes de enviar */
  var form = $('#form-cot'), enviando = false;
  form.addEventListener('submit', function (ev) {
    if (enviando) { ev.preventDefault(); return; }
    if (!hidCliente.value) { ev.preventDefault(); alert('Selecciona o crea un cliente.'); inCliente.focus(); return; }
    if (!cont.querySelector('.linea-item')) { ev.preventDefault(); alert('Agrega al menos un producto o servicio.'); return; }
    var sinTitulo = Array.prototype.filter.call(document.querySelectorAll('.adj-fila'), function (f) {
      return f.querySelector('input[type=file]').files.length && !f.querySelector('[name="adj_titulo[]"]').value.trim();
    });
    if (sinTitulo.length) { ev.preventDefault(); alert('Escribe el titulo de cada archivo adjunto (ej: Anticipo).'); sinTitulo[0].querySelector('[name="adj_titulo[]"]').focus(); return; }
    enviando = true;
    Array.prototype.forEach.call(form.querySelectorAll('[type=submit]'), function (b) { b.disabled = true; });
  });
})();
