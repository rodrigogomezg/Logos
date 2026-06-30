CREATE TABLE IF NOT EXISTS venta_pagos (
    id        INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    venta_id  INT            NOT NULL,
    tipo_pago VARCHAR(20)    NOT NULL,
    monto     DECIMAL(14,4)  NOT NULL,
    KEY idx_venta (venta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
