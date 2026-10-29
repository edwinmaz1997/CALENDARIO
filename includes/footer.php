  </main>
  <footer class="px-4 pb-6 text-center text-xs text-slate-400 sm:px-6">
    <?= e($GLOBALS['CONFIG']['app']['nombre'] ?? 'Cotizador') ?> &middot; <?= date('Y') ?> &middot; v<?= ASSET_V ?>
    <span class="mt-1 block">Diseñado por Edwin Mazariegos - 31586340</span>
  </footer>
</div>

<?php if (!empty($_emp)):
  $_abajo = [
    ['cotizaciones', 'cotizaciones.php', 'file-text', 'Cotizaciones'],
    ['dashboard', 'index.php', 'house', 'Inicio'],
    ['reportes', 'reportes.php', 'bar-chart-3', 'Reportes'],
  ]; ?>
<!-- Navegacion inferior (movil y tablet) -->
<nav class="nav-abajo fixed inset-x-0 bottom-0 z-[25] border-t border-slate-200/80 bg-white/95 backdrop-blur lg:hidden" aria-label="Navegacion principal">
  <div class="mx-auto grid max-w-md grid-cols-3 px-2 pt-1.5">
    <?php foreach ($_abajo as $m): $on = ($activo ?? '') === $m[0]; ?>
      <a href="<?= $m[1] ?>" class="group flex flex-col items-center gap-0.5 py-1 <?= $on ? 'texto-marca' : 'text-slate-400 hover:text-slate-600' ?>" <?= $on ? 'aria-current="page"' : '' ?>>
        <span class="flex h-8 w-16 items-center justify-center rounded-full transition <?= $on ? 'bg-marca-suave' : 'group-active:bg-slate-100' ?>">
          <i data-lucide="<?= $m[2] ?>" class="h-[22px] w-[22px]" <?= $on ? 'stroke-width="2.4"' : '' ?>></i>
        </span>
        <span class="text-[11px] <?= $on ? 'font-bold' : 'font-semibold' ?>"><?= e($m[3]) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</nav>
<?php endif; ?>

<script src="assets/js/app.js?v=<?= ASSET_V ?>"></script>
<script src="assets/js/visor.js?v=<?= ASSET_V ?>"></script>
<?= $extraScripts ?? '' ?>
</body>
</html>
