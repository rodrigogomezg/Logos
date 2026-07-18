-- Reservas de stock por carrito: hold temporal mientras el usuario arma la venta
-- carrito_id = UUID por tab de browser (sessionStorage), vence_en = 20 min por defecto
CREATE TABLE stock_reservas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT            NOT NULL,
    deposito_id INT            NOT NULL DEFAULT 1,
    cantidad    DECIMAL(10,2)  NOT NULL,
    carrito_id  VARCHAR(36)    NOT NULL,
    vence_en    DATETIME       NOT NULL,
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    UNIQUE KEY uq_prod_carrito (producto_id, carrito_id),
    KEY idx_vence (vence_en),
    KEY idx_carrito (carrito_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
