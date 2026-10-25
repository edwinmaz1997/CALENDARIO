<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/pdf_cotizacion.php';
require_empresa();

$eid = empresa_id();
$emp = empresa_actual();
$id = input_int('id');

$cot = db_one('SELECT * FROM cotizaciones WHERE id = ? AND empresa_id = ?', [$id, $eid]);
if (!$cot) {
    http_response_code(404);
    exit('Cotizacion no encontrada.');
}
$cliente = db_one('SELECT * FROM clientes WHERE id = ?', [$cot['cliente_id']]);
$lineas = db_all('SELECT * FROM cotizacion_detalle WHERE cotizacion_id = ? ORDER BY orden, id', [$id]);

$pdf = construir_pdf_cotizacion($cot, $emp, $cliente, $lineas);

$nombreCliente = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $cliente['nombre']) ?: 'cliente');
$archivo = numero_cotizacion($cot, $emp) . '_' . trim($nombreCliente, '_') . '.pdf';

enviar_pdf_sin_cache($pdf, $archivo, !input_int('descargar'));
