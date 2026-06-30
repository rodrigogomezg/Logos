-- Agrega observaciones a cc_movimientos y crea tabla de asignaciones pago-venta
-- Ejecutar: mysql -u root -P 3307 bron < 10_cc_asignaciones.sql

ALTER TABLE cuenta_corriente_movimientos
  ADD COLUMN observaciones TEXT NULL AFTER referencia_id;

CREATE TABLE IF NOT EXISTS cc_asignaciones (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  movimiento_id INT NOT NULL,
  venta_id      INT NOT NULL,
  monto         DECIMAL(14,4) NOT NULL,
  INDEX idx_mov (movimiento_id),
  INDEX idx_ven (venta_id),
  CONSTRAINT fk_cca_mov FOREIGN KEY (movimiento_id) REFERENCES cuenta_corriente_movimientos(id) ON DELETE CASCADE,
  CONSTRAINT fk_cca_ven FOREIGN KEY (venta_id)      REFERENCES ventas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
