<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/adjuntos.php';
require __DIR__ . '/includes/pagos.php';
require __DIR__ . '/includes/imagen_cotizacion.php';
require __DIR__ . '/includes/proveedores.php';
require __DIR__ . '/includes/whatsapp.php';
require __DIR__ . '/includes/portal.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();
$id = input_int('id');

$cot = db_one('SELECT * FROM cotizaciones WHERE id = ? AND empresa_id = ?', [$id, $eid]);
if (!$cot) {
    flash('error', 'Cotizacion no encontrada.');
    redirect('cotizaciones.php');
}

/* ---------- Mensajes nuevos del cliente (JSON, refresco del chat) ---------- */
if (isset($_GET['mensajes'])) {
    header('Content-Type: application/json; charset=utf-8');
    $rows = db_all('SELECT id, autor, mensaje, created_at FROM cotizacion_mensajes WHERE cotizacion_id = ? AND id > ? ORDER BY id', [$id, input_int('despues')]);
    marcar_mensajes_leidos($id);
    echo json_encode(['estado' => $cot['estado'], 'mensajes' => array_map(function ($m) {
        return ['id' => (int)$m['id'], 'autor' => $m['autor'], 'mensaje' => $m['mensaje'], 'cuando' => fecha($m['created_at'], true)];
    }, $rows)], JSON_UNESCAPED_UNICODE);
    exit;
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

    if ($accion === 'mensaje_cliente') {
        $txt = trim(input('mensaje'));
        if ($txt !== '') {
            $mid = agregar_mensaje($id, $eid, 'negocio', $txt);
            marcar_mensajes_leidos($id);
            if (input('ajax')) {
                header('Content-Type: application/json; charset=utf-8');
                $m = db_one('SELECT id, autor, mensaje, created_at FROM cotizacion_mensajes WHERE id = ?', [$mid]);
                echo json_encode(['ok' => true, 'mensaje' => ['id' => (int)$m['id'], 'autor' => $m['autor'], 'mensaje' => $m['mensaje'], 'cuando' => fecha($m['created_at'], true)]], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        redirect('cotizacion_ver.php?id=' . $id . '#conversacion');
    }

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
        db_exec('UPDATE cotizaciones SET estado = \'pendiente\', fecha_aceptacion = NULL, recordatorio_enviado = NULL, enviada_at = NULL, respuesta_cliente = NULL, respuesta_cliente_at = NULL WHERE id = ? AND empresa_id = ?', [$id, $eid]);
        flash('ok', 'La cotizacion regreso a pendiente y por enviar' . ($cot['estado'] === 'aceptada' ? '; se quito del calendario.' : '.'));
    } elseif ($accion === 'duplicar') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_val('SELECT id FROM empresas WHERE id = ? FOR UPDATE', [$eid]);
            $corr = (int)db_val('SELECT COALESCE(MAX(correlativo), 0) + 1 FROM cotizaciones WHERE empresa_id = ?', [$eid]);
            db_exec('INSERT INTO cotizaciones (empresa_id, correlativo, cliente_id, nombre_evento, usuario_id, fecha, fecha_evento, hora_evento, lugar_evento, notas, subtotal, descuento_id, descuento_nombre, descuento_pct, descuento_monto, total, estado)
                     SELECT empresa_id, ?, cliente_id, nombre_evento, ?, CURDATE(), fecha_evento, hora_evento, lugar_evento, notas, subtotal, descuento_id, descuento_nombre, descuento_pct, descuento_monto, total, \'pendiente\' FROM cotizaciones WHERE id = ?', [$corr, usuario_actual()['id'], $id]);
            $nuevo = (int)$pdo->lastInsertId();
            db_exec('INSERT INTO cotizacion_detalle (cotizacion_id, producto_id, proveedor_id, descripcion, unidad, cantidad, precio_unitario, subtotal, comision_pct, costo_proveedor, orden)
                     SELECT ?, producto_id, proveedor_id, descripcion, unidad, cantidad, precio_unitario, subtotal, comision_pct, costo_proveedor, orden FROM cotizacion_detalle WHERE cotizacion_id = ?', [$nuevo, $id]);
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
    } elseif ($accion === 'wa_enviar' && $cot['estado'] === 'aceptada') {
        if (!wa_configurado()) {
            flash('error', 'WhatsApp aun no esta configurado.');
        } else {
            $quien = input('destino'); // todos | cliente | proveedor:ID
            $ok = 0;
            $fallos = [];
            foreach (wa_destinatarios($cot) as $d) {
                if ($quien !== 'todos' && $quien !== $d['tipo'] && $quien !== $d['tipo'] . ':' . $d['id']) {
                    continue;
                }
                $r = wa_enviar_recordatorio($cot, $d, 'manual', true);
                if ($r['estado'] === 'enviado') {
                    $ok++;
                } else {
                    $fallos[] = $r['detalle'];
                }
            }
            if ($ok) {
                flash('ok', 'Recordatorio de WhatsApp enviado a ' . $ok . ' destinatario(s).');
            }
            foreach ($fallos as $f) {
                flash('error', 'WhatsApp: ' . $f);
            }
        }
        redirect('cotizacion_ver.php?id=' . $id . '#whatsapp');
    } elseif ($accion === 'enviar_correo_cliente') {
        require_once APP_ROOT . '/includes/correo.php';
        require_once APP_ROOT . '/includes/pdf_cotizacion.php';
        $cli = db_one('SELECT * FROM clientes WHERE id = ?', [$cot['cliente_id']]);
        if (empty($cli['correo']) || !filter_var($cli['correo'], FILTER_VALIDATE_EMAIL)) {
            flash('error', 'El cliente no tiene un correo valido.');
        } else {
            $lin = db_all('SELECT * FROM cotizacion_detalle WHERE cotizacion_id = ? ORDER BY orden, id', [$id]);
            $num = numero_cotizacion($cot, $emp);
            $pdfBin = construir_pdf_cotizacion($cot, $emp, $cli, $lin)->Output('S');
            $c1 = $emp['color_primario'];
            $html = '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;color:#334155">'
                . '<div style="background:' . e($c1) . ';color:' . e(color_texto($c1)) . ';padding:18px 22px;border-radius:12px 12px 0 0;font-size:18px;font-weight:bold">' . e($emp['nombre']) . '</div>'
                . '<div style="border:1px solid #e2e8f0;border-top:0;border-radius:0 0 12px 12px;padding:22px">'
                . '<p>Hola ' . e($cli['nombre']) . ',</p>'
                . '<p>Te compartimos la cotizacion <strong>' . e($num) . '</strong> para tu evento del <strong>' . e(fecha_larga($cot['fecha_evento'])) . '</strong> por un total de <strong>' . e(money($cot['total'], $emp['moneda'])) . '</strong>.</p>'
                . '<p>Encontraras el detalle en el PDF adjunto. Tambien puedes verla, aceptarla o solicitar cambios en linea:</p>'
                . '<p style="margin:18px 0"><a href="' . e(enlace_portal($id)) . '" style="background:' . e($c1) . ';color:' . e(color_texto($c1)) . ';padding:11px 18px;border-radius:10px;text-decoration:none;font-weight:bold;display:inline-block">Ver mi cotizacion</a></p>'
                . '<p style="margin-top:22px;color:#64748b;font-size:13px">' . e($emp['nombre']) . ($emp['telefono'] ? ' · ' . e($emp['telefono']) : '') . ($emp['correo'] ? ' · ' . e($emp['correo']) : '') . '</p>'
                . '</div></div>';
            list($okMail, $errMail) = enviar_correo($cli['correo'], 'Cotizacion ' . $num . ' - ' . $emp['nombre'], $html, '',
                [['contenido' => $pdfBin, 'nombre' => $num . '.pdf', 'mime' => 'application/pdf']], $emp['correo'] ?: null, $emp['nombre']);
            if ($okMail) {
                db_exec('UPDATE cotizaciones SET enviada_at = COALESCE(enviada_at, NOW()) WHERE id = ? AND empresa_id = ?', [$id, $eid]);
                flash('ok', 'Cotizacion enviada en PDF a ' . $cli['correo'] . ' y marcada como enviada.');
            } else {
                flash('error', 'No se pudo enviar el correo: ' . $errMail);
            }
        }
    } elseif ($accion === 'prov_chat_ajax') {
        $pid = input_int('proveedor_id');
        if (db_val('SELECT id FROM proveedores WHERE id = ? AND empresa_id = ?', [$pid, $eid])) {
            registrar_recordatorio_chat_proveedor($cot, $pid, wa_telefono(db_val('SELECT telefono FROM proveedores WHERE id = ?', [$pid])));
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    } elseif ($accion === 'enviada_ajax') {
        db_exec('UPDATE cotizaciones SET enviada_at = COALESCE(enviada_at, NOW()) WHERE id = ? AND empresa_id = ?', [$id, $eid]);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    } elseif ($accion === 'enviada') {
        db_exec('UPDATE cotizaciones SET enviada_at = NOW() WHERE id = ? AND empresa_id = ?', [$id, $eid]);
        flash('ok', 'Cotizacion marcada como enviada al cliente.');
    } elseif ($accion === 'no_enviada') {
        db_exec('UPDATE cotizaciones SET enviada_at = NULL WHERE id = ? AND empresa_id = ?', [$id, $eid]);
        flash('ok', 'Se quito la marca de enviada.');
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
$provRes = resumen_proveedores_cotizacion($id);
$waEnvios = db_all('SELECT * FROM whatsapp_envios WHERE cotizacion_id = ? ORDER BY COALESCE(updated_at, created_at) DESC', [$id]);
$waDest = $cot['estado'] === 'aceptada' ? wa_destinatarios($cot) : [];
$recProv = recordatorios_proveedores_chat($cot, $emp);
$telWa = preg_replace('/\D/', '', (string)($cliente['telefono'] ?? ''));
if (strlen($telWa) === 8) {
    $telWa = '502' . $telWa; // Guatemala
}
$mensajes = mensajes_cotizacion($id);
$sinLeer = count(array_filter($mensajes, function ($m) { return $m['autor'] === 'cliente' && !$m['leido']; }));
marcar_mensajes_leidos($id);
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
  <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-5">
    <details id="detalle" class="desplegable card overflow-hidden" data-abierto-pc <?= $sinLeer ? 'open' : '' ?>>
      <summary class="flex cursor-pointer select-none items-center gap-3 px-5 py-4 hover:bg-slate-50 sm:px-6">
        <i data-lucide="file-text" class="h-5 w-5 shrink-0 texto-marca"></i>
        <span class="min-w-0">
          <span class="block text-base font-bold text-slate-900">Detalle de la cotizacion</span>
          <span class="block truncate text-xs text-slate-500"><?= e($cliente['nombre']) ?> · <?= e(money($cot['total'], $emp['moneda'])) ?><?= $mensajes ? ' · ' . count($mensajes) . ' mensaje' . (count($mensajes) > 1 ? 's' : '') : '' ?></span>
        </span>
        <?php if ($sinLeer): ?><span class="shrink-0 rounded-full bg-rose-600 px-2 py-0.5 text-[11px] font-bold text-white"><?= $sinLeer ?> nuevo<?= $sinLeer > 1 ? 's' : '' ?></span><?php endif; ?>
        <i data-lucide="chevron-down" class="chevron ml-auto h-5 w-5 shrink-0 text-slate-400"></i>
      </summary>
    <article class="overflow-hidden border-t border-slate-100">
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
          <div class="mt-2 flex flex-wrap gap-1 sm:justify-end"><?= badge_estado($cot['estado']) ?><?= badge_respuesta_cliente($cot) ?></div>
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
          <?php if (!empty($cot['nombre_evento'])): ?><p class="font-extrabold texto-marca"><?= e($cot['nombre_evento']) ?></p><?php endif; ?>
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
        <?php if (cot_tiene_descuento($cot)): ?>
          <div class="mt-4 flex justify-end">
            <div class="w-full max-w-sm space-y-1.5 px-1 text-sm">
              <div class="flex justify-between gap-4 text-slate-600"><span>Sub total</span><span class="font-bold text-slate-900"><?= e(money(cot_subtotal($cot), $emp['moneda'])) ?></span></div>
              <div class="flex justify-between gap-4 text-rose-600"><span><?= e(cot_texto_descuento($cot)) ?></span><span class="shrink-0 font-bold">- <?= e(money($cot['descuento_monto'], $emp['moneda'])) ?></span></div>
            </div>
          </div>
        <?php endif; ?>
        <div class="<?= cot_tiene_descuento($cot) ? 'mt-2' : 'mt-4' ?> flex justify-end">
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

    <!-- Conversacion con el cliente -->
    <section id="conversacion" class="overflow-hidden border-t-4 border-slate-100" data-chat-poll="cotizacion_ver.php?id=<?= (int)$id ?>&amp;mensajes=1" data-chat-cliente="<?= e($cliente['nombre']) ?>" data-chat-empresa="<?= e($emp['nombre']) ?>" data-chat-ultimo="<?= $mensajes ? (int)end($mensajes)['id'] : 0 ?>">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4 sm:px-7">
        <h2 class="flex items-center gap-2 text-base font-bold text-slate-900"><i data-lucide="messages-square" class="h-5 w-5 texto-marca"></i>Conversacion con el cliente
          <?php if ($sinLeer): ?><span class="whitespace-nowrap rounded-full bg-rose-600 px-2 py-0.5 text-[11px] font-bold text-white"><?= $sinLeer ?> nuevo<?= $sinLeer > 1 ? 's' : '' ?></span><?php endif; ?>
        </h2>
        <span class="text-xs text-slate-400">El cliente lo ve en su enlace</span>
      </div>
      <div class="max-h-96 space-y-3 overflow-y-auto bg-slate-50/60 px-4 py-4 sm:px-6" id="chat-lista">
        <p class="py-4 text-center text-sm text-slate-400 <?= $mensajes ? 'hidden' : '' ?>" id="chat-vacio">Aun no hay mensajes. Cuando el cliente solicite cambios desde su enlace, apareceran aqui.</p>
        <?php foreach ($mensajes as $m): ?><?= burbuja_chat($m, 'negocio', $cliente['nombre'], $emp['nombre'], fecha($m['created_at'], true)) ?><?php endforeach; ?>
      </div>
      <?php if ($cot['estado'] === 'pendiente'): ?>
        <form method="post" class="flex items-end gap-2 border-t border-slate-100 p-3 sm:px-5" id="chat-form" data-sin-bloqueo>
          <?= csrf_field() ?><input type="hidden" name="accion" value="mensaje_cliente">
          <textarea name="mensaje" rows="1" maxlength="2000" required class="textarea max-h-32 min-h-[2.75rem] flex-1 resize-none py-2.5" placeholder="Responde al cliente..." id="chat-texto"></textarea>
          <button class="btn btn-primario h-11 w-11 shrink-0 p-0" aria-label="Enviar"><i data-lucide="send-horizontal"></i></button>
        </form>
        <p class="border-t border-slate-100 px-5 py-2.5 text-[11px] text-slate-500 sm:px-7"><i data-lucide="info" class="mr-1 inline h-3.5 w-3.5 align-[-2px]"></i>Para hacer los cambios, <a href="cotizacion_form.php?id=<?= (int)$id ?>" class="font-semibold texto-marca hover:underline">edita la cotizacion</a>: el cliente vera la version nueva en el mismo enlace.</p>
      <?php endif; ?>
    </section>
    </details>

    <!-- Pagos -->
    <details id="pagos" class="desplegable card overflow-hidden" data-abierto-pc <?= $errorPago ? 'open' : '' ?>>
      <summary class="flex cursor-pointer select-none items-center gap-3 px-5 py-4 hover:bg-slate-50 sm:px-6">
        <i data-lucide="wallet" class="h-5 w-5 shrink-0 texto-marca"></i>
        <span class="min-w-0">
          <span class="block text-base font-bold text-slate-900">Control de pagos</span>
          <span class="block truncate text-xs text-slate-500">Pagado <?= e(money($pagosRes['pagado'], $emp['moneda'])) ?> · Pendiente <?= e(money(max(0, $pagosRes['saldo']), $emp['moneda'])) ?></span>
        </span>
        <span class="ml-auto shrink-0"><?= badge_pago($pagosRes) ?></span>
        <i data-lucide="chevron-down" class="chevron h-5 w-5 shrink-0 text-slate-400"></i>
      </summary>
      <div class="border-t border-slate-100"></div>
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
    </details>

    <!-- Adjuntos (desplegable) -->
    <details id="adjuntos" class="desplegable card overflow-hidden">
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
    <details id="opciones" class="desplegable card overflow-hidden" data-abierto-pc>
      <summary class="flex cursor-pointer select-none items-center gap-3 px-5 py-4 hover:bg-slate-50 sm:px-6">
        <i data-lucide="sliders-horizontal" class="h-5 w-5 shrink-0 texto-marca"></i>
        <span class="text-base font-bold text-slate-900">Opciones</span>
        <i data-lucide="chevron-down" class="chevron ml-auto h-5 w-5 shrink-0 text-slate-400"></i>
      </summary>
    <div class="space-y-2 border-t border-slate-100 p-5">
      <a href="cotizacion_pdf.php?id=<?= (int)$id ?>" data-visor="pdf" data-titulo="<?= e($numero) ?>" data-descarga="cotizacion_pdf.php?id=<?= (int)$id ?>&amp;descargar=1" class="btn btn-primario w-full"><i data-lucide="file-text"></i>Ver PDF</a>
      <a href="cotizacion_pdf.php?id=<?= (int)$id ?>&descargar=1" class="btn btn-claro w-full"><i data-lucide="download"></i>Descargar PDF</a>
      <?php if ($cot['estado'] === 'pendiente'): ?>
        <a href="cotizacion_form.php?id=<?= (int)$id ?>" class="btn btn-claro w-full"><i data-lucide="pencil"></i>Editar</a>
      <?php endif; ?>
      <?php if ($cot['estado'] === 'aceptada'): ?>
        <a href="liquidacion.php?id=<?= (int)$id ?>" class="btn btn-claro w-full"><i data-lucide="clipboard-check"></i>Reporte de liquidacion</a>
      <?php endif; ?>
      <div class="grid grid-cols-2 gap-2">
        <a href="#pagos" class="btn btn-claro"><i data-lucide="wallet"></i>Registrar pago</a>
        <a href="#adjuntos" class="btn btn-claro" data-abrir="adjuntos"><i data-lucide="paperclip"></i>Adjuntos (<?= count($adjuntos) ?>)</a>
      </div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="duplicar">
        <button class="btn btn-claro w-full"><i data-lucide="copy"></i>Duplicar</button>
      </form>
    </div>

    <a href="#pagos" class="block border-t border-slate-100 p-5 transition hover:bg-slate-50">
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
    </details>

    <!-- Envio al cliente -->
    <?php
      $msgConEnlace = mensaje_portal_cliente($cot, $emp, $cliente);
      $msgCliente = $msgConEnlace;
      $enlacePortal = enlace_portal($id);
      $waLink = 'https://wa.me/' . ($telWa ?: '') . '?text=' . rawurlencode($msgConEnlace);
    ?>
    <details class="desplegable card overflow-hidden" id="envio-cliente" data-abierto-pc>
      <summary class="flex cursor-pointer select-none items-center gap-3 px-5 py-4 hover:bg-slate-50 sm:px-6">
        <i data-lucide="send" class="h-5 w-5 shrink-0 texto-marca"></i>
        <span class="text-base font-bold text-slate-900">Enviar al cliente</span>
        <span class="ml-auto shrink-0">
        <?php if ($cot['enviada_at']): ?>
          <span class="inline-flex items-center gap-1 rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-semibold text-sky-800"><i data-lucide="send" class="h-3 w-3"></i>Enviada</span>
        <?php else: ?>
          <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800">Por enviar</span>
        <?php endif; ?>
        </span>
        <i data-lucide="chevron-down" class="chevron h-5 w-5 shrink-0 text-slate-400"></i>
      </summary>
      <div class="border-t border-slate-100 p-5 pt-3">
      <?php if ($cot['enviada_at']): ?>
        <p class="mt-1 text-xs text-slate-500">Enviada el <?= e(fecha($cot['enviada_at'], true)) ?>.</p>
      <?php endif; ?>

      <div class="mt-3 space-y-2">
        <a href="<?= e($waLink) ?>" target="_blank" rel="noopener" class="btn btn-exito w-full" data-marcar-enviada>
          <i data-lucide="message-circle"></i><?= $telWa ? 'Enviar por WhatsApp a ' . e($cliente['telefono']) : 'Enviar por WhatsApp' ?>
        </a>
        <?php if (!$telWa): ?><p class="text-[11px] text-amber-700">El cliente no tiene telefono: WhatsApp te pedira elegir el contacto. <a href="clientes.php?accion=editar&id=<?= (int)$cliente['id'] ?>" class="font-semibold underline">Agregar telefono</a></p><?php endif; ?>
        <?php if (!empty($cliente['correo'])): ?>
          <form method="post" data-confirmar="Enviar la cotizacion en PDF a <?= e($cliente['correo']) ?>?">
            <?= csrf_field() ?><input type="hidden" name="accion" value="enviar_correo_cliente">
            <button class="btn btn-claro w-full"><i data-lucide="mail"></i>Enviar PDF por correo</button>
          </form>
        <?php endif; ?>
      </div>
      <p class="mt-2 text-[11px] text-slate-400">Abre el chat del cliente con un mensaje y su enlace: ahi puede ver, descargar, aceptar (eligiendo fecha y hora) o pedir cambios. Se marca como enviada.</p>
      <div class="mt-2 flex items-center gap-1.5 rounded-xl bg-slate-50 p-1.5 pl-3 ring-1 ring-slate-200">
        <i data-lucide="link" class="h-3.5 w-3.5 shrink-0 text-slate-400"></i>
        <span class="min-w-0 flex-1 truncate text-xs font-semibold text-slate-700" id="enlace-portal"><?= e(preg_replace('#^https?://#', '', $enlacePortal)) ?></span>
        <button type="button" class="rounded-lg bg-white px-2.5 py-1 text-xs font-bold text-slate-700 shadow-sm ring-1 ring-slate-200 hover:bg-slate-100" data-copiar="<?= e($enlacePortal) ?>">Copiar</button>
        <a href="<?= e($enlacePortal) ?>" target="_blank" rel="noopener" class="rounded-lg p-1 text-slate-500 hover:bg-white" title="Ver como el cliente"><i data-lucide="external-link" class="h-4 w-4"></i></a>
      </div>
      <?php if ($cot['estado'] === 'pendiente' && !empty($cot['respuesta_cliente_at']) && in_array($cot['respuesta_cliente'], ['vista', 'cambios'], true)): ?>
        <p class="mt-2 flex items-center gap-1 text-[11px] font-semibold text-emerald-700"><i data-lucide="eye" class="h-3.5 w-3.5"></i>El cliente abrio su enlace (<?= e(fecha($cot['respuesta_cliente_at'], true)) ?>)</p>
      <?php endif; ?>
      <button type="button" class="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold texto-marca hover:underline" id="btn-compartir-pdf"
              data-pdf="cotizacion_pdf.php?id=<?= (int)$id ?>&amp;descargar=1" data-nombre="<?= e($numero) ?>.pdf"
              data-texto="<?= e($msgCliente) ?>" data-wa="<?= e($waLink) ?>">
        <i data-lucide="paperclip" class="h-3.5 w-3.5"></i>Prefiero compartir el archivo PDF
      </button>
      <p class="hidden" id="ayuda-compartir"></p>

      <div class="mt-3 border-t border-slate-100 pt-3">
        <?php if ($cot['enviada_at']): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="no_enviada">
            <button class="text-xs font-semibold text-slate-500 hover:underline">Desmarcar como enviada</button>
          </form>
        <?php else: ?>
          <form method="post" id="form-marcar-enviada"><?= csrf_field() ?><input type="hidden" name="accion" value="enviada">
            <button class="text-xs font-semibold text-slate-500 hover:underline">Ya la envie por otro medio: marcar como enviada</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    </details>

    <?php if ($recProv): ?>
    <!-- Recordatorio a proveedores por WhatsApp (un chat por proveedor con sus productos) -->
    <details class="desplegable card overflow-hidden" id="recordatorio-proveedores" style="scroll-margin-top:5rem" data-abierto-pc>
      <summary class="flex cursor-pointer select-none items-center gap-3 px-5 py-4 hover:bg-slate-50 sm:px-6"><i data-lucide="truck" class="h-5 w-5 shrink-0 texto-marca"></i><span class="text-base font-bold text-slate-900">Recordatorio a proveedores</span><span class="ml-auto shrink-0 text-xs text-slate-500"><?= count(array_filter($recProv, function ($r) { return $r['enviado']; })) ?>/<?= count($recProv) ?> enviados</span><i data-lucide="chevron-down" class="chevron h-5 w-5 shrink-0 text-slate-400"></i></summary>
      <div class="border-t border-slate-100">
      <?php if ($cot['estado'] !== 'aceptada'): ?>
        <p class="bg-amber-50 px-5 py-2 text-[11px] text-amber-800">La cotizacion aun no esta aceptada.</p>
      <?php endif; ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($recProv as $rp): ?>
          <li class="px-5 py-3" data-prov-rec="<?= (int)$rp['id'] ?>">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-slate-900"><?= e($rp['nombre']) ?></p>
                <p class="text-xs <?= $rp['tel_wa'] ? 'text-slate-500' : 'font-semibold text-amber-700' ?>"><?= $rp['tel_wa'] ? 'Tel: ' . e($rp['telefono']) : 'Sin telefono' ?></p>
              </div>
              <?php if ($rp['enviado']): ?>
                <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700" data-estado>Enviado <?= e(date('d/m H:i', strtotime($rp['enviado']))) ?></span>
              <?php else: ?>
                <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600" data-estado>Pendiente</span>
              <?php endif; ?>
            </div>
            <ul class="mt-1.5 space-y-0.5 text-xs text-slate-600">
              <?php foreach ($rp['items'] as $it): ?><li><?= e($it) ?></li><?php endforeach; ?>
            </ul>
            <div class="mt-2 flex flex-wrap gap-2">
              <a href="<?= e($rp['enlace']) ?>" target="_blank" rel="noopener" class="btn btn-exito btn-sm" data-wa-proveedor="<?= (int)$rp['id'] ?>">
                <i data-lucide="message-circle"></i><?= $rp['enviado'] ? 'Reenviar' : 'Enviar' ?> por WhatsApp
              </a>
              <?php if (!$rp['tel_wa']): ?>
                <a href="proveedores.php?accion=editar&id=<?= (int)$rp['id'] ?>" class="btn btn-claro btn-sm"><i data-lucide="phone"></i>Agregar telefono</a>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="border-t border-slate-100 px-5 py-2.5 text-[11px] text-slate-400">Cada boton abre el chat de ese proveedor con su lista de productos, la fecha y el lugar del evento. Solo presiona enviar.</p>
    </div>
    </details>
    <?php endif; ?>

    <?php if ($cot['estado'] === 'aceptada'): ?>
    <!-- Recordatorios por WhatsApp -->
    <div class="card p-5" id="whatsapp" style="scroll-margin-top:5rem">
      <div class="flex items-center justify-between gap-3">
        <h3 class="flex items-center gap-2 text-sm font-bold text-slate-900"><i data-lucide="message-circle" class="h-4 w-4 text-emerald-600"></i>WhatsApp</h3>
        <span class="text-[11px] text-slate-400"><?= e(implode(' y ', array_map('wa_etiqueta_tipo', array_map(function ($d) { return $d . 'dias'; }, wa_config()['dias'])))) ?></span>
      </div>
      <?php if (!wa_configurado()): ?>
        <p class="mt-2 text-xs text-slate-500">Los recordatorios automaticos al cliente y a proveedores se activaran cuando se configure la cuenta de WhatsApp.</p>
      <?php endif; ?>
      <ul class="mt-3 space-y-1.5 text-sm">
        <?php foreach ($waDest as $d): ?>
          <li class="flex items-center justify-between gap-2">
            <span class="min-w-0 truncate text-slate-700"><?= e($d['nombre']) ?> <span class="text-xs text-slate-400"><?= $d['tipo'] === 'cliente' ? 'cliente' : 'proveedor' ?></span></span>
            <?php if ($d['telefono']): ?>
              <span class="shrink-0 text-xs text-slate-500">+<?= e($d['telefono']) ?></span>
            <?php else: ?>
              <span class="shrink-0 text-xs font-semibold text-amber-700">Sin telefono</span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($waEnvios): ?>
        <div class="mt-3 border-t border-slate-100 pt-3">
          <p class="mb-1.5 text-xs font-bold uppercase tracking-wide text-slate-400">Enviados</p>
          <ul class="space-y-1.5 text-xs">
            <?php foreach ($waEnvios as $w): ?>
              <li class="flex items-start justify-between gap-2" title="<?= e($w['error'] ?? '') ?>">
                <span class="min-w-0 text-slate-600"><?= e(wa_etiqueta_tipo($w['tipo'])) ?> · <?= $w['destinatario_tipo'] === 'cliente' ? 'Cliente' : 'Proveedor' ?> · <?= e(fecha($w['updated_at'] ?: $w['created_at'], true)) ?></span>
                <?= $w['estado'] === 'enviado' ? '<span class="shrink-0 font-semibold text-emerald-600">Enviado</span>' : '<span class="shrink-0 font-semibold text-rose-600">Error</span>' ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?php if (wa_configurado() && $waDest): ?>
        <form method="post" class="mt-3 flex gap-2" data-confirmar="Enviar el recordatorio por WhatsApp ahora?">
          <?= csrf_field() ?><input type="hidden" name="accion" value="wa_enviar">
          <select class="select flex-1 py-1.5 text-sm" name="destino">
            <option value="todos">A todos</option>
            <option value="cliente">Solo al cliente</option>
            <?php foreach ($waDest as $d): if ($d['tipo'] !== 'proveedor') continue; ?>
              <option value="proveedor:<?= (int)$d['id'] ?>">Solo a <?= e($d['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-exito btn-sm"><i data-lucide="send"></i>Enviar</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($provRes['filas']): ?>
    <details class="desplegable card overflow-hidden" data-abierto-pc>
      <summary class="flex cursor-pointer select-none items-center gap-3 px-5 py-4 hover:bg-slate-50 sm:px-6"><i data-lucide="hand-coins" class="h-5 w-5 shrink-0 texto-marca"></i><span class="min-w-0"><span class="block text-base font-bold text-slate-900">Proveedores y ganancia</span><span class="block text-xs text-slate-500">Ganancia <?= e(money($cot['total'] - $provRes['costo'], $emp['moneda'])) ?> · no sale en el PDF</span></span><span class="ml-auto"></span><i data-lucide="chevron-down" class="chevron h-5 w-5 shrink-0 text-slate-400"></i></summary>
      <div class="border-t border-slate-100 p-5 pt-1">
      <ul class="mt-3 space-y-2 text-sm">
        <?php foreach ($provRes['filas'] as $pf): ?>
          <li class="flex items-start justify-between gap-2">
            <a href="proveedor_ver.php?id=<?= (int)$pf['proveedor_id'] ?>&cotizacion_id=<?= (int)$id ?>#abono" class="min-w-0 truncate text-slate-700 hover:underline"><?= e($pf['nombre']) ?></a>
            <span class="shrink-0 text-right text-xs">
              <span class="font-semibold text-slate-900">Pagar <?= e(money($pf['costo'], $emp['moneda'])) ?></span><br>
              <span class="<?= $pf['pagado'] >= $pf['costo'] ? 'text-emerald-600' : 'text-slate-500' ?>">abonado <?= e(money($pf['pagado'], $emp['moneda'])) ?></span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-sm">
        <div class="flex justify-between"><span class="text-slate-500">Costo proveedores</span><span class="font-semibold"><?= e(money($provRes['costo'], $emp['moneda'])) ?></span></div>
        <div class="flex justify-between"><span class="text-slate-500">Tu ganancia</span><span class="font-extrabold <?= $cot['total'] - $provRes['costo'] < 0 ? 'text-rose-600' : 'text-emerald-600' ?>"><?= e(money($cot['total'] - $provRes['costo'], $emp['moneda'])) ?></span></div>
      </div>
      <?php if ($cot['estado'] !== 'aceptada'): ?><p class="mt-2 text-[11px] text-amber-700">El adeudo con los proveedores se genera al aceptar la cotizacion.</p><?php endif; ?>
    </div>
    </details>
    <?php endif; ?>

    <details class="desplegable card overflow-hidden" id="estado" data-abierto-pc>
      <summary class="flex cursor-pointer select-none items-center gap-3 px-5 py-4 hover:bg-slate-50 sm:px-6"><i data-lucide="flag" class="h-5 w-5 shrink-0 texto-marca"></i><span class="text-base font-bold text-slate-900">Estado</span><span class="ml-auto shrink-0"><?= badge_estado($cot['estado']) ?></span><i data-lucide="chevron-down" class="chevron h-5 w-5 shrink-0 text-slate-400"></i></summary>
      <div class="border-t border-slate-100 p-5">

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
          <form method="post" class="flex-1" data-confirmar="Regresar la cotizacion a pendiente? Quedara como por enviar.<?= $cot['estado'] === 'aceptada' ? ' Se quitara del calendario.' : '' ?>">
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
    </details>

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
