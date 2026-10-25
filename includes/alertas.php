<?php
/**
 * Alertas de eventos por liquidar (campanita).
 * Un evento aceptado entra en alerta cuando faltan DIAS_ALERTA dias o menos para su fecha
 * (o ya paso) y todavia tiene saldo con el cliente o con algun proveedor.
 */
if (!defined('DIAS_ALERTA')) {
    define('DIAS_ALERTA', 5);
}

function eventos_por_liquidar($eid, $dias = DIAS_ALERTA)
{
    $limite = date('Y-m-d', strtotime('+' . (int)$dias . ' days'));
    $rows = db_all("SELECT c.id, c.correlativo, c.fecha_evento, c.hora_evento, c.lugar_evento, c.total, cl.nombre AS cliente,
            COALESCE(pg.pagado, 0) AS cobrado,
            COALESCE(d.costo, 0) AS costo_prov,
            COALESCE(pp.abonado, 0) AS abonado_prov
        FROM cotizaciones c
        JOIN clientes cl ON cl.id = c.cliente_id
        LEFT JOIN (SELECT cotizacion_id, SUM(monto) AS pagado FROM cotizacion_pagos GROUP BY cotizacion_id) pg ON pg.cotizacion_id = c.id
        LEFT JOIN (SELECT cotizacion_id, SUM(costo_proveedor) AS costo FROM cotizacion_detalle GROUP BY cotizacion_id) d ON d.cotizacion_id = c.id
        LEFT JOIN (SELECT cotizacion_id, SUM(monto) AS abonado FROM proveedor_pagos WHERE cotizacion_id IS NOT NULL GROUP BY cotizacion_id) pp ON pp.cotizacion_id = c.id
        WHERE c.empresa_id = ? AND c.estado = 'aceptada' AND c.fecha_evento <= ?
        ORDER BY c.fecha_evento, c.hora_evento", [$eid, $limite]);
    $hoy = strtotime(date('Y-m-d'));
    $out = [];
    foreach ($rows as $r) {
        $r['saldo_cliente'] = round((float)$r['total'] - (float)$r['cobrado'], 2);
        $r['saldo_prov'] = round((float)$r['costo_prov'] - (float)$r['abonado_prov'], 2);
        if ($r['saldo_cliente'] <= 0.004 && $r['saldo_prov'] <= 0.004) {
            continue;
        }
        $r['dias'] = (int)round((strtotime($r['fecha_evento']) - $hoy) / 86400);
        $out[] = $r;
    }
    return $out;
}

function texto_dias($dias)
{
    if ($dias < 0) {
        return 'Hace ' . abs($dias) . ' dia' . (abs($dias) === 1 ? '' : 's');
    }
    if ($dias === 0) {
        return 'Hoy';
    }
    if ($dias === 1) {
        return 'Manana';
    }
    return 'En ' . $dias . ' dias';
}
