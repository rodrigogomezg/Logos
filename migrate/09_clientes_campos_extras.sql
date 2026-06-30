-- Agrega campos de contacto, domicilio y observaciones a la tabla clientes
-- Ejecutar: mysql -u root -P 3307 bron < 09_clientes_campos_extras.sql

ALTER TABLE clientes
  ADD COLUMN email         VARCHAR(255) NULL AFTER condicion_iva,
  ADD COLUMN telefono      VARCHAR(50)  NULL AFTER email,
  ADD COLUMN domicilio     VARCHAR(255) NULL AFTER telefono,
  ADD COLUMN localidad     VARCHAR(100) NULL AFTER domicilio,
  ADD COLUMN provincia     VARCHAR(100) NULL AFTER localidad,
  ADD COLUMN observaciones TEXT         NULL AFTER provincia;
