-- Agrega código secundario (código de proveedor, EAN alternativo, etc.) a productos
ALTER TABLE productos ADD COLUMN IF NOT EXISTS codigo_secundario varchar(50) DEFAULT NULL AFTER codigo;
CREATE INDEX IF NOT EXISTS idx_codigo_secundario ON productos (codigo_secundario);
