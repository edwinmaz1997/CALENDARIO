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
    $datos = array_merge($datos, [
        'nombre'           => input('nombre'),
        'nit'              => input('nit'),
        'direccion'        => input('direccion'),
        'telefono'         => input('telefono'),
        'correo'           => input('correo'),
        'sitio_web'        => input('sitio_web'),
        'color_primario'   => strtoupper(input('color_primario', '#1E40AF')),
        'color_secundario' => strtoupper(input('color_secundario', '#F59E0B')),
        'moneda'           => input('moneda', 'Q'),
        'prefijo'          => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', input('prefijo', 'COT'))),
        'pie_cotizacion'   => input('pie_cotizacion'),
    ]);

    if ($datos['nombre'] === '') {
        $errores[] = 'El nombre de la empresa es obligatorio.';
    } elseif (mb_strlen($datos['nombre']) > 150) {
        $errores[] = 'El nombre es demasiado largo.';
    }
    if ($datos['correo'] !== '' && !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo no es valido.';
    }
    if (!valid_color($datos['color_primario'])) {
        $errores[] = 'Color primario no valido.';
    }
    if (!valid_color($datos['color_secundario'])) {
        $errores[] = 'Color secundario no valido.';
    }
    if ($datos['moneda'] === '' || mb_strlen($datos['moneda']) > 5) {
        $errores[] = 'El simbolo de moneda debe tener entre 1 y 5 caracteres.';
    }
    if ($datos['prefijo'] === '' || strlen($datos['prefijo']) > 10) {
        $errores[] = 'El prefijo debe tener entre 1 y 10 letras o numeros.';
    }
    if ($errores) {
        return [null, $errores];
    }

    $campos = ['nombre', 'nit', 'direccion', 'telefono', 'correo', 'sitio_web', 'color_primario', 'color_secundario', 'moneda', 'prefijo', 'pie_cotizacion'];
    $valores = [];
    foreach ($campos as $c) {
        $valores[] = in_array($c, ['nombre', 'color_primario', 'color_secundario', 'moneda', 'prefijo'], true) ? $datos[$c] : nullable($datos[$c]);
    }

    if ($id) {
        $sets = implode(', ', array_map(function ($c) { return "$c = ?"; }, $campos));
        $valores[] = $id;
        db_exec("UPDATE empresas SET $sets WHERE id = ?", $valores);
    } else {
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
function render_form_empresa($emp, $accion, $cancelar, array $errores = [])
{
    $logo = logo_url($emp);
    ?>
    <?php if ($errores): ?>
      <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
        <ul class="list-inside list-disc space-y-0.5"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= e($accion) ?>" enctype="multipart/form-data" class="grid gap-5 lg:grid-cols-3">
      <?= csrf_field() ?>
      <div class="space-y-5 lg:col-span-2">
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
      </div>

      <div class="space-y-5">
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

        <div class="flex gap-3">
          <a href="<?= e($cancelar) ?>" class="btn btn-claro flex-1">Cancelar</a>
          <button class="btn btn-primario flex-1" type="submit"><i data-lucide="save"></i>Guardar</button>
        </div>
      </div>
    </form>

    <script>
    (function () {
      function txt(hex) { var r = parseInt(hex.substr(1,2),16), g = parseInt(hex.substr(3,2),16), b = parseInt(hex.substr(5,2),16); return (0.299*r+0.587*g+0.114*b)/255 > 0.6 ? '#111827' : '#FFFFFF'; }
      function bind(inp, el) { inp.addEventListener('input', function () { el.style.background = inp.value; el.style.color = txt(inp.value); }); }
      bind(document.getElementById('c1'), document.getElementById('prev-c1'));
      bind(document.getElementById('c2'), document.getElementById('prev-c2'));
      var li = document.getElementById('logo-input');
      li.addEventListener('change', function () {
        var f = li.files[0]; if (!f) return;
        var img = document.getElementById('logo-preview');
        img.src = URL.createObjectURL(f); img.classList.remove('hidden');
        document.getElementById('logo-vacio').classList.add('hidden');
      });
    })();
    </script>
    <?php
}
