<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/adjuntos.php';
require __DIR__ . '/includes/pagos.php';
require __DIR__ . '/includes/imagen_cotizacion.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();
$id = input_int('id');

$cot = db_one('SELECT * FROM cotizaciones WHERE id = ? AND empresa_id = ?', [$id, $eid]);
if (!$cot) {
    flash('error', 'Cotizacion no encontrada.');
    redirect('cotizaciones.php');
}

/* ---------- Acciones ---------- */
if (is_post()) {
    // Si los archivos superan post_max_size, PHP llega con $_POST vacio
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'Los archivos superan el limite de subida del servidor (' . ini_get('post_max_size') . ').');
        redirect('cotizacion_ver.php?id=' . $id . '#adjuntos');
    }
    csrf_check();
    $accion = input('accion');

    if ($accion === 'aceptar' && $cot['estado'] !== 'aceptada') {
        $fe = input('fecha_evento', $cot['fecha_evento']);
        $he = input('hora_evento');
        if (!valid_date($fe)) {
            flash('error', 'La fecha del evento no es valida.');
        } else {
            $he = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $he) ? $he : null;
            db_exec('UPDATE cotizaciones SET estado = \'aceptada\', fecha_evento = ?, hora_evento = ?, fecha_aceptacion = NOW(), recordatorio_enviado = NULL WHERE id = ? AND empresa_id = ?', [$fe, $he, $id, $eid]);
            flash('ok', 'Cotizacion aceptada. El evento quedo agendado el ' . fecha_larga($fe) . '.');
        }
    } elseif ($accion === 'rechazar' && $cot['estado'] !== 'rechazada') {
        db_exec('UPDATE cotizaciones SET estado = \'rechazada\', fecha_aceptacion = NULL, recordatorio_enviado = NULL WHERE id = ? AND empresa_id = ?', [$id, $eid]);
        flash('ok', 'Cotizacion marcada como rechazada.');
    } elseif ($accion === 'pendiente' && $cot['estado'] !== 'pendiente') {
        db_exec('UPDATE cotizaciones SET estado = \'pendiente\', fecha_aceptacion = NULL, recordatorio_enviado = NULL WHERE id = ? AND empresa_id = ?', [$id, $eid]);
        flash('ok', 'La cotizacion regreso a pendiente' . ($cot['estado'] === 'aceptada' ? ' y se quito del calendario.' : '.'));
    } elseif ($accion === 'duplicar') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_val('SELECT id FROM empresas WHERE id = ? FOR UPDATE', [$eid]);
            $corr = (int)db_val('SELECT COALESCE(MAX(correlativo), 0) + 1 FROM cotizaciones WHERE empresa_id = ?', [$eid]);
            db_exec('INSERT INTO cotizaciones (empresa_id, correlativo, cliente_id, usuario_id, fecha, fecha_evento, hora_evento, lugar_evento, notas, total, estado)
                     SELECT empresa_id, ?, cliente_id, ?, CURDATE(), fecha_evento, hora_evento, lugar_evento, notas, total, \'pendiente\' FROM cotizaciones WHERE id = ?', [$corr, usuario_actual()['id'], $id]);
            $nuevo = (int)$pdo->lastInsertId();
            db_exec('INSERT INTO cotizacion_detalle (cotizacion_id, producto_id, descripcion, unidad, cantidad, precio_unitario, subtotal, orden)
                     SELECT ?, producto_id, descripcion, unidad, cantidad, precio_unitario, subtotal, orden FROM cotizacion_detalle WHERE cotizacion_id = ?', [$nuevo, $id]);
            $pdo->commit();
            $origen = imagen_cot_path($eid, $cot['imagen']);
            if ($origen) {
                $copia = 'img_cot' . $nuevo . '_' . bin2hex(random_bytes(6)) . '.' . pathinfo($origen, PATHINFO_EXTENSION);
                if (@copy($origen, dirname($origen) . '/' . $copia)) {
                    db_exec('UPDATE cotizaciones SET imagen = ? WHERE id = ?', [$copia, $nuevo]);
                }
            }
            flash('ok', 'Cotizacion duplicada como pendiente. Revisa la fecha del evento.');
            redirect('cotizacion_form.php?id=' . $nuevo);
        } catch (Exception $ex) {
            $pdo->rollBack();
            flash('error', 'No se pudo duplicar la cotizacion.');
        }
    } elseif ($accion === 'eliminar') {
        if ($cot['estado'] === 'aceptada') {
            flash('error', 'No se puede eliminar una cotizacion aceptada. Regresala a pendiente primero.');
        } else {
            $archivos = db_all('SELECT archivo FROM cotizacion_adjuntos WHERE cotizacion_id = ?', [$id]);
            db_exec('DELETE FROM cotizaciones WHERE id = ? AND empresa_id = ?', [$id, $eid]);
            foreach ($archivos as $a) {
                borrar_archivo_adjunto($eid, $a['archivo']);
            }
            borrar_imagen_cot($eid, $cot['imagen']);
            flash('ok', 'Cotizacion ' . numero_cotizacion($cot, $emp) . ' eliminada.');
            redirect('cotizaciones.php');
        }
    } elseif ($accion === 'imagen') {
        if (input('hacer') === 'quitar') {
            borrar_imagen_cot($eid, $cot['imagen']);
            db_exec('UPDATE cotizaciones SET imagen = NULL WHERE id = ?', [$id]);
            flash('ok', 'Imagen quitada de la cotizacion.');
        } else {
            list($nueva, $err) = guardar_imagen_cot('imagen', $eid, $id);
            if ($err) {
                flash('error', $err);
            } elseif ($nueva) {
                borrar_imagen_cot($eid, $cot['imagen']);
                db_exec('UPDATE cotizaciones SET imagen = ? WHERE id = ?', [$nueva, $id]);
                flash('ok', 'Imagen guardada. Ya aparece en el PDF.');
            } else {
                flash('error', 'Selecciona una imagen.');
            }
        }
    } elseif ($accion === 'registrar_pago') {
        $desc = input('pago_descripcion');
        $monto = str_replace([',', ' '], '', input('pago_monto'));
        $fechaPago = input('pago_fecha', date('Y-m-d'));
        $metodo = input('pago_metodo');
        $res = resumen_pagos($cot);
        $err = null;
        if ($desc === '') {
            $err = 'Escribe la descripcion del pago (ej: Anticipo).';
        } elseif (!is_numeric($monto) || (float)$monto <= 0) {
            $err = 'El monto del pago debe ser mayor a cero.';
        } elseif (round((float)$monto, 2) > round($res['saldo'], 2) + 0.001) {
            $err = 'El pago (' . money($monto, $emp['moneda']) . ') supera el saldo pendiente de ' . money(max(0, $res['saldo']), $emp['moneda']) . '.';
        } elseif (!valid_date($fechaPago)) {
            $err = 'La fecha del pago no es valida.';
        }
        if ($err) {
            flash('error', $err);
            $_SESSION['error_pago'] = true;
            redirect('cotizacion_ver.php?id=' . $id . '#pagos');
        }
        $adjId = null;
        if (!empty($_FILES['pago_comprobante']['name'][0])) {
            list($n, $errs) = subir_adjuntos('pago_comprobante', $id, $eid, 'Comprobante: ' . $desc, usuario_actual()['id']);
            if ($n) {
                $adjId = (int)db()->lastInsertId();
            }
            foreach ($errs as $er) {
                flash('error', 'Comprobante: ' . $er);
            }
        }
        db_exec('INSERT INTO cotizacion_pagos (cotizacion_id, fecha, descripcion, metodo, monto, adjunto_id, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $id, $fechaPago, mb_substr($desc, 0, 150), in_array($metodo, pagos_metodos(), true) ? $metodo : null, round((float)$monto, 2), $adjId, usuario_actual()['id'],
        ]);
        $nuevo = resumen_pagos($cot);
        flash('ok', 'Pago de ' . money($monto, $emp['moneda']) . ' registrado. ' . ($nuevo['saldo'] > 0 ? 'Pendiente: ' . money($nuevo['saldo'], $emp['moneda']) . '.' : 'La cotizacion quedo pagada por completo.'));
        redirect('cotizacion_ver.php?id=' . $id . '#pagos');
    } elseif ($accion === 'quitar_pago') {
        $pg = db_one('SELECT * FROM cotizacion_pagos WHERE id = ? AND cotizacion_id = ?', [input_int('pago_id'), $id]);
        if ($pg) {
            db_exec('DELETE FROM cotizacion_pagos WHERE id = ?', [$pg['id']]);
            flash('ok', 'Pago "' . $pg['descripcion'] . '" eliminado.');
        }
        redirect('cotizacion_ver.php?id=' . $id . '#pagos');
    } elseif ($accion === 'adjuntar') {
        $tituloAdj = input('titulo');
        if ($tituloAdj === '') {
            flash('error', 'Escribe un titulo para el adjunto (por ejemplo: Anticipo).');
        } else {
            list($n, $errs) = subir_adjuntos('archivos', $id, $eid, $tituloAdj, usuario_actual()['id']);
            if ($n) {
                flash('ok', $n === 1 ? 'Archivo adjuntado.' : $n . ' archivos adjuntados.');
            }
            foreach ($errs as $er) {
                flash('error', $er);
            }
        }
        redirect('cotizacion_ver.php?id=' . $id . '#adjuntos');
    } elseif ($accion === 'quitar_adjunto') {
        $adj = db_one('SELECT * FROM cotizacion_adjuntos WHERE id = ? AND cotizacion_id = ?', [input_int('adjunto_id'), $id]);
        if ($adj) {
            db_exec('DELETE FROM cotizacion_adjuntos WHERE id = ?', [$adj['id']]);
            borrar_archivo_adjunto($eid, $adj['archivo']);
            flash('ok', 'Adjunto "' . $adj['titulo'] . '" eliminado.');
        }
        redirect('cotizacion_ver.php?id=' . $id . '#adjuntos');
    } elseif ($accion === 'recordatorio' && $cot['estado'] === 'aceptada') {
        require_once APP_ROOT . '/includes/recordatorios.php';
        list($ok, $msg) = enviar_recordatorio($id);
        flash($ok ? 'ok' : 'error', $msg);
    }
    redirect('cotizacion_ver.php?id=' . $id);
}


$cliente = db_one('SELECT * FROM clientes WHERE id = ?', [$cot['cliente_id']]);
$lineas = db_all('SELECT * FROM cotizacion_detalle WHERE cotizacion_id = ? ORDER BY orden, id', [$id]);
$autor = $cot['usuario_id'] ? db_val('SELECT nombre FROM usuarios WHERE id = ?', [$cot['usuario_id']]) : null;
$numero = numero_cotizacion($cot, $emp);
$logo = logo_url($emp);
$pagos = db_all('SELECT p.*, a.mime AS adj_mime FROM cotizacion_pagos p LEFT JOIN cotizacion_adjuntos a ON a.id = p.adjunto_id WHERE p.cotizacion_id = ? ORDER BY p.fecha, p.id', [$id]);
$pagosRes = resumen_pagos($cot);
$errorPago = !empty($_SESSION['error_pago']);
unset($_SESSION['error_pago']);
$adjuntos = db_all('SELECT * FROM cotizacion_adjuntos WHERE cotizacion_id = ? ORDER BY created_at DESC, id DESC', [$id]);
$maxAdj = $GLOBALS['CONFIG']['app']['adjunto_max'] ?? 10485760;

$titulo = $numero;
$activo = 'cotizaciones';
$accionesHeader = '<a href="cotizaciones.php" class="btn btn-claro btn-sm"><i data-lucide="arrow-left"></i><span class="hidden sm:inline">Volver</span></a>';
require __DIR__ . '/includes/header.php';
?>
<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
  <!-- Documento -->
  <div class="min-w-0 lg:col-span-2">
    <article class="card overflow-hidden">
      <div class="h-2" style="background: linear-gradient(90deg, var(--c1), var(--c2))"></div>
      <div class="flex flex-col gap-5 border-b border-slate-100 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-7">
        <div class="flex items-center gap-4">
          <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" class="h-16 w-16 object-contain sm:h-20 sm:w-20"><?php endif; ?>
          <div>
            <p class="text-lg font-extrabold text-slate-900"><?= e($emp['nombre']) ?></p>
            <?php if ($emp['nit']): ?><p class="text-xs text-slate-500">NIT: <?= e($emp['nit']) ?></p><?php endif; ?>
            <?php if ($emp['direccion']): ?><p class="text-xs text-slate-500"><?= e($emp['direccion']) ?></p><?php endif; ?>
            <p class="text-xs text-slate-500"><?= e(implode(' · ', array_filter([$emp['telefono'], $emp['correo']]))) ?></p>
          </div>
        </div>
        <div class="sm:text-right">
          <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Cotizacion</p>
          <p class="text-2xl font-extrabold texto-marca"><?= e($numero) ?></p>
          <p class="text-sm text-slate-500"><?= e(fecha_larga($cot['fecha'])) ?></p>
          <div class="mt-2"><?= badge_estado($cot['estado']) ?></div>
        </div>
      </div>

      <div class="grid gap-5 border-b border-slate-100 p-5 sm:grid-cols-2 sm:p-7">
        <div>
          <p class="mb-1 text-xs font-bold uppercase tracking-wider text-slate-400">Cliente</p>
          <p class="font-bold text-slate-900"><?= e($cliente['nombre']) ?></p>
          <p class="text-sm text-slate-600">NIT: <?= e($cliente['nit'] ?: 'CF') ?></p>
          <?php if ($cliente['telefono']): ?><p class="text-sm text-slate-600"><?= e($cliente['telefono']) ?></p><?php endif; ?>
          <?php if ($cliente['correo']): ?><p class="text-sm text-slate-600"><?= e($cliente['correo']) ?></p><?php endif; ?>
          <?php if ($cliente['direccion']): ?><p class="text-sm text-slate-600"><?= e($cliente['direccion']) ?></p><?php endif; ?>
        </div>
        <div>
          <p class="mb-1 text-xs font-bold uppercase tracking-wider text-slate-400">Evento</p>
          <p class="font-bold text-slate-900"><?= e(fecha_larga($cot['fecha_evento'])) ?><?= $cot['hora_evento'] ? ' · ' . e(hora($cot['hora_evento'])) : '' ?></p>
          <?php if ($cot['lugar_evento']): ?><p class="text-sm text-slate-600"><?= e($cot['lugar_evento']) ?></p><?php endif; ?>
        </div>
      </div>

      <!-- Detalle -->
      <div class="p-5 sm:p-7">
        <div class="hidden grid-cols-12 gap-3 border-b border-slate-200 pb-2 text-xs font-bold uppercase tracking-wide text-slate-500 sm:grid">
          <div class="col-span-6">Descripcion</div><div class="col-span-2 text-right">Cant.</div><div class="col-span-2 text-right">Precio</div><div class="col-span-2 text-right">Subtotal</div>
        </div>
        <?php foreach ($lineas as $l): ?>
          <div class="grid grid-cols-12 gap-x-3 gap-y-1 border-b border-slate-100 py-3 text-sm">
            <div class="col-span-12 sm:col-span-6">
              <p class="font-semibold text-slate-800"><?= e($l['descripcion']) ?></p>
              <?php if ($l['unidad']): ?><p class="text-xs text-slate-400"><?= e($l['unidad']) ?></p><?php endif; ?>
            </div>
            <div class="col-span-4 text-slate-600 sm:col-span-2 sm:text-right"><span class="sm:hidden">Cant: </span><?= e(rtrim(rtrim(number_format((float)$l['cantidad'], 2, '.', ','), '0'), '.')) ?></div>
            <div class="col-span-4 text-slate-600 sm:col-span-2 sm:text-right"><span class="sm:hidden">x </span><?= e(money($l['precio_unitario'], $emp['moneda'])) ?></div>
            <div class="col-span-4 text-right font-bold text-slate-900 sm:col-span-2"><?= e(money($l['subtotal'], $emp['moneda'])) ?></div>
          </div>
        <?php endforeach; ?>
        <div class="mt-4 flex justify-end">
          <div class="flex items-center gap-6 rounded-xl px-5 py-3" style="background: var(--c2); color: var(--c2-txt)">
            <span class="text-sm font-semibold">TOTAL</span>
            <span class="text-xl font-extrabold"><?= e(money($cot['total'], $emp['moneda'])) ?></span>
          </div>
        </div>

        <div class="mt-6">
          <?php if (!empty($cot['imagen'])): ?>
            <a href="cotizacion_imagen.php?id=<?= (int)$id ?>" data-visor="imagen" data-titulo="Imagen de <?= e($numero) ?>" class="flex h-56 w-full items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 p-2 sm:h-72">
              <img src="cotizacion_imagen.php?id=<?= (int)$id ?>&v=<?= e(substr(md5($cot['imagen']), 0, 6)) ?>" alt="Imagen de la cotizacion" class="block h-full w-full object-contain">
            </a>
          <?php endif; ?>
          <details class="desplegable mt-2">
            <summary class="inline-flex cursor-pointer select-none items-center gap-1.5 text-xs font-semibold texto-marca hover:underline">
              <i data-lucide="image" class="h-4 w-4"></i><?= !empty($cot['imagen']) ? 'Cambiar o quitar imagen' : 'Agregar imagen a la cotizacion (opcional)' ?>
            </summary>
            <form method="post" enctype="multipart/form-data" class="mt-3 flex flex-col gap-3 rounded-xl bg-slate-50 p-4 sm:flex-row sm:items-center">
              <?= csrf_field() ?><input type="hidden" name="accion" value="imagen">
              <input class="input flex-1 bg-white py-1.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-slate-700" type="file" name="imagen" accept="image/jpeg,image/png">
              <div class="flex gap-2">
                <button class="btn btn-primario btn-sm" name="hacer" value="subir"><i data-lucide="upload"></i>Guardar</button>
                <?php if (!empty($cot['imagen'])): ?>
                  <button class="btn btn-claro btn-sm text-rose-600" name="hacer" value="quitar" formnovalidate onclick="return confirm('Quitar la imagen de la cotizacion?')"><i data-lucide="trash-2"></i>Quitar</button>
                <?php endif; ?>
              </div>
            </form>
          </details>
        </div>

        <?php if ($cot['notas']): ?>
          <div class="mt-6">
            <p class="mb-1 text-xs font-bold uppercase tracking-wider text-slate-400">Notas</p>
            <p class="whitespace-pre-line text-sm text-slate-700"><?= e($cot['notas']) ?></p>
          </div>
        <?php endif; ?>
        <?php if ($emp['pie_cotizacion']): ?>
          <p class="mt-6 whitespace-pre-line border-t border-slate-100 pt-4 text-xs text-slate-500"><?= e($emp['pie_cotizacion']) ?></p>
        <?php endif; ?>
      </div>
    </article>

    <!-- Pagos -->
    <section id="pagos" class="card mt-5 overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-7">
        <h2 class="flex items-center gap-2 text-base font-bold text-slate-900"><i data-lucide="wallet" class="h-5 w-5 texto-marca"></i>Control de pagos</h2>
        <?= badge_pago($pagosRes) ?>
      </div>
      <div class="grid grid-cols-3 divide-x divide-slate-100 border-b border-slate-100 text-center">
        <div class="px-2 py-4">
          <p class="text-xs font-semibold text-slate-500">Total</p>
          <p class="mt-0.5 text-sm font-extrabold text-slate-900 sm:text-lg"><?= e(money($pagosRes['total'], $emp['moneda'])) ?></p>
        </div>
        <div class="px-2 py-4">
          <p class="text-xs font-semibold text-slate-500">Pagado</p>
          <p class="mt-0.5 text-sm font-extrabold text-emerald-600 sm:text-lg"><?= e(money($pagosRes['pagado'], $emp['moneda'])) ?></p>
        </div>
        <div class="px-2 py-4">
          <p class="text-xs font-semibold text-slate-500"><?= $pagosRes['saldo'] < 0 ? 'A favor del cliente' : 'Pendiente' ?></p>
          <p class="mt-0.5 text-sm font-extrabold sm:text-lg <?= $pagosRes['saldo'] > 0 ? 'text-rose-600' : 'text-slate-900' ?>"><?= e(money(abs($pagosRes['saldo']), $emp['moneda'])) ?></p>
        </div>
      </div>
      <div class="px-5 pt-4 sm:px-7">
        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100">
          <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: <?= (int)$pagosRes['porcentaje'] ?>%"></div>
        </div>
        <p class="mt-1.5 text-right text-xs text-slate-500"><?= (int)$pagosRes['porcentaje'] ?>% pagado</p>
      </div>

      <?php if ($pagos): ?>
        <ul class="divide-y divide-slate-100 px-5 sm:px-7">
          <?php foreach ($pagos as $pg): ?>
            <li class="flex items-center gap-3 py-3">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><i data-lucide="banknote" class="h-4 w-4"></i></div>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-slate-900"><?= e($pg['descripcion']) ?></p>
                <p class="truncate text-xs text-slate-500">
                  <?= e(fecha($pg['fecha'])) ?><?= $pg['metodo'] ? ' · ' . e($pg['metodo']) : '' ?>
                  <?php if ($pg['adjunto_id']): ?> · <a href="adjunto.php?id=<?= (int)$pg['adjunto_id'] ?>" <?= visor_attrs($pg['adjunto_id'], $pg['adj_mime'] ?? '', 'Comprobante: ' . $pg['descripcion']) ?> class="inline-flex items-center gap-0.5 font-semibold texto-marca hover:underline"><i data-lucide="paperclip" class="h-3 w-3"></i>comprobante</a><?php endif; ?>
                </p>
              </div>
              <p class="shrink-0 text-sm font-bold text-slate-900"><?= e(money($pg['monto'], $emp['moneda'])) ?></p>
              <form method="post" data-confirmar="Eliminar el pago &quot;<?= e($pg['descripcion']) ?>&quot; por <?= e(money($pg['monto'], $emp['moneda'])) ?>?">
                <?= csrf_field() ?><input type="hidden" name="accion" value="quitar_pago"><input type="hidden" name="pago_id" value="<?= (int)$pg['id'] ?>">
                <button class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Eliminar pago"><i data-lucide="trash-2" class="h-4 w-4"></i></button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="px-5 py-4 text-center text-sm text-slate-400 sm:px-7">Aun no hay pagos registrados.</p>
      <?php endif; ?>

      <details class="desplegable border-t border-slate-100" <?= $errorPago ? 'open' : '' ?>>
        <summary class="flex cursor-pointer select-none items-center gap-2 px-5 py-3.5 text-sm font-bold texto-marca hover:bg-slate-50 sm:px-7">
          <i data-lucide="plus-circle" class="h-4 w-4"></i>Registrar pago
          <i data-lucide="chevron-down" class="chevron ml-auto h-4 w-4 text-slate-400"></i>
        </summary>
        <form method="post" enctype="multipart/form-data" class="grid gap-3 bg-slate-50/60 px-5 pb-5 pt-2 sm:grid-cols-2 sm:px-7">
          <?= csrf_field() ?><input type="hidden" name="accion" value="registrar_pago">
          <label class="campo"><span>Descripcion *</span>
            <input class="input bg-white" name="pago_descripcion" list="desc-pagos" maxlength="150" placeholder="Ej: Anticipo" required>
            <datalist id="desc-pagos"><?php foreach (pagos_descripciones_sugeridas() as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
          </label>
          <label class="campo"><span>Monto (<?= e($emp['moneda']) ?>) *</span>
            <input class="input bg-white text-right" name="pago_monto" inputmode="decimal" required placeholder="<?= $pagosRes['saldo'] > 0 ? e(number_format($pagosRes['saldo'], 2, '.', '')) : '0.00' ?>">
            <?php if ($pagosRes['saldo'] > 0): ?><button type="button" class="mt-1 text-xs font-semibold texto-marca hover:underline" onclick="this.previousElementSibling.value='<?= e(number_format($pagosRes['saldo'], 2, '.', '')) ?>'">Usar saldo pendiente</button><?php endif; ?>
          </label>
          <label class="campo"><span>Fecha</span>
            <input class="input bg-white" type="date" name="pago_fecha" value="<?= e(date('Y-m-d')) ?>" required>
          </label>
          <label class="campo"><span>Metodo</span>
            <select class="select bg-white" name="pago_metodo">
              <option value="">—</option>
              <?php foreach (pagos_metodos() as $m): ?><option value="<?= e($m) ?>"><?= e($m) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="campo sm:col-span-2"><span>Comprobante (opcional)</span>
            <input class="input bg-white py-1.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-slate-700" type="file" name="pago_comprobante[]" accept="image/*,.pdf,.heic">
            <p class="ayuda">Se guarda tambien en los archivos adjuntos.</p>
          </label>
          <div class="sm:col-span-2 flex justify-end">
            <button class="btn btn-exito"><i data-lucide="check"></i>Guardar pago</button>
          </div>
        </form>
      </details>
    </section>

    <!-- Adjuntos (desplegable) -->
    <details id="adjuntos" class="desplegable card mt-5 overflow-hidden">
      <summary class="flex cursor-pointer select-none items-center gap-3 px-5 py-4 hover:bg-slate-50 sm:px-7">
        <i data-lucide="paperclip" class="h-5 w-5 texto-marca"></i>
        <span class="text-base font-bold text-slate-900">Archivos adjuntos</span>
        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600"><?= count($adjuntos) ?></span>
        <i data-lucide="chevron-down" class="chevron ml-auto h-5 w-5 text-slate-400"></i>
      </summary>

      <?php if ($adjuntos): ?>
        <ul class="divide-y divide-slate-100 border-t border-slate-100 px-5 sm:px-7">
          <?php foreach ($adjuntos as $a): $img = adjunto_es_imagen($a['mime']); ?>
            <li class="flex items-center gap-3 py-2.5">
              <a href="adjunto.php?id=<?= (int)$a['id'] ?>" <?= visor_attrs($a['id'], $a['mime'], $a['titulo']) ?> class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-100 text-slate-400">
                <?php if ($img): ?>
                  <img src="adjunto.php?id=<?= (int)$a['id'] ?>" alt="" loading="lazy" class="h-full w-full object-cover">
                <?php else: ?>
                  <i data-lucide="<?= adjunto_icono($a['mime']) ?>" class="h-5 w-5"></i>
                <?php endif; ?>
              </a>
              <a href="adjunto.php?id=<?= (int)$a['id'] ?>" <?= visor_attrs($a['id'], $a['mime'], $a['titulo']) ?> class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-slate-900"><?= e($a['titulo']) ?></p>
                <p class="truncate text-xs text-slate-500"><?= e($a['nombre_original']) ?> · <?= e(tamano_legible($a['tamano'])) ?></p>
              </a>
              <a href="adjunto.php?id=<?= (int)$a['id'] ?>&descargar=1" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Descargar"><i data-lucide="download" class="h-4 w-4"></i></a>
              <form method="post" data-confirmar="Eliminar el adjunto &quot;<?= e($a['titulo']) ?>&quot;?">
                <?= csrf_field() ?><input type="hidden" name="accion" value="quitar_adjunto"><input type="hidden" name="adjunto_id" value="<?= (int)$a['id'] ?>">
                <button class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Eliminar"><i data-lucide="trash-2" class="h-4 w-4"></i></button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="border-t border-slate-100 px-5 py-4 text-center text-sm text-slate-400 sm:px-7">Aun no hay archivos.</p>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" class="grid gap-3 border-t border-slate-100 bg-slate-50/60 px-5 py-4 sm:grid-cols-[1fr_1.4fr_auto] sm:items-end sm:px-7" id="form-adjunto">
        <?= csrf_field() ?><input type="hidden" name="accion" value="adjuntar">
        <label class="campo"><span>Titulo *</span>
          <input class="input bg-white" name="titulo" list="titulos-adjunto" maxlength="150" placeholder="Ej: Contrato" required>
          <datalist id="titulos-adjunto">
            <?php foreach (adjuntos_titulos_sugeridos() as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?>
          </datalist>
        </label>
        <label class="campo"><span>Archivo o imagen *</span>
          <input class="input bg-white py-1.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-slate-700" type="file" name="archivos[]" multiple required
                 accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.heic" id="input-adjunto">
        </label>
        <button type="submit" class="btn btn-primario"><i data-lucide="upload"></i>Subir</button>
        <p class="ayuda -mt-1 sm:col-span-3">Imagenes, PDF, Word o Excel. Maximo <?= e(tamano_legible($maxAdj)) ?> por archivo.</p>
      </form>
    </details>
  </div>

  <!-- Acciones -->
  <aside class="min-w-0 space-y-4">
    <div class="card space-y-2 p-5">
      <a href="cotizacion_pdf.php?id=<?= (int)$id ?>" data-visor="pdf" data-titulo="<?= e($numero) ?>" data-descarga="cotizacion_pdf.php?id=<?= (int)$id ?>&amp;descargar=1" class="btn btn-primario w-full"><i data-lucide="file-text"></i>Ver PDF</a>
      <a href="cotizacion_pdf.php?id=<?= (int)$id ?>&descargar=1" class="btn btn-claro w-full"><i data-lucide="download"></i>Descargar PDF</a>
      <?php if ($cot['estado'] === 'pendiente'): ?>
        <a href="cotizacion_form.php?id=<?= (int)$id ?>" class="btn btn-claro w-full"><i data-lucide="pencil"></i>Editar</a>
      <?php endif; ?>
      <div class="grid grid-cols-2 gap-2">
        <a href="#pagos" class="btn btn-claro"><i data-lucide="wallet"></i>Registrar pago</a>
        <a href="#adjuntos" class="btn btn-claro" data-abrir="adjuntos"><i data-lucide="paperclip"></i>Adjuntos (<?= count($adjuntos) ?>)</a>
      </div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="duplicar">
        <button class="btn btn-claro w-full"><i data-lucide="copy"></i>Duplicar</button>
      </form>
    </div>

    <a href="#pagos" class="card block p-5 transition hover:shadow-md">
      <div class="flex items-center justify-between gap-3">
        <h3 class="text-sm font-bold text-slate-900">Pagos</h3>
        <?= badge_pago($pagosRes) ?>
      </div>
      <div class="mt-3 flex items-end justify-between gap-3">
        <div>
          <p class="text-xs text-slate-500">Pagado</p>
          <p class="font-bold text-emerald-600"><?= e(money($pagosRes['pagado'], $emp['moneda'])) ?></p>
        </div>
        <div class="text-right">
          <p class="text-xs text-slate-500">Pendiente</p>
          <p class="text-lg font-extrabold <?= $pagosRes['saldo'] > 0 ? 'text-rose-600' : 'text-slate-900' ?>"><?= e(money(max(0, $pagosRes['saldo']), $emp['moneda'])) ?></p>
        </div>
      </div>
      <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: <?= (int)$pagosRes['porcentaje'] ?>%"></div></div>
    </a>

    <div class="card p-5">
      <h3 class="mb-3 text-sm font-bold text-slate-900">Estado de la cotizacion</h3>

      <?php if ($cot['estado'] !== 'aceptada'): ?>
        <form method="post" class="space-y-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4" data-confirmar="Marcar como aceptada y agendar el evento?">
          <?= csrf_field() ?><input type="hidden" name="accion" value="aceptar">
          <p class="text-xs text-emerald-800">Confirma la fecha del evento. Al aceptar se mostrara en el calendario.</p>
          <div class="grid grid-cols-2 gap-2">
            <input class="input" type="date" name="fecha_evento" value="<?= e($cot['fecha_evento']) ?>" required>
            <input class="input" type="time" name="hora_evento" value="<?= e($cot['hora_evento'] ? substr($cot['hora_evento'], 0, 5) : '') ?>">
          </div>
          <button class="btn btn-exito w-full"><i data-lucide="check-circle-2"></i>Aceptar</button>
        </form>
      <?php else: ?>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
          <p class="font-semibold">Aceptada<?= $cot['fecha_aceptacion'] ? ' el ' . e(fecha($cot['fecha_aceptacion'], true)) : '' ?></p>
          <p>Agendada en el calendario para el <?= e(fecha_larga($cot['fecha_evento'])) ?>.</p>
          <a class="mt-2 inline-flex items-center gap-1 font-semibold hover:underline" href="index.php?fecha=<?= e($cot['fecha_evento']) ?>"><i data-lucide="calendar" class="h-4 w-4"></i>Ver en calendario</a>
        </div>
        <div class="mt-3 rounded-xl border border-slate-200 p-4 text-sm">
          <p class="flex items-center gap-2 font-semibold text-slate-800"><i data-lucide="mail" class="h-4 w-4"></i>Recordatorio por correo</p>
          <?php if (empty($emp['correo'])): ?>
            <p class="mt-1 text-xs text-amber-700">La empresa no tiene correo. Agregalo en <a href="mi_empresa.php" class="font-semibold underline">Mi empresa</a> para recibir el aviso un dia antes.</p>
          <?php elseif ($cot['recordatorio_enviado']): ?>
            <p class="mt-1 text-xs text-slate-500">Enviado a <?= e($emp['correo']) ?> el <?= e(fecha($cot['recordatorio_enviado'], true)) ?>.</p>
          <?php else: ?>
            <p class="mt-1 text-xs text-slate-500">Se enviara a <?= e($emp['correo']) ?> el <?= e(fecha(date('Y-m-d', strtotime($cot['fecha_evento'] . ' -1 day')))) ?>.</p>
          <?php endif; ?>
          <?php if (!empty($emp['correo'])): ?>
            <form method="post" class="mt-2" data-confirmar="Enviar el recordatorio ahora a <?= e($emp['correo']) ?>?">
              <?= csrf_field() ?><input type="hidden" name="accion" value="recordatorio">
              <button class="text-xs font-semibold texto-marca hover:underline"><?= $cot['recordatorio_enviado'] ? 'Reenviar ahora' : 'Enviar ahora' ?></button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="mt-3 flex gap-2">
        <?php if ($cot['estado'] !== 'pendiente'): ?>
          <form method="post" class="flex-1" data-confirmar="Regresar la cotizacion a pendiente?<?= $cot['estado'] === 'aceptada' ? ' Se quitara del calendario.' : '' ?>">
            <?= csrf_field() ?><input type="hidden" name="accion" value="pendiente">
            <button class="btn btn-claro w-full btn-sm"><i data-lucide="rotate-ccw"></i>A pendiente</button>
          </form>
        <?php endif; ?>
        <?php if ($cot['estado'] !== 'rechazada'): ?>
          <form method="post" class="flex-1" data-confirmar="Marcar la cotizacion como rechazada?<?= $cot['estado'] === 'aceptada' ? ' Se quitara del calendario.' : '' ?>">
            <?= csrf_field() ?><input type="hidden" name="accion" value="rechazar">
            <button class="btn btn-claro w-full btn-sm text-rose-600"><i data-lucide="x-circle"></i>Rechazar</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card p-5 text-sm text-slate-600">
      <dl class="space-y-2">
        <div class="flex justify-between gap-3"><dt class="text-slate-400">Creada</dt><dd><?= e(fecha($cot['created_at'], true)) ?></dd></div>
        <?php if ($autor): ?><div class="flex justify-between gap-3"><dt class="text-slate-400">Por</dt><dd><?= e($autor) ?></dd></div><?php endif; ?>
        <?php if ($cot['updated_at']): ?><div class="flex justify-between gap-3"><dt class="text-slate-400">Modificada</dt><dd><?= e(fecha($cot['updated_at'], true)) ?></dd></div><?php endif; ?>
      </dl>
      <?php if ($cot['estado'] !== 'aceptada'): ?>
        <form method="post" class="mt-4 border-t border-slate-100 pt-4" data-confirmar="Eliminar definitivamente esta cotizacion?">
          <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar">
          <button class="inline-flex items-center gap-1.5 text-sm font-semibold text-rose-600 hover:underline"><i data-lucide="trash-2" class="h-4 w-4"></i>Eliminar cotizacion</button>
        </form>
      <?php endif; ?>
    </div>
  </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
