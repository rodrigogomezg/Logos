-- Agrega sucursal_id a las tablas de caja que aún no la tienen.
-- NOT NULL DEFAULT 1 para consistencia con ventas.sucursal_id.
-- Filas existentes quedan automáticamente asignadas a sucursal 1.

ALTER TABLE caja_turnos
    ADD COLUMN sucursal_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD CONSTRAINT fk_caja_turnos_sucursal
        FOREIGN KEY (sucursal_id) REFERENCES sucursales(id);

ALTER TABLE caja_cierres
    ADD COLUMN sucursal_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD CONSTRAINT fk_caja_cierres_sucursal
        FOREIGN KEY (sucursal_id) REFERENCES sucursales(id);

ALTER TABLE caja_movimientos
    ADD COLUMN sucursal_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD CONSTRAINT fk_caja_movimientos_sucursal
        FOREIGN KEY (sucursal_id) REFERENCES sucursales(id);
