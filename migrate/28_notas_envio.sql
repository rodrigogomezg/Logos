-- Notas de envío: registro de entregas parciales o totales de un remito
-- Una venta puede tener múltiples notas de envío hasta entregar todos los ítems.

CREATE TABLE IF NOT EXISTS notas_envio (
    id               INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    venta_id         INT            NOT NULL,
    numero           INT            NOT NULL DEFAULT 1,    -- secuencial por venta (1, 2, 3…)
    fecha_emision    DATE           NOT NULL,
    fecha_entrega    DATE           DEFAULT NULL,
    transportista    VARCHAR(255)   DEFAULT NULL,
    envio_precio     DECIMAL(14,4)  DEFAULT NULL,
    envio_direccion  VARCHAR(500)   DEFAULT NULL,
    observaciones    TEXT           DEFAULT NULL,
    creado_en        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_venta (venta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS nota_envio_items (
    id             INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nota_envio_id  INT            NOT NULL,
    venta_item_id  INT            NOT NULL,
    producto_id    INT            DEFAULT NULL,
    nombre         VARCHAR(255)   NOT NULL,
    codigo         VARCHAR(50)    DEFAULT NULL,
    cantidad       DECIMAL(14,4)  NOT NULL,
    KEY idx_nota (nota_envio_id),
    KEY idx_venta_item (venta_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
