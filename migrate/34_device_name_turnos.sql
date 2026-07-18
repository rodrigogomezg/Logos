-- Registra en qué equipo se abrió cada turno de caja
ALTER TABLE caja_turnos ADD COLUMN device_name VARCHAR(100) NULL AFTER usuario_id;
