ALTER TABLE configuracion
  ADD COLUMN IF NOT EXISTS ventas_sin_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER backup_auto_cierre;
