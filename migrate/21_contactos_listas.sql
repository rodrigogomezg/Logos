-- Migration 21: Nuevos campos en clientes/proveedores + tabla listas_precio
-- Ejecutar: mysql -u root -P 3306 logos < 21_contactos_listas.sql

USE logos;

ALTER TABLE clientes
  ADD COLUMN activo          TINYINT(1)    NOT NULL DEFAULT 1  AFTER observaciones,
  ADD COLUMN cc_habilitada   TINYINT(1)    NOT NULL DEFAULT 0  AFTER activo,
  ADD COLUMN descuento_extra DECIMAL(5,2)  NOT NULL DEFAULT 0  AFTER cc_habilitada,
  ADD COLUMN solo_remito     TINYINT(1)    NOT NULL DEFAULT 0  AFTER descuento_extra,
  ADD COLUMN lista_precio_id INT           DEFAULT NULL        AFTER solo_remito,
  ADD COLUMN creado_en       DATETIME      DEFAULT CURRENT_TIMESTAMP AFTER lista_precio_id;

ALTER TABLE proveedores
  ADD COLUMN email           VARCHAR(255)  NULL                AFTER condicion_iva,
  ADD COLUMN telefono        VARCHAR(50)   NULL                AFTER email,
  ADD COLUMN domicilio       VARCHAR(255)  NULL                AFTER telefono,
  ADD COLUMN localidad       VARCHAR(100)  NULL                AFTER domicilio,
  ADD COLUMN provincia       VARCHAR(100)  NULL                AFTER localidad,
  ADD COLUMN observaciones   TEXT          NULL                AFTER provincia,
  ADD COLUMN activo          TINYINT(1)    NOT NULL DEFAULT 1  AFTER observaciones,
  ADD COLUMN cc_habilitada   TINYINT(1)    NOT NULL DEFAULT 0  AFTER activo,
  ADD COLUMN limite_credito  DECIMAL(14,4) NOT NULL DEFAULT 0  AFTER cc_habilitada,
  ADD COLUMN plazo_pago_dias INT           DEFAULT NULL        AFTER limite_credito,
  ADD COLUMN lista_precio_id INT           DEFAULT NULL        AFTER plazo_pago_dias,
  ADD COLUMN creado_en       DATETIME      DEFAULT CURRENT_TIMESTAMP AFTER lista_precio_id;

CREATE TABLE IF NOT EXISTS listas_precio (
  id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(100) NOT NULL,
  porcentaje DECIMAL(7,2) NOT NULL DEFAULT 0 COMMENT 'Positivo = recargo, negativo = descuento',
  activa     TINYINT(1)   NOT NULL DEFAULT 1,
  creado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_activa (activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
