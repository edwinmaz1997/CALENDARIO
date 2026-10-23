<?php
require __DIR__ . '/includes/bootstrap.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();
$id = input_int('id');

$cot = db_one('SELECT * FROM cotizaciones WHERE id = ? AND empresa_id = ?', [$id, $eid]);
if (!$cot) {
    flash('error', 'Cotizacion no encontrada.');
    redirect('cotizaciones.php');
}

/* ---------- Acciones ---------- */
if (is_post()) {
    csrf_check();
    $accion = input('accion');

    if ($accion === 'aceptar' && $cot['estado'] !== 'aceptada') {
        $fe = input('fecha_evento', $cot['fecha_evento']);
        $he = input('hora_evento');
        if (!valid_date($fe)) {
            flash('error', 'La fecha del evento no es valida.');
        } else {
            $he = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $he) ? $he : null;
            db_exec('UPDATE cotizaciones SET estado = \'aceptada\', fecha_evento = ?, hora_evento = ?, fecha_aceptacion = NOW() WHERE id = ? AND empresa_id = ?', [$fe, $he, $id, $eid]);
            flash('ok', 'Cotizacion aceptada. El evento quedo agendado el ' . fecha_larga($fe) . '.');
        }
    } elseif ($accion === 'rechazar' && $cot['estado'] !== 'rechazada') {
        db_exec('UPDATE cotizaciones SET estado = \'rechazada\', fecha_aceptacion = NULL WHERE id = ? AND empresa_id = ?', [$id, $eid]);
        flash('ok', 'Cotizacion marcada como rechazada.');
    } elseif ($accion === 'pendiente' && $cot['estado'] !== 'pendiente') {
        db_exec('UPDATE cotizaciones SET estado = \'pendiente\', fecha_aceptacion = NULL WHERE id = ? AND empresa_id = ?', [$id, $eid]);
        flash('ok', 'La cotizacion regreso a pendiente' . ($cot['estado'] === 'aceptada' ? ' y se quito del calendario.' : '.'));
    } elseif ($accion === 'duplicar') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            db_val('SELECT id FROM empresas WHERE id = ? FOR UPDATE', [$eid]);
            $corr = (int)db_val('SELECT COALESCE(MAX(correlativo), 0) + 1 FROM cotizaciones WHERE empresa_id = ?', [$eid]);
            db_exec('INSERT INTO cotizaciones (empresa_id, correlativo, cliente_id, usuario_id, fecha, fecha_evento, hora_evento, lugar_evento, notas, total, estado)
                     SELECT empresa_id, ?, cliente_id, ?, CURDATE(), fecha_evento, hora_evento, lugar_evento, notas, total, \'pendiente\' FROM cotizaciones WHERE id = ?', [$corr, usuario_actual()['id'], $id]);
            $nuevo = (int)$pdo->lastInsertId();
            db_exec('INSERT INTO cotizacion_detalle (cotizacion_id, producto_id, descripcion, unidad, cantidad, precio_unitario, subtotal, orden)
                     SELECT ?, producto_id, descripcion, unidad, cantidad, precio_unitario, subtotal, orden FROM cotizacion_detalle WHERE cotizacion_id = ?', [$nuevo, $id]);
            $pdo->commit();
            flash('ok', 'Cotizacion duplicada como pendiente. Revisa la fecha del evento.');
            redirect('cotizacion_form.php?id=' . $nuevo);
        } catch (Exception $ex) {
            $pdo->rollBack();
            flash('error', 'No se pudo duplicar la cotizacion.');
        }
    } elseif ($accion === 'eliminar') {
        if ($cot['estado'] === 'aceptada') {
            flash('error', 'No se puede eliminar una cotizacion aceptada. Regresala a pendiente primero.');
        } else {
            db_exec('DELETE FROM cotizaciones WHERE id = ? AND empresa_id = ?', [$id, $eid]);
            flash('ok', 'Cotizacion ' . numero_cotizacion($cot, $emp) . ' eliminada.');
            redirect('cotizaciones.php');
        }
    }
    redirect('cotizacion_ver.php?id=' . $id);
}

$cliente = db_one('SELECT * FROM clientes WHERE id = ?', [$cot['cliente_id']]);
$lineas = db_all('SELECT * FROM cotizacion_detalle WHERE cotizacion_id = ? ORDER BY orden, id', [$id]);
$autor = $cot['usuario_id'] ? db_val('SELECT nombre FROM usuarios WHERE id = ?', [$cot['usuario_id']]) : null;
$numero = numero_cotizacion($cot, $emp);
$logo = logo_url($emp);

$titulo = $numero;
$activo = 'cotizaciones';
$accionesHeader = '<a href="cotizaciones.php" class="btn btn-claro btn-sm"><i data-lucide="arrow-left"></i><span class="hidden sm:inline">Volver</span></a>';
require __DIR__ . '/includes/header.php';
?>
<div class="grid gap-5 lg:grid-cols-3">
  <!-- Documento -->
  <div class="lg:col-span-2">
    <article class="card overflow-hidden">
      <div class="h-2" style="background: linear-gradient(90deg, var(--c1), var(--c2))"></div>
      <div class="flex flex-col gap-5 border-b border-slate-100 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-7">
        <div class="flex items-center gap-4">
          <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" class="h-16 w-16 object-contain sm:h-20 sm:w-20"><?php endif; ?>
          <div>
            <p class="text-lg font-extrabold text-slate-900"><?= e($emp['nombre']) ?></p>
            <?php if ($emp['nit']): ?><p class="text-xs text-slate-500">NIT: <?= e($emp['nit']) ?></p><?php endif; ?>
            <?php if ($emp['direccion']): ?><p class="text-xs text-slate-500"><?= e($emp['direccion']) ?></p><?php endif; ?>
            <p class="text-xs text-slate-500"><?= e(implode(' · ', array_filter([$emp['telefono'], $emp['correo']]))) ?></p>
          </div>
        </div>
        <div class="sm:text-right">
          <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Cotizacion</p>
          <p class="text-2xl font-extrabold texto-marca"><?= e($numero) ?></p>
          <p class="text-sm text-slate-500"><?= e(fecha_larga($cot['fecha'])) ?></p>
          <div class="mt-2"><?= badge_estado($cot['estado']) ?></div>
        </div>
      </div>

      <div class="grid gap-5 border-b border-slate-100 p-5 sm:grid-cols-2 sm:p-7">
        <div>
          <p class="mb-1 text-xs font-bold uppercase tracking-wider text-slate-400">Cliente</p>
          <p class="font-bold text-slate-900"><?= e($cliente['nombre']) ?></p>
          <p class="text-sm text-slate-600">NIT: <?= e($cliente['nit'] ?: 'CF') ?></p>
          <?php if ($cliente['telefono']): ?><p class="text-sm text-slate-600"><?= e($cliente['telefono']) ?></p><?php endif; ?>
          <?php if ($cliente['correo']): ?><p class="text-sm text-slate-600"><?= e($cliente['correo']) ?></p><?php endif; ?>
          <?php if ($cliente['direccion']): ?><p class="text-sm text-slate-600"><?= e($cliente['direccion']) ?></p><?php endif; ?>
        </div>
        <div>
          <p class="mb-1 text-xs font-bold uppercase tracking-wider text-slate-400">Evento</p>
          <p class="font-bold text-slate-900"><?= e(fecha_larga($cot['fecha_evento'])) ?><?= $cot['hora_evento'] ? ' · ' . e(hora($cot['hora_evento'])) : '' ?></p>
          <?php if ($cot['lugar_evento']): ?><p class="text-sm text-slate-600"><?= e($cot['lugar_evento']) ?></p><?php endif; ?>
        </div>
      </div>

      <!-- Detalle -->
      <div class="p-5 sm:p-7">
        <div class="hidden grid-cols-12 gap-3 border-b border-slate-200 pb-2 text-xs font-bold uppercase tracking-wide text-slate-500 sm:grid">
          <div class="col-span-6">Descripcion</div><div class="col-span-2 text-right">Cant.</div><div class="col-span-2 text-right">Precio</div><div class="col-span-2 text-right">Subtotal</div>
        </div>
        <?php foreach ($lineas as $l): ?>
          <div class="grid grid-cols-12 gap-x-3 gap-y-1 border-b border-slate-100 py-3 text-sm">
            <div class="col-span-12 sm:col-span-6">
              <p class="font-semibold text-slate-800"><?= e($l['descripcion']) ?></p>
              <?php if ($l['unidad']): ?><p class="text-xs text-slate-400"><?= e($l['unidad']) ?></p><?php endif; ?>
            </div>
            <div class="col-span-4 text-slate-600 sm:col-span-2 sm:text-right"><span class="sm:hidden">Cant: </span><?= e(rtrim(rtrim(number_format((float)$l['cantidad'], 2, '.', ','), '0'), '.')) ?></div>
            <div class="col-span-4 text-slate-600 sm:col-span-2 sm:text-right"><span class="sm:hidden">x </span><?= e(money($l['precio_unitario'], $emp['moneda'])) ?></div>
            <div class="col-span-4 text-right font-bold text-slate-900 sm:col-span-2"><?= e(money($l['subtotal'], $emp['moneda'])) ?></div>
          </div>
        <?php endforeach; ?>
        <div class="mt-4 flex justify-end">
          <div class="flex items-center gap-6 rounded-xl px-5 py-3" style="background: var(--c2); color: var(--c2-txt)">
            <span class="text-sm font-semibold">TOTAL</span>
            <span class="text-xl font-extrabold"><?= e(money($cot['total'], $emp['moneda'])) ?></span>
          </div>
        </div>

        <?php if ($cot['notas']): ?>
          <div class="mt-6">
            <p class="mb-1 text-xs font-bold uppercase tracking-wider text-slate-400">Notas</p>
            <p class="whitespace-pre-line text-sm text-slate-700"><?= e($cot['notas']) ?></p>
          </div>
        <?php endif; ?>
        <?php if ($emp['pie_cotizacion']): ?>
          <p class="mt-6 whitespace-pre-line border-t border-slate-100 pt-4 text-xs text-slate-500"><?= e($emp['pie_cotizacion']) ?></p>
        <?php endif; ?>
      </div>
    </article>
  </div>

  <!-- Acciones -->
  <aside class="space-y-4">
    <div class="card space-y-2 p-5">
      <a href="cotizacion_pdf.php?id=<?= (int)$id ?>" target="_blank" class="btn btn-primario w-full"><i data-lucide="file-text"></i>Ver PDF</a>
      <a href="cotizacion_pdf.php?id=<?= (int)$id ?>&descargar=1" class="btn btn-claro w-full"><i data-lucide="download"></i>Descargar PDF</a>
      <?php if ($cot['estado'] === 'pendiente'): ?>
        <a href="cotizacion_form.php?id=<?= (int)$id ?>" class="btn btn-claro w-full"><i data-lucide="pencil"></i>Editar</a>
      <?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="duplicar">
        <button class="btn btn-claro w-full"><i data-lucide="copy"></i>Duplicar</button>
      </form>
    </div>

    <div class="card p-5">
      <h3 class="mb-3 text-sm font-bold text-slate-900">Estado de la cotizacion</h3>

      <?php if ($cot['estado'] !== 'aceptada'): ?>
        <form method="post" class="space-y-3 rounded-xl border border-emerald-200 bg-emerald-50/60 p-4" data-confirmar="Marcar como aceptada y agendar el evento?">
          <?= csrf_field() ?><input type="hidden" name="accion" value="aceptar">
          <p class="text-xs text-emerald-800">Confirma la fecha del evento. Al aceptar se mostrara en el calendario.</p>
          <div class="grid grid-cols-2 gap-2">
            <input class="input" type="date" name="fecha_evento" value="<?= e($cot['fecha_evento']) ?>" required>
            <input class="input" type="time" name="hora_evento" value="<?= e($cot['hora_evento'] ? substr($cot['hora_evento'], 0, 5) : '') ?>">
          </div>
          <button class="btn btn-exito w-full"><i data-lucide="check-circle-2"></i>Aceptar</button>
        </form>
      <?php else: ?>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
          <p class="font-semibold">Aceptada<?= $cot['fecha_aceptacion'] ? ' el ' . e(fecha($cot['fecha_aceptacion'], true)) : '' ?></p>
          <p>Agendada en el calendario para el <?= e(fecha_larga($cot['fecha_evento'])) ?>.</p>
          <a class="mt-2 inline-flex items-center gap-1 font-semibold hover:underline" href="index.php?fecha=<?= e($cot['fecha_evento']) ?>"><i data-lucide="calendar" class="h-4 w-4"></i>Ver en calendario</a>
        </div>
      <?php endif; ?>

      <div class="mt-3 flex gap-2">
        <?php if ($cot['estado'] !== 'pendiente'): ?>
          <form method="post" class="flex-1" data-confirmar="Regresar la cotizacion a pendiente?<?= $cot['estado'] === 'aceptada' ? ' Se quitara del calendario.' : '' ?>">
            <?= csrf_field() ?><input type="hidden" name="accion" value="pendiente">
            <button class="btn btn-claro w-full btn-sm"><i data-lucide="rotate-ccw"></i>A pendiente</button>
          </form>
        <?php endif; ?>
        <?php if ($cot['estado'] !== 'rechazada'): ?>
          <form method="post" class="flex-1" data-confirmar="Marcar la cotizacion como rechazada?<?= $cot['estado'] === 'aceptada' ? ' Se quitara del calendario.' : '' ?>">
            <?= csrf_field() ?><input type="hidden" name="accion" value="rechazar">
            <button class="btn btn-claro w-full btn-sm text-rose-600"><i data-lucide="x-circle"></i>Rechazar</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card p-5 text-sm text-slate-600">
      <dl class="space-y-2">
        <div class="flex justify-between gap-3"><dt class="text-slate-400">Creada</dt><dd><?= e(fecha($cot['created_at'], true)) ?></dd></div>
        <?php if ($autor): ?><div class="flex justify-between gap-3"><dt class="text-slate-400">Por</dt><dd><?= e($autor) ?></dd></div><?php endif; ?>
        <?php if ($cot['updated_at']): ?><div class="flex justify-between gap-3"><dt class="text-slate-400">Modificada</dt><dd><?= e(fecha($cot['updated_at'], true)) ?></dd></div><?php endif; ?>
      </dl>
      <?php if ($cot['estado'] !== 'aceptada'): ?>
        <form method="post" class="mt-4 border-t border-slate-100 pt-4" data-confirmar="Eliminar definitivamente esta cotizacion?">
          <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar">
          <button class="inline-flex items-center gap-1.5 text-sm font-semibold text-rose-600 hover:underline"><i data-lucide="trash-2" class="h-4 w-4"></i>Eliminar cotizacion</button>
        </form>
      <?php endif; ?>
    </div>
  </aside>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
