-- El token de licencia deja de vivir solo en config local del shell nativo
-- (Electron: logos-config.json vía IPC) y pasa a persistir acá, donde
-- LicenciaController ya sabe hablar con el Hub. Tauri no necesita ningún
-- mecanismo propio de guardar/leer token: el backend resuelve el fallback.
ALTER TABLE licencia_estado
    ADD COLUMN token VARCHAR(255) NOT NULL DEFAULT '' AFTER id;
