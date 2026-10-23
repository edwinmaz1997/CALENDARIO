<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/adjuntos.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();
$u = usuario_actual();
$id = input_int('id');
$errores = [];

$cot = null;
if ($id) {
    $cot = db_one('SELECT * FROM cotizaciones WHERE id = ? AND empresa_id = ?', [$id, $eid]);
    if (!$cot) {
        flash('error', 'Cotizacion no encontrada.');
        redirect('cotizaciones.php');
    }
    if ($cot['estado'] !== 'pendiente') {
        flash('aviso', 'Solo se pueden editar cotizaciones pendientes. Regresala a pendiente para modificarla.');
        redirect('cotizacion_ver.php?id=' . $id);
    }
    $lineas = db_all('SELECT producto_id, descripcion, unidad, cantidad, precio_unitario FROM cotizacion_detalle WHERE cotizacion_id = ? ORDER BY orden, id', [$id]);
} else {
    $cot = [
        'id' => 0, 'cliente_id' => input_int('cliente_id') ?: null, 'fecha' => date('Y-m-d'),
        'fecha_evento' => valid_date(input('fecha_evento')) ? input('fecha_evento') : '', 'hora_evento' => '', 'lugar_evento' => '', 'notas' => '',
    ];
    $lineas = [];
}

/* ---------- Guardar ---------- */
if (is_post()) {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'Los archivos superan el limite de subida del servidor (' . ini_get('post_max_size') . '). Intenta con archivos mas livianos.');
        redirect('cotizacion_form.php' . ($id ? '?id=' . $id : ''));
    }
    csrf_check();
    $cot['cliente_id']   = input_int('cliente_id') ?: null;
    $cot['fecha']        = input('fecha');
    $cot['fecha_evento'] = input('fecha_evento');
    $cot['hora_evento']  = input('hora_evento');
    $cot['lugar_evento'] = input('lugar_evento');
    $cot['notas']        = input('notas');

    // Lineas
    $lineas = [];
    $pids = $_POST['producto_id'] ?? [];
    $descs = $_POST['descripcion'] ?? [];
    $unis = $_POST['unidad'] ?? [];
    $cants = $_POST['cantidad'] ?? [];
    $precios = $_POST['precio'] ?? [];
    $n = is_array($descs) ? count($descs) : 0;
    for ($i = 0; $i < $n; $i++) {
        $d = trim((string)($descs[$i] ?? ''));
        $c = str_replace(',', '', (string)($cants[$i] ?? ''));
        $p = str_replace(',', '', (string)($precios[$i] ?? ''));
        if ($d === '' && ($c === '' || (float)$c == 0) && ($p === '' || (float)$p == 0)) {
            continue; // linea vacia
        }
        if ($d === '') {
            $errores[] = 'La linea ' . ($i + 1) . ' no tiene descripcion.';
        }
        if (!is_numeric($c) || (float)$c <= 0) {
            $errores[] = 'La cantidad de la linea ' . ($i + 1) . ' debe ser mayor a cero.';
        }
        if (!is_numeric($p) || (float)$p < 0) {
            $errores[] = 'El precio de la linea ' . ($i + 1) . ' no es valido.';
        }
        $lineas[] = [
            'producto_id'     => (int)($pids[$i] ?? 0) ?: null,
            'descripcion'     => mb_substr($d, 0, 255),
            'unidad'          => mb_substr(trim((string)($unis[$i] ?? '')), 0, 30),
            'cantidad'        => is_numeric($c) ? round((float)$c, 2) : 0,
            'precio_unitario' => is_numeric($p) ? round((float)$p, 2) : 0,
        ];
    }

    if (!$cot['cliente_id'] || !db_val('SELECT id FROM clientes WHERE id = ? AND empresa_id = ?', [$cot['cliente_id'], $eid])) {
        $errores[] = 'Selecciona un cliente.';
    }
    if (!valid_date($cot['fecha'])) {
        $errores[] = 'La fecha de la cotizacion no es valida.';
    }
    if (!valid_date($cot['fecha_evento'])) {
        $errores[] = 'Indica la fecha del evento.';
    }
    if ($cot['hora_evento'] !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $cot['hora_evento'])) {
        $errores[] = 'La hora del evento no es valida.';
    }
    if (!$lineas) {
        $errores[] = 'Agrega al menos un producto o servicio.';
    }
    // Validar que los productos sean de esta empresa
    $idsProd = array_filter(array_column($lineas, 'producto_id'));
    if ($idsProd) {
        $ph = implode(',', array_fill(0, count($idsProd), '?'));
        $validos = array_map('intval', array_column(db_all("SELECT id FROM productos WHERE empresa_id = ? AND id IN ($ph)", array_merge([$eid], array_values($idsProd))), 'id'));
        foreach ($lineas as &$l) {
            if ($l['producto_id'] && !in_array($l['producto_id'], $validos, true)) {
                $l['producto_id'] = null;
            }
        }
        unset($l);
    }

    if (!$errores) {
        $total = 0;
        foreach ($lineas as $l) {
            $total += round($l['cantidad'] * $l['precio_unitario'], 2);
        }
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $hora = $cot['hora_evento'] !== '' ? $cot['hora_evento'] : null;
            if ($id) {
                db_exec('UPDATE cotizaciones SET cliente_id = ?, fecha = ?, fecha_evento = ?, hora_evento = ?, lugar_evento = ?, notas = ?, total = ? WHERE id = ? AND empresa_id = ? AND estado = \'pendiente\'', [
                    $cot['cliente_id'], $cot['fecha'], $cot['fecha_evento'], $hora, nullable($cot['lugar_evento']), nullable($cot['notas']), $total, $id, $eid,
                ]);
                db_exec('DELETE FROM cotizacion_detalle WHERE cotizacion_id = ?', [$id]);
            } else {
                // Bloquea la fila de la empresa para generar el correlativo sin duplicados
                db_val('SELECT id FROM empresas WHERE id = ? FOR UPDATE', [$eid]);
                $corr = (int)db_val('SELECT COALESCE(MAX(correlativo), 0) + 1 FROM cotizaciones WHERE empresa_id = ?', [$eid]);
                db_exec('INSERT INTO cotizaciones (empresa_id, correlativo, cliente_id, usuario_id, fecha, fecha_evento, hora_evento, lugar_evento, notas, total, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pendiente\')', [
                    $eid, $corr, $cot['cliente_id'], $u['id'], $cot['fecha'], $cot['fecha_evento'], $hora, nullable($cot['lugar_evento']), nullable($cot['notas']), $total,
                ]);
                $id = (int)$pdo->lastInsertId();
            }
            $st = $pdo->prepare('INSERT INTO cotizacion_detalle (cotizacion_id, producto_id, descripcion, unidad, cantidad, precio_unitario, subtotal, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($lineas as $i => $l) {
                $st->execute([$id, $l['producto_id'], $l['descripcion'], nullable($l['unidad']), $l['cantidad'], $l['precio_unitario'], round($l['cantidad'] * $l['precio_unitario'], 2), $i + 1]);
            }
            $pdo->commit();
            flash('ok', $cot['id'] ? 'Cotizacion actualizada.' : 'Cotizacion creada como pendiente.');
            // Adjuntos agregados en el formulario
            if (!empty($_FILES['adj_archivo']['name']) && array_filter((array)$_FILES['adj_archivo']['name'])) {
                list($nAdj, $errAdj) = subir_adjuntos('adj_archivo', $id, $eid, $_POST['adj_titulo'] ?? [], $u['id']);
                if ($nAdj) {
                    flash('ok', $nAdj === 1 ? 'Se adjunto 1 archivo.' : 'Se adjuntaron ' . $nAdj . ' archivos.');
                }
                foreach ($errAdj as $er) {
                    flash('error', $er);
                }
            }
            redirect('cotizacion_ver.php?id=' . $id);
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errores[] = 'No se pudo guardar la cotizacion. Intenta de nuevo.';
            if (!empty($GLOBALS['CONFIG']['app']['debug'])) {
                $errores[] = $ex->getMessage();
            }
        }
    }
}

/* ---------- Datos para el formulario ---------- */
$adjuntosExistentes = $cot['id'] ? db_all('SELECT id, titulo, nombre_original, mime FROM cotizacion_adjuntos WHERE cotizacion_id = ? ORDER BY id', [$cot['id']]) : [];
$clientes = db_all('SELECT id, nombre, nit, telefono FROM clientes WHERE empresa_id = ? AND (activo = 1 OR id = ?) ORDER BY nombre', [$eid, (int)$cot['cliente_id']]);
$productos = db_all('SELECT id, codigo, nombre, descripcion, unidad, precio FROM productos WHERE empresa_id = ? AND activo = 1 ORDER BY nombre', [$eid]);
foreach ($productos as &$p) {
    $p['id'] = (int)$p['id'];
    $p['precio'] = (float)$p['precio'];
}
unset($p);
$lineasJs = array_map(function ($l) {
    return [
        'producto_id' => $l['producto_id'] ? (int)$l['producto_id'] : null,
        'descripcion' => $l['descripcion'],
        'unidad'      => $l['unidad'],
        'cantidad'    => (float)$l['cantidad'],
        'precio'      => (float)$l['precio_unitario'],
    ];
}, $lineas);

$titulo = $cot['id'] ? 'Editar ' . numero_cotizacion($cot) : 'Nueva cotizacion';
$activo = 'cotizaciones';
require __DIR__ . '/includes/header.php';
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
?>
<?php if ($errores): ?>
  <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
    <ul class="list-inside list-disc space-y-0.5"><?php foreach (array_unique($errores) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<form method="post" id="form-cot" enctype="multipart/form-data" class="grid gap-5 pb-24 lg:grid-cols-3 lg:pb-0" data-sin-bloqueo>
  <?= csrf_field() ?>
  <?php if ($cot['id']): ?><input type="hidden" name="id" value="<?= (int)$cot['id'] ?>"><?php endif; ?>

  <!-- Columna principal -->
  <div class="space-y-5 lg:col-span-2">
    <section class="card p-5 sm:p-6">
      <div class="mb-4 flex items-center justify-between gap-3">
        <h2 class="text-base font-bold text-slate-900">Cliente</h2>
        <button type="button" class="btn btn-claro btn-sm" id="btn-nuevo-cliente"><i data-lucide="user-plus"></i>Nuevo</button>
      </div>
      <input type="hidden" name="cliente_id" id="cliente_id" value="<?= (int)$cot['cliente_id'] ?: '' ?>">
      <div class="relative" id="combo-cliente">
        <i data-lucide="search" class="pointer-events-none absolute left-3 top-3.5 h-4 w-4 text-slate-400"></i>
        <input type="text" class="input pl-9" id="cliente_buscar" placeholder="Busca por nombre, NIT o telefono" autocomplete="off">
        <div id="cliente_lista" class="absolute left-0 right-0 top-full z-20 mt-1 hidden max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-xl"></div>
      </div>
      <div id="cliente_sel" class="mt-3 hidden items-center gap-3 rounded-xl bg-marca-suave px-4 py-3">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white texto-marca"><i data-lucide="user" class="h-5 w-5"></i></div>
        <div class="min-w-0 flex-1">
          <p class="truncate font-semibold text-slate-900" id="cliente_sel_nombre"></p>
          <p class="truncate text-xs text-slate-600" id="cliente_sel_info"></p>
        </div>
        <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-white" id="cliente_quitar" aria-label="Cambiar cliente"><i data-lucide="x" class="h-4 w-4"></i></button>
      </div>
    </section>

    <section class="card p-5 sm:p-6">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-bold text-slate-900">Productos y servicios</h2>
        <button type="button" class="btn btn-claro btn-sm" id="btn-linea-libre"><i data-lucide="plus"></i>Linea libre</button>
      </div>

      <div class="relative mb-4">
        <i data-lucide="package-search" class="pointer-events-none absolute left-3 top-3.5 h-4 w-4 text-slate-400"></i>
        <input type="text" class="input pl-9" id="prod_buscar" placeholder="<?= $productos ? 'Busca un producto para agregarlo' : 'No hay productos registrados' ?>" autocomplete="off" <?= $productos ? '' : 'disabled' ?>>
        <div id="prod_lista" class="absolute left-0 right-0 top-full z-20 mt-1 hidden max-h-80 overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-xl"></div>
      </div>
      <?php if (!$productos): ?>
        <p class="mb-4 text-sm text-slate-500">Puedes <a href="productos.php?accion=nuevo" class="font-semibold texto-marca hover:underline">registrar productos</a> o usar lineas libres.</p>
      <?php endif; ?>

      <!-- Encabezado (solo escritorio) -->
      <div class="hidden grid-cols-12 gap-3 border-b border-slate-200 pb-2 text-xs font-bold uppercase tracking-wide text-slate-500 md:grid">
        <div class="col-span-5">Descripcion</div>
        <div class="col-span-2 text-right">Cantidad</div>
        <div class="col-span-2 text-right">Precio</div>
        <div class="col-span-2 text-right">Subtotal</div>
        <div class="col-span-1"></div>
      </div>
      <div id="lineas" class="divide-y divide-slate-100"></div>
      <p id="lineas-vacio" class="py-8 text-center text-sm text-slate-400">Aun no hay productos en la cotizacion.</p>
    </section>
    <details class="desplegable card overflow-hidden" id="adjuntos-form">
      <summary class="flex cursor-pointer select-none items-center gap-3 px-5 py-4 hover:bg-slate-50 sm:px-6">
        <i data-lucide="paperclip" class="h-5 w-5 texto-marca"></i>
        <span class="text-base font-bold text-slate-900">Archivos adjuntos</span>
        <?php if (!empty($adjuntosExistentes)): ?><span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600"><?= count($adjuntosExistentes) ?></span><?php endif; ?>
        <span class="hidden text-xs font-normal text-slate-400 sm:inline">(opcional)</span>
        <i data-lucide="chevron-down" class="chevron ml-auto h-5 w-5 text-slate-400"></i>
      </summary>
      <div class="border-t border-slate-100 p-5 sm:p-6">
      <div class="mb-1 flex justify-end">
        <button type="button" class="btn btn-claro btn-sm" id="btn-adj-mas"><i data-lucide="plus"></i>Agregar archivo</button>
      </div>
      <p class="ayuda mb-4">Opcional. Anticipos, contratos, referencias... Imagenes, PDF, Word o Excel de hasta <?= e(tamano_legible($GLOBALS['CONFIG']['app']['adjunto_max'] ?? 10485760)) ?>.</p>
      <datalist id="titulos-adjunto">
        <?php foreach (adjuntos_titulos_sugeridos() as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?>
      </datalist>
      <div id="adj-filas" class="space-y-3"></div>
      <?php if (!empty($adjuntosExistentes)): ?>
        <div class="mt-4 border-t border-slate-100 pt-4">
          <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Ya adjuntos</p>
          <ul class="space-y-1.5 text-sm">
            <?php foreach ($adjuntosExistentes as $a): ?>
              <li class="flex items-center gap-2">
                <i data-lucide="<?= adjunto_icono($a['mime']) ?>" class="h-4 w-4 shrink-0 text-slate-400"></i>
                <a href="adjunto.php?id=<?= (int)$a['id'] ?>" target="_blank" class="truncate font-semibold text-slate-800 hover:underline"><?= e($a['titulo']) ?></a>
                <span class="truncate text-xs text-slate-400"><?= e($a['nombre_original']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <a href="cotizacion_ver.php?id=<?= (int)$cot['id'] ?>#adjuntos" class="mt-2 inline-block text-xs font-semibold texto-marca hover:underline">Administrar adjuntos</a>
        </div>
      <?php endif; ?>
      </div>
    </details>
  </div>

  <!-- Columna lateral -->
  <div class="space-y-5">
    <section class="card p-5 sm:p-6">
      <h2 class="mb-4 text-base font-bold text-slate-900">Evento</h2>
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
        <label class="campo"><span>Fecha del evento *</span>
          <input class="input" type="date" name="fecha_evento" value="<?= e($cot['fecha_evento']) ?>" required>
        </label>
        <label class="campo"><span>Hora</span>
          <input class="input" type="time" name="hora_evento" value="<?= e($cot['hora_evento'] ? substr($cot['hora_evento'], 0, 5) : '') ?>">
        </label>
        <label class="campo sm:col-span-2 lg:col-span-1"><span>Lugar</span>
          <input class="input" name="lugar_evento" value="<?= e($cot['lugar_evento']) ?>" maxlength="255" placeholder="Direccion o salon">
        </label>
        <label class="campo"><span>Fecha de cotizacion</span>
          <input class="input" type="date" name="fecha" value="<?= e($cot['fecha']) ?>" required>
        </label>
      </div>
      <p class="ayuda mt-3">La cotizacion se crea como <strong>pendiente</strong>. Cuando la marques como aceptada aparecera en el calendario en la fecha del evento.</p>
    </section>

    <section class="card p-5 sm:p-6">
      <label class="campo"><span>Notas para el cliente</span>
        <textarea class="textarea" name="notas" rows="4" placeholder="Detalles adicionales, condiciones especiales..."><?= e($cot['notas']) ?></textarea>
      </label>
    </section>

    <!-- Total escritorio -->
    <section class="card hidden overflow-hidden lg:block">
      <div class="flex items-center justify-between px-6 py-5" style="background: var(--c1); color: var(--c1-txt)">
        <span class="text-sm font-semibold opacity-90">Total</span>
        <span class="text-2xl font-extrabold" data-total>Q 0.00</span>
      </div>
      <div class="flex gap-3 p-4">
        <a href="<?= $cot['id'] ? 'cotizacion_ver.php?id=' . (int)$cot['id'] : 'cotizaciones.php' ?>" class="btn btn-claro flex-1">Cancelar</a>
        <button type="submit" class="btn btn-primario flex-1"><i data-lucide="save"></i>Guardar</button>
      </div>
    </section>
  </div>

  <!-- Barra inferior movil -->
  <div class="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white px-4 py-3 shadow-[0_-4px_12px_rgba(0,0,0,.06)] lg:hidden" style="padding-bottom: max(.75rem, env(safe-area-inset-bottom))">
    <div class="flex items-center gap-3">
      <div class="min-w-0 flex-1">
        <p class="text-xs text-slate-500">Total</p>
        <p class="truncate text-xl font-extrabold texto-marca" data-total>Q 0.00</p>
      </div>
      <button type="submit" class="btn btn-primario px-6"><i data-lucide="save"></i>Guardar</button>
    </div>
  </div>
</form>

<!-- Plantilla de adjunto -->
<template id="tpl-adj">
  <div class="adj-fila grid gap-2 rounded-xl border border-slate-200 bg-slate-50/60 p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_auto] sm:items-center">
    <input class="input bg-white" name="adj_titulo[]" list="titulos-adjunto" maxlength="150" placeholder="Titulo, ej: Anticipo">
    <input class="input bg-white py-1.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-slate-700" type="file" name="adj_archivo[]" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.heic">
    <button type="button" class="justify-self-end rounded-lg p-2.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" data-quitar-adj aria-label="Quitar"><i data-lucide="x" class="h-4 w-4"></i></button>
  </div>
</template>

<!-- Plantilla de linea -->
<template id="tpl-linea">
  <div class="linea-item grid grid-cols-12 items-start gap-x-3 gap-y-2 py-3">
    <input type="hidden" name="producto_id[]">
    <input type="hidden" name="unidad[]">
    <div class="order-1 col-span-10 md:col-span-5">
      <input class="input" name="descripcion[]" maxlength="255" placeholder="Descripcion" data-desc>
      <p class="mt-1 text-xs text-slate-400" data-meta></p>
    </div>
    <div class="order-2 col-span-2 flex justify-end md:order-5 md:col-span-1">
      <button type="button" class="rounded-lg p-2.5 text-slate-400 hover:bg-rose-50 hover:text-rose-600" data-quitar aria-label="Quitar linea"><i data-lucide="trash-2" class="h-4 w-4"></i></button>
    </div>
    <label class="order-3 col-span-4 md:col-span-2">
      <span class="mb-1 block text-xs font-semibold text-slate-500 md:hidden">Cantidad</span>
      <input class="input text-right" name="cantidad[]" inputmode="decimal" value="1" data-cant>
    </label>
    <label class="order-3 col-span-4 md:col-span-2">
      <span class="mb-1 block text-xs font-semibold text-slate-500 md:hidden">Precio</span>
      <input class="input text-right" name="precio[]" inputmode="decimal" value="0.00" data-precio>
    </label>
    <div class="order-3 col-span-4 min-w-0 md:col-span-2">
      <span class="mb-1 block text-right text-xs font-semibold text-slate-500 md:hidden">Subtotal</span>
      <p class="truncate py-2.5 text-right font-bold text-slate-900" data-sub>0.00</p>
    </div>
  </div>
</template>

<!-- Modal nuevo cliente -->
<div id="modal-cliente" class="fixed inset-0 z-50 hidden items-end justify-center bg-slate-900/50 p-0 sm:items-center sm:p-4">
  <form id="form-cliente" class="w-full max-w-lg rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl sm:p-6">
    <div class="mb-4 flex items-center justify-between">
      <h3 class="text-lg font-bold text-slate-900">Nuevo cliente</h3>
      <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" data-cerrar-modal aria-label="Cerrar"><i data-lucide="x" class="h-5 w-5"></i></button>
    </div>
    <div id="modal-error" class="mb-3 hidden rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700"></div>
    <div class="grid gap-4 sm:grid-cols-2">
      <label class="campo sm:col-span-2"><span>Nombre *</span><input class="input" name="nombre" maxlength="150" required></label>
      <label class="campo"><span>NIT</span><input class="input" name="nit" maxlength="30" placeholder="CF"></label>
      <label class="campo"><span>Telefono</span><input class="input" name="telefono" maxlength="50" inputmode="tel"></label>
      <label class="campo sm:col-span-2"><span>Correo</span><input class="input" type="email" name="correo" maxlength="150"></label>
      <label class="campo sm:col-span-2"><span>Direccion</span><input class="input" name="direccion" maxlength="255"></label>
    </div>
    <div class="mt-5 flex gap-3">
      <button type="button" class="btn btn-claro flex-1" data-cerrar-modal>Cancelar</button>
      <button type="submit" class="btn btn-primario flex-1"><i data-lucide="check"></i>Guardar cliente</button>
    </div>
  </form>
</div>

<?php
$extraScripts = '<script>window.COT = ' . json_encode([
    'moneda'    => $emp['moneda'],
    'csrf'      => csrf_token(),
    'clientes'  => array_map(function ($c) { return ['id' => (int)$c['id'], 'nombre' => $c['nombre'], 'nit' => $c['nit'], 'telefono' => $c['telefono']]; }, $clientes),
    'productos' => $productos,
    'lineas'    => $lineasJs,
    'clienteId' => (int)$cot['cliente_id'] ?: null,
], $jsonFlags) . ';</script><script src="assets/js/cotizacion.js?v=' . ASSET_V . '"></script>';
require __DIR__ . '/includes/footer.php';
