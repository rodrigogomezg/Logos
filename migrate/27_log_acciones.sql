-- Registro de auditoría para acciones críticas del sistema
CREATE TABLE IF NOT EXISTS log_acciones (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usuario_id  INT NULL,
  usuario_nom VARCHAR(100) NULL,
  accion      VARCHAR(50) NOT NULL,
  entidad     VARCHAR(30) NULL,
  entidad_id  INT NULL,
  detalle     TEXT NULL,
  ip          VARCHAR(45) NULL,
  KEY idx_fecha   (fecha),
  KEY idx_accion  (accion),
  KEY idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
