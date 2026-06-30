-- Agrega nombre de fantasía y datos AFIP a configuración
-- Ejecutar: mysql -u root -P 3306 logos < 11_afip_fantasia.sql

ALTER TABLE configuracion
  ADD COLUMN nombre_fantasia VARCHAR(255) DEFAULT NULL AFTER razon_social,
  ADD COLUMN afip_cert       TEXT         DEFAULT NULL AFTER carpeta_backups,
  ADD COLUMN afip_key        TEXT         DEFAULT NULL AFTER afip_cert,
  ADD COLUMN afip_entorno    ENUM('homologacion','produccion') NOT NULL DEFAULT 'homologacion' AFTER afip_key;
