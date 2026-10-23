-- =====================================================
-- Migracion 003: control de pagos por cotizacion
-- Ejecutar en phpMyAdmin si la base ya existia antes de esta version.
-- =====================================================

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
