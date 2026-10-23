<?php
/**
 * Control de pagos de cotizaciones.
 */

function pagos_metodos()
{
    return ['Efectivo', 'Transferencia', 'Deposito', 'Tarjeta', 'Cheque', 'Otro'];
}

function pagos_descripciones_sugeridas()
{
    return ['Anticipo', 'Abono', 'Pago final', 'Reserva de fecha'];
}

/**
 * Totales de pagos de una cotizacion: ['pagado' => float, 'saldo' => float, 'porcentaje' => int, 'cantidad' => int]
 */
function resumen_pagos($cot)
{
    $r = db_one('SELECT COALESCE(SUM(monto), 0) AS pagado, COUNT(*) AS cantidad FROM cotizacion_pagos WHERE cotizacion_id = ?', [$cot['id']]);
    $total = round((float)$cot['total'], 2);
    $pagado = round((float)$r['pagado'], 2);
    $saldo = round($total - $pagado, 2);
    $pct = $total > 0 ? (int)min(100, floor($pagado * 100 / $total)) : ($pagado > 0 ? 100 : 0);
    return ['total' => $total, 'pagado' => $pagado, 'saldo' => $saldo, 'porcentaje' => $pct, 'cantidad' => (int)$r['cantidad']];
}

function estado_pago($resumen)
{
    if ($resumen['pagado'] <= 0) {
        return ['Sin pagos', 'bg-slate-100 text-slate-600 ring-slate-200'];
    }
    if ($resumen['saldo'] <= 0) {
        return ['Pagada', 'bg-emerald-100 text-emerald-800 ring-emerald-200'];
    }
    return ['Abonada', 'bg-sky-100 text-sky-800 ring-sky-200'];
}

function badge_pago($resumen)
{
    $d = estado_pago($resumen);
    return '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ' . $d[1] . '">' . e($d[0]) . '</span>';
}
