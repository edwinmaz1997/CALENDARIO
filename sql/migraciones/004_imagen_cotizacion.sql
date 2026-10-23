-- =====================================================
-- Migracion 004: imagen opcional en la cotizacion (se muestra en el PDF)
-- =====================================================

ALTER TABLE cotizaciones
  ADD COLUMN imagen VARCHAR(255) NULL AFTER notas;
