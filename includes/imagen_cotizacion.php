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
    $f = $_FILES[$campo];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return [null, 'No se pudo subir la imagen (codigo ' . (int)$f['error'] . ').'];
    }
    $max = $GLOBALS['CONFIG']['app']['adjunto_max'] ?? 10485760;
    if ($f['size'] > $max) {
        return [null, 'La imagen supera el maximo de ' . round($max / 1048576, 1) . ' MB.'];
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        return [null, 'La imagen debe ser JPG o PNG.'];
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
