-- =====================================================
-- Migracion 002: adjuntos en cotizaciones y recordatorio por correo
-- Ejecutar en phpMyAdmin SOLO si la base ya existia antes de esta version.
-- (Las instalaciones nuevas con sql/schema.sql ya lo incluyen.)
-- =====================================================

ALTER TABLE cotizaciones
  ADD COLUMN recordatorio_enviado DATETIME NULL AFTER fecha_aceptacion;

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
