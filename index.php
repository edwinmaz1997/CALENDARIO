<?php
// Enlace corto del cliente: /?c=CODIGO
if (isset($_GET['c'])) {
    define('SIN_SESION', true);
    require __DIR__ . '/portal.php';
    exit;
}
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/proveedores.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();

$hoy = date('Y-m-d');
$iniMes = date('Y-m-01');
$finMes = date('Y-m-t');

$stats = db_one("SELECT
        SUM(estado = 'pendiente') AS pendientes,
        SUM(estado = 'pendiente' AND enviada_at IS NOT NULL) AS enviadas,
        SUM(estado = 'pendiente' AND enviada_at IS NULL) AS por_enviar,
        COALESCE(SUM(CASE WHEN estado = 'pendiente' THEN total END), 0) AS monto_pendiente,
        SUM(estado = 'aceptada' AND fecha_evento BETWEEN ? AND ?) AS eventos_mes,
        COALESCE(SUM(CASE WHEN estado = 'aceptada' AND fecha_evento BETWEEN ? AND ? THEN total END), 0) AS monto_mes,
        SUM(estado = 'aceptada' AND fecha_evento >= ?) AS proximos
    FROM cotizaciones WHERE empresa_id = ?", [$iniMes, $finMes, $iniMes, $finMes, $hoy, $eid]);

$cobro = db_one("SELECT COALESCE(SUM(c.total - COALESCE(p.pagado, 0)), 0) AS por_cobrar, COUNT(*) AS n
    FROM cotizaciones c
    LEFT JOIN (SELECT cotizacion_id, SUM(monto) AS pagado FROM cotizacion_pagos GROUP BY cotizacion_id) p ON p.cotizacion_id = c.id
    WHERE c.empresa_id = ? AND c.estado = 'aceptada' AND c.total - COALESCE(p.pagado, 0) > 0", [$eid]);

$deudaProv = 0;
$provConDeuda = 0;
foreach (proveedores_con_saldo($eid) as $pv) {
    if ($pv['saldo'] > 0.004) {
        $deudaProv += (float)$pv['saldo'];
        $provConDeuda++;
    }
}

$proximos = db_all("SELECT c.id, c.correlativo, c.nombre_evento, c.fecha_evento, c.hora_evento, c.lugar_evento, c.total, cl.nombre AS cliente,
        (SELECT COALESCE(SUM(pg.monto), 0) FROM cotizacion_pagos pg WHERE pg.cotizacion_id = c.id) AS pagado
    FROM cotizaciones c JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.empresa_id = ? AND c.estado = 'aceptada' AND c.fecha_evento >= ?
    ORDER BY c.fecha_evento, c.hora_evento LIMIT 6", [$eid, $hoy]);

$pendientes = db_all("SELECT c.id, c.correlativo, c.fecha, c.fecha_evento, c.total, c.enviada_at, cl.nombre AS cliente
    FROM cotizaciones c JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.empresa_id = ? AND c.estado = 'pendiente'
    ORDER BY c.enviada_at IS NOT NULL, c.fecha_evento LIMIT 6", [$eid]);

$fechaInicial = valid_date(input('fecha')) ? input('fecha') : null;

$meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

$titulo = 'Inicio';
$activo = 'dashboard';
$accionesHeader = '<a href="cotizacion_form.php" class="btn btn-primario btn-sm"><i data-lucide="plus"></i><span class="hidden sm:inline">Nueva cotizacion</span></a>';
require __DIR__ . '/includes/header.php';
?>
<!-- Indicadores -->
<div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 2xl:grid-cols-6">
  <?php
  $tarjetas = [
      ['Pendientes', (int)$stats['pendientes'], (int)$stats['enviadas'] . ' ya enviadas al cliente', 'clock', 'bg-amber-100 text-amber-700', 'cotizaciones.php?estado=pendiente'],
      ['Por enviar', (int)$stats['por_enviar'], 'Pendientes sin enviar', 'send', 'bg-orange-100 text-orange-700', 'cotizaciones.php?estado=por_enviar'],
      ['Eventos este mes', (int)$stats['eventos_mes'], 'Cotizaciones aceptadas', 'calendar-check', 'bg-emerald-100 text-emerald-700', 'cotizaciones.php?estado=aceptada&desde=' . $iniMes . '&hasta=' . $finMes],
      ['Vendido este mes', money($stats['monto_mes'], $emp['moneda']), 'Segun fecha de evento', 'trending-up', 'bg-sky-100 text-sky-700', null],
      ['Por cobrar', money($cobro['por_cobrar'], $emp['moneda']), (int)$cobro['n'] . ' aceptadas con saldo', 'wallet', 'bg-rose-100 text-rose-700', 'cotizaciones.php?estado=aceptada'],
      ['Debo a proveedores', money($deudaProv, $emp['moneda']), $provConDeuda . ' proveedor(es) con saldo', 'truck', 'bg-slate-200 text-slate-700', 'proveedores.php'],
  ];
  foreach ($tarjetas as $t): $tag = $t[5] ? 'a' : 'div'; ?>
    <<?= $tag ?> <?= $t[5] ? 'href="' . e($t[5]) . '"' : '' ?> class="card flex flex-col gap-3 p-4 transition sm:p-5 <?= $t[5] ? 'hover:shadow-md' : '' ?>">
      <div class="flex items-center justify-between gap-2">
        <p class="text-xs font-semibold text-slate-500 sm:text-sm"><?= e($t[0]) ?></p>
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg sm:h-9 sm:w-9 <?= $t[4] ?>"><i data-lucide="<?= $t[3] ?>" class="h-4 w-4 sm:h-5 sm:w-5"></i></span>
      </div>
      <p class="truncate text-xl font-extrabold text-slate-900 sm:text-2xl"><?= e($t[1]) ?></p>
      <p class="-mt-2 truncate text-xs text-slate-500"><?= e($t[2]) ?></p>
    </<?= $tag ?>>
  <?php endforeach; ?>
</div>

<?php if (!empty($_alertas)): ?>
<section class="card mb-5 overflow-hidden border-rose-200">
  <div class="flex items-center gap-3 border-b border-rose-100 bg-rose-50 px-5 py-3">
    <i data-lucide="bell-ring" class="h-5 w-5 shrink-0 text-rose-600"></i>
    <div class="min-w-0">
      <p class="text-sm font-bold text-rose-800"><?= count($_alertas) ?> evento(s) proximos aun sin liquidar</p>
      <p class="text-xs text-rose-700">Deben quedar cancelados antes de la fecha del evento.</p>
    </div>
  </div>
  <ul class="divide-y divide-slate-100">
    <?php foreach ($_alertas as $al): ?>
      <li>
        <a href="liquidacion.php?id=<?= (int)$al['id'] ?>" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
          <div class="min-w-0 flex-1">
            <div class="flex items-center justify-between gap-2">
              <p class="truncate text-sm font-semibold text-slate-900"><?= e($al['nombre_evento'] ?: $al['cliente']) ?></p>
              <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold <?= $al['dias'] < 0 ? 'bg-rose-100 text-rose-700' : ($al['dias'] <= 1 ? 'bg-orange-100 text-orange-700' : 'bg-amber-100 text-amber-700') ?>"><?= e(texto_dias($al['dias'])) ?></span>
            </div>
            <p class="text-xs text-slate-500"><?= e(numero_cotizacion($al, $emp)) ?> · <?= e(fecha($al['fecha_evento'])) ?></p>
            <div class="mt-1 flex flex-wrap gap-x-4 text-xs">
              <?php if ($al['saldo_cliente'] > 0.004): ?><span class="text-rose-600">Cliente debe <strong><?= e(money($al['saldo_cliente'], $emp['moneda'])) ?></strong></span><?php endif; ?>
              <?php if ($al['saldo_prov'] > 0.004): ?><span class="text-slate-700">Pagar a proveedores <strong><?= e(money($al['saldo_prov'], $emp['moneda'])) ?></strong></span><?php endif; ?>
            </div>
          </div>
          <i data-lucide="chevron-right" class="h-4 w-4 shrink-0 text-slate-300"></i>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<div class="grid gap-5 xl:grid-cols-3">
  <!-- Calendario -->
  <section class="card p-3 sm:p-5 xl:col-span-2">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2 px-1">
      <h2 class="flex items-center gap-2 text-base font-bold text-slate-900"><i data-lucide="calendar-days" class="h-5 w-5 texto-marca"></i>Calendario de eventos</h2>
      <p class="text-xs text-slate-500">Toca un dia vacio para cotizar en esa fecha</p>
    </div>
    <div id="calendario" class="min-h-[420px]"></div>
  </section>

  <!-- Listas -->
  <div class="space-y-5">
    <section class="card overflow-hidden">
      <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
        <h2 class="text-sm font-bold text-slate-900">Proximos eventos</h2>
        <a href="cotizaciones.php?estado=aceptada&desde=<?= e($hoy) ?>" class="text-xs font-semibold texto-marca hover:underline">Ver todos</a>
      </div>
      <?php if (!$proximos): ?>
        <p class="px-5 py-8 text-center text-sm text-slate-400">No hay eventos agendados.</p>
      <?php else: ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($proximos as $p): $ts = strtotime($p['fecha_evento']); ?>
            <li>
              <a href="cotizacion_ver.php?id=<?= (int)$p['id'] ?>" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
                <div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-marca-suave texto-marca">
                  <span class="text-[10px] font-bold uppercase leading-none"><?= $meses[(int)date('n', $ts)] ?></span>
                  <span class="text-lg font-extrabold leading-tight"><?= date('j', $ts) ?></span>
                </div>
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-semibold text-slate-900"><?= e($p['nombre_evento'] ?: $p['cliente']) ?></p>
                  <p class="truncate text-xs text-slate-500"><?= e(implode(' · ', array_filter([hora($p['hora_evento']), $p['lugar_evento']])) ?: numero_cotizacion($p, $emp)) ?></p>
                </div>
                <div class="shrink-0 text-right">
                  <p class="text-sm font-bold text-slate-700"><?= e(money($p['total'], $emp['moneda'])) ?></p>
                  <?php $sp = round($p['total'] - $p['pagado'], 2); ?>
                  <p class="text-xs font-semibold <?= $sp > 0 ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $sp > 0 ? 'Debe ' . e(money($sp, $emp['moneda'])) : 'Pagada' ?></p>
                </div>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card overflow-hidden">
      <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
        <h2 class="text-sm font-bold text-slate-900">Pendientes de respuesta</h2>
        <a href="cotizaciones.php?estado=pendiente" class="text-xs font-semibold texto-marca hover:underline">Ver todas</a>
      </div>
      <?php if (!$pendientes): ?>
        <p class="px-5 py-8 text-center text-sm text-slate-400">No hay cotizaciones pendientes.</p>
      <?php else: ?>
        <ul class="divide-y divide-slate-100">
          <?php foreach ($pendientes as $p): $vencida = $p['fecha_evento'] < $hoy; ?>
            <li>
              <a href="cotizacion_ver.php?id=<?= (int)$p['id'] ?>" class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-semibold text-slate-900"><?= e($p['cliente']) ?></p>
                  <p class="truncate text-xs <?= $vencida ? 'font-semibold text-rose-600' : 'text-slate-500' ?>">
                    <?= e(numero_cotizacion($p, $emp)) ?> · Evento <?= e(fecha($p['fecha_evento'])) ?><?= $vencida ? ' (ya paso)' : '' ?>
                  </p>
                </div>
                <div class="shrink-0 text-right">
                  <p class="text-sm font-bold text-slate-700"><?= e(money($p['total'], $emp['moneda'])) ?></p>
                  <p class="text-xs font-semibold <?= $p['enviada_at'] ? 'text-sky-600' : 'text-orange-600' ?>"><?= $p['enviada_at'] ? 'Enviada' : 'Por enviar' ?></p>
                </div>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php
$extraScripts = '
<script src="assets/vendor/fullcalendar.min.js"></script>
<script src="assets/vendor/fullcalendar-es.min.js"></script>
<script>
(function () {
  var el = document.getElementById("calendario");
  var movil = function () { return window.innerWidth < 768; };
  var cal = new FullCalendar.Calendar(el, {
    locale: "es",
    initialView: movil() ? "listMonth" : "dayGridMonth",
    initialDate: ' . json_encode($fechaInicial) . ' || undefined,
    height: "auto",
    firstDay: 0,
    headerToolbar: { left: "prev,next today", center: "title", right: "dayGridMonth,listMonth" },
    buttonText: { today: "Hoy", month: "Mes", list: "Lista" },
    noEventsContent: "No hay eventos aceptados en este periodo",
    dayMaxEvents: 3,
    eventTimeFormat: { hour: "numeric", minute: "2-digit", meridiem: "short" },
    events: { url: "eventos.php", failure: function () { el.insertAdjacentHTML("beforebegin", "<p class=\"mb-2 text-sm text-rose-600\">No se pudieron cargar los eventos.</p>"); } },
    eventDidMount: function (info) {
      var p = info.event.extendedProps;
      info.el.title = p.numero + " · " + info.event.title + (p.lugar ? " · " + p.lugar : "") + " · " + p.total;
    },
    dateClick: function (info) {
      window.location.href = "cotizacion_form.php?fecha_evento=" + info.dateStr;
    },
    windowResize: function () {
      var v = movil() ? "listMonth" : "dayGridMonth";
      if (cal.view.type !== v) cal.changeView(v);
    }
  });
  cal.render();
})();
</script>';
require __DIR__ . '/includes/footer.php';
