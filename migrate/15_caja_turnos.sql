CREATE TABLE IF NOT EXISTS caja_turnos (
    id                  INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    caja_id             INT            NOT NULL,
    usuario_id          INT            NOT NULL,
    fondo_inicial       DECIMAL(12,2)  NOT NULL DEFAULT 0,
    abierto_en          DATETIME       NOT NULL,
    cerrado_en          DATETIME       DEFAULT NULL,
    estado              ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
    total_efectivo      DECIMAL(12,2)  DEFAULT NULL,
    total_tarjeta       DECIMAL(12,2)  DEFAULT NULL,
    total_transferencia DECIMAL(12,2)  DEFAULT NULL,
    total_cheque        DECIMAL(12,2)  DEFAULT NULL,
    total_cc            DECIMAL(12,2)  DEFAULT NULL,
    total_ingresos      DECIMAL(12,2)  DEFAULT NULL,
    total_retiros        DECIMAL(12,2) DEFAULT NULL,
    efectivo_esperado   DECIMAL(12,2)  DEFAULT NULL,
    efectivo_contado    DECIMAL(12,2)  DEFAULT NULL,
    diferencia          DECIMAL(12,2)  DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS caja_movimientos (
    id         INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    turno_id   INT            NOT NULL,
    tipo       ENUM('ingreso','retiro') NOT NULL,
    monto      DECIMAL(12,2)  NOT NULL,
    motivo     VARCHAR(255)   DEFAULT NULL,
    usuario_id INT            NOT NULL,
    creado_en  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE ventas ADD COLUMN turno_id INT NULL AFTER caja_id;
