-- Campos adicionales para el cierre de caja extendido
ALTER TABLE caja_cierres
  ADD COLUMN IF NOT EXISTS total_cc             DECIMAL(12,2)  NOT NULL DEFAULT 0    AFTER total_mercado_pago,
  ADD COLUMN IF NOT EXISTS mercado_pago_contado DECIMAL(12,2)  NULL                  AFTER posnet_cierres,
  ADD COLUMN IF NOT EXISTS fondo_siguiente       DECIMAL(12,2)  NULL                  AFTER mercado_pago_contado,
  ADD COLUMN IF NOT EXISTS observaciones         TEXT           NULL                  AFTER fondo_siguiente;

ALTER TABLE caja_turnos
  ADD COLUMN IF NOT EXISTS mercado_pago_contado DECIMAL(12,2)  NULL                  AFTER posnet_cierres,
  ADD COLUMN IF NOT EXISTS fondo_siguiente       DECIMAL(12,2)  NULL                  AFTER mercado_pago_contado,
  ADD COLUMN IF NOT EXISTS observaciones         TEXT           NULL                  AFTER fondo_siguiente;
