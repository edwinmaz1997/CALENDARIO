<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$u = usuario_actual();
$errores = [];

if (is_post()) {
    csrf_check();
    $nombre = input('nombre');
    $actual = (string)($_POST['actual'] ?? '');
    $nueva = (string)($_POST['nueva'] ?? '');
    $nueva2 = (string)($_POST['nueva2'] ?? '');

    if ($nombre === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if ($nueva !== '') {
        $hash = db_val('SELECT password_hash FROM usuarios WHERE id = ?', [$u['id']]);
        if (!password_verify($actual, $hash)) {
            $errores[] = 'La contrasena actual no es correcta.';
        } elseif (strlen($nueva) < 6) {
            $errores[] = 'La nueva contrasena debe tener al menos 6 caracteres.';
        } elseif ($nueva !== $nueva2) {
            $errores[] = 'Las contrasenas nuevas no coinciden.';
        }
    }
    if (!$errores) {
        db_exec('UPDATE usuarios SET nombre = ? WHERE id = ?', [$nombre, $u['id']]);
        if ($nueva !== '') {
            db_exec('UPDATE usuarios SET password_hash = ? WHERE id = ?', [password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
        }
        flash('ok', 'Perfil actualizado.');
        redirect('perfil.php');
    }
    $u['nombre'] = $nombre;
}

$titulo = 'Mi perfil';
$activo = 'perfil';
require __DIR__ . '/includes/header.php';
?>
<div class="mx-auto max-w-xl">
  <?php if ($errores): ?>
    <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
      <ul class="list-inside list-disc"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>
  <form method="post" class="card space-y-5 p-5 sm:p-6" autocomplete="off">
    <?= csrf_field() ?>
    <div>
      <p class="text-sm text-slate-500">Usuario</p>
      <p class="font-bold text-slate-900"><?= e($u['usuario']) ?></p>
    </div>
    <label class="campo"><span>Nombre</span>
      <input class="input" name="nombre" value="<?= e($u['nombre']) ?>" maxlength="150" required>
    </label>
    <div class="border-t border-slate-100 pt-5">
      <h2 class="mb-3 text-sm font-bold text-slate-900">Cambiar contrasena</h2>
      <div class="grid gap-4">
        <label class="campo"><span>Contrasena actual</span><input class="input" type="password" name="actual" autocomplete="current-password"></label>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="campo"><span>Nueva contrasena</span><input class="input" type="password" name="nueva" minlength="6" autocomplete="new-password"></label>
          <label class="campo"><span>Repetir nueva</span><input class="input" type="password" name="nueva2" autocomplete="new-password"></label>
        </div>
        <p class="ayuda">Deja estos campos vacios si no quieres cambiarla.</p>
      </div>
    </div>
    <div class="flex justify-end">
      <button class="btn btn-primario" type="submit"><i data-lucide="save"></i>Guardar</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
