-- Reglas de precio asignables a marca/rubro/proveedor, aplican automáticamente
-- a todos los productos que pertenezcan a ese grupo (existentes y futuros).
-- Prioridad al resolver el precio: producto.regla_precio_id > marca > rubro > proveedor.
ALTER TABLE marcas
    ADD COLUMN regla_precio_id INT DEFAULT NULL AFTER nombre,
    ADD FOREIGN KEY (regla_precio_id) REFERENCES reglas_precio(id) ON DELETE SET NULL;

ALTER TABLE rubros
    ADD COLUMN regla_precio_id INT DEFAULT NULL AFTER nombre,
    ADD FOREIGN KEY (regla_precio_id) REFERENCES reglas_precio(id) ON DELETE SET NULL;

ALTER TABLE proveedores
    ADD COLUMN regla_precio_id INT DEFAULT NULL AFTER lista_precio_id,
    ADD FOREIGN KEY (regla_precio_id) REFERENCES reglas_precio(id) ON DELETE SET NULL;
