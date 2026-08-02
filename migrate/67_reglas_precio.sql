-- Reglas de precio: recargo % sobre el costo, asignable a productos individuales
-- o en bloque (por marca/rubro/proveedor/selección) desde Productos.
CREATE TABLE reglas_precio (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    nombre              VARCHAR(100)  NOT NULL,
    porcentaje_recargo  DECIMAL(7,2)  NOT NULL,
    activa              TINYINT(1)    NOT NULL DEFAULT 1,
    creado_en           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE productos
    ADD COLUMN regla_precio_id INT DEFAULT NULL AFTER precio_venta,
    ADD FOREIGN KEY (regla_precio_id) REFERENCES reglas_precio(id) ON DELETE SET NULL,
    ADD KEY idx_regla_precio (regla_precio_id);
