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
    'whatsapp' => [
        // Poner en true cuando ya esten el token, el ID del numero y las plantillas aprobadas
        'activo'          => false,
        // Meta for Developers > tu app > WhatsApp > Configuracion de la API
        'phone_number_id' => '',
        // Token permanente de un usuario del sistema (Business Manager > Usuarios del sistema)
        'token'           => '',
        'api_version'     => 'v21.0',
        // Codigo de idioma con el que se crearon las plantillas (es, es_MX, es_ES...)
        'idioma'          => 'es',
        // Codigo de pais para telefonos de 8 digitos
        'codigo_pais'     => '502',
        // Nombres de las plantillas aprobadas en Meta (categoria Utilidad)
        'plantilla_cliente'   => 'recordatorio_evento_cliente',
        'plantilla_proveedor' => 'recordatorio_evento_proveedor',
        // Plantilla con encabezado tipo Documento para enviar el PDF al cliente (cuerpo: Hola {{1}}, adjunto tu cotizacion. ¡Excelente dia!)
        'plantilla_documento' => 'envio_cotizacion_pdf',
        // Dias antes del evento en que se envia cada recordatorio
        'dias' => [5, 1],
    ],
];
