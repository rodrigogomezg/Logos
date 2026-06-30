ALTER TABLE caja_movimientos
    MODIFY COLUMN tipo ENUM('ingreso','retiro','transferencia') NOT NULL,
    ADD COLUMN medio_pago ENUM('efectivo','transferencia','tarjeta') NOT NULL DEFAULT 'efectivo' AFTER tipo,
    ADD COLUMN medio_pago_destino ENUM('efectivo','transferencia','tarjeta') DEFAULT NULL AFTER medio_pago;
