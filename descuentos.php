<?php
/**
 * Descuentos de la empresa: nombre + porcentaje. Se aplican al total de una cotizacion.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/empresa_form.php';
require_empresa();

$eid = empresa_id();
$accion = input('accion');
$id = input_int('id');
$errores = [];
$form = ['id' => 0, 'nombre' => '', 'porcentaje' => ''];

if (is_post()) {
    csrf_check();
    if ($id && !db_val('SELECT id FROM descuentos WHERE id = ? AND empresa_id = ?', [$id, $eid])) {
        flash('error', 'Descuento no encontrado.');
        redirect('descuentos.php');
    }

    if ($accion === 'rapido') {
        // Alta rapida desde la cotizacion (AJAX)
        header('Content-Type: application/json; charset=utf-8');
        $nombre = mb_substr(input('nombre'), 0, 100);
        $pct = str_replace(['%', ','], ['', '.'], input('porcentaje'));
        if ($nombre === '') {
            echo json_encode(['ok' => false, 'error' => 'Escribe el nombre del descuento.']);
            exit;
        }
        if (!is_numeric($pct) || (float)$pct <= 0 || (float)$pct > 100) {
            echo json_encode(['ok' => false, 'error' => 'El porcentaje debe ser mayor a 0 y maximo 100.']);
            exit;
        }
        $pct = round((float)$pct, 2);
        db_exec('INSERT INTO descuentos (empresa_id, nombre, porcentaje) VALUES (?, ?, ?)', [$eid, $nombre, $pct]);
        echo json_encode(['ok' => true, 'descuento' => ['id' => (int)db()->lastInsertId(), 'nombre' => $nombre, 'pct' => $pct]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'guardar') {
        $form = [
            'id'         => $id,
            'nombre'     => mb_substr(input('nombre'), 0, 100),
            'porcentaje' => str_replace(['%', ','], ['', '.'], input('porcentaje')),
        ];
        if ($form['nombre'] === '') {
            $errores[] = 'Escribe el nombre del descuento.';
        }
        if (!is_numeric($form['porcentaje']) || (float)$form['porcentaje'] <= 0 || (float)$form['porcentaje'] > 100) {
            $errores[] = 'El porcentaje debe ser mayor a 0 y maximo 100.';
        }
        if (!$errores) {
            $pct = round((float)$form['porcentaje'], 2);
            if ($id) {
                db_exec('UPDATE descuentos SET nombre = ?, porcentaje = ? WHERE id = ? AND empresa_id = ?', [$form['nombre'], $pct, $id, $eid]);
                flash('ok', 'Descuento actualizado. Las cotizaciones ya guardadas conservan el porcentaje con el que se hicieron.');
            } else {
                db_exec('INSERT INTO descuentos (empresa_id, nombre, porcentaje) VALUES (?, ?, ?)', [$eid, $form['nombre'], $pct]);
                flash('ok', 'Descuento creado. Ya puedes elegirlo al hacer una cotizacion.');
            }
            redirect('descuentos.php');
        }
    } elseif ($accion === 'estado') {
        db_exec('UPDATE descuentos SET activo = 1 - activo WHERE id = ? AND empresa_id = ?', [$id, $eid]);
        flash('ok', 'Descuento actualizado.');
        redirect('descuentos.php');
    } elseif ($accion === 'eliminar') {
        db_exec('DELETE FROM descuentos WHERE id = ? AND empresa_id = ?', [$id, $eid]);
        flash('ok', 'Descuento eliminado. Las cotizaciones que ya lo tenian lo conservan.');
        redirect('descuentos.php');
    }
} elseif ($accion === 'editar' && $id) {
    $d = db_one('SELECT id, nombre, porcentaje FROM descuentos WHERE id = ? AND empresa_id = ?', [$id, $eid]);
    if ($d) {
        $form = ['id' => (int)$d['id'], 'nombre' => $d['nombre'], 'porcentaje' => pct_num($d['porcentaje'])];
    }
}

$lista = db_all('SELECT d.id, d.nombre, d.porcentaje, d.activo,
        (SELECT COUNT(*) FROM cotizaciones c WHERE c.descuento_id = d.id AND c.empresa_id = d.empresa_id) AS usos
    FROM descuentos d WHERE d.empresa_id = ? ORDER BY d.activo DESC, d.nombre', [$eid]);

$titulo = 'Mi empresa';
$activo = 'mi_empresa_descuentos';
require __DIR__ . '/includes/header.php';
render_tabs_mi_empresa('descuentos');
?>
<?php if ($errores): ?>
  <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
    <ul class="list-inside list-disc space-y-0.5"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div class="grid max-w-5xl gap-5 lg:grid-cols-5">
  <section class="card h-fit p-5 sm:p-6 lg:col-span-2">
    <h2 class="mb-1 text-base font-bold text-slate-900"><?= $form['id'] ? 'Editar descuento' : 'Nuevo descuento' ?></h2>
    <p class="ayuda mb-4">Se aplica al total de la cotizacion. Ej: "Cliente frecuente" 10%.</p>
    <form method="post" action="descuentos.php" class="space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="accion" value="guardar">
      <?php if ($form['id']): ?><input type="hidden" name="id" value="<?= (int)$form['id'] ?>"><?php endif; ?>
      <label class="campo"><span>Nombre del descuento *</span>
        <input class="input" name="nombre" value="<?= e($form['nombre']) ?>" maxlength="100" required placeholder="Ej: Promocion de temporada" <?= $form['id'] ? 'autofocus' : '' ?>>
      </label>
      <label class="campo"><span>Porcentaje *</span>
        <div class="relative">
          <input class="input pr-9 text-right" name="porcentaje" value="<?= e($form['porcentaje']) ?>" inputmode="decimal" required placeholder="10">
          <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-bold text-slate-400">%</span>
        </div>
      </label>
      <div class="flex gap-3">
        <?php if ($form['id']): ?><a href="descuentos.php" class="btn btn-claro flex-1">Cancelar</a><?php endif; ?>
        <button type="submit" class="btn btn-primario flex-1"><i data-lucide="<?= $form['id'] ? 'save' : 'plus' ?>"></i><?= $form['id'] ? 'Guardar' : 'Agregar descuento' ?></button>
      </div>
    </form>
  </section>

  <section class="card overflow-hidden lg:col-span-3">
    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
      <h2 class="text-base font-bold text-slate-900">Descuentos creados</h2>
      <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600"><?= count($lista) ?></span>
    </div>
    <?php if (!$lista): ?>
      <div class="px-5 py-12 text-center">
        <i data-lucide="percent" class="mx-auto h-9 w-9 text-slate-300"></i>
        <p class="mt-2 text-sm text-slate-500">Aun no hay descuentos. Crea el primero con el formulario.</p>
      </div>
    <?php else: ?>
      <ul class="divide-y divide-slate-100">
        <?php foreach ($lista as $d): ?>
          <li class="flex items-center gap-3 px-5 py-3.5 <?= $d['activo'] ? '' : 'opacity-60' ?>">
            <span class="flex h-11 w-14 shrink-0 items-center justify-center rounded-xl bg-marca-suave text-sm font-extrabold texto-marca"><?= e(pct_num($d['porcentaje'])) ?>%</span>
            <div class="min-w-0 flex-1">
              <p class="truncate font-semibold text-slate-900"><?= e($d['nombre']) ?></p>
              <p class="text-xs text-slate-500">
                <?= $d['activo'] ? 'Activo' : 'Inactivo (no aparece al cotizar)' ?>
                <?php if ($d['usos']): ?> · usado en <?= (int)$d['usos'] ?> cotizacion<?= $d['usos'] == 1 ? '' : 'es' ?><?php endif; ?>
              </p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
              <a href="descuentos.php?accion=editar&id=<?= (int)$d['id'] ?>" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Editar" aria-label="Editar"><i data-lucide="pencil" class="h-4 w-4"></i></a>
              <form method="post" action="descuentos.php">
                <?= csrf_field() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="<?= $d['activo'] ? 'Desactivar' : 'Activar' ?>" aria-label="<?= $d['activo'] ? 'Desactivar' : 'Activar' ?>"><i data-lucide="<?= $d['activo'] ? 'eye-off' : 'eye' ?>" class="h-4 w-4"></i></button>
              </form>
              <form method="post" action="descuentos.php" data-confirmar="¿Eliminar el descuento &quot;<?= e($d['nombre']) ?>&quot;?">
                <?= csrf_field() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" title="Eliminar" aria-label="Eliminar"><i data-lucide="trash-2" class="h-4 w-4"></i></button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
<?php
require __DIR__ . '/includes/footer.php';
