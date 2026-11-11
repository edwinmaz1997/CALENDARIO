<?php
/**
 * Entrega una imagen de la galeria de la cotizacion validando la empresa de la sesion.
 *   cotizacion_imagen.php?img=ID        imagen concreta
 *   cotizacion_imagen.php?id=COT        primera imagen (compatibilidad)
 */
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/imagen_cotizacion.php';
require_empresa();

$eid = empresa_id();
if (input_int('img')) {
    $r = db_one('SELECT archivo FROM cotizacion_imagenes WHERE id = ? AND empresa_id = ?', [input_int('img'), $eid]);
} else {
    $r = db_one('SELECT i.archivo FROM cotizacion_imagenes i JOIN cotizaciones c ON c.id = i.cotizacion_id
        WHERE i.cotizacion_id = ? AND c.empresa_id = ? ORDER BY i.orden, i.id LIMIT 1', [input_int('id'), $eid]);
}
$ruta = $r ? imagen_cot_path($eid, $r['archivo']) : null;
if (!$ruta) {
    http_response_code(404);
    exit('Imagen no encontrada.');
}
servir_imagen_cot($ruta);
