<?php
/**
 * Logica y formulario compartido de empresa (lo usan empresas.php y mi_empresa.php).
 */

function empresa_vacia()
{
    return [
        'id' => 0, 'nombre' => '', 'nit' => '', 'direccion' => '', 'telefono' => '', 'correo' => '', 'sitio_web' => '',
        'logo' => null, 'color_primario' => '#1E40AF', 'color_secundario' => '#F59E0B', 'moneda' => 'Q', 'prefijo' => 'COT',
        'pie_cotizacion' => "Precios sujetos a cambio sin previo aviso.\nCotizacion valida por 15 dias.", 'activo' => 1,
    ];
}

/**
 * Valida y guarda la empresa desde $_POST. Devuelve [id|null, errores[]].
 */
function guardar_empresa($id, &$datos)
{
    $errores = [];
    $todos = ['nombre', 'nit', 'direccion', 'telefono', 'correo', 'sitio_web', 'color_primario', 'color_secundario', 'moneda', 'prefijo', 'pie_cotizacion'];
    // Al editar por secciones solo se actualizan los campos que vienen en el formulario
    $campos = $id ? array_values(array_filter($todos, function ($c) { return array_key_exists($c, $_POST); })) : $todos;
    $nuevos = [];
    foreach ($campos as $c) {
        $nuevos[$c] = input($c);
    }
    if (isset($nuevos['color_primario'])) {
        $nuevos['color_primario'] = strtoupper($nuevos['color_primario'] ?: '#1E40AF');
    }
    if (isset($nuevos['color_secundario'])) {
        $nuevos['color_secundario'] = strtoupper($nuevos['color_secundario'] ?: '#F59E0B');
    }
    if (isset($nuevos['prefijo'])) {
        $nuevos['prefijo'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nuevos['prefijo']));
    }
    $datos = array_merge($datos, $nuevos);

    if (in_array('nombre', $campos, true)) {
        if ($datos['nombre'] === '') {
            $errores[] = 'El nombre de la empresa es obligatorio.';
        } elseif (mb_strlen($datos['nombre']) > 150) {
            $errores[] = 'El nombre es demasiado largo.';
        }
    }
    if (in_array('correo', $campos, true) && $datos['correo'] !== '' && !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo no es valido.';
    }
    if (in_array('color_primario', $campos, true) && !valid_color($datos['color_primario'])) {
        $errores[] = 'Color primario no valido.';
    }
    if (in_array('color_secundario', $campos, true) && !valid_color($datos['color_secundario'])) {
        $errores[] = 'Color secundario no valido.';
    }
    if (in_array('moneda', $campos, true) && ($datos['moneda'] === '' || mb_strlen($datos['moneda']) > 5)) {
        $errores[] = 'El simbolo de moneda debe tener entre 1 y 5 caracteres.';
    }
    if (in_array('prefijo', $campos, true) && ($datos['prefijo'] === '' || strlen($datos['prefijo']) > 10)) {
        $errores[] = 'El prefijo debe tener entre 1 y 10 letras o numeros.';
    }
    if ($errores) {
        return [null, $errores];
    }

    $valores = [];
    foreach ($campos as $c) {
        $valores[] = in_array($c, ['nombre', 'color_primario', 'color_secundario', 'moneda', 'prefijo'], true) ? $datos[$c] : nullable($datos[$c]);
    }

    if ($id && $campos) {
        $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, $campos));
        $valores[] = $id;
        db_exec("UPDATE empresas SET $sets WHERE id = ?", $valores);
    } elseif (!$id) {
        $ph = implode(', ', array_fill(0, count($campos), '?'));
        db_exec('INSERT INTO empresas (' . implode(', ', $campos) . ") VALUES ($ph)", $valores);
        $id = (int)db()->lastInsertId();
    }

    // Logo
    if (!empty($_POST['quitar_logo']) && !empty($datos['logo'])) {
        borrar_logo($datos['logo']);
        db_exec('UPDATE empresas SET logo = NULL WHERE id = ?', [$id]);
        $datos['logo'] = null;
    }
    list($archivo, $err) = subir_logo('logo', $id);
    if ($err) {
        return [$id, ['Los datos se guardaron, pero: ' . $err]];
    }
    if ($archivo) {
        if (!empty($datos['logo'])) {
            borrar_logo($datos['logo']);
        }
        db_exec('UPDATE empresas SET logo = ? WHERE id = ?', [$archivo, $id]);
        $datos['logo'] = $archivo;
    }
    return [$id, []];
}

/** Imprime el formulario. $accion es la URL de envio. */
function render_form_empresa($emp, $accion, $cancelar, array $errores = [], $seccion = null)
{
    $logo = logo_url($emp);
    $ver = function ($s) use ($seccion) { return $seccion === null || $seccion === $s; };
    $completo = $seccion === null;
    ?>
    <?php if ($errores): ?>
      <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
        <ul class="list-inside list-disc space-y-0.5"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e($accion) ?>" enctype="multipart/form-data" class="<?= $completo ? 'grid gap-5 lg:grid-cols-3' : 'max-w-3xl space-y-5' ?>">
      <?= csrf_field() ?>
      <div class="space-y-5 <?= $completo ? 'lg:col-span-2' : ($ver('marca') ? 'hidden' : '') ?>">
        <?php if ($ver('datos')): ?>
        <section class="card p-5 sm:p-6">
          <h2 class="mb-4 text-base font-bold text-slate-900">Datos generales</h2>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="campo sm:col-span-2"><span>Nombre de la empresa *</span>
              <input class="input" name="nombre" value="<?= e($emp['nombre']) ?>" maxlength="150" required>
            </label>
            <label class="campo"><span>NIT</span>
              <input class="input" name="nit" value="<?= e($emp['nit']) ?>" maxlength="30">
            </label>
            <label class="campo"><span>Telefono</span>
              <input class="input" name="telefono" value="<?= e($emp['telefono']) ?>" maxlength="50" inputmode="tel">
            </label>
            <label class="campo"><span>Correo</span>
              <input class="input" type="email" name="correo" value="<?= e($emp['correo']) ?>" maxlength="150">
              <p class="ayuda">Aqui llega el recordatorio un dia antes de cada evento confirmado.</p>
            </label>
            <label class="campo"><span>Sitio web / redes</span>
              <input class="input" name="sitio_web" value="<?= e($emp['sitio_web']) ?>" maxlength="150">
            </label>
            <label class="campo sm:col-span-2"><span>Direccion</span>
              <input class="input" name="direccion" value="<?= e($emp['direccion']) ?>" maxlength="255">
            </label>
          </div>
        </section>
        <?php endif; ?>

        <?php if ($ver('cotizaciones')): ?>
        <section class="card p-5 sm:p-6">
          <h2 class="mb-4 text-base font-bold text-slate-900">Cotizaciones</h2>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="campo"><span>Simbolo de moneda</span>
              <input class="input" name="moneda" value="<?= e($emp['moneda']) ?>" maxlength="5" required>
            </label>
            <label class="campo"><span>Prefijo del numero</span>
              <input class="input uppercase" name="prefijo" value="<?= e($emp['prefijo']) ?>" maxlength="10" required>
              <p class="ayuda">Ejemplo: <?= e($emp['prefijo'] ?: 'COT') ?>-00001</p>
            </label>
            <label class="campo sm:col-span-2"><span>Texto al pie de la cotizacion</span>
              <textarea class="textarea" name="pie_cotizacion" rows="4" placeholder="Condiciones, forma de pago, vigencia..."><?= e($emp['pie_cotizacion']) ?></textarea>
            </label>
          </div>
        </section>
        <?php endif; ?>
      </div>

      <div class="<?= $completo ? 'space-y-5' : ($ver('marca') ? 'grid gap-5 sm:grid-cols-2' : 'hidden') ?>">
        <?php if ($ver('marca')): ?>
        <section class="card p-5 sm:p-6">
          <h2 class="mb-4 text-base font-bold text-slate-900">Logo</h2>
          <div class="mb-4 flex h-36 items-center justify-center rounded-xl border-2 border-dashed border-slate-200 bg-slate-50 p-3">
            <img id="logo-preview" src="<?= e($logo ?: '') ?>" alt="" class="max-h-full max-w-full object-contain <?= $logo ? '' : 'hidden' ?>">
            <span id="logo-vacio" class="text-sm text-slate-400 <?= $logo ? 'hidden' : '' ?>">Sin logo</span>
          </div>
          <input class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:font-semibold file:text-slate-700 hover:file:bg-slate-200" type="file" name="logo" accept="image/png,image/jpeg" id="logo-input">
          <p class="ayuda">PNG o JPG, maximo 2 MB. Se usa en el sistema y en el PDF.</p>
          <?php if ($logo): ?>
            <label class="mt-3 flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="quitar_logo" value="1" class="rounded"> Quitar logo actual</label>
          <?php endif; ?>
        </section>

        <section class="card p-5 sm:p-6">
          <h2 class="mb-4 text-base font-bold text-slate-900">Colores</h2>
          <div class="grid grid-cols-2 gap-4">
            <label class="campo"><span>Primario</span>
              <input class="input input-color" type="color" name="color_primario" value="<?= e($emp['color_primario']) ?>" id="c1">
            </label>
            <label class="campo"><span>Secundario</span>
              <input class="input input-color" type="color" name="color_secundario" value="<?= e($emp['color_secundario']) ?>" id="c2">
            </label>
          </div>
          <div class="mt-4 overflow-hidden rounded-xl border border-slate-200">
            <div id="prev-c1" class="px-4 py-3 text-sm font-bold" style="background: <?= e($emp['color_primario']) ?>; color: <?= e(color_texto($emp['color_primario'])) ?>">Encabezado</div>
            <div class="flex items-center justify-between bg-white px-4 py-3 text-sm">
              <span class="text-slate-500">Total</span>
              <span id="prev-c2" class="rounded-lg px-2.5 py-1 font-bold" style="background: <?= e($emp['color_secundario']) ?>; color: <?= e(color_texto($emp['color_secundario'])) ?>">Q 1,250.00</span>
            </div>
          </div>
        </section>
        <?php endif; ?>
      </div>

      <div class="<?= $completo ? 'lg:col-start-3' : '' ?>">
        <div class="flex gap-3 <?= $completo ? '' : 'sm:justify-end' ?>">
          <a href="<?= e($cancelar) ?>" class="btn btn-claro flex-1 <?= $completo ? '' : 'sm:flex-none' ?>">Cancelar</a>
          <button class="btn btn-primario flex-1 <?= $completo ? '' : 'sm:flex-none' ?>" type="submit"><i data-lucide="save"></i>Guardar</button>
        </div>
      </div>
    </form>

    <script>
    (function () {
      function txt(hex) { var r = parseInt(hex.substr(1,2),16), g = parseInt(hex.substr(3,2),16), b = parseInt(hex.substr(5,2),16); return (0.299*r+0.587*g+0.114*b)/255 > 0.6 ? '#111827' : '#FFFFFF'; }
      function bind(inp, el) { if (!inp) return; inp.addEventListener('input', function () { el.style.background = inp.value; el.style.color = txt(inp.value); }); }
      bind(document.getElementById('c1'), document.getElementById('prev-c1'));
      bind(document.getElementById('c2'), document.getElementById('prev-c2'));
      var li = document.getElementById('logo-input');
      if (li) li.addEventListener('change', function () {
        var f = li.files[0]; if (!f) return;
        var img = document.getElementById('logo-preview');
        img.src = URL.createObjectURL(f); img.classList.remove('hidden');
        document.getElementById('logo-vacio').classList.add('hidden');
      });
    })();
    </script>
    <?php
}

/** Apartados de "Mi empresa": clave => [url, icono, titulo] */
function mi_empresa_secciones()
{
    return [
        'datos'        => ['mi_empresa.php?s=datos', 'building-2', 'Datos generales'],
        'marca'        => ['mi_empresa.php?s=marca', 'palette', 'Logo y colores'],
        'cotizaciones' => ['mi_empresa.php?s=cotizaciones', 'file-cog', 'Cotizaciones'],
        'descuentos'   => ['descuentos.php', 'percent', 'Descuentos'],
    ];
}

/** Pestanas superiores de "Mi empresa" (comodas en movil). */
function render_tabs_mi_empresa($actual)
{
    ?>
    <nav data-tabs-scroll class="-mx-4 mb-5 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0" aria-label="Apartados de mi empresa">
      <?php foreach (mi_empresa_secciones() as $k => $s): $on = $k === $actual; ?>
        <a href="<?= e($s[0]) ?>" <?= $on ? 'data-tab-on' : '' ?> class="flex shrink-0 items-center gap-2 rounded-xl border px-3.5 py-2 text-sm font-semibold transition <?= $on ? 'border-transparent bg-marca text-on-marca' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50' ?>">
          <i data-lucide="<?= $s[1] ?>" class="h-4 w-4"></i><?= e($s[2]) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php
}
