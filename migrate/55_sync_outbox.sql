-- Cola de cambios pendientes de sincronizar al Hub central.
-- La tabla se crea ahora; el mecanismo que la llene (triggers o capa de aplicación)
-- se define en una etapa posterior.

CREATE TABLE sync_outbox (
    id                  INT          NOT NULL AUTO_INCREMENT,
    tabla_origen        VARCHAR(100) NOT NULL,
    registro_sync_uuid  CHAR(36)     NOT NULL,
    tipo_operacion      ENUM('insert','update') NOT NULL,
    payload             JSON         NOT NULL,
    sucursal_id         INT          NOT NULL,
    sincronizado        TINYINT(1)   NOT NULL DEFAULT 0,
    fecha_creacion      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_sincronizado  DATETIME     NULL,
    PRIMARY KEY (id),
    INDEX idx_sout_pendientes (sincronizado, fecha_creacion),
    INDEX idx_sout_sucursal   (sucursal_id),
    INDEX idx_sout_uuid       (registro_sync_uuid),
    CONSTRAINT fk_sync_outbox_sucursal
        FOREIGN KEY (sucursal_id) REFERENCES sucursales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
