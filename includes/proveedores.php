<?php
/**
 * Proveedores: costo, comision y saldos.
 *
 * Regla: el precio de venta ya incluye la comision de la empresa.
 *   costo proveedor = subtotal x (1 - comision% / 100)
 *   comision (ganancia) = subtotal - costo proveedor
 * Solo las cotizaciones ACEPTADAS generan deuda con el proveedor.
 */

function costo_proveedor($subtotal, $pct)
{
    $pct = max(0, min(100, (float)$pct));
    return round((float)$subtotal * (1 - $pct / 100), 2);
}

function proveedores_activos($eid, $incluirId = 0)
{
    return db_all('SELECT id, nombre, comision_pct FROM proveedores WHERE empresa_id = ? AND (activo = 1 OR id = ?) ORDER BY nombre', [$eid, (int)$incluirId]);
}

/** SQL de compras (costo) por proveedor en cotizaciones aceptadas */
function sql_compras_proveedor()
{
    return "SELECT d.proveedor_id, SUM(d.costo_proveedor) AS compras, SUM(d.subtotal) AS ventas
            FROM cotizacion_detalle d JOIN cotizaciones c ON c.id = d.cotizacion_id
            WHERE c.empresa_id = ? AND c.estado = 'aceptada' AND d.proveedor_id IS NOT NULL
            GROUP BY d.proveedor_id";
}

/** Lista de proveedores con compras, abonos y saldo (todo el historial) */
function proveedores_con_saldo($eid, $where = '1=1', array $params = [])
{
    $sql = "SELECT p.*,
                COALESCE(cp.compras, 0) AS compras,
                COALESCE(cp.ventas, 0) AS ventas,
                COALESCE(pg.pagado, 0) AS pagado,
                COALESCE(cp.compras, 0) - COALESCE(pg.pagado, 0) AS saldo
            FROM proveedores p
            LEFT JOIN (" . sql_compras_proveedor() . ") cp ON cp.proveedor_id = p.id
            LEFT JOIN (SELECT proveedor_id, SUM(monto) AS pagado FROM proveedor_pagos WHERE empresa_id = ? GROUP BY proveedor_id) pg ON pg.proveedor_id = p.id
            WHERE p.empresa_id = ? AND $where
            ORDER BY p.nombre";
    return db_all($sql, array_merge([$eid, $eid, $eid], $params));
}

function proveedor_con_saldo($eid, $pid)
{
    $r = proveedores_con_saldo($eid, 'p.id = ?', [$pid]);
    return $r ? $r[0] : null;
}

/** Costo, comision y abonos a proveedores de una cotizacion */
function resumen_proveedores_cotizacion($cotId)
{
    $filas = db_all("SELECT d.proveedor_id, p.nombre, SUM(d.subtotal) AS venta, SUM(d.costo_proveedor) AS costo
        FROM cotizacion_detalle d JOIN proveedores p ON p.id = d.proveedor_id
        WHERE d.cotizacion_id = ? GROUP BY d.proveedor_id, p.nombre ORDER BY p.nombre", [$cotId]);
    $pagos = [];
    foreach (db_all('SELECT proveedor_id, SUM(monto) AS pagado FROM proveedor_pagos WHERE cotizacion_id = ? GROUP BY proveedor_id', [$cotId]) as $r) {
        $pagos[(int)$r['proveedor_id']] = (float)$r['pagado'];
    }
    $costo = 0;
    $venta = 0;
    foreach ($filas as &$f) {
        $f['pagado'] = $pagos[(int)$f['proveedor_id']] ?? 0;
        $costo += (float)$f['costo'];
        $venta += (float)$f['venta'];
    }
    unset($f);
    return ['filas' => $filas, 'costo' => round($costo, 2), 'venta_proveedores' => round($venta, 2)];
}

function pct_txt($pct)
{
    return rtrim(rtrim(number_format((float)$pct, 2, '.', ''), '0'), '.') . '%';
}
