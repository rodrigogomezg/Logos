-- Identificación de equipo/instalación física en log y sesiones
ALTER TABLE log_acciones ADD COLUMN device_name VARCHAR(100) NULL AFTER ip;
ALTER TABLE sesiones     ADD COLUMN device_name VARCHAR(100) NULL AFTER expira;
