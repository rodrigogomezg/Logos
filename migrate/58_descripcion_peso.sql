ALTER TABLE productos ADD COLUMN IF NOT EXISTS descripcion text DEFAULT NULL AFTER nombre;
ALTER TABLE productos ADD COLUMN IF NOT EXISTS peso decimal(10,3) DEFAULT NULL AFTER unidad_medida;
