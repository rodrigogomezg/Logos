-- Migración 41: carpeta secundaria de backups (off-site)
-- Un backup que vive solo en la misma PC no protege contra robo, incendio o
-- muerte del disco. La carpeta secundaria apunta típicamente a una unidad de
-- red o a una carpeta sincronizada a la nube (Google Drive / Dropbox / OneDrive):
-- cada backup se copia ahí automáticamente después de generarse.

ALTER TABLE configuracion
  ADD COLUMN carpeta_backups_secundaria VARCHAR(500) NULL AFTER carpeta_backups;
