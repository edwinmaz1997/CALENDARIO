-- =====================================================
-- Migracion 010: portal del cliente (enlace corto, aceptar / cambios / rechazar) y conversacion
-- =====================================================

ALTER TABLE cotizaciones ADD COLUMN codigo_corto VARCHAR(12) NULL AFTER token_publico;
ALTER TABLE cotizaciones ADD UNIQUE KEY uq_cotizacion_codigo (codigo_corto);
ALTER TABLE cotizaciones ADD COLUMN respuesta_cliente VARCHAR(20) NULL AFTER codigo_corto;
ALTER TABLE cotizaciones ADD COLUMN respuesta_cliente_at DATETIME NULL AFTER respuesta_cliente;

CREATE TABLE IF NOT EXISTS cotizacion_mensajes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cotizacion_id INT UNSIGNED NOT NULL,
  empresa_id    INT UNSIGNED NOT NULL,
  autor         ENUM('cliente','negocio','sistema') NOT NULL,
  mensaje       TEXT NOT NULL,
  leido         TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mensajes_cotizacion (cotizacion_id, id),
  KEY idx_mensajes_empresa (empresa_id, autor, leido),
  CONSTRAINT fk_mensajes_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
