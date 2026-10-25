<?php
/**
 * Punto de arranque comun: configuracion, sesion, conexion y utilidades.
 */
define('APP_ROOT', dirname(__DIR__));
define('ASSET_V', '1.10.1');

$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Falta config/config.php. Copia config/config.example.php como config.php y completa los datos de la base de datos.');
}
$GLOBALS['CONFIG'] = require $configFile;

date_default_timezone_set($GLOBALS['CONFIG']['app']['timezone'] ?? 'America/Guatemala');

if (!empty($GLOBALS['CONFIG']['app']['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

if (!defined('SIN_SESION') && PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('COTIZADORSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once APP_ROOT . '/includes/db.php';
require_once APP_ROOT . '/includes/helpers.php';
require_once APP_ROOT . '/includes/auth.php';
require_once APP_ROOT . '/includes/migraciones.php';

// Pagina de error amigable (en lugar de un error 500 en blanco)
set_exception_handler(function ($ex) {
    error_log('[cotizador] ' . $ex->getMessage() . ' en ' . $ex->getFile() . ':' . $ex->getLine());
    @file_put_contents(APP_ROOT . '/uploads/.errores.log', date('Y-m-d H:i:s') . ' ' . $ex->getMessage() . ' en ' . $ex->getFile() . ':' . $ex->getLine() . PHP_EOL, FILE_APPEND);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $detalle = !empty($GLOBALS['CONFIG']['app']['debug']) ? '<pre style="white-space:pre-wrap;font-size:12px;color:#64748b;text-align:left">' . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>' : '';
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ocurrio un error</title></head>'
        . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f1f5f9;font-family:system-ui,sans-serif;padding:24px;box-sizing:border-box">'
        . '<div style="max-width:420px;text-align:center;background:#fff;border-radius:16px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,.08)">'
        . '<p style="font-size:40px;margin:0">&#9888;</p><h1 style="font-size:20px;margin:8px 0">Ocurrio un error</h1>'
        . '<p style="color:#64748b;margin:0 0 18px">No se pudo completar la accion. Intenta de nuevo; si continua, avisa al administrador.</p>' . $detalle
        . '<a href="index.php" style="display:inline-block;background:#1E40AF;color:#fff;padding:10px 18px;border-radius:10px;text-decoration:none;font-weight:700">Ir al inicio</a>'
        . '</div></body></html>';
});

// Actualiza la base de datos automaticamente con las migraciones pendientes
try {
    aplicar_migraciones();
} catch (Throwable $e) {
    error_log('[cotizador] Migraciones: ' . $e->getMessage());
    @file_put_contents(APP_ROOT . '/uploads/.errores.log', date('Y-m-d H:i:s') . ' MIGRACION ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
}
