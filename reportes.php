<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/proveedores.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();
$mon = $emp['moneda'];
$mesesLargo = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$mesesCorto = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

/* ---------- Periodo ---------- */
$tipo = in_array(input('tipo'), ['anual', 'mensual', 'rango'], true) ? input('tipo') : 'mensual';
$anio = input_int('anio', (int)date('Y'));
$anio = ($anio < 2000 || $anio > 2100) ? (int)date('Y') : $anio;
$mes = input_int('mes', (int)date('n'));
$mes = ($mes < 1 || $mes > 12) ? (int)date('n') : $mes;
$desde = input('desde');
$hasta = input('hasta');

if ($tipo === 'anual') {
    $ini = sprintf('%04d-01-01', $anio);
    $fin = sprintf('%04d-12-31', $anio);
    $etiqueta = 'Año ' . $anio;
} elseif ($tipo === 'rango') {
    $ini = valid_date($desde) ? $desde : date('Y-m-01');
    $fin = valid_date($hasta) ? $hasta : date('Y-m-t');
    if ($fin < $ini) {
        list($ini, $fin) = [$fin, $ini];
    }
    $etiqueta = fecha($ini) . ' al ' . fecha($fin);
} else {
    $ini = sprintf('%04d-%02d-01', $anio, $mes);
    $fin = date('Y-m-t', strtotime($ini));
    $etiqueta = $mesesLargo[$mes] . ' ' . $anio;
}
$desde = $desde ?: $ini;
$hasta = $hasta ?: $fin;

/* ---------- Eventos aceptados del periodo (por fecha del evento) ---------- */
$eventos = db_all("SELECT c.id, c.correlativo, c.nombre_evento, c.fecha_evento, c.hora_evento, c.lugar_evento, c.total, cl.nombre AS cliente,
        COALESCE(pg.pagado, 0) AS cobrado,
        COALESCE(d.costo, 0) AS costo,
        COALESCE(pp.abonado, 0) AS abonado_prov
    FROM cotizaciones c
    JOIN clientes cl ON cl.id = c.cliente_id
    LEFT JOIN (SELECT cotizacion_id, SUM(monto) AS pagado FROM cotizacion_pagos GROUP BY cotizacion_id) pg ON pg.cotizacion_id = c.id
    LEFT JOIN (SELECT cotizacion_id, SUM(costo_proveedor) AS costo FROM cotizacion_detalle GROUP BY cotizacion_id) d ON d.cotizacion_id = c.id
    LEFT JOIN (SELECT cotizacion_id, SUM(monto) AS abonado FROM proveedor_pagos WHERE cotizacion_id IS NOT NULL GROUP BY cotizacion_id) pp ON pp.cotizacion_id = c.id
    WHERE c.empresa_id = ? AND c.estado = 'aceptada' AND c.fecha_evento BETWEEN ? AND ?
    ORDER BY c.fecha_evento, c.hora_evento", [$eid, $ini, $fin]);

$k = ['eventos' => count($eventos), 'vendido' => 0, 'cobrado' => 0, 'costo' => 0, 'abonado_prov' => 0];
foreach ($eventos as $ev) {
    $k['vendido'] += (float)$ev['total'];
    $k['cobrado'] += (float)$ev['cobrado'];
    $k['costo'] += (float)$ev['costo'];
    $k['abonado_prov'] += (float)$ev['abonado_prov'];
}
$k['por_cobrar'] = $k['vendido'] - $k['cobrado'];
$k['ganancia'] = $k['vendido'] - $k['costo'];
$k['adeudo_prov'] = $k['costo'] - $k['abonado_prov'];
$k['margen'] = $k['vendido'] > 0 ? round($k['ganancia'] * 100 / $k['vendido'], 1) : 0;

/* ---------- Flujo de dinero del periodo (por fecha de pago) ---------- */
$ingresos = (float)db_val('SELECT COALESCE(SUM(p.monto), 0) FROM cotizacion_pagos p JOIN cotizaciones c ON c.id = p.cotizacion_id WHERE c.empresa_id = ? AND p.fecha BETWEEN ? AND ?', [$eid, $ini, $fin]);
$egresos = (float)db_val('SELECT COALESCE(SUM(monto), 0) FROM proveedor_pagos WHERE empresa_id = ? AND fecha BETWEEN ? AND ?', [$eid, $ini, $fin]);
$ingresosMetodo = db_all('SELECT COALESCE(p.metodo, \'Sin especificar\') AS metodo, SUM(p.monto) AS monto FROM cotizacion_pagos p JOIN cotizaciones c ON c.id = p.cotizacion_id
    WHERE c.empresa_id = ? AND p.fecha BETWEEN ? AND ? GROUP BY COALESCE(p.metodo, \'Sin especificar\') ORDER BY monto DESC', [$eid, $ini, $fin]);

/* ---------- Cotizaciones creadas en el periodo ---------- */
$cq = array_map('floatval', db_one("SELECT COUNT(*) AS creadas,
        COALESCE(SUM(enviada_at IS NOT NULL), 0) AS enviadas,
        COALESCE(SUM(estado = 'aceptada'), 0) AS aceptadas,
        COALESCE(SUM(estado = 'rechazada'), 0) AS rechazadas,
        COALESCE(SUM(estado = 'pendiente'), 0) AS pendientes,
        COALESCE(SUM(total), 0) AS monto
    FROM cotizaciones WHERE empresa_id = ? AND fecha BETWEEN ? AND ?", [$eid, $ini, $fin]));
$tasa = $cq['creadas'] > 0 ? round($cq['aceptadas'] * 100 / $cq['creadas']) : 0;

/* ---------- Por proveedor ---------- */
$porProv = db_all("SELECT p.id, p.nombre, p.comision_pct,
        COALESCE(cp.venta, 0) AS venta, COALESCE(cp.costo, 0) AS costo,
        COALESCE(ab.abonado, 0) AS abonado_periodo
    FROM proveedores p
    LEFT JOIN (SELECT d.proveedor_id, SUM(d.subtotal) AS venta, SUM(d.costo_proveedor) AS costo
               FROM cotizacion_detalle d JOIN cotizaciones c ON c.id = d.cotizacion_id
               WHERE c.empresa_id = ? AND c.estado = 'aceptada' AND c.fecha_evento BETWEEN ? AND ?
               GROUP BY d.proveedor_id) cp ON cp.proveedor_id = p.id
    LEFT JOIN (SELECT proveedor_id, SUM(monto) AS abonado FROM proveedor_pagos WHERE empresa_id = ? AND fecha BETWEEN ? AND ? GROUP BY proveedor_id) ab ON ab.proveedor_id = p.id
    WHERE p.empresa_id = ? AND (cp.costo IS NOT NULL OR ab.abonado IS NOT NULL)
    ORDER BY costo DESC", [$eid, $ini, $fin, $eid, $ini, $fin, $eid]);
$saldosProv = [];
foreach (proveedores_con_saldo($eid) as $pv) {
    $saldosProv[(int)$pv['id']] = (float)$pv['saldo'];
}
$deudaTotalProv = array_sum(array_map(function ($v) { return max(0, $v); }, $saldosProv));

/* ---------- Productos mas vendidos del periodo ---------- */
$topProd = db_all("SELECT d.descripcion, SUM(d.cantidad) AS cantidad, SUM(d.subtotal) AS venta
    FROM cotizacion_detalle d JOIN cotizaciones c ON c.id = d.cotizacion_id
    WHERE c.empresa_id = ? AND c.estado = 'aceptada' AND c.fecha_evento BETWEEN ? AND ?
    GROUP BY d.descripcion ORDER BY venta DESC LIMIT 8", [$eid, $ini, $fin]);

/* ---------- Desglose por mes (anual) ---------- */
$porMes = [];
if ($tipo === 'anual') {
    for ($m = 1; $m <= 12; $m++) {
        $porMes[$m] = ['eventos' => 0, 'vendido' => 0, 'cobrado' => 0, 'costo' => 0];
    }
    foreach ($eventos as $ev) {
        $m = (int)date('n', strtotime($ev['fecha_evento']));
        $porMes[$m]['eventos']++;
        $porMes[$m]['vendido'] += (float)$ev['total'];
        $porMes[$m]['cobrado'] += (float)$ev['cobrado'];
        $porMes[$m]['costo'] += (float)$ev['costo'];
    }
}
$maxMes = $porMes ? max(1, max(array_map(function ($r) { return $r['vendido']; }, $porMes))) : 1;

/* ---------- Exportar CSV ---------- */
if (input('exportar') === 'csv') {
    $nombre = 'reporte_' . preg_replace('/[^0-9a-z_-]+/i', '_', $ini . '_' . $fin) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para Excel
    fputcsv($out, ['Reporte', $emp['nombre'], $etiqueta]);
    fputcsv($out, []);
    fputcsv($out, ['Cotizacion', 'Evento', 'Fecha evento', 'Cliente', 'Lugar', 'Total', 'Cobrado', 'Por cobrar', 'Costo proveedores', 'Ganancia', 'Abonado a proveedores']);
    foreach ($eventos as $ev) {
        fputcsv($out, [numero_cotizacion($ev, $emp), $ev['nombre_evento'], $ev['fecha_evento'], $ev['cliente'], $ev['lugar_evento'],
            number_format($ev['total'], 2, '.', ''), number_format($ev['cobrado'], 2, '.', ''), number_format($ev['total'] - $ev['cobrado'], 2, '.', ''),
            number_format($ev['costo'], 2, '.', ''), number_format($ev['total'] - $ev['costo'], 2, '.', ''), number_format($ev['abonado_prov'], 2, '.', '')]);
    }
    fputcsv($out, ['TOTAL', '', '', $k['eventos'] . ' eventos', '', number_format($k['vendido'], 2, '.', ''), number_format($k['cobrado'], 2, '.', ''),
        number_format($k['por_cobrar'], 2, '.', ''), number_format($k['costo'], 2, '.', ''), number_format($k['ganancia'], 2, '.', ''), number_format($k['abonado_prov'], 2, '.', '')]);
    fputcsv($out, []);
    fputcsv($out, ['Flujo del periodo (por fecha de pago)']);
    fputcsv($out, ['Cobrado a clientes', number_format($ingresos, 2, '.', '')]);
    fputcsv($out, ['Pagado a proveedores', number_format($egresos, 2, '.', '')]);
    fputcsv($out, ['Neto', number_format($ingresos - $egresos, 2, '.', '')]);
    if ($porProv) {
        fputcsv($out, []);
        fputcsv($out, ['Proveedor', 'Comision %', 'Venta', 'Costo (a pagar)', 'Abonado en el periodo', 'Saldo actual']);
        foreach ($porProv as $pv) {
            fputcsv($out, [$pv['nombre'], $pv['comision_pct'], number_format($pv['venta'], 2, '.', ''), number_format($pv['costo'], 2, '.', ''),
                number_format($pv['abonado_periodo'], 2, '.', ''), number_format($saldosProv[(int)$pv['id']] ?? 0, 2, '.', '')]);
        }
    }
    fclose($out);
    exit;
}

$anioMin = (int)(db_val('SELECT MIN(YEAR(fecha_evento)) FROM cotizaciones WHERE empresa_id = ?', [$eid]) ?: date('Y'));
$anioMin = min($anioMin, (int)date('Y'));
$anioMax = (int)date('Y') + 1;

$titulo = 'Reportes';
$activo = 'reportes';
$qs = http_build_query(['tipo' => $tipo, 'anio' => $anio, 'mes' => $mes, 'desde' => $ini, 'hasta' => $fin]);
$accionesHeader = '<a href="reportes.php?' . e($qs) . '&amp;exportar=csv" class="btn btn-claro btn-sm"><i data-lucide="file-spreadsheet"></i><span class="hidden sm:inline">Excel (CSV)</span></a>'
    . '<button type="button" onclick="window.print()" class="btn btn-claro btn-sm"><i data-lucide="printer"></i><span class="hidden sm:inline">Imprimir</span></button>';
require __DIR__ . '/includes/header.php';

function kpi($titulo, $valor, $sub, $color = 'text-slate-900')
{
    echo '<div class="card min-w-0 p-4"><p class="text-xs font-semibold text-slate-500">' . e($titulo) . '</p>'
        . '<p class="mt-1 truncate text-lg font-extrabold sm:text-xl ' . $color . '">' . e($valor) . '</p>'
        . ($sub !== '' ? '<p class="truncate text-xs text-slate-500">' . e($sub) . '</p>' : '') . '</div>';
}
?>
<!-- Filtro de periodo -->
<form method="get" class="card no-print mb-5 p-4" id="filtro">
  <div class="mb-3 inline-flex gap-1 rounded-xl bg-slate-200/60 p-1">
    <?php foreach (['mensual' => 'Mensual', 'anual' => 'Anual', 'rango' => 'Rango de fechas'] as $t => $lbl): ?>
      <label class="cursor-pointer">
        <input type="radio" name="tipo" value="<?= $t ?>" class="peer sr-only" <?= $tipo === $t ? 'checked' : '' ?> onchange="this.form.submit()">
        <span class="block whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-semibold text-slate-600 peer-checked:bg-white peer-checked:text-slate-900 peer-checked:shadow-sm"><?= $lbl ?></span>
      </label>
    <?php endforeach; ?>
  </div>
  <div class="grid gap-3 sm:grid-cols-4 sm:items-end">
    <?php if ($tipo === 'rango'): ?>
      <label class="campo"><span>Desde</span><input class="input" type="date" name="desde" value="<?= e($ini) ?>"></label>
      <label class="campo"><span>Hasta</span><input class="input" type="date" name="hasta" value="<?= e($fin) ?>"></label>
    <?php else: ?>
      <?php if ($tipo === 'mensual'): ?>
        <label class="campo"><span>Mes</span>
          <select class="select" name="mes" onchange="this.form.submit()">
            <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= $mesesLargo[$m] ?></option><?php endfor; ?>
          </select>
        </label>
      <?php endif; ?>
      <label class="campo"><span>Año</span>
        <select class="select" name="anio" onchange="this.form.submit()">
          <?php for ($a = $anioMax; $a >= $anioMin; $a--): ?><option value="<?= $a ?>" <?= $a === $anio ? 'selected' : '' ?>><?= $a ?></option><?php endfor; ?>
        </select>
      </label>
    <?php endif; ?>
    <button class="btn btn-primario"><i data-lucide="bar-chart-3"></i>Ver reporte</button>
  </div>
</form>

<div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
  <h2 class="text-xl font-extrabold text-slate-900"><?= e($etiqueta) ?></h2>
  <p class="text-xs text-slate-500"><?= e($emp['nombre']) ?> · Eventos aceptados segun su fecha</p>
</div>

<!-- Indicadores principales -->
<div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3">
  <?php
  kpi('Eventos', (string)$k['eventos'], 'Aceptados en el periodo');
  kpi('Vendido', money($k['vendido'], $mon), 'Total de los eventos');
  kpi('Cobrado', money($k['cobrado'], $mon), 'Pagos de esos clientes', 'text-emerald-600');
  kpi('Por cobrar', money($k['por_cobrar'], $mon), 'Saldo de clientes', $k['por_cobrar'] > 0 ? 'text-rose-600' : 'text-slate-900');
  kpi('Costo proveedores', money($k['costo'], $mon), 'A pagar por esos eventos');
  kpi('Ganancia', money($k['ganancia'], $mon), 'Margen ' . $k['margen'] . '%', 'text-emerald-600');
  ?>
</div>

<div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
  <div class="min-w-0 space-y-5 xl:col-span-2">

    <?php if ($tipo === 'anual'): ?>
    <!-- Grafica mensual -->
    <section class="card p-5">
      <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-base font-bold text-slate-900">Ventas por mes</h3>
        <div class="flex gap-3 text-xs text-slate-500">
          <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm" style="background: var(--c1)"></span>Vendido</span>
          <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-500"></span>Ganancia</span>
        </div>
      </div>
      <div class="flex h-48 items-end gap-1 sm:gap-2">
        <?php foreach ($porMes as $m => $r): $hV = round($r['vendido'] * 100 / $maxMes); $hG = round(max(0, $r['vendido'] - $r['costo']) * 100 / $maxMes); ?>
          <a href="reportes.php?tipo=mensual&anio=<?= $anio ?>&mes=<?= $m ?>" class="group flex h-full min-w-0 flex-1 flex-col justify-end" title="<?= e($mesesLargo[$m] . ': ' . money($r['vendido'], $mon) . ' · ' . $r['eventos'] . ' eventos') ?>">
            <div class="relative w-full rounded-t-md transition group-hover:opacity-80" style="height: <?= max($r['vendido'] > 0 ? 2 : 0, $hV) ?>%; background: var(--c1)">
              <div class="absolute inset-x-1 bottom-0 rounded-t-sm bg-emerald-400/90" style="height: <?= $hV > 0 ? round($hG * 100 / $hV) : 0 ?>%"></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="mt-1 flex gap-1 sm:gap-2">
        <?php for ($m = 1; $m <= 12; $m++): ?><span class="min-w-0 flex-1 text-center text-[10px] font-semibold text-slate-500 sm:text-xs"><?= $mesesCorto[$m] ?></span><?php endfor; ?>
      </div>
      <div class="mt-5 overflow-x-auto">
        <table class="tabla text-xs sm:text-sm">
          <thead><tr><th>Mes</th><th class="text-center">Eventos</th><th class="text-right">Vendido</th><th class="text-right">Cobrado</th><th class="text-right">Costo prov.</th><th class="text-right">Ganancia</th></tr></thead>
          <tbody>
            <?php foreach ($porMes as $m => $r): ?>
              <tr class="<?= $r['eventos'] ? '' : 'text-slate-400' ?>">
                <td><a href="reportes.php?tipo=mensual&anio=<?= $anio ?>&mes=<?= $m ?>" class="font-semibold hover:underline"><?= $mesesLargo[$m] ?></a></td>
                <td class="text-center"><?= (int)$r['eventos'] ?></td>
                <td class="whitespace-nowrap text-right"><?= e(money($r['vendido'], $mon)) ?></td>
                <td class="whitespace-nowrap text-right"><?= e(money($r['cobrado'], $mon)) ?></td>
                <td class="whitespace-nowrap text-right"><?= e(money($r['costo'], $mon)) ?></td>
                <td class="whitespace-nowrap text-right font-semibold"><?= e(money($r['vendido'] - $r['costo'], $mon)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

    <!-- Eventos del periodo -->
    <section class="card overflow-hidden">
      <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
        <h3 class="text-base font-bold text-slate-900">Eventos del periodo</h3>
        <span class="text-xs text-slate-500"><?= (int)$k['eventos'] ?> evento(s)</span>
      </div>
      <?php if (!$eventos): ?>
        <p class="px-5 py-10 text-center text-sm text-slate-400">No hay eventos aceptados en este periodo.</p>
      <?php else: ?>
      <table class="tabla tabla-movil">
        <thead><tr><th>Evento</th><th class="text-right">Total</th><th class="text-right">Cobrado</th><th class="text-right">Costo prov.</th><th class="text-right">Ganancia</th></tr></thead>
        <tbody>
          <?php foreach ($eventos as $ev): $saldoC = $ev['total'] - $ev['cobrado']; ?>
            <tr>
              <td class="td-principal">
                <a href="cotizacion_ver.php?id=<?= (int)$ev['id'] ?>" class="font-semibold texto-marca hover:underline"><?= e(numero_cotizacion($ev, $emp)) ?></a>
                <span class="text-sm text-slate-800"><?= e($ev['cliente']) ?></span><?php if (!empty($ev['nombre_evento'])): ?><span class="block text-xs font-semibold texto-marca"><?= e($ev['nombre_evento']) ?></span><?php endif; ?>
                <p class="text-xs text-slate-500"><?= e(fecha($ev['fecha_evento'])) ?><?= $ev['lugar_evento'] ? ' · ' . e($ev['lugar_evento']) : '' ?></p>
              </td>
              <td data-label="Total" class="whitespace-nowrap font-semibold md:text-right"><?= e(money($ev['total'], $mon)) ?></td>
              <td data-label="Cobrado" class="whitespace-nowrap md:text-right">
                <span class="text-emerald-600"><?= e(money($ev['cobrado'], $mon)) ?></span>
                <?php if ($saldoC > 0.004): ?><span class="block text-xs text-rose-600">debe <?= e(money($saldoC, $mon)) ?></span><?php endif; ?>
              </td>
              <td data-label="Costo prov." class="whitespace-nowrap md:text-right"><?= e(money($ev['costo'], $mon)) ?></td>
              <td data-label="Ganancia" class="whitespace-nowrap font-bold text-emerald-700 md:text-right"><?= e(money($ev['total'] - $ev['costo'], $mon)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot class="hidden md:table-footer-group">
          <tr class="bg-slate-50 font-bold">
            <td class="px-4 py-3">Totales</td>
            <td class="whitespace-nowrap px-4 py-3 text-right"><?= e(money($k['vendido'], $mon)) ?></td>
            <td class="whitespace-nowrap px-4 py-3 text-right text-emerald-600"><?= e(money($k['cobrado'], $mon)) ?></td>
            <td class="whitespace-nowrap px-4 py-3 text-right"><?= e(money($k['costo'], $mon)) ?></td>
            <td class="whitespace-nowrap px-4 py-3 text-right text-emerald-700"><?= e(money($k['ganancia'], $mon)) ?></td>
          </tr>
        </tfoot>
      </table>
      <?php endif; ?>
    </section>

    <!-- Proveedores -->
    <section class="card overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
        <h3 class="text-base font-bold text-slate-900">Proveedores</h3>
        <span class="text-xs text-slate-500">Adeudo total actual: <strong class="text-rose-600"><?= e(money($deudaTotalProv, $mon)) ?></strong></span>
      </div>
      <?php if (!$porProv): ?>
        <p class="px-5 py-8 text-center text-sm text-slate-400">Sin compras ni abonos a proveedores en este periodo.</p>
      <?php else: ?>
      <table class="tabla tabla-movil">
        <thead><tr><th>Proveedor</th><th class="text-right">Vendido</th><th class="text-right">A pagar</th><th class="text-right">Tu comision</th><th class="text-right">Abonado (periodo)</th><th class="text-right">Saldo actual</th></tr></thead>
        <tbody>
          <?php foreach ($porProv as $pv): $sal = $saldosProv[(int)$pv['id']] ?? 0; ?>
            <tr>
              <td class="td-principal"><a href="proveedor_ver.php?id=<?= (int)$pv['id'] ?>" class="font-semibold text-slate-900 hover:underline"><?= e($pv['nombre']) ?></a> <span class="text-xs text-slate-400"><?= e(pct_txt($pv['comision_pct'])) ?></span></td>
              <td data-label="Vendido" class="whitespace-nowrap md:text-right"><?= e(money($pv['venta'], $mon)) ?></td>
              <td data-label="A pagar" class="whitespace-nowrap md:text-right"><?= e(money($pv['costo'], $mon)) ?></td>
              <td data-label="Tu comision" class="whitespace-nowrap text-emerald-700 md:text-right"><?= e(money($pv['venta'] - $pv['costo'], $mon)) ?></td>
              <td data-label="Abonado (periodo)" class="whitespace-nowrap md:text-right"><?= e(money($pv['abonado_periodo'], $mon)) ?></td>
              <td data-label="Saldo actual" class="whitespace-nowrap font-bold md:text-right <?= $sal > 0 ? 'text-rose-600' : 'text-slate-500' ?>"><?= e(money($sal, $mon)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>
  </div>

  <aside class="min-w-0 space-y-5">
    <!-- Flujo de dinero -->
    <section class="card p-5">
      <h3 class="text-base font-bold text-slate-900">Dinero del periodo</h3>
      <p class="mb-4 text-xs text-slate-500">Segun la fecha en que se recibio o se hizo cada pago.</p>
      <div class="space-y-3">
        <div class="flex items-center justify-between gap-3"><span class="flex items-center gap-2 text-sm text-slate-600"><i data-lucide="arrow-down-left" class="h-4 w-4 text-emerald-600"></i>Cobrado a clientes</span><strong class="text-emerald-600"><?= e(money($ingresos, $mon)) ?></strong></div>
        <div class="flex items-center justify-between gap-3"><span class="flex items-center gap-2 text-sm text-slate-600"><i data-lucide="arrow-up-right" class="h-4 w-4 text-rose-600"></i>Pagado a proveedores</span><strong class="text-rose-600"><?= e(money($egresos, $mon)) ?></strong></div>
        <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-3"><span class="text-sm font-semibold text-slate-800">Neto</span><strong class="text-lg <?= $ingresos - $egresos >= 0 ? 'text-slate-900' : 'text-rose-600' ?>"><?= e(money($ingresos - $egresos, $mon)) ?></strong></div>
      </div>
      <?php if ($ingresosMetodo): ?>
        <div class="mt-4 border-t border-slate-100 pt-3">
          <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Cobros por metodo</p>
          <?php foreach ($ingresosMetodo as $im): $pct = $ingresos > 0 ? round($im['monto'] * 100 / $ingresos) : 0; ?>
            <div class="mb-2">
              <div class="flex justify-between text-xs"><span class="text-slate-600"><?= e($im['metodo']) ?></span><span class="font-semibold"><?= e(money($im['monto'], $mon)) ?></span></div>
              <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: <?= $pct ?>%"></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <!-- Proveedores de los eventos del periodo -->
    <section class="card p-5">
      <h3 class="mb-3 text-base font-bold text-slate-900">Proveedores de estos eventos</h3>
      <div class="space-y-2 text-sm">
        <div class="flex justify-between"><span class="text-slate-600">A pagar</span><strong><?= e(money($k['costo'], $mon)) ?></strong></div>
        <div class="flex justify-between"><span class="text-slate-600">Abonado a esos eventos</span><strong class="text-emerald-600"><?= e(money($k['abonado_prov'], $mon)) ?></strong></div>
        <div class="flex justify-between border-t border-slate-100 pt-2"><span class="font-semibold text-slate-800">Adeudo</span><strong class="<?= $k['adeudo_prov'] > 0 ? 'text-rose-600' : 'text-slate-900' ?>"><?= e(money($k['adeudo_prov'], $mon)) ?></strong></div>
      </div>
      <p class="mt-2 text-[11px] text-slate-400">Solo cuenta abonos ligados a un evento. Los abonos generales se ven en el saldo de cada proveedor.</p>
    </section>

    <!-- Cotizaciones -->
    <section class="card p-5">
      <h3 class="text-base font-bold text-slate-900">Cotizaciones creadas</h3>
      <p class="mb-4 text-xs text-slate-500">Segun la fecha de la cotizacion.</p>
      <div class="grid grid-cols-2 gap-3 text-center">
        <div class="rounded-xl bg-slate-50 p-3"><p class="text-xl font-extrabold text-slate-900"><?= (int)$cq['creadas'] ?></p><p class="text-xs text-slate-500">Creadas</p></div>
        <div class="rounded-xl bg-sky-50 p-3"><p class="text-xl font-extrabold text-sky-700"><?= (int)$cq['enviadas'] ?></p><p class="text-xs text-slate-500">Enviadas</p></div>
        <div class="rounded-xl bg-emerald-50 p-3"><p class="text-xl font-extrabold text-emerald-700"><?= (int)$cq['aceptadas'] ?></p><p class="text-xs text-slate-500">Aceptadas</p></div>
        <div class="rounded-xl bg-rose-50 p-3"><p class="text-xl font-extrabold text-rose-700"><?= (int)$cq['rechazadas'] ?></p><p class="text-xs text-slate-500">Rechazadas</p></div>
      </div>
      <div class="mt-4">
        <div class="flex justify-between text-xs"><span class="text-slate-600">Tasa de aceptacion</span><strong><?= $tasa ?>%</strong></div>
        <div class="mt-1 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-500" style="width: <?= $tasa ?>%"></div></div>
        <p class="mt-2 text-xs text-slate-500"><?= (int)$cq['pendientes'] ?> siguen pendientes · Monto cotizado <?= e(money($cq['monto'], $mon)) ?></p>
      </div>
    </section>

    <?php if ($topProd): ?>
    <section class="card p-5">
      <h3 class="mb-3 text-base font-bold text-slate-900">Lo mas vendido</h3>
      <ul class="space-y-2 text-sm">
        <?php foreach ($topProd as $tp): ?>
          <li class="flex items-start justify-between gap-3">
            <span class="min-w-0 truncate text-slate-700"><?= e($tp['descripcion']) ?> <span class="text-xs text-slate-400">x<?= e(rtrim(rtrim(number_format((float)$tp['cantidad'], 2, '.', ','), '0'), '.')) ?></span></span>
            <strong class="shrink-0"><?= e(money($tp['venta'], $mon)) ?></strong>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
  </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
