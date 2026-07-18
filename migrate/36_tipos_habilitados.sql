ALTER TABLE configuracion
  ADD COLUMN IF NOT EXISTS tipos_habilitados JSON NULL AFTER color_tema;
