<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/empresa_form.php';
require_admin();

$accion = input('accion', 'lista');
$id = input_int('id');

/* ---------- Acciones POST ---------- */
if (is_post()) {
    csrf_check();

    if ($accion === 'guardar') {
        $emp = $id ? db_one('SELECT * FROM empresas WHERE id = ?', [$id]) : empresa_vacia();
        if (!$emp) {
            flash('error', 'Empresa no encontrada.');
            redirect('empresas.php');
        }
        list($nuevoId, $errores) = guardar_empresa($id, $emp);
        if (!$errores) {
            flash('ok', $id ? 'Empresa actualizada.' : 'Empresa creada. Ahora crea su usuario de acceso.');
            redirect($id ? 'empresas.php' : 'usuarios.php?accion=nuevo&empresa_id=' . $nuevoId);
        }
        if ($nuevoId && !$id) {
            flash('aviso', implode(' ', $errores));
            redirect('empresas.php?accion=editar&id=' . $nuevoId);
        }
        $accion = $id ? 'editar' : 'nueva';
    }

    if ($accion === 'estado') {
        db_exec('UPDATE empresas SET activo = 1 - activo WHERE id = ?', [$id]);
        if ((int)($_SESSION['empresa_id'] ?? 0) === $id && !db_val('SELECT activo FROM empresas WHERE id = ?', [$id])) {
            $_SESSION['empresa_id'] = null;
        }
        flash('ok', 'Estado de la empresa actualizado.');
        redirect('empresas.php');
    }

    if ($accion === 'eliminar') {
        $emp = db_one('SELECT * FROM empresas WHERE id = ?', [$id]);
        $cots = (int)db_val('SELECT COUNT(*) FROM cotizaciones WHERE empresa_id = ?', [$id]);
        if (!$emp) {
            flash('error', 'Empresa no encontrada.');
        } elseif ($cots > 0) {
            flash('error', 'No se puede eliminar: la empresa tiene ' . $cots . ' cotizaciones. Puedes desactivarla.');
        } else {
            db_exec('DELETE FROM empresas WHERE id = ?', [$id]);
            borrar_logo($emp['logo']);
            if ((int)($_SESSION['empresa_id'] ?? 0) === $id) {
                $_SESSION['empresa_id'] = null;
            }
            flash('ok', 'Empresa eliminada.');
        }
        redirect('empresas.php');
    }
}

/* ---------- Formulario ---------- */
if ($accion === 'nueva' || $accion === 'editar') {
    if (!isset($emp)) {
        $emp = $accion === 'editar' ? db_one('SELECT * FROM empresas WHERE id = ?', [$id]) : empresa_vacia();
        if (!$emp) {
            flash('error', 'Empresa no encontrada.');
            redirect('empresas.php');
        }
    }
    $titulo = $accion === 'editar' ? 'Editar empresa' : 'Nueva empresa';
    $activo = 'empresas';
    require __DIR__ . '/includes/header.php';
    render_form_empresa($emp, 'empresas.php?accion=guardar' . ($emp['id'] ? '&id=' . (int)$emp['id'] : ''), 'empresas.php', $errores ?? []);
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* ---------- Lista ---------- */
$empresas = db_all('SELECT e.*, u.usuario,
        (SELECT COUNT(*) FROM cotizaciones c WHERE c.empresa_id = e.id) AS cotizaciones,
        (SELECT COUNT(*) FROM productos p WHERE p.empresa_id = e.id) AS productos
    FROM empresas e LEFT JOIN usuarios u ON u.empresa_id = e.id
    ORDER BY e.activo DESC, e.nombre');

$titulo = 'Empresas';
$activo = 'empresas';
$accionesHeader = '<a href="empresas.php?accion=nueva" class="btn btn-primario btn-sm"><i data-lucide="plus"></i><span class="hidden sm:inline">Nueva empresa</span></a>';
require __DIR__ . '/includes/header.php';
?>
<div class="card overflow-hidden">
  <?php if (!$empresas): ?>
    <p class="px-6 py-12 text-center text-slate-500">No hay empresas registradas.</p>
  <?php else: ?>
  <table class="tabla tabla-movil">
    <thead><tr><th>Empresa</th><th>Usuario</th><th class="text-center">Productos</th><th class="text-center">Cotizaciones</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($empresas as $emp): $logo = logo_url($emp); ?>
      <tr>
        <td class="td-principal">
          <div class="flex items-center gap-3">
            <?php if ($logo): ?>
              <img src="<?= e($logo) ?>" alt="" class="h-10 w-10 shrink-0 rounded-lg border border-slate-100 object-contain p-0.5">
            <?php else: ?>
              <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg font-bold" style="background: <?= e($emp['color_primario']) ?>; color: <?= e(color_texto($emp['color_primario'])) ?>"><?= e(mb_strtoupper(mb_substr($emp['nombre'], 0, 1))) ?></div>
            <?php endif; ?>
            <div class="min-w-0">
              <p class="truncate font-semibold text-slate-900"><?= e($emp['nombre']) ?></p>
              <div class="mt-1 flex gap-1">
                <span class="h-2.5 w-6 rounded-full" style="background: <?= e($emp['color_primario']) ?>"></span>
                <span class="h-2.5 w-6 rounded-full" style="background: <?= e($emp['color_secundario']) ?>"></span>
              </div>
            </div>
          </div>
        </td>
        <td data-label="Usuario">
          <?php if ($emp['usuario']): ?>
            <span class="font-medium"><?= e($emp['usuario']) ?></span>
          <?php else: ?>
            <a href="usuarios.php?accion=nuevo&empresa_id=<?= (int)$emp['id'] ?>" class="text-sm font-semibold text-amber-600 hover:underline">Crear usuario</a>
          <?php endif; ?>
        </td>
        <td data-label="Productos" class="md:text-center"><?= (int)$emp['productos'] ?></td>
        <td data-label="Cotizaciones" class="md:text-center"><?= (int)$emp['cotizaciones'] ?></td>
        <td data-label="Estado">
          <?= $emp['activo'] ? '<span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">Activa</span>' : '<span class="rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-600">Inactiva</span>' ?>
        </td>
        <td class="td-acciones">
          <div class="flex justify-end gap-1.5">
            <a href="empresas.php?accion=editar&id=<?= (int)$emp['id'] ?>" class="btn btn-claro btn-sm btn-icono" title="Editar"><i data-lucide="pencil"></i></a>
            <form method="post" action="empresas.php?accion=estado&id=<?= (int)$emp['id'] ?>" data-confirmar="<?= $emp['activo'] ? 'Desactivar esta empresa? Su usuario no podra ingresar.' : 'Activar esta empresa?' ?>">
              <?= csrf_field() ?>
              <button class="btn btn-claro btn-sm btn-icono" title="<?= $emp['activo'] ? 'Desactivar' : 'Activar' ?>"><i data-lucide="<?= $emp['activo'] ? 'power-off' : 'power' ?>"></i></button>
            </form>
            <?php if (!(int)$emp['cotizaciones']): ?>
            <form method="post" action="empresas.php?accion=eliminar&id=<?= (int)$emp['id'] ?>" data-confirmar="Eliminar definitivamente la empresa, su usuario, clientes y productos?">
              <?= csrf_field() ?>
              <button class="btn btn-claro btn-sm btn-icono text-rose-600" title="Eliminar"><i data-lucide="trash-2"></i></button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
