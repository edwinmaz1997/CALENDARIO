-- =====================================================
-- Migracion 007: enlace publico para que el cliente vea el PDF de su cotizacion
-- =====================================================

ALTER TABLE cotizaciones ADD COLUMN token_publico VARCHAR(40) NULL AFTER enviada_at;
ALTER TABLE cotizaciones ADD UNIQUE KEY uq_cotizacion_token (token_publico);
