-- Agregar mercado_pago como tipo de pago en ventas
ALTER TABLE ventas MODIFY COLUMN tipo_pago
    ENUM('efectivo','transferencia','cc','tarjeta','cheque','mixto','mercado_pago')
    NOT NULL DEFAULT 'efectivo';

-- Columnas adicionales en caja_turnos para cierre enriquecido
ALTER TABLE caja_turnos
    ADD COLUMN IF NOT EXISTS total_mercado_pago  DECIMAL(12,2) DEFAULT NULL AFTER total_cc,
    ADD COLUMN IF NOT EXISTS cheques_recibidos   DECIMAL(12,2) DEFAULT NULL AFTER diferencia,
    ADD COLUMN IF NOT EXISTS depositos_recibidos DECIMAL(12,2) DEFAULT NULL AFTER cheques_recibidos,
    ADD COLUMN IF NOT EXISTS posnet_cierres      JSON          DEFAULT NULL AFTER depositos_recibidos;

-- Terminales posnet en configuración (JSON)
ALTER TABLE configuracion
    ADD COLUMN IF NOT EXISTS posnet_terminales JSON DEFAULT NULL AFTER impresora_nombre;

-- Tabla de cierres (parciales y totales)
CREATE TABLE IF NOT EXISTS caja_cierres (
    id                  INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    turno_id            INT            NOT NULL,
    tipo                ENUM('parcial','total') NOT NULL DEFAULT 'parcial',
    registrado_en       DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_id          INT            DEFAULT NULL,

    periodo_desde       DATETIME       NOT NULL,

    -- Totales del sistema para el período
    total_efectivo      DECIMAL(14,2)  NOT NULL DEFAULT 0,
    total_tarjeta       DECIMAL(14,2)  NOT NULL DEFAULT 0,
    total_transferencia DECIMAL(14,2)  NOT NULL DEFAULT 0,
    total_cheque        DECIMAL(14,2)  NOT NULL DEFAULT 0,
    total_mercado_pago  DECIMAL(14,2)  NOT NULL DEFAULT 0,
    total_ingresos      DECIMAL(14,2)  NOT NULL DEFAULT 0,
    total_retiros       DECIMAL(14,2)  NOT NULL DEFAULT 0,

    -- Fondo del período (efectivo disponible al inicio)
    fondo_periodo       DECIMAL(14,2)  NOT NULL DEFAULT 0,
    efectivo_esperado   DECIMAL(14,2)  DEFAULT NULL,

    -- Montos ingresados por el cajero
    efectivo_contado    DECIMAL(14,2)  DEFAULT NULL,
    cheques_recibidos   DECIMAL(14,2)  DEFAULT NULL,
    depositos_recibidos DECIMAL(14,2)  DEFAULT NULL,
    posnet_cierres      JSON           DEFAULT NULL,
    diferencia_efectivo DECIMAL(14,2)  DEFAULT NULL,

    KEY idx_turno (turno_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
