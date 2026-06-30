-- Agrega campos de ajuste de precio a venta_items
-- Ejecutar desde phpMyAdmin o consola: mysql -u root -P 3306 logos < 06_venta_items_ajustes.sql

ALTER TABLE venta_items
  ADD COLUMN precio_original DECIMAL(12,2)  NULL        AFTER precio_unitario,
  ADD COLUMN ajuste_desc     VARCHAR(30)    NULL        AFTER precio_original,
  ADD COLUMN ajuste_visible  TINYINT(1)     NOT NULL DEFAULT 1 AFTER ajuste_desc;
