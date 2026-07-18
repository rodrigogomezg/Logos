-- sucursal_ids: JSON array de IDs permitidos. NULL = acceso a todas (backward compatible)
ALTER TABLE usuarios
    ADD COLUMN sucursal_ids JSON NULL AFTER permisos;
