-- Movimientos de stock: registrar en qué depósito ocurrió
ALTER TABLE movimientos_stock
    ADD COLUMN deposito_id INT NOT NULL DEFAULT 1 AFTER producto_id,
    ADD CONSTRAINT fk_movstock_deposito FOREIGN KEY (deposito_id) REFERENCES depositos(id);
UPDATE movimientos_stock SET deposito_id = 1;

-- Stock por depósito: caché denormalizado por producto+depósito
CREATE TABLE stock_depositos (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    producto_id    INT           NOT NULL,
    deposito_id    INT           NOT NULL,
    stock_actual   DECIMAL(14,4) NOT NULL DEFAULT 0,
    actualizado_en DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_prod_dep (producto_id, deposito_id),
    FOREIGN KEY (deposito_id) REFERENCES depositos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: stock actual de cada producto en el depósito principal
INSERT INTO stock_depositos (producto_id, deposito_id, stock_actual)
SELECT id, 1, COALESCE(stock_actual, 0) FROM productos;
