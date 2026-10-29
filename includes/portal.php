<?php
/**
 * Portal del cliente: enlace corto, respuestas (aceptar / cambios / rechazar) y conversacion.
 */

/** Codigo corto publico de la cotizacion (se crea la primera vez). */
function codigo_corto_cotizacion($cotId)
{
    $c = db_val('SELECT codigo_corto FROM cotizaciones WHERE id = ?', [$cotId]);
    if ($c) {
        return $c;
    }
    $alfabeto = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    for ($intento = 0; $intento < 8; $intento++) {
        $c = '';
        for ($i = 0; $i < 7; $i++) {
            $c .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        try {
            db_exec('UPDATE cotizaciones SET codigo_corto = ? WHERE id = ? AND codigo_corto IS NULL', [$c, $cotId]);
        } catch (Exception $e) {
            continue; // choque con otro codigo: intentar de nuevo
        }
        $guardado = db_val('SELECT codigo_corto FROM cotizaciones WHERE id = ?', [$cotId]);
        if ($guardado) {
            return $guardado;
        }
    }
    throw new RuntimeException('No se pudo generar el enlace de la cotizacion.');
}

/** Enlace corto que se comparte con el cliente: https://dominio/?c=Ab3xY7k */
function enlace_portal($cotId)
{
    return url_base() . '/?c=' . codigo_corto_cotizacion($cotId);
}

/** Texto de WhatsApp para el cliente (sin total). */
function mensaje_portal_cliente($cot, $emp, $cliente)
{
    $num = numero_cotizacion($cot, $emp);
    $evento = !empty($cot['nombre_evento']) ? ' para "' . $cot['nombre_evento'] . '"' : '';
    return 'Hola ' . primer_nombre($cliente['nombre']) . ', te saluda ' . $emp['nombre'] . ".\n"
        . 'Te compartimos tu cotizacion ' . $num . $evento . ".\n\n"
        . "En este enlace puedes verla, descargarla, aceptarla o solicitar cambios:\n"
        . enlace_portal($cot['id']);
}

function primer_nombre($nombre)
{
    $p = preg_split('/\s+/', trim((string)$nombre));
    return $p[0] ?? (string)$nombre;
}

/** Agrega un mensaje a la conversacion. Los del cliente quedan "sin leer" para el negocio. */
function agregar_mensaje($cotId, $eid, $autor, $texto)
{
    $texto = trim((string)$texto);
    if ($texto === '') {
        return 0;
    }
    db_exec('INSERT INTO cotizacion_mensajes (cotizacion_id, empresa_id, autor, mensaje, leido) VALUES (?, ?, ?, ?, ?)', [
        $cotId, $eid, $autor, mb_substr($texto, 0, 2000), $autor === 'cliente' ? 0 : 1,
    ]);
    return (int)db()->lastInsertId();
}

function mensajes_cotizacion($cotId)
{
    return db_all('SELECT id, autor, mensaje, leido, created_at FROM cotizacion_mensajes WHERE cotizacion_id = ? ORDER BY id', [$cotId]);
}

function marcar_mensajes_leidos($cotId)
{
    db_exec("UPDATE cotizacion_mensajes SET leido = 1 WHERE cotizacion_id = ? AND autor = 'cliente' AND leido = 0", [$cotId]);
}

/** Cotizaciones con mensajes del cliente sin leer (para la campanita). */
function cotizaciones_con_mensajes($eid)
{
    return db_all("SELECT c.id, c.correlativo, c.nombre_evento, c.respuesta_cliente, cl.nombre AS cliente, COUNT(m.id) AS sin_leer, MAX(m.created_at) AS ultimo,
            (SELECT m2.mensaje FROM cotizacion_mensajes m2 WHERE m2.cotizacion_id = c.id AND m2.autor = 'cliente' ORDER BY m2.id DESC LIMIT 1) AS ultimo_texto
        FROM cotizacion_mensajes m
        JOIN cotizaciones c ON c.id = m.cotizacion_id
        JOIN clientes cl ON cl.id = c.cliente_id
        WHERE m.empresa_id = ? AND m.autor = 'cliente' AND m.leido = 0
        GROUP BY c.id, c.correlativo, c.nombre_evento, c.respuesta_cliente, cl.nombre
        ORDER BY ultimo DESC", [$eid]);
}

/** Aviso por correo al negocio cuando el cliente responde (si hay correo configurado). Nunca rompe el flujo. */
function avisar_negocio($cot, $emp, $asunto, $texto)
{
    if (empty($emp['correo']) || !filter_var($emp['correo'], FILTER_VALIDATE_EMAIL)) {
        return;
    }
    try {
        require_once APP_ROOT . '/includes/correo.php';
        $c1 = $emp['color_primario'];
        $url = url_base() . '/cotizacion_ver.php?id=' . (int)$cot['id'] . '#conversacion';
        $html = '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;color:#334155">'
            . '<div style="background:' . e($c1) . ';color:' . e(color_texto($c1)) . ';padding:16px 22px;border-radius:12px 12px 0 0;font-size:17px;font-weight:bold">' . e($asunto) . '</div>'
            . '<div style="border:1px solid #e2e8f0;border-top:0;border-radius:0 0 12px 12px;padding:22px">'
            . '<p style="white-space:pre-line">' . e($texto) . '</p>'
            . '<p style="margin-top:20px"><a href="' . e($url) . '" style="background:' . e($c1) . ';color:' . e(color_texto($c1)) . ';padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:bold">Ver cotizacion</a></p>'
            . '</div></div>';
        enviar_correo($emp['correo'], $asunto, $html, $texto, [], null, $emp['nombre']);
    } catch (Throwable $e) {
        // el aviso es opcional
    }
}

/** Etiqueta de la respuesta del cliente para listados. */
function badge_respuesta_cliente($cot)
{
    if (($cot['estado'] ?? '') !== 'pendiente' || empty($cot['respuesta_cliente'])) {
        return '';
    }
    if ($cot['respuesta_cliente'] === 'cambios') {
        return '<span class="ml-1 inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-800"><i data-lucide="message-square-diff" class="h-3 w-3"></i>Pide cambios</span>';
    }
    if ($cot['respuesta_cliente'] === 'vista') {
        return '<span class="ml-1 inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700"><i data-lucide="eye" class="h-3 w-3"></i>Vista</span>';
    }
    return '';
}
