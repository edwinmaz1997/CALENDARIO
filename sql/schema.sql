-- =====================================================
-- CALENDARIO - Sistema de cotizaciones multiempresa
-- Compatible con MySQL 5.7+ / MariaDB 10.2+
-- Importar en phpMyAdmin sobre la base de datos ya creada.
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- Empresas
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS empresas (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre           VARCHAR(150) NOT NULL,
  nit              VARCHAR(30)  NULL,
  direccion        VARCHAR(255) NULL,
  telefono         VARCHAR(50)  NULL,
  correo           VARCHAR(150) NULL,
  sitio_web        VARCHAR(150) NULL,
  logo             VARCHAR(255) NULL,
  color_primario   CHAR(7)      NOT NULL DEFAULT '#1E40AF',
  color_secundario CHAR(7)      NOT NULL DEFAULT '#F59E0B',
  moneda           VARCHAR(5)   NOT NULL DEFAULT 'Q',
  prefijo          VARCHAR(10)  NOT NULL DEFAULT 'COT',
  pie_cotizacion   TEXT         NULL,
  activo           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Usuarios
--   rol 'admin'   : administrador general (empresa_id NULL), gestiona empresas y usuarios
--   rol 'empresa' : un usuario por empresa
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa_id    INT UNSIGNED NULL,
  nombre        VARCHAR(150) NOT NULL,
  usuario       VARCHAR(60)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol           ENUM('admin','empresa') NOT NULL DEFAULT 'empresa',
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  ultimo_acceso DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_usuario (usuario),
  UNIQUE KEY uq_usuarios_empresa (empresa_id),
  CONSTRAINT fk_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Clientes (catalogo por empresa)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS clientes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa_id  INT UNSIGNED NOT NULL,
  nombre      VARCHAR(150) NOT NULL,
  nit         VARCHAR(30)  NULL,
  telefono    VARCHAR(50)  NULL,
  correo      VARCHAR(150) NULL,
  direccion   VARCHAR(255) NULL,
  activo      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_clientes_empresa (empresa_id, nombre),
  CONSTRAINT fk_clientes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Productos (catalogo por empresa)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS productos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa_id  INT UNSIGNED NOT NULL,
  codigo      VARCHAR(50)  NULL,
  nombre      VARCHAR(150) NOT NULL,
  descripcion TEXT         NULL,
  unidad      VARCHAR(30)  NOT NULL DEFAULT 'Unidad',
  precio      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  activo      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_productos_empresa (empresa_id, nombre),
  CONSTRAINT fk_productos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Cotizaciones
--   estado 'pendiente' al crearse; al pasar a 'aceptada' aparece en el calendario en fecha_evento
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizaciones (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa_id       INT UNSIGNED NOT NULL,
  correlativo      INT UNSIGNED NOT NULL,
  cliente_id       INT UNSIGNED NOT NULL,
  usuario_id       INT UNSIGNED NULL,
  fecha            DATE         NOT NULL,
  fecha_evento     DATE         NOT NULL,
  hora_evento      TIME         NULL,
  lugar_evento     VARCHAR(255) NULL,
  estado           ENUM('pendiente','aceptada','rechazada') NOT NULL DEFAULT 'pendiente',
  notas            TEXT         NULL,
  imagen           VARCHAR(255) NULL,
  total            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  fecha_aceptacion DATETIME     NULL,
  recordatorio_enviado DATETIME NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cotizacion_correlativo (empresa_id, correlativo),
  KEY idx_cotizaciones_estado_evento (empresa_id, estado, fecha_evento),
  CONSTRAINT fk_cotizaciones_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE,
  CONSTRAINT fk_cotizaciones_cliente FOREIGN KEY (cliente_id) REFERENCES clientes (id),
  CONSTRAINT fk_cotizaciones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Detalle de cotizacion (guarda copia del nombre y precio al momento de cotizar)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizacion_detalle (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cotizacion_id   INT UNSIGNED NOT NULL,
  producto_id     INT UNSIGNED NULL,
  descripcion     VARCHAR(255) NOT NULL,
  unidad          VARCHAR(30)  NULL,
  cantidad        DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  orden           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_detalle_cotizacion (cotizacion_id),
  CONSTRAINT fk_detalle_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE,
  CONSTRAINT fk_detalle_producto FOREIGN KEY (producto_id) REFERENCES productos (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Archivos adjuntos de la cotizacion (anticipos, comprobantes, contratos...)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizacion_adjuntos (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cotizacion_id   INT UNSIGNED NOT NULL,
  titulo          VARCHAR(150) NOT NULL,
  archivo         VARCHAR(255) NOT NULL,
  nombre_original VARCHAR(255) NOT NULL,
  mime            VARCHAR(100) NOT NULL,
  tamano          INT UNSIGNED NOT NULL DEFAULT 0,
  usuario_id      INT UNSIGNED NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_adjuntos_cotizacion (cotizacion_id),
  CONSTRAINT fk_adjuntos_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE,
  CONSTRAINT fk_adjuntos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Pagos recibidos por cotizacion (anticipos, abonos, pago final)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizacion_pagos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cotizacion_id INT UNSIGNED NOT NULL,
  fecha         DATE         NOT NULL,
  descripcion   VARCHAR(150) NOT NULL,
  metodo        VARCHAR(30)  NULL,
  monto         DECIMAL(12,2) NOT NULL,
  adjunto_id    INT UNSIGNED NULL,
  usuario_id    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pagos_cotizacion (cotizacion_id, fecha),
  CONSTRAINT fk_pagos_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE,
  CONSTRAINT fk_pagos_adjunto FOREIGN KEY (adjunto_id) REFERENCES cotizacion_adjuntos (id) ON DELETE SET NULL,
  CONSTRAINT fk_pagos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------
-- Usuario administrador inicial
--   usuario: admin   contrasena: admin123   (cambiarla al primer ingreso en "Mi perfil")
-- -----------------------------------------------------
INSERT INTO usuarios (empresa_id, nombre, usuario, password_hash, rol)
SELECT NULL, 'Administrador', 'admin', '$2y$10$NyhpfexrS7pylpsAu6zeIeBw5v8KaNM4810cD5L2XHp7Tt7wKyjHq', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM usuarios WHERE usuario = 'admin');
