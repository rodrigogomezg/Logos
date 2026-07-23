CREATE TABLE IF NOT EXISTS reportes_comisiones (
  id              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  periodo         CHAR(7)       NOT NULL COMMENT 'YYYY-MM',
  vendedor_id     INT UNSIGNED  NOT NULL,
  vendedor_nombre VARCHAR(255)  NOT NULL,
  total_ventas    DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_comision  DECIMAL(14,2) NOT NULL DEFAULT 0,
  cant_ventas     INT           NOT NULL DEFAULT 0,
  cerrado_en      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cerrado_por     INT UNSIGNED  DEFAULT NULL,
  UNIQUE KEY uk_periodo_vendedor (periodo, vendedor_id),
  INDEX idx_periodo (periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
