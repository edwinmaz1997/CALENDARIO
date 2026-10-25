<?php
require __DIR__ . '/includes/bootstrap.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();

$q = input('q');
$estado = input('estado');
$desde = input('desde');
$hasta = input('hasta');

$where = 'c.empresa_id = ?';
$params = [$eid];
if ($estado === 'por_enviar') {
    $where .= " AND c.estado = 'pendiente' AND c.enviada_at IS NULL";
} elseif ($estado === 'enviadas') {
    $where .= " AND c.estado = 'pendiente' AND c.enviada_at IS NOT NULL";
} elseif (isset(estados()[$estado])) {
    $where .= ' AND c.estado = ?';
    $params[] = $estado;
} else {
    $estado = '';
}
if (valid_date($desde)) {
    $where .= ' AND c.fecha_evento >= ?';
    $params[] = $desde;
} else {
    $desde = '';
}
if (valid_date($hasta)) {
    $where .= ' AND c.fecha_evento <= ?';
    $params[] = $hasta;
} else {
    $hasta = '';
}
if ($q !== '') {
    $num = (int)preg_replace('/\D/', '', $q);
    $where .= ' AND (cl.nombre LIKE ? OR cl.nit LIKE ? OR c.lugar_evento LIKE ? OR c.nombre_evento LIKE ?' . ($num ? ' OR c.correlativo = ?' : '') . ')';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
    if ($num) {
        $params[] = $num;
    }
}

$pag = paginar((int)db_val("SELECT COUNT(*) FROM cotizaciones c JOIN clientes cl ON cl.id = c.cliente_id WHERE $where", $params));
$cots = db_all("SELECT c.*, cl.nombre AS cliente, (SELECT COUNT(*) FROM cotizacion_adjuntos a WHERE a.cotizacion_id = c.id) AS adjuntos, (SELECT COALESCE(SUM(pg.monto), 0) FROM cotizacion_pagos pg WHERE pg.cotizacion_id = c.id) AS pagado FROM cotizaciones c JOIN clientes cl ON cl.id = c.cliente_id
    WHERE $where ORDER BY c.id DESC LIMIT {$pag['limite']} OFFSET {$pag['offset']}", $params);

$conteos = array_map('intval', db_one("SELECT
        COALESCE(SUM(estado = 'pendiente'), 0) AS pendiente,
        COALESCE(SUM(estado = 'pendiente' AND enviada_at IS NULL), 0) AS por_enviar,
        COALESCE(SUM(estado = 'pendiente' AND enviada_at IS NOT NULL), 0) AS enviadas,
        COALESCE(SUM(estado = 'aceptada'), 0) AS aceptada,
        COALESCE(SUM(estado = 'rechazada'), 0) AS rechazada
    FROM cotizaciones WHERE empresa_id = ?", [$eid]));

$titulo = 'Cotizaciones';
$activo = 'cotizaciones';
$accionesHeader = '<a href="cotizacion_form.php" class="btn btn-primario btn-sm"><i data-lucide="plus"></i><span class="hidden sm:inline">Nueva cotizacion</span></a>';
require __DIR__ . '/includes/header.php';
?>
<!-- Pestanas por estado -->
<div class="-mx-4 mb-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
  <div class="inline-flex gap-1 rounded-xl bg-slate-200/60 p-1">
    <?php
    $tabs = ['' => ['Todas', $conteos['pendiente'] + $conteos['aceptada'] + $conteos['rechazada']]];
    $tabs['pendiente'] = ['Pendientes', $conteos['pendiente']];
    $tabs['por_enviar'] = ['Por enviar', $conteos['por_enviar']];
    $tabs['enviadas'] = ['Enviadas', $conteos['enviadas']];
    $tabs['aceptada'] = ['Aceptadas', $conteos['aceptada']];
    $tabs['rechazada'] = ['Rechazadas', $conteos['rechazada']];
    foreach ($tabs as $k => $t): $on = $estado === $k; ?>
      <a href="<?= e(url_con(['estado' => $k, 'p' => null])) ?>" class="whitespace-nowrap rounded-lg px-3.5 py-2 text-sm font-semibold transition <?= $on ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' ?>">
        <?= e($t[0]) ?> <span class="ml-1 rounded-full px-1.5 text-xs <?= $on ? 'bg-slate-100' : 'bg-white/60' ?>"><?= (int)$t[1] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<form class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end" method="get">
  <?php if ($estado): ?><input type="hidden" name="estado" value="<?= e($estado) ?>"><?php endif; ?>
  <label class="campo sm:col-span-2"><span>Buscar</span>
    <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Numero, cliente, evento, NIT o lugar">
  </label>
  <label class="campo"><span>Evento desde</span><input class="input" type="date" name="desde" value="<?= e($desde) ?>"></label>
  <label class="campo"><span>Evento hasta</span><input class="input" type="date" name="hasta" value="<?= e($hasta) ?>"></label>
  <div class="flex gap-2">
    <button class="btn btn-primario flex-1" type="submit"><i data-lucide="filter"></i>Filtrar</button>
    <?php if ($q !== '' || $desde || $hasta): ?><a class="btn btn-claro" href="cotizaciones.php<?= $estado ? '?estado=' . e($estado) : '' ?>" title="Limpiar"><i data-lucide="x"></i></a><?php endif; ?>
  </div>
</form>

<div class="card overflow-hidden">
  <?php if (!$cots): ?>
    <div class="px-6 py-14 text-center">
      <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><i data-lucide="file-text" class="h-6 w-6"></i></div>
      <p class="text-slate-500">No hay cotizaciones para mostrar.</p>
      <a href="cotizacion_form.php" class="btn btn-primario mt-4"><i data-lucide="plus"></i>Crear cotizacion</a>
    </div>
  <?php else: ?>
  <table class="tabla tabla-movil">
    <thead><tr><th>Numero</th><th>Cliente</th><th>Evento</th><th class="text-right">Total</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($cots as $c): ?>
      <tr>
        <td class="td-principal">
          <a href="cotizacion_ver.php?id=<?= (int)$c['id'] ?>" class="font-bold texto-marca hover:underline"><?= e(numero_cotizacion($c, $emp)) ?></a>
          <p class="text-xs text-slate-500">Creada <?= e(fecha($c['fecha'])) ?><?php if ((int)$c['adjuntos']): ?> · <span class="inline-flex items-center gap-0.5" title="Archivos adjuntos"><i data-lucide="paperclip" class="h-3 w-3"></i><?= (int)$c['adjuntos'] ?></span><?php endif; ?></p>
        </td>
        <td data-label="Cliente" class="font-medium text-slate-800"><?= e($c['cliente']) ?><?php if (!empty($c['nombre_evento'])): ?><span class="block text-xs font-semibold texto-marca"><?= e($c['nombre_evento']) ?></span><?php endif; ?></td>
        <td data-label="Evento">
          <span class="whitespace-nowrap"><?= e(fecha($c['fecha_evento'])) ?></span>
          <?php if ($c['hora_evento']): ?><span class="text-xs text-slate-500"><?= e(hora($c['hora_evento'])) ?></span><?php endif; ?>
        </td>
        <td data-label="Total" class="whitespace-nowrap md:text-right">
          <span class="font-bold"><?= e(money($c['total'], $emp['moneda'])) ?></span>
          <?php $saldoC = round($c['total'] - $c['pagado'], 2); if ((float)$c['pagado'] > 0 || $c['estado'] === 'aceptada'): ?>
            <span class="block text-xs font-semibold <?= $saldoC > 0 ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $saldoC > 0 ? 'Pendiente ' . e(money($saldoC, $emp['moneda'])) : 'Pagada' ?></span>
          <?php endif; ?>
        </td>
        <td data-label="Estado">
          <?= badge_estado($c['estado']) ?>
          <?php if ($c['estado'] === 'pendiente'): ?>
            <?= $c['enviada_at'] ? '<span class="ml-1 inline-flex items-center gap-1 rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800"><i data-lucide="send" class="h-3 w-3"></i>Enviada</span>' : '<span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">Por enviar</span>' ?>
          <?php endif; ?>
        </td>
        <td class="td-acciones">
          <div class="flex justify-end gap-1.5">
            <a href="cotizacion_ver.php?id=<?= (int)$c['id'] ?>" class="btn btn-claro btn-sm btn-icono" title="Ver"><i data-lucide="eye"></i></a>
            <a href="cotizacion_pdf.php?id=<?= (int)$c['id'] ?>" data-visor="pdf" data-titulo="<?= e(numero_cotizacion($c, $emp)) ?>" data-descarga="cotizacion_pdf.php?id=<?= (int)$c['id'] ?>&amp;descargar=1" class="btn btn-claro btn-sm btn-icono" title="PDF"><i data-lucide="file-down"></i></a>
            <a href="cotizacion_ver.php?id=<?= (int)$c['id'] ?>#adjuntos" class="btn btn-claro btn-sm btn-icono" title="Adjuntos"><i data-lucide="paperclip"></i></a>
            <?php if ($c['estado'] === 'pendiente'): ?>
              <a href="cotizacion_form.php?id=<?= (int)$c['id'] ?>" class="btn btn-claro btn-sm btn-icono" title="Editar"><i data-lucide="pencil"></i></a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/paginacion.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
