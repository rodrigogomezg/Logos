ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS marca     VARCHAR(100) DEFAULT NULL AFTER subcategoria,
    ADD COLUMN IF NOT EXISTS proveedor VARCHAR(150) DEFAULT NULL AFTER marca;

CREATE TABLE IF NOT EXISTS productos_import_plantillas (
    id             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    proveedor      VARCHAR(150) NOT NULL,
    mapeo          JSON         NOT NULL,
    opciones       JSON         NOT NULL,
    actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_proveedor (proveedor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS productos_import_lotes (
    id           INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    proveedor    VARCHAR(150) NOT NULL,
    archivo      VARCHAR(255) NOT NULL,
    usuario_id   INT          NOT NULL,
    total_filas  INT          NOT NULL DEFAULT 0,
    creados      INT          NOT NULL DEFAULT 0,
    actualizados INT          NOT NULL DEFAULT 0,
    errores      INT          NOT NULL DEFAULT 0,
    desactivados INT          NOT NULL DEFAULT 0,
    creado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS productos_import_detalle (
    id          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lote_id     INT          NOT NULL,
    producto_id INT          DEFAULT NULL,
    codigo      VARCHAR(50)  DEFAULT NULL,
    accion      ENUM('crear','actualizar','error','desactivado') NOT NULL,
    antes       JSON         DEFAULT NULL,
    despues     JSON         DEFAULT NULL,
    mensaje     VARCHAR(500) DEFAULT NULL,
    KEY idx_lote (lote_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
