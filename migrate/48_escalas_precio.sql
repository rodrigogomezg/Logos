-- Escalas de precio por volumen: precio especial a partir de cierta cantidad
CREATE TABLE escalas_precio (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    producto_id     INT            NOT NULL,
    desde_cantidad  DECIMAL(10,2)  NOT NULL,
    precio_unitario DECIMAL(14,4)  NOT NULL,
    creado_en       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    UNIQUE KEY uq_producto_cantidad (producto_id, desde_cantidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
