<?php
/**
 * Reporte de liquidacion de un evento: lo que debe el cliente y lo que se debe pagar a cada proveedor.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/proveedores.php';
require __DIR__ . '/includes/alertas.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();
$mon = $emp['moneda'];
$id = input_int('id');

$cot = db_one('SELECT * FROM cotizaciones WHERE id = ? AND empresa_id = ?', [$id, $eid]);
if (!$cot) {
    flash('error', 'Evento no encontrado.');
    redirect('index.php');
}
$cliente = db_one('SELECT * FROM clientes WHERE id = ?', [$cot['cliente_id']]);
$numero = numero_cotizacion($cot, $emp);
$pagosCli = db_all('SELECT * FROM cotizacion_pagos WHERE cotizacion_id = ? ORDER BY fecha, id', [$id]);
$cobrado = array_sum(array_map(function ($p) { return (float)$p['monto']; }, $pagosCli));
$saldoCli = round((float)$cot['total'] - $cobrado, 2);

// Proveedores del evento con sus lineas y abonos ligados al evento
$lineasProv = db_all('SELECT d.*, p.nombre AS proveedor, p.telefono AS prov_tel, p.contacto AS prov_contacto
    FROM cotizacion_detalle d JOIN proveedores p ON p.id = d.proveedor_id
    WHERE d.cotizacion_id = ? ORDER BY p.nombre, d.orden', [$id]);
$provs = [];
foreach ($lineasProv as $l) {
    $pid = (int)$l['proveedor_id'];
    if (!isset($provs[$pid])) {
        $provs[$pid] = ['id' => $pid, 'nombre' => $l['proveedor'], 'telefono' => $l['prov_tel'], 'contacto' => $l['prov_contacto'],
            'lineas' => [], 'venta' => 0, 'costo' => 0, 'abonos' => [], 'abonado' => 0];
    }
    $provs[$pid]['lineas'][] = $l;
    $provs[$pid]['venta'] += (float)$l['subtotal'];
    $provs[$pid]['costo'] += (float)$l['costo_proveedor'];
}
foreach (db_all('SELECT * FROM proveedor_pagos WHERE cotizacion_id = ? AND empresa_id = ? ORDER BY fecha, id', [$id, $eid]) as $ab) {
    $pid = (int)$ab['proveedor_id'];
    if (!isset($provs[$pid])) {
        $nombre = db_val('SELECT nombre FROM proveedores WHERE id = ?', [$pid]);
        $provs[$pid] = ['id' => $pid, 'nombre' => $nombre, 'telefono' => null, 'contacto' => null, 'lineas' => [], 'venta' => 0, 'costo' => 0, 'abonos' => [], 'abonado' => 0];
    }
    $provs[$pid]['abonos'][] = $ab;
    $provs[$pid]['abonado'] += (float)$ab['monto'];
}
$costoProv = 0;
$abonadoProv = 0;
foreach ($provs as $p) {
    $costoProv += $p['costo'];
    $abonadoProv += $p['abonado'];
}
$saldoProv = round($costoProv - $abonadoProv, 2);
$ganancia = (float)$cot['total'] - $costoProv;
$dias = (int)round((strtotime($cot['fecha_evento']) - strtotime(date('Y-m-d'))) / 86400);
$liquidado = $saldoCli <= 0.004 && $saldoProv <= 0.004;

$titulo = 'Liquidacion ' . $numero;
$activo = 'cotizaciones';
$accionesHeader = '<a href="cotizacion_ver.php?id=' . (int)$id . '" class="btn btn-claro btn-sm"><i data-lucide="arrow-left"></i><span class="hidden sm:inline">Cotizacion</span></a>'
    . '<button type="button" onclick="window.print()" class="btn btn-claro btn-sm"><i data-lucide="printer"></i><span class="hidden sm:inline">Imprimir / PDF</span></button>';
require __DIR__ . '/includes/header.php';
?>
<div class="mx-auto max-w-4xl space-y-5">
  <!-- Encabezado -->
  <section class="card overflow-hidden">
    <div class="h-2" style="background: linear-gradient(90deg, var(--c1), var(--c2))"></div>
    <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6">
      <div class="min-w-0">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Reporte de liquidacion</p>
        <p class="text-xl font-extrabold text-slate-900"><?= e($cot['nombre_evento'] ?: $cliente['nombre']) ?></p>
        <?php if (!empty($cot['nombre_evento'])): ?><p class="text-sm font-semibold text-slate-700"><?= e($cliente['nombre']) ?></p><?php endif; ?>
        <p class="text-sm text-slate-600"><?= e($numero) ?> · <?= e(ucfirst(fecha_larga($cot['fecha_evento']))) ?><?= $cot['hora_evento'] ? ', ' . e(hora($cot['hora_evento'])) : '' ?></p>
        <?php if ($cot['lugar_evento']): ?><p class="text-sm text-slate-500"><?= e($cot['lugar_evento']) ?></p><?php endif; ?>
        <?php if ($cliente['telefono']): ?><p class="text-sm text-slate-500">Cliente: <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $cliente['telefono'])) ?>" class="hover:underline"><?= e($cliente['telefono']) ?></a></p><?php endif; ?>
      </div>
      <div class="shrink-0 sm:text-right">
        <?php if ($liquidado): ?>
          <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-sm font-bold text-emerald-800"><i data-lucide="check-circle-2" class="h-4 w-4"></i>Evento liquidado</span>
        <?php else: ?>
          <span class="inline-flex items-center gap-1.5 rounded-full <?= $dias < 0 ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800' ?> px-3 py-1 text-sm font-bold"><i data-lucide="bell-ring" class="h-4 w-4"></i><?= e(texto_dias($dias)) ?></span>
          <p class="mt-1 text-xs text-slate-500">El evento debe quedar cancelado antes de su fecha.</p>
        <?php endif; ?>
        <?php if ($cot['estado'] !== 'aceptada'): ?><p class="mt-1 text-xs font-semibold text-amber-700">Cotizacion <?= e($cot['estado']) ?></p><?php endif; ?>
      </div>
    </div>
    <div class="grid grid-cols-2 divide-x divide-y divide-slate-100 border-t border-slate-100 text-center sm:grid-cols-4 sm:divide-y-0">
      <div class="px-2 py-4"><p class="text-xs font-semibold text-slate-500">Total del evento</p><p class="text-base font-extrabold text-slate-900 sm:text-lg"><?= e(money($cot['total'], $mon)) ?></p></div>
      <div class="px-2 py-4"><p class="text-xs font-semibold text-slate-500">Cliente debe</p><p class="text-base font-extrabold sm:text-lg <?= $saldoCli > 0 ? 'text-rose-600' : 'text-emerald-600' ?>"><?= e(money(max(0, $saldoCli), $mon)) ?></p></div>
      <div class="px-2 py-4"><p class="text-xs font-semibold text-slate-500">Debo a proveedores</p><p class="text-base font-extrabold sm:text-lg <?= $saldoProv > 0 ? 'text-rose-600' : 'text-emerald-600' ?>"><?= e(money(max(0, $saldoProv), $mon)) ?></p></div>
      <div class="px-2 py-4"><p class="text-xs font-semibold text-slate-500">Mi ganancia</p><p class="text-base font-extrabold text-emerald-600 sm:text-lg"><?= e(money($ganancia, $mon)) ?></p></div>
    </div>
  </section>

  <!-- Cliente -->
  <section class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
      <h2 class="flex items-center gap-2 text-base font-bold text-slate-900"><i data-lucide="user" class="h-5 w-5 texto-marca"></i>Cobro al cliente</h2>
      <?php if ($saldoCli > 0): ?><a href="cotizacion_ver.php?id=<?= (int)$id ?>#pagos" class="btn btn-exito btn-sm no-print"><i data-lucide="plus"></i>Registrar pago</a><?php endif; ?>
    </div>
    <div class="px-5 py-3">
      <?php if (!$pagosCli): ?>
        <p class="py-2 text-sm text-slate-400">El cliente no ha realizado pagos.</p>
      <?php else: ?>
        <table class="w-full text-sm">
          <?php foreach ($pagosCli as $pg): ?>
            <tr class="border-b border-slate-100">
              <td class="py-2 pr-2 text-slate-500"><?= e(fecha($pg['fecha'])) ?></td>
              <td class="py-2 pr-2 text-slate-800"><?= e($pg['descripcion']) ?><?= $pg['metodo'] ? ' <span class="text-xs text-slate-400">(' . e($pg['metodo']) . ')</span>' : '' ?></td>
              <td class="whitespace-nowrap py-2 text-right font-semibold"><?= e(money($pg['monto'], $mon)) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      <div class="mt-3 space-y-1 text-sm">
        <div class="flex justify-between"><span class="text-slate-500">Total</span><span class="font-semibold"><?= e(money($cot['total'], $mon)) ?></span></div>
        <div class="flex justify-between"><span class="text-slate-500">Pagado</span><span class="font-semibold text-emerald-600"><?= e(money($cobrado, $mon)) ?></span></div>
        <div class="flex justify-between border-t border-slate-100 pt-2"><span class="font-bold text-slate-800">Pendiente de cobro</span><span class="font-extrabold <?= $saldoCli > 0 ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $saldoCli > 0 ? e(money($saldoCli, $mon)) : 'Pagado' ?></span></div>
      </div>
    </div>
  </section>

  <!-- Proveedores -->
  <section class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
      <h2 class="flex items-center gap-2 text-base font-bold text-slate-900"><i data-lucide="truck" class="h-5 w-5 texto-marca"></i>Pago a proveedores</h2>
      <span class="text-sm">Pendiente: <strong class="<?= $saldoProv > 0 ? 'text-rose-600' : 'text-emerald-600' ?>"><?= e(money(max(0, $saldoProv), $mon)) ?></strong></span>
    </div>
    <?php if (!$provs): ?>
      <p class="px-5 py-6 text-sm text-slate-400">Este evento no tiene productos de proveedores.</p>
    <?php else: ?>
      <div class="divide-y divide-slate-100">
        <?php foreach ($provs as $p): $sp = round($p['costo'] - $p['abonado'], 2); ?>
          <div class="px-5 py-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <a href="proveedor_ver.php?id=<?= (int)$p['id'] ?>" class="font-bold text-slate-900 hover:underline"><?= e($p['nombre']) ?></a>
                <p class="text-xs text-slate-500"><?= e(implode(' · ', array_filter([$p['contacto'], $p['telefono']]))) ?></p>
              </div>
              <div class="flex items-center gap-2">
                <span class="rounded-full px-2.5 py-0.5 text-xs font-bold <?= $sp > 0 ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700' ?>"><?= $sp > 0 ? 'Debo ' . e(money($sp, $mon)) : 'Pagado' ?></span>
                <?php if ($sp > 0): ?><a href="proveedor_ver.php?id=<?= (int)$p['id'] ?>&cotizacion_id=<?= (int)$id ?>#abono" class="btn btn-claro btn-sm no-print"><i data-lucide="hand-coins"></i>Abonar</a><?php endif; ?>
              </div>
            </div>
            <table class="mt-3 w-full text-sm">
              <?php foreach ($p['lineas'] as $l): ?>
                <tr class="border-b border-slate-100">
                  <td class="py-1.5 pr-2 text-slate-700"><?= e($l['descripcion']) ?> <span class="text-xs text-slate-400">x<?= e(rtrim(rtrim(number_format((float)$l['cantidad'], 2, '.', ','), '0'), '.')) ?></span></td>
                  <td class="whitespace-nowrap py-1.5 text-right text-xs text-slate-400">venta <?= e(money($l['subtotal'], $mon)) ?></td>
                  <td class="whitespace-nowrap py-1.5 pl-3 text-right font-semibold"><?= e(money($l['costo_proveedor'], $mon)) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php foreach ($p['abonos'] as $ab): ?>
                <tr class="text-emerald-700">
                  <td class="py-1.5 pr-2" colspan="2">Abono <?= e(fecha($ab['fecha'])) ?> · <?= e($ab['descripcion']) ?></td>
                  <td class="whitespace-nowrap py-1.5 pl-3 text-right font-semibold">- <?= e(money($ab['monto'], $mon)) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
            <div class="mt-2 flex justify-end gap-6 text-sm">
              <span class="text-slate-500">A pagar <strong class="text-slate-900"><?= e(money($p['costo'], $mon)) ?></strong></span>
              <span class="text-slate-500">Abonado <strong class="text-emerald-600"><?= e(money($p['abonado'], $mon)) ?></strong></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="border-t border-slate-100 px-5 py-2.5 text-[11px] text-slate-400">Solo se cuentan los abonos ligados a este evento. Los abonos generales se ven en la ficha de cada proveedor.</p>
    <?php endif; ?>
  </section>

  <!-- Resumen -->
  <section class="card p-5">
    <h2 class="mb-3 text-base font-bold text-slate-900">Resumen</h2>
    <div class="space-y-2 text-sm">
      <div class="flex justify-between"><span class="text-slate-600">Total del evento</span><strong><?= e(money($cot['total'], $mon)) ?></strong></div>
      <div class="flex justify-between"><span class="text-slate-600">Costo de proveedores</span><strong>- <?= e(money($costoProv, $mon)) ?></strong></div>
      <div class="flex justify-between border-t border-slate-100 pt-2"><span class="font-semibold text-slate-800">Mi ganancia</span><strong class="text-emerald-600"><?= e(money($ganancia, $mon)) ?></strong></div>
      <div class="flex justify-between pt-3"><span class="text-slate-600">Dinero recibido del cliente</span><strong class="text-emerald-600"><?= e(money($cobrado, $mon)) ?></strong></div>
      <div class="flex justify-between"><span class="text-slate-600">Pagado a proveedores</span><strong>- <?= e(money($abonadoProv, $mon)) ?></strong></div>
      <div class="flex justify-between border-t border-slate-100 pt-2"><span class="font-semibold text-slate-800">Dinero en mano de este evento</span><strong><?= e(money($cobrado - $abonadoProv, $mon)) ?></strong></div>
    </div>
  </section>
  <p class="text-center text-xs text-slate-400">Generado el <?= e(fecha(date('Y-m-d H:i:s'), true)) ?> · <?= e($emp['nombre']) ?></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
