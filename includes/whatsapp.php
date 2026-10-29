<?php
/**
 * Recordatorios por WhatsApp (API oficial de Meta - WhatsApp Cloud API).
 *
 * Se envian plantillas de categoria "Utilidad" al cliente y a cada proveedor del evento,
 * N dias antes de la fecha (por defecto 5 y 1). Cada envio queda registrado en whatsapp_envios
 * para no duplicarse; si falla se reintenta hasta 3 veces en las siguientes ejecuciones del cron.
 *
 * Plantillas que hay que crear en Meta (WhatsApp Manager > Plantillas), idioma Espanol, categoria Utilidad:
 *
 *  recordatorio_evento_cliente (5 variables)
 *    Hola {{1}}, te saluda {{2}}. Te recordamos que tu evento es el {{3}} en {{4}}.
 *    Saldo pendiente: {{5}}. Cualquier consulta, responde a este mensaje.
 *
 *  recordatorio_evento_proveedor (5 variables)
 *    Hola {{1}}, te saluda {{2}}. Te recordamos el evento del {{3}} en {{4}}.
 *    Productos a entregar: {{5}}. Por favor confirma tu disponibilidad respondiendo este mensaje.
 */

const WA_MAX_INTENTOS = 3;

function wa_config()
{
    $c = $GLOBALS['CONFIG']['whatsapp'] ?? [];
    $dias = array_values(array_unique(array_map('intval', $c['dias'] ?? [5, 1])));
    rsort($dias);
    return [
        'activo'              => !empty($c['activo']),
        'phone_number_id'     => trim((string)($c['phone_number_id'] ?? '')),
        'token'               => trim((string)($c['token'] ?? '')),
        'api_version'         => $c['api_version'] ?? 'v21.0',
        'api_base'            => rtrim($c['api_base'] ?? 'https://graph.facebook.com', '/'),
        'idioma'              => $c['idioma'] ?? 'es',
        'codigo_pais'         => preg_replace('/\D/', '', (string)($c['codigo_pais'] ?? '502')),
        'plantilla_cliente'   => $c['plantilla_cliente'] ?? 'recordatorio_evento_cliente',
        'plantilla_proveedor' => $c['plantilla_proveedor'] ?? 'recordatorio_evento_proveedor',
        'dias'                => $dias,
    ];
}

function wa_configurado()
{
    $c = wa_config();
    return $c['phone_number_id'] !== '' && $c['token'] !== '';
}

/** Normaliza un telefono a formato internacional sin "+" (ej. 50255551234). Devuelve null si no es valido. */
function wa_telefono($tel)
{
    $d = preg_replace('/\D/', '', (string)$tel);
    if ($d === '') {
        return null;
    }
    if (strpos($d, '00') === 0) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 8) {
        $d = wa_config()['codigo_pais'] . $d;
    }
    return (strlen($d) >= 10 && strlen($d) <= 15) ? $d : null;
}

/** WhatsApp no acepta saltos de linea, tabs ni mas de 4 espacios seguidos en las variables */
function wa_texto($s, $max = 300)
{
    $s = preg_replace('/[\r\n\t]+/', ' ', (string)$s);
    $s = preg_replace('/ {2,}/', ' ', trim($s));
    if ($s === '') {
        $s = '-';
    }
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 3) . '...' : $s;
}

/**
 * Envia una plantilla. Devuelve [ok, message_id|null, error|null].
 */
function wa_enviar_plantilla($telefono, $plantilla, array $variables, $idioma = null)
{
    $c = wa_config();
    if (!wa_configurado()) {
        return [false, null, 'WhatsApp no esta configurado (falta phone_number_id o token).'];
    }
    $componentes = [];
    if ($variables) {
        $componentes[] = [
            'type'       => 'body',
            'parameters' => array_map(function ($v) { return ['type' => 'text', 'text' => wa_texto($v)]; }, array_values($variables)),
        ];
    }
    $cuerpo = [
        'messaging_product' => 'whatsapp',
        'to'                => $telefono,
        'type'              => 'template',
        'template'          => ['name' => $plantilla, 'language' => ['code' => $idioma ?: $c['idioma']]],
    ];
    if ($componentes) {
        $cuerpo['template']['components'] = $componentes;
    }
    $url = $c['api_base'] . '/' . $c['api_version'] . '/' . rawurlencode($c['phone_number_id']) . '/messages';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $c['token'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errCurl = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        return [false, null, 'Error de conexion: ' . $errCurl];
    }
    $j = json_decode($resp, true);
    if ($http >= 200 && $http < 300 && !empty($j['messages'][0]['id'])) {
        return [true, $j['messages'][0]['id'], null];
    }
    $msg = $j['error']['error_data']['details'] ?? ($j['error']['message'] ?? ('HTTP ' . $http));
    if (!empty($j['error']['code'])) {
        $msg = '[' . $j['error']['code'] . '] ' . $msg;
    }
    return [false, null, $msg];
}

/* ---------------- Datos de los mensajes ---------------- */

function wa_fecha_evento($cot)
{
    return fecha_larga($cot['fecha_evento']) . ($cot['hora_evento'] ? ' a las ' . hora($cot['hora_evento']) : '');
}

function wa_variables_cliente($cot, $emp, $cliente)
{
    $pagado = (float)db_val('SELECT COALESCE(SUM(monto), 0) FROM cotizacion_pagos WHERE cotizacion_id = ?', [$cot['id']]);
    $saldo = round((float)$cot['total'] - $pagado, 2);
    return [
        $cliente['nombre'],
        $emp['nombre'],
        wa_fecha_evento($cot),
        $cot['lugar_evento'] ?: 'el lugar acordado',
        $saldo > 0 ? money($saldo, $emp['moneda']) : money(0, $emp['moneda']) . ' (evento pagado, gracias)',
    ];
}

function wa_variables_proveedor($cot, $emp, $proveedor, $lineas)
{
    $items = array_map(function ($l) {
        $cant = rtrim(rtrim(number_format((float)$l['cantidad'], 2, '.', ''), '0'), '.');
        return $l['descripcion'] . ' x' . $cant;
    }, $lineas);
    return [
        $proveedor['contacto'] ?: $proveedor['nombre'],
        $emp['nombre'],
        wa_fecha_evento($cot),
        $cot['lugar_evento'] ?: 'el lugar acordado',
        implode(', ', $items),
    ];
}

/** Destinatarios de un evento: cliente + proveedores con sus lineas */
function wa_destinatarios($cot)
{
    $c = wa_config();
    $emp = db_one('SELECT * FROM empresas WHERE id = ?', [$cot['empresa_id']]);
    $cliente = db_one('SELECT * FROM clientes WHERE id = ?', [$cot['cliente_id']]);
    $dest = [];
    $dest[] = [
        'tipo' => 'cliente', 'id' => (int)$cliente['id'], 'nombre' => $cliente['nombre'], 'telefono' => wa_telefono($cliente['telefono']),
        'plantilla' => $c['plantilla_cliente'], 'variables' => wa_variables_cliente($cot, $emp, $cliente),
    ];
    $lineas = db_all('SELECT proveedor_id, descripcion, cantidad FROM cotizacion_detalle WHERE cotizacion_id = ? AND proveedor_id IS NOT NULL ORDER BY orden, id', [$cot['id']]);
    $porProv = [];
    foreach ($lineas as $l) {
        $porProv[(int)$l['proveedor_id']][] = $l;
    }
    foreach ($porProv as $pid => $ls) {
        $pv = db_one('SELECT * FROM proveedores WHERE id = ?', [$pid]);
        if (!$pv) {
            continue;
        }
        $dest[] = [
            'tipo' => 'proveedor', 'id' => (int)$pv['id'], 'nombre' => $pv['nombre'], 'telefono' => wa_telefono($pv['telefono']),
            'plantilla' => $c['plantilla_proveedor'], 'variables' => wa_variables_proveedor($cot, $emp, $pv, $ls),
        ];
    }
    return $dest;
}

/**
 * Envia (o reintenta) un recordatorio de tipo $tipo ('5dias', '1dia', 'manual...') a un destinatario.
 * Devuelve ['estado' => enviado|error|omitido|ya_enviado, 'detalle' => ...]
 */
function wa_enviar_recordatorio($cot, $d, $tipo, $forzar = false)
{
    if (!$d['telefono']) {
        return ['estado' => 'omitido', 'detalle' => $d['nombre'] . ': sin telefono valido'];
    }
    $prev = db_one('SELECT * FROM whatsapp_envios WHERE cotizacion_id = ? AND destinatario_tipo = ? AND destinatario_id = ? AND tipo = ?', [$cot['id'], $d['tipo'], $d['id'], $tipo]);
    if ($prev && !$forzar) {
        if ($prev['estado'] === 'enviado') {
            return ['estado' => 'ya_enviado', 'detalle' => $d['nombre']];
        }
        if ((int)$prev['intentos'] >= WA_MAX_INTENTOS) {
            return ['estado' => 'omitido', 'detalle' => $d['nombre'] . ': se agotaron los reintentos'];
        }
    }
    list($ok, $msgId, $err) = wa_enviar_plantilla($d['telefono'], $d['plantilla'], $d['variables']);
    $estado = $ok ? 'enviado' : 'error';
    if ($prev) {
        db_exec('UPDATE whatsapp_envios SET telefono = ?, plantilla = ?, estado = ?, intentos = ?, wa_message_id = ?, error = ? WHERE id = ?', [
            $d['telefono'], $d['plantilla'], $estado, $forzar ? 1 : (int)$prev['intentos'] + 1, $msgId, $err ? mb_substr($err, 0, 500) : null, $prev['id'],
        ]);
    } else {
        db_exec('INSERT INTO whatsapp_envios (empresa_id, cotizacion_id, destinatario_tipo, destinatario_id, tipo, telefono, plantilla, estado, wa_message_id, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $cot['empresa_id'], $cot['id'], $d['tipo'], $d['id'], $tipo, $d['telefono'], $d['plantilla'], $estado, $msgId, $err ? mb_substr($err, 0, 500) : null,
        ]);
    }
    return ['estado' => $estado, 'detalle' => $d['nombre'] . ($err ? ': ' . $err : '')];
}

/** Clave del recordatorio segun los dias que faltan, o null si no toca ninguno */
function wa_tipo_por_dias($faltan, array $dias)
{
    // dias ordenados de mayor a menor, ej. [5, 1]: 5 aplica si 1 < faltan <= 5; 1 aplica si 0 <= faltan <= 1
    foreach ($dias as $i => $d) {
        $siguiente = $dias[$i + 1] ?? -1;
        if ($faltan <= $d && $faltan > $siguiente) {
            return $d . 'dias';
        }
    }
    return null;
}

/**
 * Proceso del cron: revisa eventos aceptados proximos y envia lo que corresponda.
 * Devuelve lineas de log.
 */
function procesar_whatsapp()
{
    $c = wa_config();
    $log = [];
    if (!$c['activo']) {
        return [date('Y-m-d H:i:s') . ' WhatsApp desactivado en config (whatsapp.activo = false).'];
    }
    if (!wa_configurado()) {
        return [date('Y-m-d H:i:s') . ' WhatsApp sin configurar (falta phone_number_id o token).'];
    }
    $max = max($c['dias']);
    $hoy = date('Y-m-d');
    $eventos = db_all("SELECT c.* FROM cotizaciones c JOIN empresas e ON e.id = c.empresa_id
        WHERE c.estado = 'aceptada' AND e.activo = 1 AND c.fecha_evento BETWEEN ? AND ?
        ORDER BY c.fecha_evento", [$hoy, date('Y-m-d', strtotime('+' . $max . ' days'))]);
    $enviados = 0;
    foreach ($eventos as $cot) {
        $faltan = (int)round((strtotime($cot['fecha_evento']) - strtotime($hoy)) / 86400);
        $tipo = wa_tipo_por_dias($faltan, $c['dias']);
        if (!$tipo) {
            continue;
        }
        foreach (wa_destinatarios($cot) as $d) {
            $r = wa_enviar_recordatorio($cot, $d, $tipo);
            if ($r['estado'] === 'ya_enviado' || $r['estado'] === 'omitido') {
                continue; // sin telefono o reintentos agotados: se ve en la cotizacion, no se repite en el log
            }
            if ($r['estado'] === 'enviado') {
                $enviados++;
            }
            $log[] = date('Y-m-d H:i:s') . ' WA cotizacion #' . $cot['id'] . ' ' . $tipo . ' ' . $d['tipo'] . ' ' . strtoupper($r['estado']) . ': ' . $r['detalle'];
        }
    }
    if (!$log) {
        $log[] = date('Y-m-d H:i:s') . ' WA sin recordatorios pendientes.';
    }
    return $log;
}

function wa_etiqueta_tipo($tipo)
{
    if (preg_match('/^(\d+)dias$/', $tipo, $m)) {
        return $m[1] === '1' ? '1 dia antes' : $m[1] . ' dias antes';
    }
    return $tipo === 'manual' ? 'Manual' : ($tipo === 'chat' ? 'Chat' : $tipo);
}

/**
 * Recordatorios a proveedores por chat de WhatsApp (sin API): un mensaje por proveedor
 * con sus productos del evento. Devuelve lista de proveedores con telefono, lineas, mensaje y enlace wa.me.
 */
function recordatorios_proveedores_chat($cot, $emp)
{
    $cliente = db_one('SELECT nombre FROM clientes WHERE id = ?', [$cot['cliente_id']]);
    $lineas = db_all('SELECT d.proveedor_id, d.descripcion, d.unidad, d.cantidad FROM cotizacion_detalle d
        WHERE d.cotizacion_id = ? AND d.proveedor_id IS NOT NULL ORDER BY d.orden, d.id', [$cot['id']]);
    $grupos = [];
    foreach ($lineas as $l) {
        $grupos[(int)$l['proveedor_id']][] = $l;
    }
    $enviados = [];
    foreach (db_all("SELECT destinatario_id, COALESCE(updated_at, created_at) AS cuando FROM whatsapp_envios
        WHERE cotizacion_id = ? AND destinatario_tipo = 'proveedor' AND tipo = 'chat'", [$cot['id']]) as $r) {
        $enviados[(int)$r['destinatario_id']] = $r['cuando'];
    }
    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $fecha = $cot['fecha_evento'] ? $dias[(int)date('w', strtotime($cot['fecha_evento']))] . ' ' . fecha_larga($cot['fecha_evento']) : '';
    $nombreEv = trim((string)($cot['nombre_evento'] ?? ''));
    $numero = numero_cotizacion($cot, $emp);
    $out = [];
    foreach ($grupos as $pid => $ls) {
        $pv = db_one('SELECT id, nombre, contacto, telefono FROM proveedores WHERE id = ?', [$pid]);
        if (!$pv) {
            continue;
        }
        $items = [];
        foreach ($ls as $l) {
            $cant = rtrim(rtrim(number_format((float)$l['cantidad'], 2, '.', ''), '0'), '.');
            $items[] = '• ' . $cant . ' ' . $l['descripcion'] . ($l['unidad'] && !in_array(mb_strtolower($l['unidad']), ['unidad', 'unidades'], true) ? ' (' . $l['unidad'] . ')' : '');
        }
        $saludo = $pv['contacto'] ?: $pv['nombre'];
        $msg = "Hola " . $saludo . ", te saluda " . $emp['nombre'] . ".\n"
            . "Te recordamos del evento " . ($nombreEv !== '' ? '"' . $nombreEv . '"' : $numero . " (" . $cliente['nombre'] . ")") . ($fecha !== '' ? " del " . $fecha : '') . ".\n\n"
            . ($cot['lugar_evento'] ? "📍 " . $cot['lugar_evento'] . "\n\n" : '')
            . "Productos a entregar:\n" . implode("\n", $items) . "\n\n"
            . "Por favor confirmanos. ¡Gracias!";
        $tel = wa_telefono($pv['telefono']);
        $out[] = [
            'id'       => (int)$pv['id'],
            'nombre'   => $pv['nombre'],
            'telefono' => $pv['telefono'],
            'tel_wa'   => $tel,
            'items'    => $items,
            'mensaje'  => $msg,
            'enlace'   => 'https://wa.me/' . ($tel ?: '') . '?text=' . rawurlencode($msg),
            'enviado'  => $enviados[(int)$pv['id']] ?? null,
        ];
    }
    return $out;
}

/** Registra que se abrio el chat de WhatsApp con el recordatorio para un proveedor */
function registrar_recordatorio_chat_proveedor($cot, $provId, $telefono)
{
    db_exec("INSERT INTO whatsapp_envios (empresa_id, cotizacion_id, destinatario_tipo, destinatario_id, tipo, telefono, plantilla, estado)
        VALUES (?, ?, 'proveedor', ?, 'chat', ?, 'chat', 'enviado')
        ON DUPLICATE KEY UPDATE telefono = VALUES(telefono), estado = 'enviado', intentos = intentos + 1, updated_at = NOW()",
        [$cot['empresa_id'], $cot['id'], $provId, $telefono ?: '-']);
}

