<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();

if (is_post()) {
    csrf_check();
    $id = input_int('empresa_id');
    $emp = db_one('SELECT id, nombre FROM empresas WHERE id = ? AND activo = 1', [$id]);
    if ($emp) {
        $_SESSION['empresa_id'] = (int)$emp['id'];
        flash('ok', 'Trabajando con ' . $emp['nombre'] . '.');
        redirect('index.php');
    }
    flash('error', 'Empresa no valida.');
    redirect('seleccionar_empresa.php');
}

$empresas = db_all('SELECT e.*,
        (SELECT COUNT(*) FROM cotizaciones c WHERE c.empresa_id = e.id AND c.estado = \'pendiente\') AS pendientes,
        (SELECT COUNT(*) FROM cotizaciones c WHERE c.empresa_id = e.id AND c.estado = \'aceptada\' AND c.fecha_evento >= CURDATE()) AS proximos
    FROM empresas e WHERE e.activo = 1 ORDER BY e.nombre');
$actual = empresa_id();

$titulo = 'Seleccionar empresa';
$activo = 'seleccionar';
require __DIR__ . '/includes/header.php';
?>
<div class="mx-auto max-w-5xl">
  <p class="mb-6 text-slate-600">Elige la empresa con la que vas a trabajar. Las cotizaciones, clientes y productos se muestran segun la empresa seleccionada.</p>

  <?php if (!$empresas): ?>
    <div class="card flex flex-col items-center px-6 py-14 text-center">
      <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-500"><i data-lucide="building-2" class="h-7 w-7"></i></div>
      <p class="text-lg font-bold text-slate-900">Aun no hay empresas</p>
      <p class="mb-5 mt-1 text-sm text-slate-500">Crea la primera empresa para empezar a cotizar.</p>
      <a href="empresas.php?accion=nueva" class="btn btn-primario"><i data-lucide="plus"></i>Nueva empresa</a>
    </div>
  <?php else: ?>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <?php foreach ($empresas as $emp): $logo = logo_url($emp); ?>
        <form method="post" class="contents">
          <?= csrf_field() ?>
          <input type="hidden" name="empresa_id" value="<?= (int)$emp['id'] ?>">
          <button type="submit" class="card group overflow-hidden text-left transition hover:-translate-y-0.5 hover:shadow-lg <?= $actual === (int)$emp['id'] ? 'ring-2' : '' ?>" style="<?= $actual === (int)$emp['id'] ? '--tw-ring-color:' . e($emp['color_primario']) : '' ?>">
            <div class="h-2" style="background: linear-gradient(90deg, <?= e($emp['color_primario']) ?>, <?= e($emp['color_secundario']) ?>)"></div>
            <div class="flex items-center gap-4 p-5">
              <?php if ($logo): ?>
                <img src="<?= e($logo) ?>" alt="" class="h-14 w-14 shrink-0 rounded-xl border border-slate-100 object-contain p-1">
              <?php else: ?>
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl text-xl font-bold" style="background: <?= e($emp['color_primario']) ?>; color: <?= e(color_texto($emp['color_primario'])) ?>"><?= e(mb_strtoupper(mb_substr($emp['nombre'], 0, 1))) ?></div>
              <?php endif; ?>
              <div class="min-w-0 flex-1">
                <p class="truncate font-bold text-slate-900"><?= e($emp['nombre']) ?></p>
                <p class="truncate text-xs text-slate-500"><?= e($emp['direccion'] ?: 'Sin direccion') ?></p>
              </div>
              <i data-lucide="chevron-right" class="h-5 w-5 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-500"></i>
            </div>
            <div class="flex gap-4 border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
              <span><strong class="text-slate-800"><?= (int)$emp['pendientes'] ?></strong> pendientes</span>
              <span><strong class="text-slate-800"><?= (int)$emp['proximos'] ?></strong> eventos proximos</span>
            </div>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
