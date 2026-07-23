ALTER TABLE productos
  ADD COLUMN IF NOT EXISTS comisionable   TINYINT(1)               NOT NULL DEFAULT 0 AFTER publicado_web,
  ADD COLUMN IF NOT EXISTS comision_tipo  ENUM('porcentaje','fijo') DEFAULT NULL       AFTER comisionable,
  ADD COLUMN IF NOT EXISTS comision_valor DECIMAL(10,2)             DEFAULT NULL       AFTER comision_tipo;
