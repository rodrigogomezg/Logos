CREATE TABLE IF NOT EXISTS rubros (
  id     INT         NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_rubro_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS marcas (
  id     INT         NOT NULL AUTO_INCREMENT,
  nombre VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_marca_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Poblar desde los valores existentes en productos
INSERT IGNORE INTO rubros (nombre)
  SELECT DISTINCT TRIM(categoria) FROM productos
  WHERE categoria IS NOT NULL AND TRIM(categoria) != '';

INSERT IGNORE INTO marcas (nombre)
  SELECT DISTINCT TRIM(marca) FROM productos
  WHERE marca IS NOT NULL AND TRIM(marca) != '';
