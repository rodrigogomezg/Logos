-- Tabla singleton para el estado de licencia local (id siempre = 1).
-- La fila inicial arranca con estado 'al_dia' y fecha NULL para que una
-- instalación recién hecha no quede bloqueada antes del primer heartbeat.
CREATE TABLE IF NOT EXISTS licencia_estado (
    id                                  INT PRIMARY KEY DEFAULT 1,
    estado_hub                          ENUM('al_dia','en_gracia','bloqueado') NOT NULL DEFAULT 'al_dia',
    dias_restantes_gracia               TINYINT NULL,
    proximo_vencimiento                 DATE NULL,
    mensaje_hub                         TEXT NULL,
    fecha_ultima_verificacion_exitosa   DATETIME NULL,
    actualizado_en                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO licencia_estado
    (id, estado_hub, dias_restantes_gracia, proximo_vencimiento, mensaje_hub, fecha_ultima_verificacion_exitosa)
VALUES
    (1, 'al_dia', NULL, NULL, NULL, NULL);
