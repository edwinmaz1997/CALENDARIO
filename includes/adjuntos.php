<?php
/**
 * Archivos adjuntos de cotizaciones.
 * Se guardan en uploads/adjuntos/<empresa_id>/ (carpeta bloqueada al publico)
 * y solo se descargan a traves de adjunto.php validando la sesion.
 */

function adjuntos_tipos()
{
    return [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'gif'  => ['image/gif'],
        'heic' => ['image/heic', 'image/heif', 'application/octet-stream'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls'  => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    ];
}

function adjuntos_titulos_sugeridos()
{
    return ['Anticipo', 'Comprobante de pago', 'Pago final', 'Contrato', 'Referencia', 'Fotografia del lugar', 'Factura'];
}

function adjunto_es_imagen($mime)
{
    return in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
}

function adjunto_dir($empresaId)
{
    return APP_ROOT . '/uploads/adjuntos/' . (int)$empresaId;
}

function tamano_legible($bytes)
{
    $bytes = (int)$bytes;
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return max(1, (int)round($bytes / 1024)) . ' KB';
}

function adjunto_icono($mime)
{
    if (strpos($mime, 'image/') === 0) {
        return 'image';
    }
    if ($mime === 'application/pdf') {
        return 'file-text';
    }
    if (strpos($mime, 'sheet') !== false || strpos($mime, 'excel') !== false) {
        return 'file-spreadsheet';
    }
    return 'file';
}

/**
 * Sube uno o varios archivos del campo $campo (input multiple) para la cotizacion.
 * Devuelve [cantidadGuardada, errores[]].
 */
function subir_adjuntos($campo, $cotizacionId, $empresaId, $titulo, $usuarioId)
{
    $errores = [];
    $guardados = 0;
    if (empty($_FILES[$campo]) || !is_array($_FILES[$campo]['name'])) {
        return [0, ['Selecciona al menos un archivo.']];
    }
    $max = $GLOBALS['CONFIG']['app']['adjunto_max'] ?? 10485760;
    $tipos = adjuntos_tipos();
    $dir = adjunto_dir($empresaId);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return [0, ['No se pudo crear la carpeta de adjuntos. Revisa permisos de uploads/.']];
    }
    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;

    $f = $_FILES[$campo];
    $n = count($f['name']);
    for ($i = 0; $i < $n; $i++) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $nombre = basename((string)$f['name'][$i]);
        if ($f['error'][$i] === UPLOAD_ERR_INI_SIZE || $f['error'][$i] === UPLOAD_ERR_FORM_SIZE) {
            $errores[] = $nombre . ': supera el tamano permitido por el servidor.';
            continue;
        }
        if ($f['error'][$i] !== UPLOAD_ERR_OK) {
            $errores[] = $nombre . ': no se pudo subir (codigo ' . (int)$f['error'][$i] . ').';
            continue;
        }
        if ($f['size'][$i] > $max) {
            $errores[] = $nombre . ': supera el maximo de ' . tamano_legible($max) . '.';
            continue;
        }
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        if (!isset($tipos[$ext])) {
            $errores[] = $nombre . ': tipo de archivo no permitido.';
            continue;
        }
        $mime = $finfo ? finfo_file($finfo, $f['tmp_name'][$i]) : ($f['type'][$i] ?: 'application/octet-stream');
        if (!in_array($mime, $tipos[$ext], true)) {
            $errores[] = $nombre . ': el contenido no coincide con la extension.';
            continue;
        }
        if (adjunto_es_imagen($mime) && !@getimagesize($f['tmp_name'][$i])) {
            $errores[] = $nombre . ': la imagen esta danada.';
            continue;
        }
        $archivo = 'cot' . (int)$cotizacionId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'][$i], $dir . '/' . $archivo)) {
            $errores[] = $nombre . ': no se pudo guardar en el servidor.';
            continue;
        }
        $mimeGuardado = $mime === 'application/octet-stream' || $mime === 'application/zip' ? $tipos[$ext][0] : $mime;
        db_exec('INSERT INTO cotizacion_adjuntos (cotizacion_id, titulo, archivo, nombre_original, mime, tamano, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $cotizacionId, mb_substr($titulo, 0, 150), $archivo, mb_substr($nombre, 0, 255), $mimeGuardado, (int)$f['size'][$i], $usuarioId,
        ]);
        $guardados++;
    }
    if ($finfo) {
        finfo_close($finfo);
    }
    return [$guardados, $errores];
}

function borrar_archivo_adjunto($empresaId, $archivo)
{
    $p = adjunto_dir($empresaId) . '/' . basename($archivo);
    if (is_file($p)) {
        @unlink($p);
    }
}
