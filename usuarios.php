<?php
require __DIR__ . '/includes/bootstrap.php';
require_admin();

$accion = input('accion', 'lista');
$id = input_int('id');
$yo = usuario_actual();
$errores = [];

function usuario_vacio()
{
    return ['id' => 0, 'empresa_id' => input_int('empresa_id') ?: null, 'nombre' => '', 'usuario' => '', 'rol' => 'empresa', 'activo' => 1];
}

if (is_post()) {
    csrf_check();

    if ($accion === 'guardar') {
        $u = $id ? db_one('SELECT * FROM usuarios WHERE id = ?', [$id]) : usuario_vacio();
        if (!$u) {
            flash('error', 'Usuario no encontrado.');
            redirect('usuarios.php');
        }
        $u['nombre'] = input('nombre');
        $u['usuario'] = strtolower(input('usuario'));
        $u['rol'] = input('rol') === 'admin' ? 'admin' : 'empresa';
        $u['empresa_id'] = $u['rol'] === 'empresa' ? (input_int('empresa_id') ?: null) : null;
        $pass = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password2'] ?? '');

        if ($id === (int)$yo['id'] && $u['rol'] !== 'admin') {
            $errores[] = 'No puedes quitarte el rol de administrador a ti mismo.';
        }
        if ($u['nombre'] === '') {
            $errores[] = 'El nombre es obligatorio.';
        }
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $u['usuario'])) {
            $errores[] = 'El usuario debe tener de 3 a 60 caracteres: letras, numeros, punto, guion o guion bajo, sin espacios.';
        } elseif (db_val('SELECT id FROM usuarios WHERE usuario = ? AND id <> ?', [$u['usuario'], $id])) {
            $errores[] = 'Ese nombre de usuario ya existe.';
        }
        if ($u['rol'] === 'empresa') {
            if (!$u['empresa_id'] || !db_val('SELECT id FROM empresas WHERE id = ?', [$u['empresa_id']])) {
                $errores[] = 'Selecciona la empresa del usuario.';
            } elseif (db_val('SELECT id FROM usuarios WHERE empresa_id = ? AND id <> ?', [$u['empresa_id'], $id])) {
                $errores[] = 'Esa empresa ya tiene un usuario asignado (solo se permite uno por empresa).';
            }
        }
        if (!$id || $pass !== '') {
            if (strlen($pass) < 6) {
                $errores[] = 'La contrasena debe tener al menos 6 caracteres.';
            } elseif ($pass !== $pass2) {
                $errores[] = 'Las contrasenas no coinciden.';
            }
        }

        if (!$errores) {
            if ($id) {
                db_exec('UPDATE usuarios SET nombre = ?, usuario = ?, rol = ?, empresa_id = ? WHERE id = ?', [$u['nombre'], $u['usuario'], $u['rol'], $u['empresa_id'], $id]);
                if ($pass !== '') {
                    db_exec('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
                }
                flash('ok', 'Usuario actualizado.');
            } else {
                db_exec('INSERT INTO usuarios (empresa_id, nombre, usuario, password_hash, rol) VALUES (?, ?, ?, ?, ?)', [$u['empresa_id'], $u['nombre'], $u['usuario'], password_hash($pass, PASSWORD_DEFAULT), $u['rol']]);
                flash('ok', 'Usuario creado.');
            }
            redirect('usuarios.php');
        }
        $accion = $id ? 'editar' : 'nuevo';
    }

    if ($accion === 'estado') {
        if ($id === (int)$yo['id']) {
            flash('error', 'No puedes desactivar tu propio usuario.');
        } else {
            db_exec('UPDATE usuarios SET activo = 1 - activo WHERE id = ?', [$id]);
            flash('ok', 'Estado del usuario actualizado.');
        }
        redirect('usuarios.php');
    }

    if ($accion === 'eliminar') {
        if ($id === (int)$yo['id']) {
            flash('error', 'No puedes eliminar tu propio usuario.');
        } else {
            db_exec('DELETE FROM usuarios WHERE id = ?', [$id]);
            flash('ok', 'Usuario eliminado.');
        }
        redirect('usuarios.php');
    }
}

/* ---------- Formulario ---------- */
if ($accion === 'nuevo' || $accion === 'editar') {
    if (!isset($u)) {
        $u = $accion === 'editar' ? db_one('SELECT * FROM usuarios WHERE id = ?', [$id]) : usuario_vacio();
        if (!$u) {
            flash('error', 'Usuario no encontrado.');
            redirect('usuarios.php');
        }
    }
    // Empresas sin usuario (mas la propia si se edita)
    $empresas = db_all('SELECT e.id, e.nombre FROM empresas e
        WHERE NOT EXISTS (SELECT 1 FROM usuarios x WHERE x.empresa_id = e.id AND x.id <> ?)
        ORDER BY e.nombre', [(int)$u['id']]);

    $titulo = $u['id'] ? 'Editar usuario' : 'Nuevo usuario';
    $activo = 'usuarios';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="mx-auto max-w-2xl">
      <?php if ($errores): ?>
        <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
          <ul class="list-inside list-disc space-y-0.5"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>
      <form method="post" action="usuarios.php?accion=guardar<?= $u['id'] ? '&id=' . (int)$u['id'] : '' ?>" class="card space-y-5 p-5 sm:p-6" autocomplete="off">
        <?= csrf_field() ?>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="campo sm:col-span-2"><span>Nombre completo *</span>
            <input class="input" name="nombre" value="<?= e($u['nombre']) ?>" maxlength="150" required>
          </label>
          <label class="campo"><span>Usuario *</span>
            <input class="input lowercase" name="usuario" value="<?= e($u['usuario']) ?>" maxlength="60" autocapitalize="none" required>
          </label>
          <label class="campo"><span>Tipo</span>
            <select class="select" name="rol" id="rol">
              <option value="empresa" <?= $u['rol'] === 'empresa' ? 'selected' : '' ?>>Usuario de empresa</option>
              <option value="admin" <?= $u['rol'] === 'admin' ? 'selected' : '' ?>>Administrador general</option>
            </select>
          </label>
          <label class="campo sm:col-span-2" id="campo-empresa"><span>Empresa *</span>
            <select class="select" name="empresa_id">
              <option value="">Selecciona...</option>
              <?php foreach ($empresas as $em): ?>
                <option value="<?= (int)$em['id'] ?>" <?= (int)$u['empresa_id'] === (int)$em['id'] ? 'selected' : '' ?>><?= e($em['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="ayuda">Solo aparecen las empresas que aun no tienen usuario.</p>
          </label>
          <label class="campo"><span>Contrasena <?= $u['id'] ? '(dejar vacia para no cambiar)' : '*' ?></span>
            <input class="input" type="password" name="password" autocomplete="new-password" minlength="6" <?= $u['id'] ? '' : 'required' ?>>
          </label>
          <label class="campo"><span>Repetir contrasena</span>
            <input class="input" type="password" name="password2" autocomplete="new-password">
          </label>
        </div>
        <div class="flex justify-end gap-3 border-t border-slate-100 pt-5">
          <a href="usuarios.php" class="btn btn-claro">Cancelar</a>
          <button class="btn btn-primario" type="submit"><i data-lucide="save"></i>Guardar</button>
        </div>
      </form>
    </div>
    <script>
      (function () {
        var r = document.getElementById('rol'), c = document.getElementById('campo-empresa');
        function t() { c.style.display = r.value === 'empresa' ? '' : 'none'; }
        r.addEventListener('change', t); t();
      })();
    </script>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* ---------- Lista ---------- */
$usuarios = db_all('SELECT u.*, e.nombre AS empresa FROM usuarios u LEFT JOIN empresas e ON e.id = u.empresa_id ORDER BY u.rol, e.nombre, u.nombre');

$titulo = 'Usuarios';
$activo = 'usuarios';
$accionesHeader = '<a href="usuarios.php?accion=nuevo" class="btn btn-primario btn-sm"><i data-lucide="plus"></i><span class="hidden sm:inline">Nuevo usuario</span></a>';
require __DIR__ . '/includes/header.php';
?>
<div class="card overflow-hidden">
  <table class="tabla tabla-movil">
    <thead><tr><th>Nombre</th><th>Usuario</th><th>Empresa</th><th>Ultimo acceso</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($usuarios as $us): ?>
      <tr>
        <td class="td-principal">
          <p class="font-semibold text-slate-900"><?= e($us['nombre']) ?></p>
          <?php if ($us['rol'] === 'admin'): ?><span class="text-xs font-semibold text-indigo-600">Administrador general</span><?php endif; ?>
        </td>
        <td data-label="Usuario"><?= e($us['usuario']) ?></td>
        <td data-label="Empresa"><?= e($us['empresa'] ?? '—') ?></td>
        <td data-label="Ultimo acceso" class="text-slate-500"><?= $us['ultimo_acceso'] ? e(fecha($us['ultimo_acceso'], true)) : 'Nunca' ?></td>
        <td data-label="Estado"><?= $us['activo'] ? '<span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">Activo</span>' : '<span class="rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-semibold text-slate-600">Inactivo</span>' ?></td>
        <td class="td-acciones">
          <div class="flex justify-end gap-1.5">
            <a href="usuarios.php?accion=editar&id=<?= (int)$us['id'] ?>" class="btn btn-claro btn-sm btn-icono" title="Editar"><i data-lucide="pencil"></i></a>
            <?php if ((int)$us['id'] !== (int)$yo['id']): ?>
              <form method="post" action="usuarios.php?accion=estado&id=<?= (int)$us['id'] ?>">
                <?= csrf_field() ?>
                <button class="btn btn-claro btn-sm btn-icono" title="<?= $us['activo'] ? 'Desactivar' : 'Activar' ?>"><i data-lucide="<?= $us['activo'] ? 'user-x' : 'user-check' ?>"></i></button>
              </form>
              <form method="post" action="usuarios.php?accion=eliminar&id=<?= (int)$us['id'] ?>" data-confirmar="Eliminar este usuario?">
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
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
