<?php
/**
 * Entrega la imagen de una cotizacion validando la empresa de la sesion.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/imagen_cotizacion.php';
require_empresa();

$eid = empresa_id();
$cot = db_one('SELECT imagen FROM cotizaciones WHERE id = ? AND empresa_id = ?', [input_int('id'), $eid]);
$ruta = $cot ? imagen_cot_path($eid, $cot['imagen']) : null;
if (!$ruta) {
    http_response_code(404);
    exit('Imagen no encontrada.');
}
if (ob_get_length()) {
    ob_end_clean();
}
header('Content-Type: ' . (substr($ruta, -4) === '.png' ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($ruta);
