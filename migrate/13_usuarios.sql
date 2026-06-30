CREATE TABLE IF NOT EXISTS usuarios (
    id        INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nombre    VARCHAR(100) NOT NULL,
    pin_hash  VARCHAR(255) NOT NULL,
    rol       ENUM('admin','user') NOT NULL DEFAULT 'user',
    activo    TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cajas ADD COLUMN tipo ENUM('venta','compra') NOT NULL DEFAULT 'venta' AFTER nombre;
UPDATE cajas SET tipo = 'compra' WHERE nombre = 'Compras';

ALTER TABLE ventas  ADD COLUMN caja_id INT NULL, ADD COLUMN usuario_id INT NULL;
ALTER TABLE compras ADD COLUMN caja_id INT NULL, ADD COLUMN usuario_id INT NULL;

-- Admin por defecto, PIN inicial 1234 (cambiarlo desde Configuración > Usuarios)
INSERT INTO usuarios (nombre, pin_hash, rol)
VALUES ('Admin', '$2y$10$wW.dB/yyAQ2oKfnfPBGHgeDEvZWdxPrstxcNWUAZkfhr/tlNBs4nu', 'admin');
