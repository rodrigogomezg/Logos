-- Agrega sync_uuid a las tablas que se crean de forma distribuida en cada sucursal.
-- La PK INT local se mantiene para performance; el UUID es solo para sincronización
-- entre sucursales sin choque de IDs.
--
-- Patrón por tabla:
--   1) ADD COLUMN nullable
--   2) UPDATE para poblar filas existentes (UUID() se evalúa por fila en UPDATE)
--   3) UNIQUE KEY + MODIFY NOT NULL
--   4) BEFORE INSERT trigger para auto-generación en nuevos registros
--      (IF IS NULL OR '': en MariaDB non-strict el default implícito de CHAR NOT NULL
--       es '' no NULL, por eso COALESCE solo no alcanza)

-- ── ventas ──────────────────────────────────────────────────────────────────
ALTER TABLE ventas ADD COLUMN sync_uuid CHAR(36) NULL AFTER id;
UPDATE ventas SET sync_uuid = UUID() WHERE sync_uuid IS NULL;
ALTER TABLE ventas
    ADD  UNIQUE KEY uk_ventas_sync_uuid (sync_uuid),
    MODIFY COLUMN  sync_uuid CHAR(36) NOT NULL;

CREATE TRIGGER trg_ventas_sync_uuid BEFORE INSERT ON ventas
FOR EACH ROW SET NEW.sync_uuid = IF(NEW.sync_uuid IS NULL OR NEW.sync_uuid = '', UUID(), NEW.sync_uuid);

-- ── venta_items ──────────────────────────────────────────────────────────────
ALTER TABLE venta_items ADD COLUMN sync_uuid CHAR(36) NULL AFTER id;
UPDATE venta_items SET sync_uuid = UUID() WHERE sync_uuid IS NULL;
ALTER TABLE venta_items
    ADD  UNIQUE KEY uk_venta_items_sync_uuid (sync_uuid),
    MODIFY COLUMN  sync_uuid CHAR(36) NOT NULL;

CREATE TRIGGER trg_venta_items_sync_uuid BEFORE INSERT ON venta_items
FOR EACH ROW SET NEW.sync_uuid = IF(NEW.sync_uuid IS NULL OR NEW.sync_uuid = '', UUID(), NEW.sync_uuid);

-- ── venta_pagos ──────────────────────────────────────────────────────────────
ALTER TABLE venta_pagos ADD COLUMN sync_uuid CHAR(36) NULL AFTER id;
UPDATE venta_pagos SET sync_uuid = UUID() WHERE sync_uuid IS NULL;
ALTER TABLE venta_pagos
    ADD  UNIQUE KEY uk_venta_pagos_sync_uuid (sync_uuid),
    MODIFY COLUMN  sync_uuid CHAR(36) NOT NULL;

CREATE TRIGGER trg_venta_pagos_sync_uuid BEFORE INSERT ON venta_pagos
FOR EACH ROW SET NEW.sync_uuid = IF(NEW.sync_uuid IS NULL OR NEW.sync_uuid = '', UUID(), NEW.sync_uuid);

-- ── movimientos_stock ────────────────────────────────────────────────────────
ALTER TABLE movimientos_stock ADD COLUMN sync_uuid CHAR(36) NULL AFTER id;
UPDATE movimientos_stock SET sync_uuid = UUID() WHERE sync_uuid IS NULL;
ALTER TABLE movimientos_stock
    ADD  UNIQUE KEY uk_movstock_sync_uuid (sync_uuid),
    MODIFY COLUMN  sync_uuid CHAR(36) NOT NULL;

CREATE TRIGGER trg_movstock_sync_uuid BEFORE INSERT ON movimientos_stock
FOR EACH ROW SET NEW.sync_uuid = IF(NEW.sync_uuid IS NULL OR NEW.sync_uuid = '', UUID(), NEW.sync_uuid);

-- ── caja_cierres ─────────────────────────────────────────────────────────────
ALTER TABLE caja_cierres ADD COLUMN sync_uuid CHAR(36) NULL AFTER id;
UPDATE caja_cierres SET sync_uuid = UUID() WHERE sync_uuid IS NULL;
ALTER TABLE caja_cierres
    ADD  UNIQUE KEY uk_caja_cierres_sync_uuid (sync_uuid),
    MODIFY COLUMN  sync_uuid CHAR(36) NOT NULL;

CREATE TRIGGER trg_caja_cierres_sync_uuid BEFORE INSERT ON caja_cierres
FOR EACH ROW SET NEW.sync_uuid = IF(NEW.sync_uuid IS NULL OR NEW.sync_uuid = '', UUID(), NEW.sync_uuid);

-- ── caja_movimientos ─────────────────────────────────────────────────────────
ALTER TABLE caja_movimientos ADD COLUMN sync_uuid CHAR(36) NULL AFTER id;
UPDATE caja_movimientos SET sync_uuid = UUID() WHERE sync_uuid IS NULL;
ALTER TABLE caja_movimientos
    ADD  UNIQUE KEY uk_caja_movimientos_sync_uuid (sync_uuid),
    MODIFY COLUMN  sync_uuid CHAR(36) NOT NULL;

CREATE TRIGGER trg_caja_movimientos_sync_uuid BEFORE INSERT ON caja_movimientos
FOR EACH ROW SET NEW.sync_uuid = IF(NEW.sync_uuid IS NULL OR NEW.sync_uuid = '', UUID(), NEW.sync_uuid);
