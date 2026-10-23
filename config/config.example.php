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
        // URL publica del sistema (se usa en los enlaces de los correos). Sin "/" al final.
        'url'       => 'https://cotizador.tudominio.com',
        // true solo en desarrollo: muestra errores en pantalla
        'debug'     => false,
        // Tamano maximo del logo en bytes (2 MB)
        'logo_max'  => 2 * 1024 * 1024,
        // Tamano maximo de cada archivo adjunto en bytes (10 MB)
        'adjunto_max' => 10 * 1024 * 1024,
        // Clave para ejecutar el recordatorio por URL (si el cron usa wget/curl). Cambiar por una cadena larga.
        'cron_token' => 'CAMBIAR_POR_UNA_CLAVE_LARGA',
    ],
    'mail' => [
        // Correo remitente. Debe ser una cuenta creada en cPanel (Email Accounts) del mismo dominio.
        'from_email' => 'notificaciones@tudominio.com',
        'from_name'  => 'Cotizador',
        // SMTP recomendado para que los correos no lleguen a spam.
        // Si 'host' queda vacio se usa la funcion mail() del servidor.
        'smtp' => [
            'host'   => 'mail.tudominio.com',
            'port'   => 465,
            'secure' => 'ssl',   // 'ssl' para 465, 'tls' para 587
            'user'   => 'notificaciones@tudominio.com',
            'pass'   => 'CAMBIAR',
        ],
    ],
];
