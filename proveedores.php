<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/proveedores.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();
$accion = input('accion', 'lista');
$id = input_int('id');
$errores = [];

if (is_post()) {
    csrf_check();

    if ($accion === 'guardar') {
        $p = [
            'id'           => $id,
            'nombre'       => input('nombre'),
            'contacto'     => input('contacto'),
            'telefono'     => input('telefono'),
            'correo'       => input('correo'),
            'comision_pct' => str_replace(['%', ','], ['', '.'], input('comision_pct', '10')),
            'notas'        => input('notas'),
            'activo'       => isset($_POST['activo']) ? 1 : 0,
        ];
        if ($id && !db_val('SELECT id FROM proveedores WHERE id = ? AND empresa_id = ?', [$id, $eid])) {
            flash('error', 'Proveedor no encontrado.');
            redirect('proveedores.php');
        }
        if ($p['nombre'] === '') {
            $errores[] = 'El nombre del proveedor es obligatorio.';
        }
        if (!is_numeric($p['comision_pct']) || (float)$p['comision_pct'] < 0 || (float)$p['comision_pct'] > 100) {
            $errores[] = 'La comision debe ser un porcentaje entre 0 y 100.';
        }
        if ($p['correo'] !== '' && !filter_var($p['correo'], FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El correo no es valido.';
        }
        if (!$errores) {
            $vals = [$p['nombre'], nullable($p['contacto']), nullable($p['telefono']), nullable($p['correo']), round((float)$p['comision_pct'], 2), nullable($p['notas']), $p['activo']];
            if ($id) {
                db_exec('UPDATE proveedores SET nombre = ?, contacto = ?, telefono = ?, correo = ?, comision_pct = ?, notas = ?, activo = ? WHERE id = ? AND empresa_id = ?', array_merge($vals, [$id, $eid]));
                flash('ok', 'Proveedor actualizado. La nueva comision aplica a las cotizaciones que se guarden desde ahora.');
                redirect('proveedor_ver.php?id=' . $id);
            }
            db_exec('INSERT INTO proveedores (empresa_id, nombre, contacto, telefono, correo, comision_pct, notas, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array_merge([$eid], $vals));
            flash('ok', 'Proveedor creado. Ahora asignalo a sus productos.');
            redirect('proveedores.php');
        }
        $proveedor = $p;
        $accion = $id ? 'editar' : 'nuevo';
    }

    if ($accion === 'eliminar') {
        $usado = (int)db_val('SELECT COUNT(*) FROM cotizacion_detalle d JOIN cotizaciones c ON c.id = d.cotizacion_id WHERE d.proveedor_id = ? AND c.empresa_id = ?', [$id, $eid])
            + (int)db_val('SELECT COUNT(*) FROM proveedor_pagos WHERE proveedor_id = ? AND empresa_id = ?', [$id, $eid]);
        if ($usado) {
            db_exec('UPDATE proveedores SET activo = 0 WHERE id = ? AND empresa_id = ?', [$id, $eid]);
            flash('aviso', 'El proveedor tiene movimientos, asi que se desactivo en lugar de eliminarse.');
        } else {
            db_exec('DELETE FROM proveedores WHERE id = ? AND empresa_id = ?', [$id, $eid]);
            flash('ok', 'Proveedor eliminado.');
        }
        redirect('proveedores.php');
    }
}

/* ---------- Formulario ---------- */
if ($accion === 'nuevo' || $accion === 'editar') {
    if (!isset($proveedor)) {
        $proveedor = $accion === 'editar'
            ? db_one('SELECT * FROM proveedores WHERE id = ? AND empresa_id = ?', [$id, $eid])
            : ['id' => 0, 'nombre' => '', 'contacto' => '', 'telefono' => '', 'correo' => '', 'comision_pct' => '10', 'notas' => '', 'activo' => 1];
        if (!$proveedor) {
            flash('error', 'Proveedor no encontrado.');
            redirect('proveedores.php');
        }
    }
    $titulo = $proveedor['id'] ? 'Editar proveedor' : 'Nuevo proveedor';
    $activo = 'proveedores';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="mx-auto max-w-2xl">
      <?php if ($errores): ?>
        <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
          <ul class="list-inside list-disc"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <form method="post" action="proveedores.php?accion=guardar<?= $proveedor['id'] ? '&id=' . (int)$proveedor['id'] : '' ?>" class="card space-y-5 p-5 sm:p-6">
        <?= csrf_field() ?>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="campo sm:col-span-2"><span>Nombre del proveedor *</span>
            <input class="input" name="nombre" value="<?= e($proveedor['nombre']) ?>" maxlength="150" required autofocus placeholder="Ej: Pasteleria Del Castillo">
          </label>
          <label class="campo"><span>Persona de contacto</span>
            <input class="input" name="contacto" value="<?= e($proveedor['contacto']) ?>" maxlength="150">
          </label>
          <label class="campo"><span>Telefono</span>
            <input class="input" name="telefono" value="<?= e($proveedor['telefono']) ?>" maxlength="50" inputmode="tel">
          </label>
          <label class="campo"><span>Correo</span>
            <input class="input" type="email" name="correo" value="<?= e($proveedor['correo']) ?>" maxlength="150">
          </label>
          <label class="campo"><span>Mi comision (%) *</span>
            <input class="input text-right" name="comision_pct" id="comision" value="<?= e(rtrim(rtrim(number_format((float)$proveedor['comision_pct'], 2, '.', ''), '0'), '.')) ?>" inputmode="decimal" required>
          </label>
          <div class="rounded-xl bg-marca-suave px-4 py-3 text-sm text-slate-700 sm:col-span-2" id="ejemplo-comision"></div>
          <label class="campo sm:col-span-2"><span>Notas</span>
            <textarea class="textarea" name="notas" rows="3" placeholder="Cuenta bancaria, condiciones de pago..."><?= e($proveedor['notas']) ?></textarea>
          </label>
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700 sm:col-span-2">
            <input type="checkbox" name="activo" value="1" class="h-4 w-4 rounded" <?= $proveedor['activo'] ? 'checked' : '' ?>> Proveedor activo
          </label>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <a href="<?= $proveedor['id'] ? 'proveedor_ver.php?id=' . (int)$proveedor['id'] : 'proveedores.php' ?>" class="btn btn-claro">Cancelar</a>
          <button class="btn btn-primario" type="submit"><i data-lucide="save"></i>Guardar</button>
        </div>
      </form>
    </div>
    <script>
      (function () {
        var c = document.getElementById('comision'), out = document.getElementById('ejemplo-comision'), m = <?= json_encode($emp['moneda']) ?>;
        function f(n) { return m + ' ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
        function pintar() {
          var p = parseFloat(c.value) || 0, venta = 5000, costo = Math.round(venta * (1 - p / 100) * 100) / 100;
          out.innerHTML = 'Ejemplo: si vendes un producto de este proveedor en <strong>' + f(venta) + '</strong>, al proveedor le pagas <strong>' + f(costo) + '</strong> y tu comision es <strong>' + f(venta - costo) + '</strong>.';
        }
        c.addEventListener('input', pintar); pintar();
      })();
    </script>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* ---------- Lista ---------- */
$q = input('q');
$where = 'p.activo = 1';
$params = [];
if (input('ver') === 'todos') {
    $where = '1=1';
}
if ($q !== '') {
    $where .= ' AND (p.nombre LIKE ? OR p.contacto LIKE ? OR p.telefono LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$proveedores = proveedores_con_saldo($eid, $where, $params);
$totDeuda = 0;
$totCompras = 0;
$totPagado = 0;
foreach ($proveedores as $p) {
    $totDeuda += max(0, (float)$p['saldo']);
    $totCompras += (float)$p['compras'];
    $totPagado += (float)$p['pagado'];
}

$titulo = 'Proveedores';
$activo = 'proveedores';
$accionesHeader = '<a href="proveedores.php?accion=nuevo" class="btn btn-primario btn-sm"><i data-lucide="plus"></i><span class="hidden sm:inline">Nuevo proveedor</span></a>';
require __DIR__ . '/includes/header.php';
?>
<div class="mb-4 grid grid-cols-3 gap-3">
  <div class="card p-3 sm:p-4"><p class="text-xs font-semibold text-slate-500">Compras</p><p class="truncate text-base font-extrabold text-slate-900 sm:text-xl"><?= e(money($totCompras)) ?></p></div>
  <div class="card p-3 sm:p-4"><p class="text-xs font-semibold text-slate-500">Abonado</p><p class="truncate text-base font-extrabold text-emerald-600 sm:text-xl"><?= e(money($totPagado)) ?></p></div>
  <div class="card p-3 sm:p-4"><p class="text-xs font-semibold text-slate-500">Adeudo</p><p class="truncate text-base font-extrabold text-rose-600 sm:text-xl"><?= e(money($totDeuda)) ?></p></div>
</div>

<form class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center" method="get">
  <div class="relative flex-1">
    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
    <input class="input pl-9" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar proveedor">
  </div>
  <div class="flex gap-2">
    <select class="select sm:w-40" name="ver" onchange="this.form.submit()">
      <option value="">Activos</option>
      <option value="todos" <?= input('ver') === 'todos' ? 'selected' : '' ?>>Todos</option>
    </select>
    <button class="btn btn-claro" type="submit">Buscar</button>
  </div>
</form>

<div class="card overflow-hidden">
  <?php if (!$proveedores): ?>
    <div class="px-6 py-12 text-center">
      <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400"><i data-lucide="truck" class="h-6 w-6"></i></div>
      <p class="text-slate-500">Aun no hay proveedores.</p>
      <a href="proveedores.php?accion=nuevo" class="btn btn-primario mt-4"><i data-lucide="plus"></i>Agregar proveedor</a>
    </div>
  <?php else: ?>
  <table class="tabla tabla-movil">
    <thead><tr><th>Proveedor</th><th class="text-center">Comision</th><th class="text-right">Compras</th><th class="text-right">Abonado</th><th class="text-right">Adeudo</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($proveedores as $p): ?>
      <tr>
        <td class="td-principal">
          <a href="proveedor_ver.php?id=<?= (int)$p['id'] ?>" class="font-semibold text-slate-900 hover:underline"><?= e($p['nombre']) ?></a>
          <p class="text-xs text-slate-500"><?= e(implode(' · ', array_filter([$p['contacto'], $p['telefono']])) ?: '—') ?><?= $p['activo'] ? '' : ' · Inactivo' ?></p>
        </td>
        <td data-label="Comision" class="md:text-center"><?= e(pct_txt($p['comision_pct'])) ?></td>
        <td data-label="Compras" class="md:text-right"><?= e(money($p['compras'])) ?></td>
        <td data-label="Abonado" class="text-emerald-600 md:text-right"><?= e(money($p['pagado'])) ?></td>
        <td data-label="Adeudo" class="font-bold md:text-right <?= $p['saldo'] > 0 ? 'text-rose-600' : 'text-slate-500' ?>"><?= e(money($p['saldo'])) ?></td>
        <td class="td-acciones">
          <div class="flex justify-end gap-1.5">
            <a href="proveedor_ver.php?id=<?= (int)$p['id'] ?>#abono" class="btn btn-claro btn-sm" title="Registrar abono"><i data-lucide="hand-coins"></i><span class="md:hidden">Abonar</span></a>
            <a href="proveedores.php?accion=editar&id=<?= (int)$p['id'] ?>" class="btn btn-claro btn-sm btn-icono" title="Editar"><i data-lucide="pencil"></i></a>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<p class="mt-3 text-xs text-slate-500">El adeudo se genera con las cotizaciones <strong>aceptadas</strong> que incluyen productos del proveedor: precio de venta menos tu comision.</p>
<?php require __DIR__ . '/includes/footer.php'; ?>
