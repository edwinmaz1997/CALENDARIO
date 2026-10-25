<?php
/**
 * Envia por correo el recordatorio de los eventos de manana (cotizaciones aceptadas)
 * y los recordatorios por WhatsApp al cliente y a los proveedores (5 y 1 dia antes).
 *
 * Cron en cPanel (cada hora de 7:00 a 20:00):
 *   0 7-20 * * * /usr/local/bin/php /home/USUARIO/RUTA_DEL_SISTEMA/cron/recordatorios.php >/dev/null 2>&1
 *
 * Cada cotizacion se notifica una sola vez; correr el cron varias veces al dia
 * permite avisar tambien las cotizaciones que se aceptan el mismo dia anterior al evento.
 */
if (PHP_SAPI !== 'cli' && !defined('CRON_WEB')) {
    http_response_code(403);
    exit('Solo por linea de comandos.');
}
if (!defined('SIN_SESION')) {
    define('SIN_SESION', true);
}
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/recordatorios.php';
require_once APP_ROOT . '/includes/whatsapp.php';

@set_time_limit(300);
$log = procesar_recordatorios();
// WhatsApp: recordatorios al cliente y a proveedores (por defecto 5 y 1 dia antes)
$log = array_merge($log, procesar_whatsapp());

$archivoLog = __DIR__ . '/recordatorios.log';
if (is_file($archivoLog) && filesize($archivoLog) > 1048576) {
    @rename($archivoLog, $archivoLog . '.1');
}
@file_put_contents($archivoLog, implode(PHP_EOL, $log) . PHP_EOL, FILE_APPEND);

echo implode(PHP_EOL, $log) . PHP_EOL;
