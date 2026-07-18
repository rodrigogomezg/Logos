-- Opción de backup automático al cerrar turno diario
ALTER TABLE configuracion ADD COLUMN IF NOT EXISTS backup_auto_cierre TINYINT(1) NOT NULL DEFAULT 0;
