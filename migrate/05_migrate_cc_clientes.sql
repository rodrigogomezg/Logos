-- Migración: cuenta corriente clientes 2026
-- Solo movimientos de clientes que ya migramos y con Fecha en 2026

USE bron;

INSERT INTO cuenta_corriente_movimientos (entidad_tipo, entidad_id, tipo, monto, referencia_id, fecha)
SELECT
    'cliente'                                           AS entidad_tipo,
    cc.PersonaID                                        AS entidad_id,
    CASE cc.tipo
        WHEN 0 THEN 'cargo'
        WHEN 1 THEN 'pago'
        ELSE        'ajuste'
    END                                                 AS tipo,
    cc.Monto                                            AS monto,
    NULLIF(cc.EnlaceID, 0)                              AS referencia_id,
    DATE_ADD('1899-12-30', INTERVAL cc.Fecha DAY)       AS fecha
FROM professionalplus.clientescc cc
INNER JOIN bron.clientes c ON c.id = cc.PersonaID
WHERE cc.BORRADO  = 0
  AND cc.Fecha   >= 46023;

SELECT CONCAT('Movimientos CC clientes migrados: ', COUNT(*)) AS resultado
FROM bron.cuenta_corriente_movimientos
WHERE entidad_tipo = 'cliente';
