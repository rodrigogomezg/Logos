-- Sucursales: cada local físico del comercio
CREATE TABLE sucursales (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    nombre          VARCHAR(100) NOT NULL,
    nombre_fantasia VARCHAR(100) DEFAULT NULL,
    domicilio       VARCHAR(255) DEFAULT NULL,
    telefono        VARCHAR(50)  DEFAULT NULL,
    email           VARCHAR(100) DEFAULT NULL,
    punto_venta     INT          DEFAULT NULL,
    activo          TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Depósitos: almacenes físicos, pertenecen a una sucursal
CREATE TABLE depositos (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    sucursal_id  INT          NOT NULL,
    nombre       VARCHAR(100) NOT NULL,
    descripcion  VARCHAR(255) DEFAULT NULL,
    es_principal TINYINT(1)   NOT NULL DEFAULT 0,
    activo       TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sucursal_id) REFERENCES sucursales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: crear sucursal y depósito principal con datos del negocio instalado
INSERT INTO sucursales (id, nombre, nombre_fantasia, domicilio, telefono, activo)
SELECT 1,
       razon_social,
       NULLIF(nombre_fantasia, ''),
       domicilio,
       telefono,
       1
FROM configuracion WHERE id = 1;

INSERT INTO depositos (id, sucursal_id, nombre, es_principal, activo)
VALUES (1, 1, 'Depósito Principal', 1, 1);
