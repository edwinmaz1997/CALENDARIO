<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/adjuntos.php';
require __DIR__ . '/includes/imagen_cotizacion.php';
require __DIR__ . '/includes/proveedores.php';
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
    $lineas = db_all('SELECT producto_id, proveedor_id, descripcion, unidad, cantidad, precio_unitario FROM cotizacion_detalle WHERE cotizacion_id = ? ORDER BY orden, id', [$id]);
} else {
    $cot = [
        'id' => 0, 'cliente_id' => input_int('cliente_id') ?: null, 'fecha' => date('Y-m-d'),
        'fecha_evento' => valid_date(input('fecha_evento')) ? input('fecha_evento') : '', 'hora_evento' => '', 'lugar_evento' => '', 'nombre_evento' => '', 'notas' => '', 'descuento_id' => null, 'descuento_nombre' => null, 'descuento_pct' => 0,
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
    $cot['nombre_evento'] = mb_substr(input('nombre_evento'), 0, 150);
    $cot['notas']        = input('notas');

    // Descuento (opcional): se guarda una copia del nombre y % por si luego cambia o se borra
    $descId = input_int('descuento_id');
    $descNombre = null;
    $descPct = 0.0;
    if ($descId) {
        $dsc = db_one('SELECT id, nombre, porcentaje FROM descuentos WHERE id = ? AND empresa_id = ?', [$descId, $eid]);
        if ($dsc) {
            $descNombre = $dsc['nombre'];
            $descPct = (float)$dsc['porcentaje'];
        } elseif ($id && (int)$cot['descuento_id'] === $descId) {
            $descNombre = $cot['descuento_nombre'];
            $descPct = (float)$cot['descuento_pct'];
        } else {
            $descId = 0;
        }
    }
    $cot['descuento_id'] = $descId ?: null;
    $cot['descuento_nombre'] = $descNombre;
    $cot['descuento_pct'] = $descPct;

    // Lineas
    $lineas = [];
    $pids = $_POST['producto_id'] ?? [];
    $provIds = $_POST['proveedor_id'] ?? [];
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
            'proveedor_id'    => (int)($provIds[$i] ?? 0) ?: null,
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

    // Proveedores validos de esta empresa y su comision actual
    $comisiones = [];
    foreach (db_all('SELECT id, comision_pct FROM proveedores WHERE empresa_id = ?', [$eid]) as $pv) {
        $comisiones[(int)$pv['id']] = (float)$pv['comision_pct'];
    }
    foreach ($lineas as &$l) {
        if ($l['proveedor_id'] && !isset($comisiones[$l['proveedor_id']])) {
            $l['proveedor_id'] = null;
        }
    }
    unset($l);

    if (!$errores) {
        $subtotal = 0;
        foreach ($lineas as $l) {
            $subtotal += round($l['cantidad'] * $l['precio_unitario'], 2);
        }
        $subtotal = round($subtotal, 2);
        $descMonto = $cot['descuento_id'] ? round($subtotal * $cot['descuento_pct'] / 100, 2) : 0;
        $total = round($subtotal - $descMonto, 2);
        $descVals = [$subtotal, $cot['descuento_id'], $cot['descuento_id'] ? $cot['descuento_nombre'] : null, $cot['descuento_id'] ? $cot['descuento_pct'] : 0, $descMonto];
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $hora = $cot['hora_evento'] !== '' ? $cot['hora_evento'] : null;
            if ($id) {
                $huellaAntes = huella_cotizacion($id);
                db_exec('UPDATE cotizaciones SET cliente_id = ?, nombre_evento = ?, fecha = ?, fecha_evento = ?, hora_evento = ?, lugar_evento = ?, notas = ?, subtotal = ?, descuento_id = ?, descuento_nombre = ?, descuento_pct = ?, descuento_monto = ?, total = ? WHERE id = ? AND empresa_id = ? AND estado = \'pendiente\'', array_merge([
                    $cot['cliente_id'], nullable($cot['nombre_evento']), $cot['fecha'], $cot['fecha_evento'], $hora, nullable($cot['lugar_evento']), nullable($cot['notas'])], $descVals, [$total, $id, $eid,
                ]));
                db_exec('DELETE FROM cotizacion_detalle WHERE cotizacion_id = ?', [$id]);
            } else {
                // Bloquea la fila de la empresa para generar el correlativo sin duplicados
                db_val('SELECT id FROM empresas WHERE id = ? FOR UPDATE', [$eid]);
                $corr = (int)db_val('SELECT COALESCE(MAX(correlativo), 0) + 1 FROM cotizaciones WHERE empresa_id = ?', [$eid]);
                db_exec('INSERT INTO cotizaciones (empresa_id, correlativo, cliente_id, nombre_evento, usuario_id, fecha, fecha_evento, hora_evento, lugar_evento, notas, subtotal, descuento_id, descuento_nombre, descuento_pct, descuento_monto, total, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pendiente\')', array_merge([
                    $eid, $corr, $cot['cliente_id'], nullable($cot['nombre_evento']), $u['id'], $cot['fecha'], $cot['fecha_evento'], $hora, nullable($cot['lugar_evento']), nullable($cot['notas'])], $descVals, [$total,
                ]));
                $id = (int)$pdo->lastInsertId();
            }
            $st = $pdo->prepare('INSERT INTO cotizacion_detalle (cotizacion_id, producto_id, proveedor_id, descripcion, unidad, cantidad, precio_unitario, subtotal, comision_pct, costo_proveedor, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($lineas as $i => $l) {
                $sub = round($l['cantidad'] * $l['precio_unitario'], 2);
                $pct = $l['proveedor_id'] ? $comisiones[$l['proveedor_id']] : null;
                $costo = $l['proveedor_id'] ? costo_proveedor($sub, $pct) : 0;
                $st->execute([$id, $l['producto_id'], $l['proveedor_id'], $l['descripcion'], nullable($l['unidad']), $l['cantidad'], $l['precio_unitario'], $sub, $pct, $costo, $i + 1]);
            }
            $pdo->commit();
            $volvioPorEnviar = false;
            if ($cot['id'] && huella_cotizacion($id) !== $huellaAntes && db_val('SELECT enviada_at FROM cotizaciones WHERE id = ?', [$id])) {
                // Cambio el contenido: es una nueva version que hay que volver a enviar al cliente
                db_exec('UPDATE cotizaciones SET enviada_at = NULL WHERE id = ?', [$id]);
                $volvioPorEnviar = true;
            }
            flash('ok', $cot['id'] ? 'Cotizacion actualizada.' . ($volvioPorEnviar ? ' Como cambio, quedo otra vez "Por enviar".' : '') : 'Cotizacion creada como pendiente.');
            // Imagen de la cotizacion
            $imgActual = db_val('SELECT imagen FROM cotizaciones WHERE id = ?', [$id]);
            if (!empty($_POST['quitar_imagen']) && $imgActual) {
                borrar_imagen_cot($eid, $imgActual);
                db_exec('UPDATE cotizaciones SET imagen = NULL WHERE id = ?', [$id]);
                $imgActual = null;
            }
            list($imgNueva, $imgErr) = guardar_imagen_cot('imagen', $eid, $id);
            if ($imgErr) {
                flash('error', 'Imagen: ' . $imgErr);
            } elseif ($imgNueva) {
                if ($imgActual) {
                    borrar_imagen_cot($eid, $imgActual);
                }
                db_exec('UPDATE cotizaciones SET imagen = ? WHERE id = ?', [$imgNueva, $id]);
            }
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
$proveedoresJs = array_map(function ($pv) { return ['id' => (int)$pv['id'], 'nombre' => $pv['nombre'], 'pct' => (float)$pv['comision_pct']]; }, proveedores_activos($eid));
$descuentos = db_all('SELECT id, nombre, porcentaje FROM descuentos WHERE empresa_id = ? AND activo = 1 ORDER BY nombre', [$eid]);
$descuentosJs = array_map(function ($d) { return ['id' => (int)$d['id'], 'nombre' => $d['nombre'], 'pct' => (float)$d['porcentaje']]; }, $descuentos);
// Si la cotizacion tiene un descuento que ya no esta activo/existe, se conserva como opcion
if (!empty($cot['descuento_id']) && !in_array((int)$cot['descuento_id'], array_column($descuentosJs, 'id'), true)) {
    $descuentosJs[] = ['id' => (int)$cot['descuento_id'], 'nombre' => (string)$cot['descuento_nombre'], 'pct' => (float)$cot['descuento_pct']];
}
$productos = db_all('SELECT id, proveedor_id, codigo, nombre, descripcion, unidad, precio FROM productos WHERE empresa_id = ? AND activo = 1 ORDER BY nombre', [$eid]);
foreach ($productos as &$p) {
    $p['id'] = (int)$p['id'];
    $p['precio'] = (float)$p['precio'];
    $p['proveedor_id'] = $p['proveedor_id'] ? (int)$p['proveedor_id'] : null;
}
unset($p);
$lineasJs = array_map(function ($l) {
    return [
        'producto_id' => $l['producto_id'] ? (int)$l['producto_id'] : null,
        'proveedor_id' => !empty($l['proveedor_id']) ? (int)$l['proveedor_id'] : null,
        'descripcion' => $l['descripcion'],
        'unidad'      => $l['unidad'],
        'cantidad'    => (float)$l['cantidad'],
        'precio'      => (float)$l['precio_unitario'],
    ];
}, $lineas);

$colsLinea = 'grid-cols-[minmax(0,1fr)_2.5rem_4.25rem_4rem_1.25rem] sm:grid-cols-[minmax(0,1fr)_3.5rem_5.5rem_5.5rem_1.75rem] '
    . ($proveedoresJs ? 'md:grid-cols-[minmax(9rem,1fr)_8.5rem_3.75rem_6.25rem_5.75rem_1.75rem]' : 'md:grid-cols-[minmax(9rem,1fr)_3.75rem_6.25rem_5.75rem_1.75rem]');
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

<form method="post" id="form-cot" enctype="multipart/form-data" class="grid grid-cols-1 gap-5 pb-24 lg:grid-cols-3 lg:pb-0" data-sin-bloqueo>
  <?= csrf_field() ?>
  <?php if ($cot['id']): ?><input type="hidden" name="id" value="<?= (int)$cot['id'] ?>"><?php endif; ?>

  <!-- Columna principal -->
  <div class="min-w-0 space-y-5 lg:col-span-2">
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

      <div class="mt-4">
        <div class="mb-1.5 flex items-center justify-between gap-2">
          <label for="descuento_id" class="flex items-center gap-1.5 text-sm font-semibold text-slate-700"><i data-lucide="percent" class="h-4 w-4 texto-marca"></i>Descuentos</label>
          <button type="button" class="btn btn-claro btn-sm" id="btn-nuevo-descuento"><i data-lucide="plus"></i>Nuevo</button>
        </div>
        <select class="select" name="descuento_id" id="descuento_id">
          <option value="">Sin descuento</option>
          <?php foreach ($descuentosJs as $d): ?>
            <option value="<?= $d['id'] ?>" <?= (int)$cot['descuento_id'] === $d['id'] ? 'selected' : '' ?>><?= e($d['nombre']) ?> (<?= e(pct_num($d['pct'])) ?>%)</option>
          <?php endforeach; ?>
        </select>
        <p class="ayuda">Se aplica al total de la venta.<?= $descuentosJs ? '' : ' Aun no tienes descuentos: crea uno con "Nuevo".' ?></p>
      </div>
    </section>

    <section class="card px-3 py-5 sm:p-6">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3 px-2 sm:px-0">
        <h2 class="text-base font-bold text-slate-900">Productos y servicios</h2>
        <div class="flex gap-2">
          <button type="button" class="btn btn-claro btn-sm" id="btn-nuevo-producto"><i data-lucide="package-plus"></i>Nuevo producto</button>
          <button type="button" class="btn btn-claro btn-sm" id="btn-linea-libre"><i data-lucide="plus"></i>Linea libre</button>
        </div>
      </div>

      <div class="relative mb-4">
        <i data-lucide="package-search" class="pointer-events-none absolute left-3 top-3.5 h-4 w-4 text-slate-400"></i>
        <input type="text" class="input pl-9" id="prod_buscar" placeholder="<?= $productos ? 'Busca un producto para agregarlo' : 'No hay productos registrados' ?>" autocomplete="off" <?= $productos ? '' : 'disabled' ?>>
        <div id="prod_lista" class="absolute left-0 right-0 top-full z-20 mt-1 hidden max-h-80 overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-xl"></div>
      </div>
      <?php if (!$productos): ?>
        <p class="mb-4 text-sm text-slate-500">Puedes <a href="productos.php?accion=nuevo" class="font-semibold texto-marca hover:underline">registrar productos</a> o usar lineas libres.</p>
      <?php endif; ?>

      <!-- Encabezado -->
      <div class="grid gap-1.5 border-b border-slate-200 pb-2 text-[10px] font-bold uppercase tracking-wide text-slate-500 sm:text-[11px] md:gap-2 <?= $colsLinea ?>">
        <div>Producto</div>
        <?php if ($proveedoresJs): ?><div class="hidden md:block">Proveedor</div><?php endif; ?>
        <div class="text-right">Cant.</div>
        <div class="text-right">Precio</div>
        <div class="text-right">Subtotal</div>
        <div></div>
      </div>
      <div id="lineas" class="divide-y divide-slate-100"></div>
      <p class="mt-2 hidden text-right text-xs text-slate-400" data-n-lineas></p>
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
                <a href="adjunto.php?id=<?= (int)$a['id'] ?>" <?= visor_attrs($a['id'], $a['mime'], $a['titulo']) ?> class="truncate font-semibold text-slate-800 hover:underline"><?= e($a['titulo']) ?></a>
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
  <div class="min-w-0 space-y-5">
    <section class="card p-5 sm:p-6">
      <h2 class="mb-4 text-base font-bold text-slate-900">Evento</h2>
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
        <label class="campo sm:col-span-2 lg:col-span-1"><span>Nombre del evento</span>
          <input class="input" name="nombre_evento" value="<?= e($cot['nombre_evento'] ?? '') ?>" maxlength="150" placeholder="Ej: Boda de Ana y Luis, XV años de Sofia">
        </label>
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
      <h2 class="mb-1 flex items-center gap-2 text-base font-bold text-slate-900"><i data-lucide="image" class="h-5 w-5 texto-marca"></i>Imagen <span class="text-xs font-normal text-slate-400">(opcional)</span></h2>
      <p class="ayuda mb-3">Se muestra en la cotizacion y en el PDF. Ej: referencia del montaje o la decoracion.</p>
      <?php $tieneImg = !empty($cot['imagen']) && $cot['id']; ?>
      <div id="img-caja" class="mb-3 flex h-40 w-full items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-slate-200 bg-slate-50 <?= $tieneImg ? '' : 'hidden' ?>">
        <img id="img-prev" src="<?= $tieneImg ? 'cotizacion_imagen.php?id=' . (int)$cot['id'] . '&v=' . e(substr(md5($cot['imagen']), 0, 6)) : '' ?>" alt="" class="block h-full w-full object-contain p-1">
      </div>
      <input class="input bg-white py-1.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-slate-700" type="file" name="imagen" id="img-input" accept="image/jpeg,image/png">
      <p class="ayuda">JPG o PNG.</p>
      <?php if ($tieneImg): ?>
        <label class="mt-2 flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="quitar_imagen" value="1" class="rounded"> Quitar imagen actual</label>
      <?php endif; ?>
    </section>

    <section class="card p-5 sm:p-6">
      <label class="campo"><span>Notas para el cliente</span>
        <textarea class="textarea" name="notas" rows="4" placeholder="Detalles adicionales, condiciones especiales..."><?= e($cot['notas']) ?></textarea>
      </label>
    </section>

    <!-- Total escritorio -->
    <section class="card hidden overflow-hidden lg:block">
      <div class="hidden space-y-1 border-b border-slate-100 px-6 py-3 text-sm" data-desc-box>
        <div class="flex justify-between text-slate-600"><span>Subtotal</span><strong class="text-slate-900" data-subtotal>Q 0.00</strong></div>
        <div class="flex justify-between gap-3 text-rose-600"><span class="min-w-0" data-desc-txt>Descuento</span><strong class="shrink-0" data-desc-monto>- Q 0.00</strong></div>
      </div>
      <div class="flex items-center justify-between px-6 py-5" style="background: var(--c1); color: var(--c1-txt)">
        <span class="text-sm font-semibold opacity-90">Total</span>
        <span class="text-2xl font-extrabold" data-total>Q 0.00</span>
      </div>
      <div class="hidden border-b border-slate-100 px-6 py-3 text-xs text-slate-600" data-costos>
        <div class="flex justify-between"><span>Pagas a proveedores</span><strong data-costo>Q 0.00</strong></div>
        <div class="flex justify-between"><span>Tu ganancia</span><strong class="text-emerald-600" data-ganancia>Q 0.00</strong></div>
        <p class="mt-1 text-[11px] text-slate-400">Solo tu lo ves; no aparece en el PDF.</p>
      </div>
      <div class="flex gap-3 p-4">
        <a href="<?= $cot['id'] ? 'cotizacion_ver.php?id=' . (int)$cot['id'] : 'cotizaciones.php' ?>" class="btn btn-claro flex-1">Cancelar</a>
        <button type="submit" class="btn btn-primario flex-1"><i data-lucide="save"></i>Guardar</button>
      </div>
    </section>
  </div>

  <!-- Barra inferior movil -->
  <div class="barra-sobre-nav fixed inset-x-0 z-20 border-t border-slate-200 bg-white px-4 py-2.5 lg:hidden">
    <div class="flex items-center gap-3">
      <div class="min-w-0 flex-1">
        <p class="text-xs text-slate-500">Total <span class="hidden font-semibold text-rose-600" data-desc-movil></span></p>
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
  <div class="linea-item grid items-center gap-1.5 py-1.5 md:gap-2 md:py-2 <?= $colsLinea ?>">
    <input type="hidden" name="producto_id[]">
    <input type="hidden" name="proveedor_id[]">
    <input type="hidden" name="unidad[]">
    <div class="flex min-w-0 flex-col md:contents">
      <input class="input inp-xs md:order-1" name="descripcion[]" maxlength="255" placeholder="Descripcion" data-desc>
      <?php if ($proveedoresJs): ?>
        <select class="linea-prov-sel md:order-2" data-prov aria-label="Proveedor"></select>
      <?php endif; ?>
    </div>
    <input class="input inp-xs text-right md:order-3" name="cantidad[]" inputmode="decimal" value="1" data-cant aria-label="Cantidad">
    <input class="input inp-xs text-right md:order-4" name="precio[]" inputmode="decimal" value="0.00" data-precio aria-label="Precio">
    <p class="truncate text-right text-xs font-bold text-slate-900 sm:text-sm md:order-5" data-sub>0.00</p>
    <button type="button" class="flex justify-center rounded-lg p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600 md:order-6" data-quitar aria-label="Quitar linea"><i data-lucide="x" class="h-4 w-4"></i></button>
  </div>
</template>

<!-- Modal nuevo producto -->
<div id="modal-producto" class="fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-900/50 p-4">
  <form id="form-producto" class="w-full max-w-lg my-auto max-h-full overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-6" data-sin-bloqueo>
    <div class="mb-4 flex items-center justify-between">
      <h3 class="text-lg font-bold text-slate-900">Nuevo producto</h3>
      <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" data-cerrar-producto aria-label="Cerrar"><i data-lucide="x" class="h-5 w-5"></i></button>
    </div>
    <div id="prod-error" class="mb-3 hidden rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700"></div>
    <div class="grid gap-4 sm:grid-cols-2">
      <label class="campo sm:col-span-2"><span>Nombre *</span><input class="input" name="nombre" maxlength="150" required></label>
      <label class="campo"><span>Precio de venta (<?= e($emp['moneda']) ?>) *</span><input class="input text-right" name="precio" inputmode="decimal" required placeholder="0.00"></label>
      <label class="campo"><span>Unidad</span><input class="input" name="unidad" value="Unidad" maxlength="30"></label>
      <label class="campo sm:col-span-2"><span>Proveedor</span>
        <select class="select" name="proveedor_id" id="prod-proveedor">
          <option value="">Propio (sin proveedor)</option>
          <?php foreach ($proveedoresJs as $pv): ?><option value="<?= (int)$pv['id'] ?>"><?= e($pv['nombre']) ?> (<?= e(rtrim(rtrim(number_format($pv['pct'], 2, '.', ''), '0'), '.')) ?>%)</option><?php endforeach; ?>
        </select>
        <?php if (!$proveedoresJs): ?><p class="ayuda">Aun no tienes proveedores. <a href="proveedores.php?accion=nuevo" target="_blank" class="font-semibold texto-marca hover:underline">Crear proveedor</a></p><?php endif; ?>
      </label>
      <p id="prod-calc" class="hidden rounded-xl bg-marca-suave px-3 py-2.5 text-sm text-slate-700 sm:col-span-2"></p>
    </div>
    <p class="ayuda mt-3">Se guarda en tu catalogo de productos y se agrega a esta cotizacion.</p>
    <div class="mt-5 flex gap-3">
      <button type="button" class="btn btn-claro flex-1" data-cerrar-producto>Cancelar</button>
      <button type="submit" class="btn btn-primario flex-1"><i data-lucide="check"></i>Guardar y agregar</button>
    </div>
  </form>
</div>

<!-- Modal nuevo descuento -->
<div id="modal-descuento" class="fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-900/50 p-4">
  <form id="form-descuento" class="my-auto w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl sm:p-6" data-sin-bloqueo>
    <div class="mb-4 flex items-center justify-between">
      <h3 class="text-lg font-bold text-slate-900">Nuevo descuento</h3>
      <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100" data-cerrar-descuento aria-label="Cerrar"><i data-lucide="x" class="h-5 w-5"></i></button>
    </div>
    <div id="desc-error" class="mb-3 hidden rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700"></div>
    <div class="space-y-4">
      <label class="campo"><span>Nombre del descuento *</span><input class="input" name="nombre" maxlength="100" required placeholder="Ej: Cliente frecuente"></label>
      <label class="campo"><span>Porcentaje *</span>
        <div class="relative">
          <input class="input pr-9 text-right" name="porcentaje" inputmode="decimal" required placeholder="10">
          <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-bold text-slate-400">%</span>
        </div>
      </label>
    </div>
    <p class="ayuda mt-3">Se guarda en Mi empresa &gt; Descuentos y se aplica a esta cotizacion.</p>
    <div class="mt-5 flex gap-3">
      <button type="button" class="btn btn-claro flex-1" data-cerrar-descuento>Cancelar</button>
      <button type="submit" class="btn btn-primario flex-1"><i data-lucide="check"></i>Guardar y aplicar</button>
    </div>
  </form>
</div>

<!-- Modal nuevo cliente -->
<div id="modal-cliente" class="fixed inset-0 z-50 hidden items-center justify-center overflow-y-auto bg-slate-900/50 p-4">
  <form id="form-cliente" class="w-full max-w-lg my-auto max-h-full overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl sm:p-6">
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
    'proveedores' => $proveedoresJs,
    'descuentos'  => $descuentosJs,
    'clienteId' => (int)$cot['cliente_id'] ?: null,
], $jsonFlags) . ';</script><script src="assets/js/cotizacion.js?v=' . ASSET_V . '"></script>';
require __DIR__ . '/includes/footer.php';
