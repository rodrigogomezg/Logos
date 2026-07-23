-- Completa la tabla sucursales con campos para multi-sucursal y modo de operación.
-- Circular FK manejable: sucursales.deposito_id → depositos.id  (nullable)
--                        depositos.sucursal_id  → sucursales.id  (ya existe)
-- Al crear una sucursal nueva: INSERT con deposito_id = NULL → INSERT deposito → UPDATE sucursales.

ALTER TABLE sucursales
    ADD COLUMN modo_operacion ENUM('pool_unico','independiente') NOT NULL DEFAULT 'pool_unico'
        AFTER nombre,
    ADD COLUMN fecha_alta DATE NOT NULL DEFAULT (CURRENT_DATE)
        AFTER activo,
    ADD COLUMN deposito_id INT NULL
        AFTER fecha_alta,
    ADD CONSTRAINT fk_sucursales_deposito
        FOREIGN KEY (deposito_id) REFERENCES depositos(id) ON DELETE SET NULL;

-- Vincular cada sucursal existente con su depósito principal
UPDATE sucursales s
SET    s.deposito_id = (
    SELECT d.id FROM depositos d
    WHERE  d.sucursal_id = s.id AND d.es_principal = 1
    LIMIT 1
);
