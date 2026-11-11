<?php
/**
 * Imagen opcional de la cotizacion (se muestra en la vista y en el PDF).
 * Se guarda en uploads/adjuntos/<empresa_id>/ (carpeta protegida) y se sirve con cotizacion_imagen.php.
 */

function imagen_cot_path($empresaId, $archivo)
{
    if (!$archivo) {
        return null;
    }
    $p = APP_ROOT . '/uploads/adjuntos/' . (int)$empresaId . '/' . basename($archivo);
    return is_file($p) ? $p : null;
}

function borrar_imagen_cot($empresaId, $archivo)
{
    $p = imagen_cot_path($empresaId, $archivo);
    if ($p) {
        @unlink($p);
    }
}

/**
 * Procesa la imagen subida en $campo. Devuelve [archivo|null, error|null].
 * Acepta JPG y PNG; si GD esta disponible la reduce a un maximo de 1600 px y corrige la orientacion de fotos de celular.
 */
function guardar_imagen_cot($campo, $empresaId, $cotId)
{
    if (empty($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    return procesar_imagen_cot($_FILES[$campo], $empresaId, $cotId);
}

/** Procesa un archivo subido ($f con name, tmp_name, size, error). Devuelve [archivo|null, error|null]. */
function procesar_imagen_cot(array $f, $empresaId, $cotId)
{
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return [null, ($f['name'] ?? 'Imagen') . ': no se pudo subir (codigo ' . (int)$f['error'] . ').'];
    }
    $max = $GLOBALS['CONFIG']['app']['adjunto_max'] ?? 10485760;
    if ($f['size'] > $max) {
        return [null, 'La imagen supera el maximo de ' . round($max / 1048576, 1) . ' MB.'];
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        return [null, ($f['name'] ?? 'La imagen') . ': debe ser JPG o PNG.'];
    }
    $dir = APP_ROOT . '/uploads/adjuntos/' . (int)$empresaId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return [null, 'No se pudo crear la carpeta de imagenes. Revisa permisos de uploads/.'];
    }
    $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    $nombre = 'img_cot' . (int)$cotId . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destino = $dir . '/' . $nombre;

    $procesada = false;
    if (function_exists('imagecreatefromjpeg')) {
        $img = $ext === 'png' ? @imagecreatefrompng($f['tmp_name']) : @imagecreatefromjpeg($f['tmp_name']);
        if ($img) {
            // Orientacion EXIF (fotos de celular)
            if ($ext === 'jpg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($f['tmp_name']);
                $o = (int)($exif['Orientation'] ?? 1);
                if ($o === 3) {
                    $img = imagerotate($img, 180, 0);
                } elseif ($o === 6) {
                    $img = imagerotate($img, -90, 0);
                } elseif ($o === 8) {
                    $img = imagerotate($img, 90, 0);
                }
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $lim = 1600;
            if ($w > $lim || $h > $lim) {
                $r = min($lim / $w, $lim / $h);
                $nw = (int)round($w * $r);
                $nh = (int)round($h * $r);
                $nueva = imagecreatetruecolor($nw, $nh);
                if ($ext === 'png') {
                    imagealphablending($nueva, false);
                    imagesavealpha($nueva, true);
                }
                imagecopyresampled($nueva, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $nueva;
            }
            if ($ext === 'png') {
                imagesavealpha($img, true);
                $procesada = imagepng($img, $destino, 6);
            } else {
                $procesada = imagejpeg($img, $destino, 85);
            }
            imagedestroy($img);
        }
    }
    if (!$procesada && !move_uploaded_file($f['tmp_name'], $destino)) {
        return [null, 'No se pudo guardar la imagen en el servidor.'];
    }
    return [$nombre, null];
}

/* ===================== Galeria (varias imagenes) ===================== */

define('MAX_IMAGENES_COT', 20);

function imagenes_cotizacion($cotId)
{
    return db_all('SELECT id, archivo, orden FROM cotizacion_imagenes WHERE cotizacion_id = ? ORDER BY orden, id', [$cotId]);
}

/**
 * Guarda las imagenes subidas en $campo[] (input multiple). Devuelve [cantidad_guardada, errores[]].
 */
function guardar_imagenes_cot($campo, $empresaId, $cotId)
{
    if (empty($_FILES[$campo]['name'])) {
        return [0, []];
    }
    $F = $_FILES[$campo];
    $nombres = (array)$F['name'];
    $errores = [];
    $ok = 0;
    $actuales = (int)db_val('SELECT COUNT(*) FROM cotizacion_imagenes WHERE cotizacion_id = ?', [$cotId]);
    $orden = (int)db_val('SELECT COALESCE(MAX(orden), 0) FROM cotizacion_imagenes WHERE cotizacion_id = ?', [$cotId]);
    foreach ($nombres as $i => $nombre) {
        $err = is_array($F['error']) ? $F['error'][$i] : $F['error'];
        if ($err === UPLOAD_ERR_NO_FILE || $nombre === '') {
            continue;
        }
        if ($actuales + $ok >= MAX_IMAGENES_COT) {
            $errores[] = 'Maximo ' . MAX_IMAGENES_COT . ' imagenes por cotizacion; algunas no se agregaron.';
            break;
        }
        list($archivo, $e) = procesar_imagen_cot([
            'name'     => $nombre,
            'tmp_name' => is_array($F['tmp_name']) ? $F['tmp_name'][$i] : $F['tmp_name'],
            'size'     => is_array($F['size']) ? $F['size'][$i] : $F['size'],
            'error'    => $err,
        ], $empresaId, $cotId);
        if ($e) {
            $errores[] = $e;
            continue;
        }
        db_exec('INSERT INTO cotizacion_imagenes (cotizacion_id, empresa_id, archivo, orden) VALUES (?, ?, ?, ?)', [$cotId, $empresaId, $archivo, ++$orden]);
        $ok++;
    }
    return [$ok, $errores];
}

/** Borra una imagen de la galeria (registro y archivo). */
function borrar_imagen_galeria($imgId, $cotId, $empresaId)
{
    $r = db_one('SELECT archivo FROM cotizacion_imagenes WHERE id = ? AND cotizacion_id = ? AND empresa_id = ?', [$imgId, $cotId, $empresaId]);
    if (!$r) {
        return false;
    }
    db_exec('DELETE FROM cotizacion_imagenes WHERE id = ?', [$imgId]);
    borrar_imagen_cot($empresaId, $r['archivo']);
    return true;
}

/** Borra todas las imagenes (archivos) de una cotizacion. Los registros se van con la cotizacion. */
function borrar_imagenes_cotizacion($cotId, $empresaId)
{
    foreach (imagenes_cotizacion($cotId) as $r) {
        borrar_imagen_cot($empresaId, $r['archivo']);
    }
}

/** Copia las imagenes de una cotizacion a otra (al duplicar). */
function copiar_imagenes_cotizacion($origenId, $destinoId, $empresaId)
{
    foreach (imagenes_cotizacion($origenId) as $r) {
        $ruta = imagen_cot_path($empresaId, $r['archivo']);
        if (!$ruta) {
            continue;
        }
        $copia = 'img_cot' . (int)$destinoId . '_' . bin2hex(random_bytes(6)) . '.' . pathinfo($ruta, PATHINFO_EXTENSION);
        if (@copy($ruta, dirname($ruta) . '/' . $copia)) {
            db_exec('INSERT INTO cotizacion_imagenes (cotizacion_id, empresa_id, archivo, orden) VALUES (?, ?, ?, ?)', [$destinoId, $empresaId, $copia, $r['orden']]);
        }
    }
}

/** Envia el archivo de imagen al navegador. */
function servir_imagen_cot($ruta)
{
    if (ob_get_length()) {
        ob_end_clean();
    }
    header('Content-Type: ' . (substr($ruta, -4) === '.png' ? 'image/png' : 'image/jpeg'));
    header('Content-Length: ' . filesize($ruta));
    header('Cache-Control: private, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    readfile($ruta);
    exit;
}
