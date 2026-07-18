-- Cajas: asignar a sucursal
ALTER TABLE cajas
    ADD COLUMN sucursal_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD CONSTRAINT fk_cajas_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id);
UPDATE cajas SET sucursal_id = 1;

-- Ventas: sucursal derivada de la caja al momento de la venta
ALTER TABLE ventas
    ADD COLUMN sucursal_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD CONSTRAINT fk_ventas_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id);
UPDATE ventas v
    JOIN cajas c ON c.id = v.caja_id
    SET v.sucursal_id = c.sucursal_id
WHERE v.caja_id IS NOT NULL;

-- Compras: asignar a sucursal
ALTER TABLE compras
    ADD COLUMN sucursal_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD CONSTRAINT fk_compras_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales(id);
UPDATE compras cp
    JOIN cajas c ON c.id = cp.caja_id
    SET cp.sucursal_id = c.sucursal_id
WHERE cp.caja_id IS NOT NULL;
