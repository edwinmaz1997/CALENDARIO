<?php
/**
 * Alternativa por URL para el cron (si el hosting solo permite wget/curl):
 *   wget -q -O /dev/null "https://tudominio.com/cron_recordatorios.php?token=TU_CRON_TOKEN"
 */
define('SIN_SESION', true);
define('CRON_WEB', true);
require __DIR__ . '/includes/bootstrap.php';

$token = (string)($GLOBALS['CONFIG']['app']['cron_token'] ?? '');
$dado = (string)($_GET['token'] ?? '');
if ($token === '' || strlen($token) < 16 || strpos($token, 'CAMBIAR') === 0 || !hash_equals($token, $dado)) {
    http_response_code(403);
    exit('No autorizado.');
}
header('Content-Type: text/plain; charset=utf-8');
require __DIR__ . '/cron/recordatorios.php';
