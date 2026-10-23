<?php
/**
 * Recordatorio por correo al negocio un dia antes del evento (cotizaciones aceptadas).
 */
require_once APP_ROOT . '/includes/correo.php';
require_once APP_ROOT . '/includes/pdf_cotizacion.php';

/**
 * Envia el recordatorio de una cotizacion. Devuelve [ok, mensaje].
 */
function enviar_recordatorio($cotId)
{
    $cot = db_one('SELECT * FROM cotizaciones WHERE id = ?', [$cotId]);
    if (!$cot) {
        return [false, 'Cotizacion no encontrada.'];
    }
    $emp = db_one('SELECT * FROM empresas WHERE id = ?', [$cot['empresa_id']]);
    if (empty($emp['correo']) || !filter_var($emp['correo'], FILTER_VALIDATE_EMAIL)) {
        return [false, 'La empresa "' . ($emp['nombre'] ?? '') . '" no tiene un correo valido configurado.'];
    }
    $cliente = db_one('SELECT * FROM clientes WHERE id = ?', [$cot['cliente_id']]);
    $lineas = db_all('SELECT * FROM cotizacion_detalle WHERE cotizacion_id = ? ORDER BY orden, id', [$cotId]);
    $adjuntos = db_all('SELECT titulo, nombre_original FROM cotizacion_adjuntos WHERE cotizacion_id = ? ORDER BY id', [$cotId]);

    $numero = numero_cotizacion($cot, $emp);
    $c1 = $emp['color_primario'];
    $c1t = color_texto($c1);
    $c2 = $emp['color_secundario'];
    $c2t = color_texto($c2);
    $moneda = $emp['moneda'];
    $fechaTxt = fecha_larga($cot['fecha_evento']) . ($cot['hora_evento'] ? ', ' . hora($cot['hora_evento']) : '');
    $diasFaltan = (int)round((strtotime($cot['fecha_evento']) - strtotime(date('Y-m-d'))) / 86400);
    $cuando = $diasFaltan === 1 ? 'mañana' : ($diasFaltan === 0 ? 'hoy' : 'el ' . fecha_larga($cot['fecha_evento']));

    $base = rtrim($GLOBALS['CONFIG']['app']['url'] ?? '', '/');
    $enlace = $base ? $base . '/cotizacion_ver.php?id=' . (int)$cotId : '';

    $filas = '';
    foreach ($lineas as $l) {
        $cant = rtrim(rtrim(number_format((float)$l['cantidad'], 2, '.', ','), '0'), '.');
        $filas .= '<tr>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eef2f7;font-size:14px;color:#0f172a">' . e($l['descripcion']) . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eef2f7;font-size:14px;color:#475569;text-align:right;white-space:nowrap">' . e($cant) . '</td>'
            . '<td style="padding:8px 10px;border-bottom:1px solid #eef2f7;font-size:14px;color:#0f172a;text-align:right;white-space:nowrap;font-weight:bold">' . e(money($l['subtotal'], $moneda)) . '</td>'
            . '</tr>';
    }
    $listaAdj = '';
    if ($adjuntos) {
        $listaAdj = '<p style="margin:18px 0 6px;font-size:12px;font-weight:bold;color:#64748b;text-transform:uppercase;letter-spacing:.05em">Archivos adjuntos en el sistema</p><ul style="margin:0;padding-left:18px;font-size:14px;color:#334155">';
        foreach ($adjuntos as $a) {
            $listaAdj .= '<li>' . e($a['titulo']) . ' <span style="color:#94a3b8">(' . e($a['nombre_original']) . ')</span></li>';
        }
        $listaAdj .= '</ul>';
    }

    $pagadoTotal = (float)db_val('SELECT COALESCE(SUM(monto), 0) FROM cotizacion_pagos WHERE cotizacion_id = ?', [$cotId]);
    $saldoTotal = round((float)$cot['total'] - $pagadoTotal, 2);
    $bloquePagos = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin-top:12px"><tr>'
        . '<td style="font-size:14px;color:#475569">Pagado: <strong style="color:#059669">' . e(money($pagadoTotal, $moneda)) . '</strong></td>'
        . '<td align="right" style="font-size:14px;color:#475569">' . ($saldoTotal > 0 ? 'Pendiente de cobro: <strong style="color:#e11d48">' . e(money($saldoTotal, $moneda)) . '</strong>' : '<strong style="color:#059669">Pagado por completo</strong>') . '</td>'
        . '</tr></table>';

    $dato = function ($k, $v) {
        return $v === null || $v === '' ? '' : '<tr><td style="padding:4px 0;font-size:13px;color:#64748b;width:110px;vertical-align:top">' . e($k) . '</td><td style="padding:4px 0;font-size:14px;color:#0f172a;font-weight:600">' . e($v) . '</td></tr>';
    };

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden">'
        . '<tr><td style="background:' . e($c1) . ';color:' . e($c1t) . ';padding:22px 26px">'
        . '<p style="margin:0;font-size:13px;opacity:.85">' . e($emp['nombre']) . '</p>'
        . '<p style="margin:6px 0 0;font-size:22px;font-weight:bold">Evento ' . e($cuando) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:24px 26px">'
        . '<p style="margin:0 0 16px;font-size:15px;color:#334155">Recordatorio del evento confirmado con la cotización <strong>' . e($numero) . '</strong>.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin-bottom:18px">'
        . $dato('Fecha', ucfirst($fechaTxt))
        . $dato('Lugar', $cot['lugar_evento'])
        . $dato('Cliente', $cliente['nombre'])
        . $dato('Teléfono', $cliente['telefono'])
        . $dato('Correo', $cliente['correo'])
        . '</table>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse">'
        . '<tr><th style="padding:8px 10px;background:#f8fafc;font-size:11px;color:#64748b;text-align:left;text-transform:uppercase">Descripción</th><th style="padding:8px 10px;background:#f8fafc;font-size:11px;color:#64748b;text-align:right;text-transform:uppercase">Cant.</th><th style="padding:8px 10px;background:#f8fafc;font-size:11px;color:#64748b;text-align:right;text-transform:uppercase">Subtotal</th></tr>'
        . $filas
        . '</table>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin-top:14px"><tr><td align="right">'
        . '<span style="display:inline-block;background:' . e($c2) . ';color:' . e($c2t) . ';padding:10px 16px;border-radius:10px;font-size:18px;font-weight:bold">Total ' . e(money($cot['total'], $moneda)) . '</span>'
        . '</td></tr></table>'
        . $bloquePagos
        . ($cot['notas'] ? '<p style="margin:18px 0 6px;font-size:12px;font-weight:bold;color:#64748b;text-transform:uppercase;letter-spacing:.05em">Notas</p><p style="margin:0;font-size:14px;color:#334155;white-space:pre-line">' . e($cot['notas']) . '</p>' : '')
        . $listaAdj
        . ($enlace ? '<p style="margin:24px 0 0"><a href="' . e($enlace) . '" style="display:inline-block;background:' . e($c1) . ';color:' . e($c1t) . ';padding:12px 20px;border-radius:10px;text-decoration:none;font-weight:bold;font-size:14px">Ver cotización en el sistema</a></p>' : '')
        . '</td></tr>'
        . '<tr><td style="padding:14px 26px;background:#f8fafc;font-size:12px;color:#94a3b8">Se adjunta el PDF de la cotización. Correo automático enviado por el sistema de cotizaciones.</td></tr>'
        . '</table></td></tr></table></body></html>';

    $pdf = construir_pdf_cotizacion($cot, $emp, $cliente, $lineas);
    $pdfBin = $pdf->Output('S');

    $asunto = 'Recordatorio: evento ' . $cuando . ' - ' . $cliente['nombre'] . ' (' . $numero . ')';
    list($ok, $err) = enviar_correo(
        $emp['correo'],
        $asunto,
        $html,
        '',
        [['contenido' => $pdfBin, 'nombre' => $numero . '.pdf', 'mime' => 'application/pdf']],
        null,
        $emp['nombre']
    );
    if (!$ok) {
        return [false, 'No se pudo enviar el correo: ' . $err];
    }
    db_exec('UPDATE cotizaciones SET recordatorio_enviado = NOW() WHERE id = ?', [$cotId]);
    return [true, 'Recordatorio enviado a ' . $emp['correo'] . '.'];
}

/**
 * Busca cotizaciones aceptadas cuyo evento es manana y aun no tienen recordatorio.
 * Devuelve lista de lineas de resultado (para el log del cron).
 */
function procesar_recordatorios()
{
    $manana = date('Y-m-d', strtotime('+1 day'));
    $pend = db_all("SELECT c.id FROM cotizaciones c JOIN empresas e ON e.id = c.empresa_id
        WHERE c.estado = 'aceptada' AND c.fecha_evento = ? AND c.recordatorio_enviado IS NULL AND e.activo = 1
        ORDER BY c.id", [$manana]);
    $log = [];
    foreach ($pend as $p) {
        list($ok, $msg) = enviar_recordatorio((int)$p['id']);
        $log[] = date('Y-m-d H:i:s') . ' cotizacion #' . $p['id'] . ' ' . ($ok ? 'OK' : 'ERROR') . ': ' . $msg;
    }
    if (!$pend) {
        $log[] = date('Y-m-d H:i:s') . ' Sin recordatorios pendientes para ' . $manana . '.';
    }
    return $log;
}
