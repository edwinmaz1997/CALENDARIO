<?php
require __DIR__ . '/includes/bootstrap.php';

if (usuario_actual()) {
    redirect(es_admin() && !empresa_actual() ? 'seleccionar_empresa.php' : 'index.php');
}

$error = null;
$usuario = '';

if (is_post()) {
    csrf_check();
    $usuario = input('usuario');
    $password = (string)($_POST['password'] ?? '');

    // Freno simple contra intentos repetidos
    $_SESSION['login_intentos'] = $_SESSION['login_intentos'] ?? [];
    $_SESSION['login_intentos'] = array_filter($_SESSION['login_intentos'], function ($t) { return $t > time() - 300; });
    if (count($_SESSION['login_intentos']) >= 8) {
        $error = 'Demasiados intentos. Espera unos minutos e intenta de nuevo.';
    } elseif ($usuario === '' || $password === '') {
        $error = 'Ingresa usuario y contrasena.';
    } else {
        $error = intentar_login($usuario, $password);
        if ($error === null) {
            unset($_SESSION['login_intentos']);
            redirect(es_admin() ? 'seleccionar_empresa.php' : 'index.php');
        }
        $_SESSION['login_intentos'][] = time();
    }
}
$appNombre = $GLOBALS['CONFIG']['app']['nombre'] ?? 'Cotizador';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#1E40AF">
<title>Ingresar · <?= e($appNombre) ?></title>
<?php include __DIR__ . '/includes/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= ASSET_V ?>">
<link rel="stylesheet" href="assets/css/tailwind.css?v=<?= ASSET_V ?>">
<script src="assets/vendor/lucide.min.js"></script>
<script src="assets/js/pwa.js?v=<?= ASSET_V ?>" defer></script>
<style>
  :root { --c1: #1E40AF; --c2: #F59E0B; --c1-txt: #fff; --c2-txt: #111827; }
  /* Login fijo: sin scroll ni zoom en movil */
  html, body { height: 100%; overflow: hidden; overscroll-behavior: none; }
  body { position: fixed; inset: 0; touch-action: manipulation; -webkit-text-size-adjust: 100%; }
  .login-alto { height: 100vh; height: 100dvh; }
  /* 16px evita el zoom automatico de iOS al enfocar un campo */
  .input { font-size: 16px !important; }
</style>
</head>
<body class="bg-slate-100 font-sans text-slate-800 antialiased">
<div class="login-alto relative flex items-center justify-center overflow-hidden lg:bg-[#1E40AF]">
  <div class="pointer-events-none absolute -left-24 -top-24 hidden h-96 w-96 rounded-full bg-white/10 lg:block"></div>
  <div class="pointer-events-none absolute -bottom-32 -right-20 hidden h-[28rem] w-[28rem] rounded-full bg-amber-400/20 lg:block"></div>
  <div class="pointer-events-none absolute right-1/4 top-16 hidden h-40 w-40 rounded-full bg-white/5 lg:block"></div>

  <div class="relative flex w-full flex-col items-center px-5 lg:px-0" style="padding-bottom: env(safe-area-inset-bottom)">
    <div class="mb-6 hidden max-w-md text-center text-white lg:block">
      <h2 class="text-2xl font-extrabold leading-tight">Cotiza, confirma y agenda tus eventos en un solo lugar.</h2>
      <p class="mt-2 text-sm text-white/80">Cada empresa con su logo, sus colores, sus productos y sus clientes.</p>
    </div>
    <div class="w-full max-w-sm lg:max-w-md lg:rounded-3xl lg:bg-white lg:p-10 lg:shadow-2xl">
      <div class="mb-8 flex items-center gap-3">
        <img src="assets/icons/icono-192.png" alt="" class="h-12 w-12 rounded-2xl shadow-md">
        <div>
          <p class="text-xl font-extrabold text-slate-900"><?= e($appNombre) ?></p>
          <p class="text-sm text-slate-500">Ingresa con tu usuario</p>
        </div>
      </div>

      <?php if ($error): ?>
        <div class="mb-5 flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
          <i data-lucide="alert-circle" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="post" class="space-y-4" autocomplete="on">
        <?= csrf_field() ?>
        <label class="campo"><span>Usuario</span>
          <input class="input" type="text" name="usuario" value="<?= e($usuario) ?>" autocomplete="username" autocapitalize="none" required autofocus>
        </label>
        <label class="campo"><span>Contrasena</span>
          <div class="relative">
            <input class="input pr-11" type="password" name="password" id="password" autocomplete="current-password" required>
            <button type="button" class="absolute inset-y-0 right-0 px-3 text-slate-400 hover:text-slate-600" onclick="var p=document.getElementById('password');p.type=p.type==='password'?'text':'password'" aria-label="Mostrar contrasena">
              <i data-lucide="eye" class="h-5 w-5"></i>
            </button>
          </div>
        </label>
        <button type="submit" class="btn btn-primario w-full py-3 text-base">Ingresar <i data-lucide="arrow-right"></i></button>
      </form>
      <button type="button" data-instalar-app class="btn btn-claro mt-3 hidden w-full"><i data-lucide="smartphone"></i>Instalar como aplicacion</button>
    </div>
  </div>
    <p class="absolute inset-x-0 bottom-0 px-4 pb-4 text-center text-xs text-slate-400 lg:text-white/70" style="padding-bottom: max(1rem, env(safe-area-inset-bottom))">Diseñado por Edwin Mazariegos - 31586340</p>
</div>
<script>
lucide.createIcons();
// iOS ignora user-scalable=no: bloquear pellizco y doble toque
document.addEventListener('gesturestart', function (e) { e.preventDefault(); }, { passive: false });
document.addEventListener('touchmove', function (e) { if (e.touches.length > 1) e.preventDefault(); }, { passive: false });
var ultimoToque = 0;
document.addEventListener('touchend', function (e) {
  var ahora = Date.now();
  if (ahora - ultimoToque < 300 && !e.target.closest('input, button, a')) e.preventDefault();
  ultimoToque = ahora;
}, { passive: false });
</script>
</body>
</html>
