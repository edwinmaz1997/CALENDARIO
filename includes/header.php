<?php
/**
 * Layout superior. Variables opcionales antes de incluir:
 *   $titulo  : titulo de la pagina
 *   $activo  : clave del menu activo
 *   $extraHead: HTML adicional para <head>
 */
$titulo = $titulo ?? 'Inicio';
$activo = $activo ?? '';
$_u = usuario_actual();
$_emp = empresa_actual();
$_c1 = $_emp['color_primario'] ?? '#1E40AF';
$_c2 = $_emp['color_secundario'] ?? '#F59E0B';
$_appNombre = $GLOBALS['CONFIG']['app']['nombre'] ?? 'Cotizador';

$_menu = [];
if ($_emp) {
    $_menu[] = ['dashboard', 'index.php', 'layout-dashboard', 'Inicio'];
    $_menu[] = ['cotizaciones', 'cotizaciones.php', 'file-text', 'Cotizaciones'];
    $_menu[] = ['clientes', 'clientes.php', 'users', 'Clientes'];
    $_menu[] = ['productos', 'productos.php', 'package', 'Productos'];
    $_menu[] = ['mi_empresa', 'mi_empresa.php', 'building-2', 'Mi empresa'];
}
$_menuAdmin = [];
if ($_u && $_u['rol'] === 'admin') {
    $_menuAdmin[] = ['empresas', 'empresas.php', 'briefcase', 'Empresas'];
    $_menuAdmin[] = ['usuarios', 'usuarios.php', 'user-cog', 'Usuarios'];
    $_menuAdmin[] = ['seleccionar', 'seleccionar_empresa.php', 'repeat', 'Cambiar empresa'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($_c1) ?>">
<title><?= e($titulo) ?> · <?= e($_emp['nombre'] ?? $_appNombre) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css?v=<?= ASSET_V ?>">
<link rel="stylesheet" href="assets/css/tailwind.css?v=<?= ASSET_V ?>">
<script src="assets/vendor/lucide.min.js"></script>
<style>
  :root { --c1: <?= e($_c1) ?>; --c2: <?= e($_c2) ?>; --c1-txt: <?= e(color_texto($_c1)) ?>; --c2-txt: <?= e(color_texto($_c2)) ?>; }
</style>
<?= $extraHead ?? '' ?>
</head>
<body class="h-full bg-slate-50 font-sans text-slate-800 antialiased">

<!-- Fondo del menu movil -->
<div id="menu-fondo" class="fixed inset-0 z-30 hidden bg-slate-900/50 lg:hidden"></div>

<!-- Barra lateral -->
<aside id="menu" class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col bg-white shadow-xl transition-transform duration-200 lg:translate-x-0 lg:shadow-none lg:border-r lg:border-slate-200">
  <div class="flex items-center gap-3 px-5 py-5" style="background: var(--c1); color: var(--c1-txt)">
    <?php if ($_emp && logo_url($_emp)): ?>
      <img src="<?= e(logo_url($_emp)) ?>" alt="" class="h-11 w-11 shrink-0 rounded-xl bg-white object-contain p-1">
    <?php else: ?>
      <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/20 text-lg font-bold">
        <?= e(mb_strtoupper(mb_substr($_emp['nombre'] ?? $_appNombre, 0, 1))) ?>
      </div>
    <?php endif; ?>
    <div class="min-w-0">
      <p class="truncate text-base font-bold leading-tight"><?= e($_emp['nombre'] ?? $_appNombre) ?></p>
      <p class="truncate text-xs opacity-80"><?= e($_u['nombre'] ?? '') ?></p>
    </div>
    <button type="button" class="ml-auto rounded-lg p-1.5 hover:bg-white/15 lg:hidden" data-cerrar-menu aria-label="Cerrar menu">
      <i data-lucide="x" class="h-5 w-5"></i>
    </button>
  </div>

  <nav class="flex-1 overflow-y-auto px-3 py-4">
    <?php foreach ($_menu as $m): $on = $activo === $m[0]; ?>
      <a href="<?= $m[1] ?>" class="nav-link <?= $on ? 'nav-on' : '' ?>">
        <i data-lucide="<?= $m[2] ?>" class="h-5 w-5"></i><span><?= e($m[3]) ?></span>
      </a>
    <?php endforeach; ?>

    <?php if ($_menuAdmin): ?>
      <p class="mb-1 mt-6 px-3 text-[11px] font-bold uppercase tracking-wider text-slate-400">Administracion</p>
      <?php foreach ($_menuAdmin as $m): $on = $activo === $m[0]; ?>
        <a href="<?= $m[1] ?>" class="nav-link <?= $on ? 'nav-on' : '' ?>">
          <i data-lucide="<?= $m[2] ?>" class="h-5 w-5"></i><span><?= e($m[3]) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </nav>

  <div class="border-t border-slate-200 px-3 py-3">
    <a href="perfil.php" class="nav-link <?= $activo === 'perfil' ? 'nav-on' : '' ?>"><i data-lucide="user-circle" class="h-5 w-5"></i><span>Mi perfil</span></a>
    <a href="logout.php" class="nav-link text-rose-600 hover:bg-rose-50"><i data-lucide="log-out" class="h-5 w-5"></i><span>Cerrar sesion</span></a>
  </div>
</aside>

<div class="flex min-h-screen flex-col lg:pl-72">
  <!-- Barra superior -->
  <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur sm:px-6">
    <button type="button" class="-ml-1 rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" data-abrir-menu aria-label="Abrir menu">
      <i data-lucide="menu" class="h-6 w-6"></i>
    </button>
    <h1 class="truncate text-lg font-bold text-slate-900 sm:text-xl"><?= e($titulo) ?></h1>
    <?php if (!empty($accionesHeader)): ?>
      <div class="ml-auto flex items-center gap-2"><?= $accionesHeader ?></div>
    <?php endif; ?>
  </header>

  <main class="flex-1 px-4 py-5 sm:px-6 lg:px-8 lg:py-8">
    <?php foreach (flashes() as $f):
      $cls = $f['tipo'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : ($f['tipo'] === 'aviso' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800');
      $ico = $f['tipo'] === 'error' ? 'alert-circle' : ($f['tipo'] === 'aviso' ? 'alert-triangle' : 'check-circle-2'); ?>
      <div class="flash mb-4 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm <?= $cls ?>">
        <i data-lucide="<?= $ico ?>" class="mt-0.5 h-5 w-5 shrink-0"></i>
        <p class="flex-1"><?= e($f['mensaje']) ?></p>
        <button type="button" class="opacity-60 hover:opacity-100" onclick="this.parentElement.remove()" aria-label="Cerrar"><i data-lucide="x" class="h-4 w-4"></i></button>
      </div>
    <?php endforeach; ?>
