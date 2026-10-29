-- =====================================================
-- Migracion 011: la fecha del evento es opcional mientras la cotizacion esta pendiente
-- =====================================================

ALTER TABLE cotizaciones MODIFY fecha_evento DATE NULL;
