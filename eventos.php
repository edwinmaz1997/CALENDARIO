<?php
/**
 * Fuente JSON del calendario: cotizaciones aceptadas por fecha de evento.
 */
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if (!usuario_actual() || !empresa_actual()) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesion expirada']);
    exit;
}

$eid = empresa_id();
$emp = empresa_actual();
$inicio = substr((string)input('start'), 0, 10);
$fin = substr((string)input('end'), 0, 10);
if (!valid_date($inicio) || !valid_date($fin)) {
    $inicio = date('Y-m-01');
    $fin = date('Y-m-t');
}

$rows = db_all("SELECT c.id, c.correlativo, c.nombre_evento, c.fecha_evento, c.hora_evento, c.lugar_evento, c.total, cl.nombre AS cliente
    FROM cotizaciones c JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.empresa_id = ? AND c.estado = 'aceptada' AND c.fecha_evento >= ? AND c.fecha_evento < ?
    ORDER BY c.fecha_evento, c.hora_evento", [$eid, $inicio, $fin]);

$eventos = [];
foreach ($rows as $r) {
    $ev = [
        'id'     => (int)$r['id'],
        'title'  => $r['nombre_evento'] ? $r['nombre_evento'] . ' · ' . $r['cliente'] : $r['cliente'],
        'start'  => $r['fecha_evento'] . ($r['hora_evento'] ? 'T' . substr($r['hora_evento'], 0, 5) : ''),
        'allDay' => !$r['hora_evento'],
        'url'    => 'cotizacion_ver.php?id=' . (int)$r['id'],
        'extendedProps' => [
            'numero' => numero_cotizacion($r, $emp),
            'lugar'  => $r['lugar_evento'],
            'total'  => money($r['total'], $emp['moneda']),
        ],
    ];
    $eventos[] = $ev;
}
echo json_encode($eventos, JSON_UNESCAPED_UNICODE);
