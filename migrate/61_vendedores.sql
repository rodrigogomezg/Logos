ALTER TABLE vendedores
  ADD COLUMN IF NOT EXISTS activo      TINYINT(1)    NOT NULL DEFAULT 1   AFTER nombre,
  ADD COLUMN IF NOT EXISTS meta_monto  DECIMAL(14,2) DEFAULT NULL         AFTER activo,
  ADD COLUMN IF NOT EXISTS meta_desde  DATE          DEFAULT NULL         AFTER meta_monto,
  ADD COLUMN IF NOT EXISTS meta_hasta  DATE          DEFAULT NULL         AFTER meta_desde;
