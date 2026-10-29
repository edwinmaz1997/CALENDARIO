-- =====================================================
-- Migracion 009: descuentos por empresa aplicables al total de la cotizacion
-- =====================================================

CREATE TABLE IF NOT EXISTS descuentos (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa_id  INT UNSIGNED NOT NULL,
  nombre      VARCHAR(100) NOT NULL,
  porcentaje  DECIMAL(5,2) NOT NULL,
  activo      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_descuentos_empresa (empresa_id, nombre),
  CONSTRAINT fk_descuentos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cotizaciones ADD COLUMN subtotal DECIMAL(12,2) NULL AFTER notas;
ALTER TABLE cotizaciones ADD COLUMN descuento_id INT UNSIGNED NULL AFTER subtotal;
ALTER TABLE cotizaciones ADD COLUMN descuento_nombre VARCHAR(100) NULL AFTER descuento_id;
ALTER TABLE cotizaciones ADD COLUMN descuento_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER descuento_nombre;
ALTER TABLE cotizaciones ADD COLUMN descuento_monto DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER descuento_pct;
