ALTER TABLE productos
    ADD COLUMN unidad_medida VARCHAR(20) NULL DEFAULT NULL AFTER iva_porcentaje;
