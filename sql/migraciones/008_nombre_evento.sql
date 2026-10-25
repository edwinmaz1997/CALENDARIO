-- =====================================================
-- Migracion 008: nombre del evento en la cotizacion
-- =====================================================

ALTER TABLE cotizaciones ADD COLUMN nombre_evento VARCHAR(150) NULL AFTER cliente_id;
