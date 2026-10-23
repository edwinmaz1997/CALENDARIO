<?php
/**
 * Entrega un archivo adjunto validando que pertenezca a la empresa de la sesion.
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/adjuntos.php';
require_empresa();

$eid = empresa_id();
$adj = db_one('SELECT a.* FROM cotizacion_adjuntos a JOIN cotizaciones c ON c.id = a.cotizacion_id WHERE a.id = ? AND c.empresa_id = ?', [input_int('id'), $eid]);
$ruta = $adj ? adjunto_dir($eid) . '/' . basename($adj['archivo']) : null;
if (!$adj || !is_file($ruta)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

$inline = !input_int('descargar') && (adjunto_es_imagen($adj['mime']) || $adj['mime'] === 'application/pdf');
$nombre = str_replace(['"', "\r", "\n"], '', $adj['nombre_original']);
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: 'archivo');

if (ob_get_length()) {
    ob_end_clean();
}
header('Content-Type: ' . $adj['mime']);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
readfile($ruta);
