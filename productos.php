<?php
require __DIR__ . '/includes/bootstrap.php';
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
            'id'          => $id,
            'codigo'      => input('codigo'),
            'nombre'      => input('nombre'),
            'descripcion' => input('descripcion'),
            'unidad'      => input('unidad', 'Unidad'),
            'precio'      => str_replace(',', '', input('precio', '0')),
            'activo'      => isset($_POST['activo']) ? 1 : 0,
        ];
        if ($id && !db_val('SELECT id FROM productos WHERE id = ? AND empresa_id = ?', [$id, $eid])) {
            flash('error', 'Producto no encontrado.');
            redirect('productos.php');
        }
        if ($p['nombre'] === '') {
            $errores[] = 'El nombre del producto es obligatorio.';
        }
        if (!is_numeric($p['precio']) || (float)$p['precio'] < 0) {
            $errores[] = 'El precio debe ser un numero mayor o igual a cero.';
        }
        if ($p['unidad'] === '') {
            $p['unidad'] = 'Unidad';
        }
        if ($p['codigo'] !== '' && db_val('SELECT id FROM productos WHERE empresa_id = ? AND codigo = ? AND id <> ?', [$eid, $p['codigo'], $id])) {
            $errores[] = 'Ya existe otro producto con ese codigo.';
        }
        if (!$errores) {
            $vals = [nullable($p['codigo']), $p['nombre'], nullable($p['descripcion']), mb_substr($p['unidad'], 0, 30), round((float)$p['precio'], 2), $p['activo']];
            if ($id) {
                $vals[] = $id;
                $vals[] = $eid;
                db_exec('UPDATE productos SET codigo = ?, nombre = ?, descripcion = ?, unidad = ?, precio = ?, activo = ? WHERE id = ? AND empresa_id = ?', $vals);
                flash('ok', 'Producto actualizado.');
            } else {
                array_unshift($vals, $eid);
                db_exec('INSERT INTO productos (empresa_id, codigo, nombre, descripcion, unidad, precio, activo) VALUES (?, ?, ?, ?, ?, ?, ?)', $vals);
                flash('ok', 'Producto creado.');
            }
            redirect('productos.php');
        }
        $producto = $p;
        $accion = $id ? 'editar' : 'nuevo';
    }

    if ($accion === 'eliminar') {
        $usado = (int)db_val('SELECT COUNT(*) FROM cotizacion_detalle d JOIN cotizaciones c ON c.id = d.cotizacion_id WHERE d.producto_id = ? AND c.empresa_id = ?', [$id, $eid]);
        if ($usado) {
            db_exec('UPDATE productos SET activo = 0 WHERE id = ? AND empresa_id = ?', [$id, $eid]);
            flash('aviso', 'El producto se usa en cotizaciones, asi que se desactivo en lugar de eliminarse.');
        } else {
            db_exec('DELETE FROM productos WHERE id = ? AND empresa_id = ?', [$id, $eid]);
            flash('ok', 'Producto eliminado.');
        }
        redirect('productos.php');
    }
}

/* ---------- Formulario ---------- */
if ($accion === 'nuevo' || $accion === 'editar') {
    if (!isset($producto)) {
        $producto = $accion === 'editar'
            ? db_one('SELECT * FROM productos WHERE id = ? AND empresa_id = ?', [$id, $eid])
            : ['id' => 0, 'codigo' => '', 'nombre' => '', 'descripcion' => '', 'unidad' => 'Unidad', 'precio' => '', 'activo' => 1];
        if (!$producto) {
            flash('error', 'Producto no encontrado.');
            redirect('productos.php');
        }
    }
    $unidades = db_all('SELECT DISTINCT unidad FROM productos WHERE empresa_id = ? ORDER BY unidad', [$eid]);
    $titulo = $producto['id'] ? 'Editar producto' : 'Nuevo producto';
    $activo = 'productos';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="mx-auto max-w-2xl">
      <?php if ($errores): ?>
        <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
          <ul class="list-inside list-disc"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <form method="post" action="productos.php?accion=guardar<?= $producto['id'] ? '&id=' . (int)$producto['id'] : '' ?>" class="card space-y-5 p-5 sm:p-6">
        <?= csrf_field() ?>
        <div class="grid gap-4 sm:grid-cols-6">
          <label class="campo sm:col-span-4"><span>Nombre *</span>
            <input class="input" name="nombre" value="<?= e($producto['nombre']) ?>" maxlength="150" required autofocus>
          </label>
          <label class="campo sm:col-span-2"><span>Codigo</span>
            <input class="input" name="codigo" value="<?= e($producto['codigo']) ?>" maxlength="50">
          </label>
          <label class="campo sm:col-span-6"><span>Descripcion</span>
            <textarea class="textarea" name="descripcion" rows="3" placeholder="Detalle que aparecera en la cotizacion (opcional)"><?= e($producto['descripcion']) ?></textarea>
          </label>
          <label class="campo sm:col-span-3"><span>Unidad de medida</span>
            <input class="input" name="unidad" value="<?= e($producto['unidad']) ?>" maxlength="30" list="lista-unidades">
            <datalist id="lista-unidades">
              <?php foreach (array_unique(array_merge(['Unidad', 'Servicio', 'Hora', 'Dia', 'Paquete', 'Persona', 'Metro'], array_column($unidades, 'unidad'))) as $un): ?>
                <option value="<?= e($un) ?>">
              <?php endforeach; ?>
            </datalist>
          </label>
          <label class="campo sm:col-span-3"><span>Precio (<?= e($emp['moneda']) ?>) *</span>
            <input class="input text-right" name="precio" value="<?= e($producto['precio'] === '' ? '' : number_format((float)$producto['precio'], 2, '.', '')) ?>" inputmode="decimal" required placeholder="0.00">
          </label>
          <label class="flex items-center gap-2 text-sm font-medium text-slate-700 sm:col-span-6">
            <input type="checkbox" name="activo" value="1" class="h-4 w-4 rounded" <?= $producto['activo'] ? 'checked' : '' ?>> Disponible para cotizar
          </label>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <a href="productos.php" class="btn btn-claro">Cancelar</a>
          <button class="btn btn-primario" type="submit"><i data-lucide="save"></i>Guardar</button>
        </div>
      </form>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* ---------- Lista ---------- */
$q = input('q');
$ver = input('ver', 'activos');
$where = 'empresa_id = ?';
$params = [$eid];
if ($ver === 'activos') {
    $where .= ' AND activo = 1';
} elseif ($ver === 'inactivos') {
    $where .= ' AND activo = 0';
}
if ($q !== '') {
    $where .= ' AND (nombre LIKE ? OR codigo LIKE ? OR descripcion LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$pag = paginar((int)db_val("SELECT COUNT(*) FROM productos WHERE $where", $params));
$productos = db_all("SELECT * FROM productos WHERE $where ORDER BY nombre LIMIT {$pag['limite']} OFFSET {$pag['offset']}", $params);

$titulo = 'Productos';
$activo = 'productos';
$accionesHeader = '<a href="productos.php?accion=nuevo" class="btn btn-primario btn-sm"><i data-lucide="plus"></i><span class="hidden sm:inline">Nuevo producto</span></a>';
require __DIR__ . '/includes/header.php';
?>
<form class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center" method="get">
  <div class="relative flex-1">
    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
    <input class="input pl-9" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre, codigo o descripcion">
  </div>
  <div class="flex gap-2">
    <select class="select sm:w-40" name="ver" onchange="this.form.submit()">
      <option value="activos" <?= $ver === 'activos' ? 'selected' : '' ?>>Disponibles</option>
      <option value="inactivos" <?= $ver === 'inactivos' ? 'selected' : '' ?>>No disponibles</option>
      <option value="todos" <?= $ver === 'todos' ? 'selected' : '' ?>>Todos</option>
    </select>
    <button class="btn btn-claro" type="submit">Buscar</button>
  </div>
</form>

<div class="card overflow-hidden">
  <?php if (!$productos): ?>
    <div class="px-6 py-12 text-center">
      <p class="text-slate-500"><?= $q !== '' ? 'No hay productos que coincidan con la busqueda.' : 'No hay productos en esta vista.' ?></p>
      <?php if ($q === ''): ?><a href="productos.php?accion=nuevo" class="btn btn-primario mt-4"><i data-lucide="plus"></i>Agregar producto</a><?php endif; ?>
    </div>
  <?php else: ?>
  <table class="tabla tabla-movil">
    <thead><tr><th>Producto</th><th>Codigo</th><th>Unidad</th><th class="text-right">Precio</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($productos as $p): ?>
      <tr>
        <td class="td-principal">
          <p class="font-semibold text-slate-900"><?= e($p['nombre']) ?></p>
          <?php if ($p['descripcion']): ?><p class="line-clamp-1 text-xs text-slate-500"><?= e($p['descripcion']) ?></p><?php endif; ?>
        </td>
        <td data-label="Codigo"><?= e($p['codigo'] ?: '—') ?></td>
        <td data-label="Unidad"><?= e($p['unidad']) ?></td>
        <td data-label="Precio" class="font-semibold md:text-right"><?= e(money($p['precio'])) ?></td>
        <td data-label="Estado"><?= $p['activo'] ? '<span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">Disponible</span>' : '<span class="rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-600">No disponible</span>' ?></td>
        <td class="td-acciones">
          <div class="flex justify-end gap-1.5">
            <a href="productos.php?accion=editar&id=<?= (int)$p['id'] ?>" class="btn btn-claro btn-sm btn-icono" title="Editar"><i data-lucide="pencil"></i></a>
            <form method="post" action="productos.php?accion=eliminar&id=<?= (int)$p['id'] ?>" data-confirmar="Eliminar este producto?"><?= csrf_field() ?>
              <button class="btn btn-claro btn-sm btn-icono text-rose-600" title="Eliminar"><i data-lucide="trash-2"></i></button>
            </form>
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
