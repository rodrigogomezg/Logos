CREATE TABLE IF NOT EXISTS configuracion (
    id                   INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    razon_social         VARCHAR(255)   NOT NULL DEFAULT '',
    cuit                 VARCHAR(20)    NOT NULL DEFAULT '',
    condicion_iva        VARCHAR(50)    NOT NULL DEFAULT 'Responsable Inscripto',
    domicilio            VARCHAR(255)   DEFAULT NULL,
    iibb                 VARCHAR(50)    DEFAULT NULL,
    telefono             VARCHAR(50)    DEFAULT NULL,
    website              VARCHAR(255)   DEFAULT NULL,
    punto_venta          INT            NOT NULL DEFAULT 1,
    iva_porcentaje       DECIMAL(5,2)   NOT NULL DEFAULT 21.00,
    impresora_nombre     VARCHAR(255)   DEFAULT NULL,
    carpeta_comprobantes VARCHAR(500)   DEFAULT NULL,
    carpeta_backups      VARCHAR(500)   DEFAULT NULL,
    actualizado_en       DATETIME       DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO configuracion (id, razon_social, cuit, condicion_iva, domicilio, iibb, telefono, website, punto_venta, iva_porcentaje)
VALUES (1, 'BULFON GUILLERMO JESUS', '20396273455', 'Responsable Inscripto',
        'Av. Luis María Campos 411, CP1426, CABA', '1590479-02', '11-3521-1985',
        'www.bronargentina.com.ar', 7, 21.00);

CREATE TABLE IF NOT EXISTS cajas (
    id     INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    activo TINYINT(1)   NOT NULL DEFAULT 1,
    orden  INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO cajas (nombre, orden) VALUES ('Ferretería', 1), ('Sanitarios', 2), ('Compras', 3);
