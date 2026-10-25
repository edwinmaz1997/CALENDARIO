-- =====================================================
-- Migracion 006: registro de recordatorios por WhatsApp
-- =====================================================

-- -----------------------------------------------------
-- Registro de mensajes de WhatsApp enviados (evita duplicados)
--   tipo: '5dias' o '1dia'; destinatario_tipo: cliente | proveedor
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS whatsapp_envios (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa_id        INT UNSIGNED NOT NULL,
  cotizacion_id     INT UNSIGNED NOT NULL,
  destinatario_tipo ENUM('cliente','proveedor') NOT NULL,
  destinatario_id   INT UNSIGNED NOT NULL,
  tipo              VARCHAR(20)  NOT NULL,
  telefono          VARCHAR(30)  NOT NULL,
  plantilla         VARCHAR(100) NOT NULL,
  estado            ENUM('enviado','error') NOT NULL,
  intentos          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  wa_message_id     VARCHAR(120) NULL,
  error             VARCHAR(500) NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_envio (cotizacion_id, destinatario_tipo, destinatario_id, tipo),
  KEY idx_wa_empresa (empresa_id, created_at),
  CONSTRAINT fk_wa_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
