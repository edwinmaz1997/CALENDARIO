<?php
/** Requiere $pag = paginar(...) */
if (!empty($pag) && $pag['paginas'] > 1): ?>
<nav class="mt-4 flex items-center justify-between gap-3 text-sm">
  <p class="text-slate-500">Pagina <?= (int)$pag['pagina'] ?> de <?= (int)$pag['paginas'] ?> · <?= (int)$pag['total'] ?> registros</p>
  <div class="flex gap-2">
    <?php if ($pag['pagina'] > 1): ?>
      <a class="btn btn-claro btn-sm" href="<?= e(url_con(['p' => $pag['pagina'] - 1])) ?>"><i data-lucide="chevron-left"></i><span class="hidden sm:inline">Anterior</span></a>
    <?php endif; ?>
    <?php if ($pag['pagina'] < $pag['paginas']): ?>
      <a class="btn btn-claro btn-sm" href="<?= e(url_con(['p' => $pag['pagina'] + 1])) ?>"><span class="hidden sm:inline">Siguiente</span><i data-lucide="chevron-right"></i></a>
    <?php endif; ?>
  </div>
</nav>
<?php endif;
