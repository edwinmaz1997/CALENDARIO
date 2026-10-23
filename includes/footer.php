  </main>
  <footer class="px-4 pb-6 text-center text-xs text-slate-400 sm:px-6">
    <?= e($GLOBALS['CONFIG']['app']['nombre'] ?? 'Cotizador') ?> &middot; <?= date('Y') ?> &middot; v<?= ASSET_V ?>
    <span class="mt-1 block">Diseñado por Edwin Mazariegos - 31586340</span>
  </footer>
</div>

<script src="assets/js/app.js?v=<?= ASSET_V ?>"></script>
<?= $extraScripts ?? '' ?>
</body>
</html>
