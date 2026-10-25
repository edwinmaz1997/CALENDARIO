<?php
/**
 * Enlace publico para que el cliente vea su cotizacion en PDF (sin iniciar sesion).
 * URL: c.php?t=TOKEN  (el token se genera al enviar la cotizacion por WhatsApp)
 */
define('SIN_SESION', true);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/pdf_cotizacion.php';

$t = (string)($_GET['t'] ?? '');
$cot = preg_match('/^[a-f0-9]{32}$/', $t) ? db_one('SELECT * FROM cotizaciones WHERE token_publico = ?', [$t]) : null;
$emp = $cot ? db_one('SELECT * FROM empresas WHERE id = ? AND activo = 1', [$cot['empresa_id']]) : null;
if (!$cot || !$emp || $cot['estado'] === 'rechazada') {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Cotizacion no disponible</title></head>'
        . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f1f5f9;font-family:system-ui,sans-serif;padding:24px">'
        . '<div style="max-width:380px;text-align:center;background:#fff;border-radius:16px;padding:28px"><h1 style="font-size:20px">Cotizacion no disponible</h1>'
        . '<p style="color:#64748b">El enlace no es valido o la cotizacion ya no esta disponible. Comunicate con quien te la envio.</p></div></body></html>';
    exit;
}
$cliente = db_one('SELECT * FROM clientes WHERE id = ?', [$cot['cliente_id']]);
$lineas = db_all('SELECT * FROM cotizacion_detalle WHERE cotizacion_id = ? ORDER BY orden, id', [$cot['id']]);
$pdf = construir_pdf_cotizacion($cot, $emp, $cliente, $lineas);

header('X-Robots-Tag: noindex, nofollow');
enviar_pdf_sin_cache($pdf, numero_cotizacion($cot, $emp) . '.pdf', true);
