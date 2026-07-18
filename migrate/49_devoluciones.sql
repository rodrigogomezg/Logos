-- Devoluciones con crédito: el cliente devuelve ítems y recibe saldo a favor en CC
CREATE TABLE devoluciones (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    venta_id    INT            NOT NULL,
    cliente_id  INT            NOT NULL,
    motivo      VARCHAR(255)   DEFAULT NULL,
    monto_total DECIMAL(14,4)  NOT NULL DEFAULT 0,
    usuario_id  INT            DEFAULT NULL,
    creado_en   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (venta_id)   REFERENCES ventas(id),
    FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    KEY idx_venta (venta_id),
    KEY idx_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE devolucion_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    devolucion_id   INT            NOT NULL,
    producto_id     INT            NOT NULL,
    nombre          VARCHAR(255)   NOT NULL,
    cantidad        DECIMAL(10,2)  NOT NULL,
    precio_unitario DECIMAL(14,4)  NOT NULL,
    FOREIGN KEY (devolucion_id) REFERENCES devoluciones(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id)   REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
