-- =====================================================
-- Migracion 013: varias imagenes por cotizacion (galeria / multimedia)
-- =====================================================

CREATE TABLE IF NOT EXISTS cotizacion_imagenes (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cotizacion_id INT UNSIGNED NOT NULL,
  empresa_id    INT UNSIGNED NOT NULL,
  archivo       VARCHAR(120) NOT NULL,
  orden         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_imagenes_cotizacion (cotizacion_id, orden, id),
  CONSTRAINT fk_imagenes_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cotizacion_imagenes (cotizacion_id, empresa_id, archivo, orden)
  SELECT c.id, c.empresa_id, c.imagen, 1 FROM cotizaciones c
  WHERE c.imagen IS NOT NULL AND c.imagen <> ''
    AND NOT EXISTS (SELECT 1 FROM cotizacion_imagenes i WHERE i.cotizacion_id = c.id AND i.archivo = c.imagen);

UPDATE cotizaciones SET imagen = NULL WHERE imagen IS NOT NULL;
