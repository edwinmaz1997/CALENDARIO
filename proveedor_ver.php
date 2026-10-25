<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/proveedores.php';
require __DIR__ . '/includes/pagos.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();
$id = input_int('id');
$prov = proveedor_con_saldo($eid, $id);
if (!$prov) {
    flash('error', 'Proveedor no encontrado.');
    redirect('proveedores.php');
}

if (is_post()) {
    csrf_check();
    $accion = input('accion');
    if ($accion === 'abonar') {
        $desc = input('descripcion');
        $monto = str_replace([',', ' '], '', input('monto'));
        $fecha = input('fecha', date('Y-m-d'));
        $metodo = input('metodo');
        $cotId = input_int('cotizacion_id') ?: null;
        $err = null;
        if ($desc === '') {
            $err = 'Escribe la descripcion del abono.';
        } elseif (!is_numeric($monto) || (float)$monto <= 0) {
            $err = 'El monto debe ser mayor a cero.';
        } elseif (!valid_date($fecha)) {
            $err = 'La fecha no es valida.';
        } elseif ($cotId && !db_val('SELECT id FROM cotizaciones WHERE id = ? AND empresa_id = ?', [$cotId, $eid])) {
            $err = 'El evento seleccionado no es valido.';
        }
        if ($err) {
            flash('error', $err);
        } else {
            db_exec('INSERT INTO proveedor_pagos (empresa_id, proveedor_id, cotizacion_id, fecha, descripcion, metodo, monto, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                $eid, $id, $cotId, $fecha, mb_substr($desc, 0, 150), in_array($metodo, pagos_metodos(), true) ? $metodo : null, round((float)$monto, 2), usuario_actual()['id'],
            ]);
            $nuevo = proveedor_con_saldo($eid, $id);
            flash('ok', 'Abono de ' . money($monto) . ' registrado. ' . ($nuevo['saldo'] > 0 ? 'Adeudo restante: ' . money($nuevo['saldo']) . '.' : ($nuevo['saldo'] < 0 ? 'Saldo a tu favor: ' . money(abs($nuevo['saldo'])) . '.' : 'Proveedor al dia.')));
        }
        redirect('proveedor_ver.php?id=' . $id . '#abonos');
    }
    if ($accion === 'quitar_abono') {
        $ab = db_one('SELECT * FROM proveedor_pagos WHERE id = ? AND proveedor_id = ? AND empresa_id = ?', [input_int('abono_id'), $id, $eid]);
        if ($ab) {
            db_exec('DELETE FROM proveedor_pagos WHERE id = ?', [$ab['id']]);
            flash('ok', 'Abono eliminado.');
        }
        redirect('proveedor_ver.php?id=' . $id . '#abonos');
    }
}

// Eventos (cotizaciones) con productos de este proveedor
$eventos = db_all("SELECT c.id, c.correlativo, c.estado, c.fecha_evento, cl.nombre AS cliente,
        SUM(d.subtotal) AS venta, SUM(d.costo_proveedor) AS costo,
        (SELECT COALESCE(SUM(pp.monto), 0) FROM proveedor_pagos pp WHERE pp.cotizacion_id = c.id AND pp.proveedor_id = ?) AS abonado
    FROM cotizacion_detalle d
    JOIN cotizaciones c ON c.id = d.cotizacion_id
    JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.empresa_id = ? AND d.proveedor_id = ? AND c.estado IN ('aceptada', 'pendiente')
    GROUP BY c.id, c.correlativo, c.estado, c.fecha_evento, cl.nombre
    ORDER BY c.estado = 'aceptada' DESC, c.fecha_evento DESC", [$id, $eid, $id]);
$aceptados = array_values(array_filter($eventos, function ($e) { return $e['estado'] === 'aceptada'; }));
$pendientes = array_values(array_filter($eventos, function ($e) { return $e['estado'] === 'pendiente'; }));
$porConfirmar = array_sum(array_map(function ($e) { return (float)$e['costo']; }, $pendientes));

$abonos = db_all('SELECT pp.*, c.correlativo FROM proveedor_pagos pp LEFT JOIN cotizaciones c ON c.id = pp.cotizacion_id
    WHERE pp.proveedor_id = ? AND pp.empresa_id = ? ORDER BY pp.fecha DESC, pp.id DESC', [$id, $eid]);
$productos = db_all('SELECT id, nombre, precio, activo FROM productos WHERE proveedor_id = ? AND empresa_id = ? ORDER BY nombre', [$id, $eid]);
$eventoPre = input_int('cotizacion_id');

$titulo = $prov['nombre'];
$activo = 'proveedores';
$accionesHeader = '<a href="proveedores.php" class="btn btn-claro btn-sm"><i data-lucide="arrow-left"></i><span class="hidden sm:inline">Volver</span></a>'
    . '<a href="proveedores.php?accion=editar&id=' . (int)$id . '" class="btn btn-claro btn-sm"><i data-lucide="pencil"></i><span class="hidden sm:inline">Editar</span></a>';
require __DIR__ . '/includes/header.php';
?>
<div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
  <div class="min-w-0 space-y-5 lg:col-span-2">
    <!-- Resumen -->
    <section class="card overflow-hidden">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
        <div class="min-w-0">
          <p class="text-xs text-slate-500"><?= e(implode(' · ', array_filter([$prov['contacto'], $prov['telefono'], $prov['correo']])) ?: 'Sin datos de contacto') ?></p>
        </div>
        <span class="rounded-full bg-marca-suave px-3 py-1 text-xs font-bold texto-marca">Tu comision: <?= e(pct_txt($prov['comision_pct'])) ?></span>
      </div>
      <div class="grid grid-cols-3 divide-x divide-slate-100 text-center">
        <div class="px-2 py-4"><p class="text-xs font-semibold text-slate-500">Compras</p><p class="text-sm font-extrabold text-slate-900 sm:text-lg"><?= e(money($prov['compras'])) ?></p></div>
        <div class="px-2 py-4"><p class="text-xs font-semibold text-slate-500">Abonado</p><p class="text-sm font-extrabold text-emerald-600 sm:text-lg"><?= e(money($prov['pagado'])) ?></p></div>
        <div class="px-2 py-4"><p class="text-xs font-semibold text-slate-500"><?= $prov['saldo'] < 0 ? 'A tu favor' : 'Adeudo' ?></p><p class="text-sm font-extrabold sm:text-lg <?= $prov['saldo'] > 0 ? 'text-rose-600' : 'text-slate-900' ?>"><?= e(money(abs($prov['saldo']))) ?></p></div>
      </div>
      <?php if ($porConfirmar > 0): ?>
        <p class="border-t border-slate-100 bg-amber-50 px-5 py-2.5 text-xs text-amber-800">Hay <?= count($pendientes) ?> cotizacion(es) pendiente(s) con <?= e(money($porConfirmar)) ?> para este proveedor. Se sumaran al adeudo cuando se acepten.</p>
      <?php endif; ?>
    </section>

    <!-- Compras por evento -->
    <section class="card overflow-hidden">
      <h2 class="border-b border-slate-100 px-5 py-4 text-base font-bold text-slate-900">Compras por evento</h2>
      <?php if (!$eventos): ?>
        <p class="px-5 py-8 text-center text-sm text-slate-400">Este proveedor aun no tiene productos en cotizaciones.</p>
      <?php else: ?>
      <table class="tabla tabla-movil">
        <thead><tr><th>Evento</th><th class="text-right">Venta</th><th class="text-right">A pagar</th><th class="text-right">Abonado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($eventos as $ev): $pend = $ev['estado'] === 'pendiente'; ?>
          <tr class="<?= $pend ? 'opacity-60' : '' ?>">
            <td class="td-principal">
              <a href="cotizacion_ver.php?id=<?= (int)$ev['id'] ?>" class="font-semibold texto-marca hover:underline"><?= e(numero_cotizacion($ev, $emp)) ?></a>
              <span class="text-sm text-slate-700"><?= e($ev['cliente']) ?></span>
              <p class="text-xs text-slate-500"><?= e(fecha($ev['fecha_evento'])) ?><?= $pend ? ' · Pendiente (no suma al adeudo)' : '' ?></p>
            </td>
            <td data-label="Venta" class="md:text-right"><?= e(money($ev['venta'])) ?></td>
            <td data-label="A pagar" class="font-semibold md:text-right"><?= e(money($ev['costo'])) ?></td>
            <td data-label="Abonado" class="text-emerald-600 md:text-right"><?= e(money($ev['abonado'])) ?></td>
            <td class="td-acciones">
              <?php if (!$pend): ?>
                <a href="proveedor_ver.php?id=<?= (int)$id ?>&cotizacion_id=<?= (int)$ev['id'] ?>#abono" class="btn btn-claro btn-sm" title="Abonar a este evento"><i data-lucide="hand-coins"></i><span class="md:hidden">Abonar</span></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>

    <!-- Abonos -->
    <section id="abonos" class="card overflow-hidden" style="scroll-margin-top:5rem">
      <h2 class="border-b border-slate-100 px-5 py-4 text-base font-bold text-slate-900">Abonos realizados</h2>
      <?php if (!$abonos): ?>
        <p class="px-5 py-8 text-center text-sm text-slate-400">Aun no hay abonos a este proveedor.</p>
      <?php else: ?>
        <ul class="divide-y divide-slate-100 px-5">
          <?php foreach ($abonos as $ab): ?>
            <li class="flex items-center gap-3 py-3">
              <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600"><i data-lucide="hand-coins" class="h-4 w-4"></i></div>
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-slate-900"><?= e($ab['descripcion']) ?></p>
                <p class="truncate text-xs text-slate-500"><?= e(fecha($ab['fecha'])) ?><?= $ab['metodo'] ? ' · ' . e($ab['metodo']) : '' ?><?= $ab['cotizacion_id'] ? ' · <a class="texto-marca hover:underline" href="cotizacion_ver.php?id=' . (int)$ab['cotizacion_id'] . '">' . e(numero_cotizacion($ab, $emp)) . '</a>' : '' ?></p>
              </div>
              <p class="shrink-0 text-sm font-bold text-slate-900"><?= e(money($ab['monto'])) ?></p>
              <form method="post" data-confirmar="Eliminar el abono de <?= e(money($ab['monto'])) ?>?">
                <?= csrf_field() ?><input type="hidden" name="accion" value="quitar_abono"><input type="hidden" name="abono_id" value="<?= (int)$ab['id'] ?>">
                <button class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Eliminar"><i data-lucide="trash-2" class="h-4 w-4"></i></button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

  <aside class="min-w-0 space-y-5">
    <!-- Registrar abono -->
    <section id="abono" class="card p-5" style="scroll-margin-top:5rem">
      <h2 class="mb-4 flex items-center gap-2 text-base font-bold text-slate-900"><i data-lucide="hand-coins" class="h-5 w-5 texto-marca"></i>Registrar abono</h2>
      <form method="post" class="space-y-3" id="form-abono">
        <?= csrf_field() ?><input type="hidden" name="accion" value="abonar">
        <label class="campo"><span>Descripcion *</span>
          <input class="input" name="descripcion" list="desc-abono" maxlength="150" required placeholder="Ej: Anticipo pastel">
          <datalist id="desc-abono"><option value="Anticipo"><option value="Abono"><option value="Pago final"></datalist>
        </label>
        <label class="campo"><span>Monto (<?= e($emp['moneda']) ?>) *</span>
          <input class="input text-right" name="monto" id="abono-monto" inputmode="decimal" required placeholder="<?= $prov['saldo'] > 0 ? e(number_format($prov['saldo'], 2, '.', '')) : '0.00' ?>">
          <?php if ($prov['saldo'] > 0): ?><button type="button" class="mt-1 text-xs font-semibold texto-marca hover:underline" onclick="document.getElementById('abono-monto').value='<?= e(number_format($prov['saldo'], 2, '.', '')) ?>'">Usar adeudo total</button><?php endif; ?>
        </label>
        <div class="grid grid-cols-2 gap-3">
          <label class="campo"><span>Fecha</span><input class="input" type="date" name="fecha" value="<?= e(date('Y-m-d')) ?>" required></label>
          <label class="campo"><span>Metodo</span>
            <select class="select" name="metodo"><option value="">—</option><?php foreach (pagos_metodos() as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select>
          </label>
        </div>
        <label class="campo"><span>Evento (opcional)</span>
          <select class="select" name="cotizacion_id">
            <option value="">General (sin evento)</option>
            <?php foreach ($aceptados as $ev): ?>
              <option value="<?= (int)$ev['id'] ?>" <?= $eventoPre === (int)$ev['id'] ? 'selected' : '' ?>><?= e(numero_cotizacion($ev, $emp) . ' · ' . $ev['cliente'] . ' · ' . fecha($ev['fecha_evento'])) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-exito w-full"><i data-lucide="check"></i>Guardar abono</button>
      </form>
    </section>

    <section class="card p-5">
      <h2 class="mb-3 text-sm font-bold text-slate-900">Productos de este proveedor</h2>
      <?php if (!$productos): ?>
        <p class="text-sm text-slate-500">Ninguno. Asigna el proveedor desde <a href="productos.php" class="font-semibold texto-marca hover:underline">Productos</a>.</p>
      <?php else: ?>
        <ul class="space-y-2 text-sm">
          <?php foreach ($productos as $pr): ?>
            <li class="flex items-center justify-between gap-2 <?= $pr['activo'] ? '' : 'opacity-50' ?>">
              <a href="productos.php?accion=editar&id=<?= (int)$pr['id'] ?>" class="min-w-0 truncate text-slate-700 hover:underline"><?= e($pr['nombre']) ?></a>
              <span class="shrink-0 text-right text-xs"><span class="font-semibold text-slate-900"><?= e(money($pr['precio'])) ?></span><br><span class="text-slate-500">pagas <?= e(money(costo_proveedor($pr['precio'], $prov['comision_pct']))) ?></span></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <?php if ($prov['notas']): ?>
      <section class="card p-5"><h2 class="mb-2 text-sm font-bold text-slate-900">Notas</h2><p class="whitespace-pre-line text-sm text-slate-600"><?= e($prov['notas']) ?></p></section>
    <?php endif; ?>

    <form method="post" action="proveedores.php?accion=eliminar&id=<?= (int)$id ?>" data-confirmar="Eliminar este proveedor? Si tiene movimientos se desactivara.">
      <?= csrf_field() ?>
      <button class="inline-flex items-center gap-1.5 text-sm font-semibold text-rose-600 hover:underline"><i data-lucide="trash-2" class="h-4 w-4"></i>Eliminar proveedor</button>
    </form>
  </aside>
</div>
<script>
  (function () {
    var f = document.getElementById('form-abono'), saldo = <?= json_encode(round((float)$prov['saldo'], 2)) ?>;
    f.addEventListener('submit', function (ev) {
      var m = parseFloat(String(f.monto.value).replace(/,/g, '')) || 0;
      if (m > saldo + 0.001 && !confirm('El abono es mayor al adeudo actual. Quedara un saldo a tu favor. Continuar?')) ev.preventDefault();
    }, true);
  })();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
