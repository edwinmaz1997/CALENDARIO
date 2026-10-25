<?php
/**
 * Utilidades generales.
 */

function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function is_post()
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input($key, $default = '')
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function input_int($key, $default = 0)
{
    $v = $_POST[$key] ?? $_GET[$key] ?? null;
    return ($v === null || $v === '') ? $default : (int)$v;
}

function nullable($v)
{
    $v = is_string($v) ? trim($v) : $v;
    return ($v === '' || $v === null) ? null : $v;
}

/* ---------------- CSRF ---------------- */

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check()
{
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('La sesion del formulario expiro. Regresa y vuelve a intentarlo.');
    }
}

/* ---------------- Mensajes flash ---------------- */

function flash($tipo, $mensaje)
{
    $_SESSION['flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function flashes()
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------- Formatos ---------------- */

function money($monto, $moneda = null)
{
    if ($moneda === null) {
        $emp = empresa_actual();
        $moneda = $emp['moneda'] ?? 'Q';
    }
    return $moneda . ' ' . number_format((float)$monto, 2, '.', ',');
}

function fecha($f, $conHora = false)
{
    if (!$f) {
        return '';
    }
    $ts = strtotime($f);
    return $conHora ? date('d/m/Y H:i', $ts) : date('d/m/Y', $ts);
}

function fecha_larga($f)
{
    if (!$f) {
        return '';
    }
    $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $ts = strtotime($f);
    return date('j', $ts) . ' de ' . $meses[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}

function hora($h)
{
    return $h ? date('g:i A', strtotime($h)) : '';
}

function numero_cotizacion($cot, $empresa = null)
{
    $prefijo = $empresa['prefijo'] ?? (empresa_actual()['prefijo'] ?? 'COT');
    return $prefijo . '-' . str_pad((string)$cot['correlativo'], 5, '0', STR_PAD_LEFT);
}

function valid_date($d)
{
    $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function valid_color($c)
{
    return (bool)preg_match('/^#[0-9a-fA-F]{6}$/', (string)$c);
}

function hex2rgb($hex)
{
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) !== 6) {
        return [30, 64, 175];
    }
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** Devuelve blanco o negro segun el contraste con el color de fondo */
function color_texto($hex)
{
    list($r, $g, $b) = hex2rgb($hex);
    $l = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $l > 0.6 ? '#111827' : '#FFFFFF';
}

function estados()
{
    return [
        'pendiente' => ['Pendiente', 'bg-amber-100 text-amber-800 ring-amber-200'],
        'aceptada'  => ['Aceptada', 'bg-emerald-100 text-emerald-800 ring-emerald-200'],
        'rechazada' => ['Rechazada', 'bg-rose-100 text-rose-800 ring-rose-200'],
    ];
}

function badge_estado($estado)
{
    $es = estados();
    $d = $es[$estado] ?? [$estado, 'bg-slate-100 text-slate-700 ring-slate-200'];
    return '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ' . $d[1] . '">' . e($d[0]) . '</span>';
}

/** Convierte UTF-8 a la codificacion que usa FPDF */
function pdf_txt($s)
{
    $s = (string)$s;
    $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
    return $out === false ? utf8_to_latin_fallback($s) : $out;
}

function utf8_to_latin_fallback($s)
{
    return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
}

/* ---------------- Logos ---------------- */

function logo_url($empresa)
{
    if (!empty($empresa['logo']) && is_file(APP_ROOT . '/uploads/logos/' . $empresa['logo'])) {
        return 'uploads/logos/' . rawurlencode($empresa['logo']);
    }
    return null;
}

function logo_path($empresa)
{
    if (!empty($empresa['logo'])) {
        $p = APP_ROOT . '/uploads/logos/' . $empresa['logo'];
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

/**
 * Procesa la subida de logo. Devuelve [nombreArchivo|null, error|null].
 */
function subir_logo($campo, $empresaId)
{
    if (empty($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    $f = $_FILES[$campo];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        return [null, 'No se pudo subir el logo (codigo ' . (int)$f['error'] . ').'];
    }
    $max = $GLOBALS['CONFIG']['app']['logo_max'] ?? 2097152;
    if ($f['size'] > $max) {
        return [null, 'El logo supera el tamano maximo de ' . round($max / 1048576, 1) . ' MB.'];
    }
    $info = @getimagesize($f['tmp_name']);
    $tipos = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'];
    if (!$info || !isset($tipos[$info[2]])) {
        return [null, 'El logo debe ser una imagen PNG o JPG.'];
    }
    $dir = APP_ROOT . '/uploads/logos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $nombre = 'empresa_' . (int)$empresaId . '_' . bin2hex(random_bytes(4)) . '.' . $tipos[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $nombre)) {
        return [null, 'No se pudo guardar el logo en el servidor. Revisa permisos de la carpeta uploads/logos.'];
    }
    return [$nombre, null];
}

function borrar_logo($archivo)
{
    if ($archivo) {
        $p = APP_ROOT . '/uploads/logos/' . basename($archivo);
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

/** Paginacion simple */
function paginar($total, $porPagina = 20)
{
    $paginas = max(1, (int)ceil($total / $porPagina));
    $pagina = min(max(1, input_int('p', 1)), $paginas);
    return ['pagina' => $pagina, 'paginas' => $paginas, 'offset' => ($pagina - 1) * $porPagina, 'limite' => $porPagina, 'total' => $total];
}

function url_con($params)
{
    $q = array_merge($_GET, $params);
    return '?' . http_build_query(array_filter($q, function ($v) {
        return $v !== '' && $v !== null;
    }));
}

/** URL base publica del sistema (config app.url o la del servidor actual) */
function url_base()
{
    $u = rtrim((string)($GLOBALS['CONFIG']['app']['url'] ?? ''), '/');
    if ($u !== '' && strpos($u, 'tudominio') === false) {
        return $u;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/** Devuelve (y crea si no existe) el token publico de una cotizacion */
function token_publico_cotizacion($cotId)
{
    $t = db_val('SELECT token_publico FROM cotizaciones WHERE id = ?', [$cotId]);
    if (!$t) {
        $t = bin2hex(random_bytes(16));
        db_exec('UPDATE cotizaciones SET token_publico = ? WHERE id = ? AND token_publico IS NULL', [$t, $cotId]);
        $t = db_val('SELECT token_publico FROM cotizaciones WHERE id = ?', [$cotId]);
    }
    return $t;
}

function enlace_publico_cotizacion($cotId)
{
    return url_base() . '/c.php?t=' . token_publico_cotizacion($cotId) . '&v=' . substr(huella_cotizacion($cotId), 0, 6);
}

/** Huella del contenido de una cotizacion (cliente, fechas, lugar, notas, total y lineas) para detectar cambios */
function huella_cotizacion($cotId)
{
    $c = db_one('SELECT cliente_id, fecha, fecha_evento, hora_evento, lugar_evento, notas, total FROM cotizaciones WHERE id = ?', [$cotId]);
    $l = db_all('SELECT producto_id, proveedor_id, descripcion, unidad, cantidad, precio_unitario FROM cotizacion_detalle WHERE cotizacion_id = ? ORDER BY orden, id', [$cotId]);
    return md5(json_encode([$c, $l]));
}

