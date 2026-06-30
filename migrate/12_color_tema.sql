USE logos;

ALTER TABLE configuracion
  ADD COLUMN color_tema VARCHAR(30) NOT NULL DEFAULT 'azul' AFTER clave_autorizacion_hash;
