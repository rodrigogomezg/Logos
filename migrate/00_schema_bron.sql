-- BRON DB Schema
-- Ejecutar primero: crea la base y todas las tablas

CREATE DATABASE IF NOT EXISTS bron CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bron;

CREATE TABLE IF NOT EXISTS productos (
    id            INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    codigo        VARCHAR(50)    NOT NULL,
    nombre        VARCHAR(255)   NOT NULL,
    categoria     VARCHAR(100)   DEFAULT NULL,
    subcategoria  VARCHAR(100)   DEFAULT NULL,
    precio_venta  DECIMAL(14,4)  NOT NULL DEFAULT 0,
    costo_actual  DECIMAL(14,4)  NOT NULL DEFAULT 0,
    stock_actual  DECIMAL(14,4)  NOT NULL DEFAULT 0,
    stock_minimo  DECIMAL(14,4)  NOT NULL DEFAULT 0,
    activo        TINYINT(1)     NOT NULL DEFAULT 1,
    KEY idx_codigo (codigo),
    KEY idx_nombre (nombre),
    KEY idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clientes (
    id                     INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nombre                 VARCHAR(255)   NOT NULL,
    cuit                   VARCHAR(20)    DEFAULT NULL,
    condicion_iva          VARCHAR(50)    DEFAULT NULL,
    limite_credito         DECIMAL(14,4)  NOT NULL DEFAULT 0,
    saldo_cuenta_corriente DECIMAL(14,4)  NOT NULL DEFAULT 0,
    KEY idx_nombre (nombre),
    KEY idx_cuit (cuit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS proveedores (
    id                     INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nombre                 VARCHAR(255)   NOT NULL,
    cuit                   VARCHAR(20)    DEFAULT NULL,
    condicion_iva          VARCHAR(50)    DEFAULT NULL,
    saldo_cuenta_corriente DECIMAL(14,4)  NOT NULL DEFAULT 0,
    KEY idx_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendedores (
    id     INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ventas (
    id               INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fecha            DATE           NOT NULL,
    cliente_id       INT            DEFAULT NULL,
    vendedor_id      INT            DEFAULT NULL,
    total            DECIMAL(14,4)  NOT NULL DEFAULT 0,
    tipo_comprobante VARCHAR(50)    DEFAULT NULL,
    numero_afip      VARCHAR(50)    DEFAULT NULL,
    cae              VARCHAR(50)    DEFAULT NULL,
    estado           VARCHAR(20)    NOT NULL DEFAULT 'completado',
    KEY idx_fecha (fecha),
    KEY idx_cliente (cliente_id),
    KEY idx_numero_afip (numero_afip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS venta_items (
    id              INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    venta_id        INT            NOT NULL,
    producto_id     INT            NOT NULL,
    cantidad        DECIMAL(14,4)  NOT NULL DEFAULT 0,
    precio_unitario DECIMAL(14,4)  NOT NULL DEFAULT 0,
    costo_unitario  DECIMAL(14,4)  NOT NULL DEFAULT 0,
    KEY idx_venta (venta_id),
    KEY idx_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS compras (
    id           INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fecha        DATE           NOT NULL,
    proveedor_id INT            DEFAULT NULL,
    total        DECIMAL(14,4)  NOT NULL DEFAULT 0,
    estado       VARCHAR(20)    NOT NULL DEFAULT 'completado',
    KEY idx_fecha (fecha),
    KEY idx_proveedor (proveedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS compra_items (
    id             INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    compra_id      INT            NOT NULL,
    producto_id    INT            NOT NULL,
    cantidad       DECIMAL(14,4)  NOT NULL DEFAULT 0,
    costo_unitario DECIMAL(14,4)  NOT NULL DEFAULT 0,
    KEY idx_compra (compra_id),
    KEY idx_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS movimientos_stock (
    id           INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    producto_id  INT            NOT NULL,
    tipo         VARCHAR(20)    NOT NULL,
    cantidad     DECIMAL(14,4)  NOT NULL DEFAULT 0,
    referencia_id INT           DEFAULT NULL,
    fecha        DATETIME       NOT NULL,
    KEY idx_producto (producto_id),
    KEY idx_fecha (fecha),
    KEY idx_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cuenta_corriente_movimientos (
    id           INT                        NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entidad_tipo ENUM('cliente','proveedor') NOT NULL,
    entidad_id   INT                        NOT NULL,
    tipo         VARCHAR(20)                NOT NULL,
    monto        DECIMAL(14,4)              NOT NULL DEFAULT 0,
    referencia_id INT                       DEFAULT NULL,
    fecha        DATE                       NOT NULL,
    KEY idx_entidad (entidad_tipo, entidad_id),
    KEY idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
