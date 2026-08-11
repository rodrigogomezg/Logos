-- Migración 69: registro del último backup para poder detectar en la UI
-- cuando el backup automático nunca corrió o viene fallando en silencio
-- (ver CLAUDE.md — antes un fallo en el backup al cierre de turno se
-- descartaba sin dejar rastro alguno).

ALTER TABLE configuracion
  ADD COLUMN backup_ultimo_en    DATETIME     NULL AFTER backup_auto_cierre,
  ADD COLUMN backup_ultimo_ok    TINYINT(1)   NULL AFTER backup_ultimo_en,
  ADD COLUMN backup_ultimo_error VARCHAR(500) NULL AFTER backup_ultimo_ok;
