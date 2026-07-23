-- Marca si un producto debe sincronizarse / mostrarse en la tienda web.
-- Default 0: los productos existentes no se publican hasta que se habilite manualmente.
ALTER TABLE productos
    ADD COLUMN publicado_web TINYINT(1) NOT NULL DEFAULT 0 AFTER activo;
