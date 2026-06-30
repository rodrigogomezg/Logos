-- Campo para almacenar información de origen en remitos unificados
-- Ejecutar desde phpMyAdmin o consola: mysql -u root -P 3306 logos < 07_ventas_origen.sql

ALTER TABLE ventas
  ADD COLUMN origen_descripcion TEXT NULL AFTER observaciones;
