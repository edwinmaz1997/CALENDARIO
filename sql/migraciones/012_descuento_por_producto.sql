-- =====================================================
-- Migracion 012: descuento por producto (linea) en la cotizacion
-- subtotal de la linea = cantidad * precio - descuento_monto
-- =====================================================

ALTER TABLE cotizacion_detalle ADD COLUMN descuento_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER precio_unitario;
ALTER TABLE cotizacion_detalle ADD COLUMN descuento_monto DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER descuento_pct;
ALTER TABLE cotizaciones ADD COLUMN descuento_lineas DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER subtotal;
