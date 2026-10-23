<?php
require __DIR__ . '/includes/bootstrap.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();

$hoy = date('Y-m-d');
$iniMes = date('Y-m-01');
$finMes = date('Y-m-t');

$stats = db_one("SELECT
        SUM(estado = 'pendiente') AS pendientes,
        COALESCE(SUM(CASE WHEN estado = 'pendiente' THEN total END), 0) AS monto_pendiente,
        SUM(estado = 'aceptada' AND fecha_evento BETWEEN ? AND ?) AS eventos_mes,
        COALESCE(SUM(CASE WHEN estado = 'aceptada' AND fecha_evento BETWEEN ? AND ? THEN total END), 0) AS monto_mes,
        SUM(estado = 'aceptada' AND fecha_evento >= ?) AS proximos
    FROM cotizaciones WHERE empresa_id = ?", [$iniMes, $finMes, $iniMes, $finMes, $hoy, $eid]);

$proximos = db_all("SELECT c.id, c.correlativo, c.fecha_evento, c.hora_evento, c.lugar_evento, c.total, cl.nombre AS cliente
    FROM cotizaciones c JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.empresa_id = ? AND c.estado = 'aceptada' AND c.fecha_evento >= ?
    ORDER BY c.fecha_evento, c.hora_evento LIMIT 6", [$eid, $hoy]);

$pendientes = db_all("SELECT c.id, c.correlativo, c.fecha, c.fecha_evento, c.total, cl.nombre AS cliente
    FROM cotizaciones c JOIN clientes cl ON cl.id = c.cliente_id
    WHERE c.empresa_id = ? AND c.estado = 'pendiente'
    ORDER BY c.fecha_evento LIMIT 6", [$eid]);

$fechaInicial = valid_date(input('fecha')) ? input('fecha') : null;

$meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

$titulo = 'Inicio';
$activo = 'dashboard';
$accionesHeader = '<a href="cotizacion_form.php" class="btn btn-primario btn-sm"><i data-lucide="plus"></i><span class="hidden sm:inline">Nueva cotizacion</span></a>';
require __DIR__ . '/includes/header.php';
?>
<!-- Indicadores -->
<div class="mb-5 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
  <?php
  $tarjetas = [
      ['Pendientes', (int)$stats['pendientes'], money($stats['monto_pendiente'], $emp['moneda']) . ' por confirmar', 'clock', 'bg-amber-100 text-amber-700', 'cotizaciones.php?estado=pendiente'],
      ['Eventos este mes', (int)$stats['eventos_mes'], 'Cotizaciones aceptadas', 'calendar-check', 'bg-emerald-100 text-emerald-700', 'cotizaciones.php?estado=aceptada&desde=' . $iniMes . '&hasta=' . $finMes],
      ['Vendido este mes', money($stats['monto_mes'], $emp['moneda']), 'Segun fecha de evento', 'trending-up', 'bg-sky-100 text-sky-700', null],
      ['Proximos eventos', (int)$stats['proximos'], 'Desde hoy', 'party-popper', 'bg-violet-100 text-violet-700', 'cotizaciones.php?estado=aceptada&desde=' . $hoy],
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
                  <p class="truncate text-sm font-semibold text-slate-900"><?= e($p['cliente']) ?></p>
                  <p class="truncate text-xs text-slate-500"><?= e(implode(' · ', array_filter([hora($p['hora_evento']), $p['lugar_evento']])) ?: numero_cotizacion($p, $emp)) ?></p>
                </div>
                <p class="shrink-0 text-sm font-bold text-slate-700"><?= e(money($p['total'], $emp['moneda'])) ?></p>
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
                <p class="shrink-0 text-sm font-bold text-slate-700"><?= e(money($p['total'], $emp['moneda'])) ?></p>
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
