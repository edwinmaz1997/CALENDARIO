<?php
/**
 * Punto de arranque comun: configuracion, sesion, conexion y utilidades.
 */
define('APP_ROOT', dirname(__DIR__));
define('ASSET_V', '1.5.0');

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
