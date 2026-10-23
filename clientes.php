<?php
require __DIR__ . '/includes/bootstrap.php';
require_empresa();

$eid = empresa_id();
$accion = input('accion', 'lista');
$id = input_int('id');
$errores = [];

function cliente_de_post()
{
    return [
        'nombre'    => input('nombre'),
        'nit'       => input('nit'),
        'telefono'  => input('telefono'),
        'correo'    => input('correo'),
        'direccion' => input('direccion'),
    ];
}

function validar_cliente($c)
{
    $err = [];
    if ($c['nombre'] === '') {
        $err[] = 'El nombre del cliente es obligatorio.';
    }
    if ($c['correo'] !== '' && !filter_var($c['correo'], FILTER_VALIDATE_EMAIL)) {
        $err[] = 'El correo no es valido.';
    }
    return $err;
}

function insertar_cliente($eid, $c)
{
    db_exec('INSERT INTO clientes (empresa_id, nombre, nit, telefono, correo, direccion) VALUES (?, ?, ?, ?, ?, ?)', [
        $eid, $c['nombre'], nullable($c['nit']), nullable($c['telefono']), nullable($c['correo']), nullable($c['direccion']),
    ]);
    return (int)db()->lastInsertId();
}

if (is_post()) {
    csrf_check();

    // Alta rapida desde el formulario de cotizacion (JSON)
    if ($accion === 'rapido') {
        header('Content-Type: application/json; charset=utf-8');
        $c = cliente_de_post();
        $err = validar_cliente($c);
        if ($err) {
            echo json_encode(['ok' => false, 'error' => implode(' ', $err)]);
            exit;
        }
        $nuevo = insertar_cliente($eid, $c);
        echo json_encode(['ok' => true, 'cliente' => ['id' => $nuevo, 'nombre' => $c['nombre'], 'nit' => $c['nit'], 'telefono' => $c['telefono']]]);
        exit;
    }

    if ($accion === 'guardar') {
        $c = cliente_de_post();
        $errores = validar_cliente($c);
        if ($id && !db_val('SELECT id FROM clientes WHERE id = ? AND empresa_id = ?', [$id, $eid])) {
            flash('error', 'Cliente no encontrado.');
            redirect('clientes.php');
        }
        if (!$errores) {
            if ($id) {
                db_exec('UPDATE clientes SET nombre = ?, nit = ?, telefono = ?, correo = ?, direccion = ? WHERE id = ? AND empresa_id = ?', [
                    $c['nombre'], nullable($c['nit']), nullable($c['telefono']), nullable($c['correo']), nullable($c['direccion']), $id, $eid,
                ]);
                flash('ok', 'Cliente actualizado.');
            } else {
                insertar_cliente($eid, $c);
                flash('ok', 'Cliente creado.');
            }
            redirect('clientes.php');
        }
        $cliente = $c + ['id' => $id];
        $accion = $id ? 'editar' : 'nuevo';
    }

    if ($accion === 'eliminar') {
        $usado = (int)db_val('SELECT COUNT(*) FROM cotizaciones WHERE cliente_id = ? AND empresa_id = ?', [$id, $eid]);
        if ($usado) {
            db_exec('UPDATE clientes SET activo = 0 WHERE id = ? AND empresa_id = ?', [$id, $eid]);
            flash('aviso', 'El cliente tiene cotizaciones, asi que se archivo en lugar de eliminarse.');
        } else {
            db_exec('DELETE FROM clientes WHERE id = ? AND empresa_id = ?', [$id, $eid]);
            flash('ok', 'Cliente eliminado.');
        }
        redirect('clientes.php');
    }

    if ($accion === 'restaurar') {
        db_exec('UPDATE clientes SET activo = 1 WHERE id = ? AND empresa_id = ?', [$id, $eid]);
        flash('ok', 'Cliente restaurado.');
        redirect('clientes.php?archivados=1');
    }
}

/* ---------- Formulario ---------- */
if ($accion === 'nuevo' || $accion === 'editar') {
    if (!isset($cliente)) {
        $cliente = $accion === 'editar'
            ? db_one('SELECT * FROM clientes WHERE id = ? AND empresa_id = ?', [$id, $eid])
            : ['id' => 0, 'nombre' => '', 'nit' => '', 'telefono' => '', 'correo' => '', 'direccion' => ''];
        if (!$cliente) {
            flash('error', 'Cliente no encontrado.');
            redirect('clientes.php');
        }
    }
    $titulo = $cliente['id'] ? 'Editar cliente' : 'Nuevo cliente';
    $activo = 'clientes';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="mx-auto max-w-2xl">
      <?php if ($errores): ?>
        <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
          <ul class="list-inside list-disc"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <form method="post" action="clientes.php?accion=guardar<?= $cliente['id'] ? '&id=' . (int)$cliente['id'] : '' ?>" class="card space-y-5 p-5 sm:p-6">
        <?= csrf_field() ?>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="campo sm:col-span-2"><span>Nombre *</span>
            <input class="input" name="nombre" value="<?= e($cliente['nombre']) ?>" maxlength="150" required autofocus>
          </label>
          <label class="campo"><span>NIT</span>
            <input class="input" name="nit" value="<?= e($cliente['nit']) ?>" maxlength="30" placeholder="CF">
          </label>
          <label class="campo"><span>Telefono</span>
            <input class="input" name="telefono" value="<?= e($cliente['telefono']) ?>" maxlength="50" inputmode="tel">
          </label>
          <label class="campo sm:col-span-2"><span>Correo</span>
            <input class="input" type="email" name="correo" value="<?= e($cliente['correo']) ?>" maxlength="150">
          </label>
          <label class="campo sm:col-span-2"><span>Direccion</span>
            <input class="input" name="direccion" value="<?= e($cliente['direccion']) ?>" maxlength="255">
          </label>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <a href="clientes.php" class="btn btn-claro">Cancelar</a>
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
$archivados = input_int('archivados') === 1;
$where = 'c.empresa_id = ? AND c.activo = ?';
$params = [$eid, $archivados ? 0 : 1];
if ($q !== '') {
    $where .= ' AND (c.nombre LIKE ? OR c.nit LIKE ? OR c.telefono LIKE ? OR c.correo LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$pag = paginar((int)db_val("SELECT COUNT(*) FROM clientes c WHERE $where", $params));
$clientes = db_all("SELECT c.*,
        (SELECT COUNT(*) FROM cotizaciones k WHERE k.cliente_id = c.id) AS cotizaciones
    FROM clientes c WHERE $where ORDER BY c.nombre LIMIT {$pag['limite']} OFFSET {$pag['offset']}", $params);

$titulo = 'Clientes';
$activo = 'clientes';
$accionesHeader = '<a href="clientes.php?accion=nuevo" class="btn btn-primario btn-sm"><i data-lucide="plus"></i><span class="hidden sm:inline">Nuevo cliente</span></a>';
require __DIR__ . '/includes/header.php';
?>
<form class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center" method="get">
  <div class="relative flex-1">
    <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
    <input class="input pl-9" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre, NIT, telefono o correo">
  </div>
  <?php if ($archivados): ?><input type="hidden" name="archivados" value="1"><?php endif; ?>
  <div class="flex gap-2">
    <button class="btn btn-claro flex-1 sm:flex-none" type="submit">Buscar</button>
    <a class="btn btn-claro flex-1 sm:flex-none" href="clientes.php<?= $archivados ? '' : '?archivados=1' ?>"><?= $archivados ? 'Ver activos' : 'Ver archivados' ?></a>
  </div>
</form>

<div class="card overflow-hidden">
  <?php if (!$clientes): ?>
    <div class="px-6 py-12 text-center">
      <p class="text-slate-500"><?= $q !== '' ? 'No hay clientes que coincidan con la busqueda.' : ($archivados ? 'No hay clientes archivados.' : 'Aun no tienes clientes.') ?></p>
      <?php if ($q === '' && !$archivados): ?><a href="clientes.php?accion=nuevo" class="btn btn-primario mt-4"><i data-lucide="plus"></i>Agregar cliente</a><?php endif; ?>
    </div>
  <?php else: ?>
  <table class="tabla tabla-movil">
    <thead><tr><th>Cliente</th><th>NIT</th><th>Telefono</th><th>Correo</th><th class="text-center">Cotizaciones</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($clientes as $c): ?>
      <tr>
        <td class="td-principal font-semibold text-slate-900"><?= e($c['nombre']) ?></td>
        <td data-label="NIT"><?= e($c['nit'] ?: 'CF') ?></td>
        <td data-label="Telefono"><?= $c['telefono'] ? '<a class="hover:underline" href="tel:' . e(preg_replace('/[^0-9+]/', '', $c['telefono'])) . '">' . e($c['telefono']) . '</a>' : '—' ?></td>
        <td data-label="Correo" class="max-w-[14rem] truncate"><?= e($c['correo'] ?: '—') ?></td>
        <td data-label="Cotizaciones" class="md:text-center"><?= (int)$c['cotizaciones'] ?></td>
        <td class="td-acciones">
          <div class="flex justify-end gap-1.5">
            <?php if ($archivados): ?>
              <form method="post" action="clientes.php?accion=restaurar&id=<?= (int)$c['id'] ?>"><?= csrf_field() ?>
                <button class="btn btn-claro btn-sm"><i data-lucide="undo-2"></i>Restaurar</button>
              </form>
            <?php else: ?>
              <a href="cotizacion_form.php?cliente_id=<?= (int)$c['id'] ?>" class="btn btn-claro btn-sm btn-icono" title="Nueva cotizacion"><i data-lucide="file-plus-2"></i></a>
              <a href="clientes.php?accion=editar&id=<?= (int)$c['id'] ?>" class="btn btn-claro btn-sm btn-icono" title="Editar"><i data-lucide="pencil"></i></a>
              <form method="post" action="clientes.php?accion=eliminar&id=<?= (int)$c['id'] ?>" data-confirmar="<?= (int)$c['cotizaciones'] ? 'El cliente tiene cotizaciones y se archivara. Continuar?' : 'Eliminar este cliente?' ?>"><?= csrf_field() ?>
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
<?php include __DIR__ . '/includes/paginacion.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
