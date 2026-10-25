-- =====================================================
-- Migracion 005: proveedores, abonos a proveedores, costo por linea y cotizacion enviada
-- Ejecutar en phpMyAdmin si la base ya existia antes de esta version.
-- =====================================================

-- -----------------------------------------------------
-- Proveedores (por empresa). La comision es el % que se queda la empresa
-- sobre el precio de venta: precio 5000 con 10% => se paga 4500 al proveedor.
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS proveedores (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa_id    INT UNSIGNED NOT NULL,
  nombre        VARCHAR(150) NOT NULL,
  contacto      VARCHAR(150) NULL,
  telefono      VARCHAR(50)  NULL,
  correo        VARCHAR(150) NULL,
  comision_pct  DECIMAL(5,2) NOT NULL DEFAULT 10.00,
  notas         TEXT         NULL,
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_proveedores_empresa (empresa_id, nombre),
  CONSTRAINT fk_proveedores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Abonos/pagos hechos a proveedores (opcionalmente ligados a un evento)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS proveedor_pagos (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  empresa_id    INT UNSIGNED NOT NULL,
  proveedor_id  INT UNSIGNED NOT NULL,
  cotizacion_id INT UNSIGNED NULL,
  fecha         DATE         NOT NULL,
  descripcion   VARCHAR(150) NOT NULL,
  metodo        VARCHAR(30)  NULL,
  monto         DECIMAL(12,2) NOT NULL,
  usuario_id    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_prov_pagos (empresa_id, proveedor_id, fecha),
  KEY idx_prov_pagos_cot (cotizacion_id),
  CONSTRAINT fk_prov_pagos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas (id) ON DELETE CASCADE,
  CONSTRAINT fk_prov_pagos_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id) ON DELETE CASCADE,
  CONSTRAINT fk_prov_pagos_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE SET NULL,
  CONSTRAINT fk_prov_pagos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE productos
  ADD COLUMN proveedor_id INT UNSIGNED NULL AFTER empresa_id,
  ADD KEY idx_productos_proveedor (proveedor_id),
  ADD CONSTRAINT fk_productos_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id) ON DELETE SET NULL;

ALTER TABLE cotizacion_detalle
  ADD COLUMN proveedor_id    INT UNSIGNED NULL AFTER producto_id,
  ADD COLUMN comision_pct    DECIMAL(5,2) NULL AFTER subtotal,
  ADD COLUMN costo_proveedor DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER comision_pct,
  ADD KEY idx_detalle_proveedor (proveedor_id),
  ADD CONSTRAINT fk_detalle_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores (id) ON DELETE SET NULL;

ALTER TABLE cotizaciones
  ADD COLUMN enviada_at DATETIME NULL AFTER estado;
