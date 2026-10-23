<?php
/**
 * Copiar este archivo como config.php y completar los datos de la base de datos de cPanel.
 * config.php NO se sube al repositorio.
 */
return [
    'db' => [
        'host'     => 'localhost',
        'name'     => 'cpaneluser_calendario',
        'user'     => 'cpaneluser_calendario',
        'pass'     => 'CAMBIAR',
        'charset'  => 'utf8mb4',
    ],
    'app' => [
        'nombre'    => 'Cotizador',
        'timezone'  => 'America/Guatemala',
        // true solo en desarrollo: muestra errores en pantalla
        'debug'     => false,
        // Tamano maximo del logo en bytes (2 MB)
        'logo_max'  => 2 * 1024 * 1024,
    ],
];
