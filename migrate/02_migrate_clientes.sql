-- Migración: clientes
-- Criterio: solo clientes con al menos una venta en 2026
-- Delphi serial 2026-01-01 = 46023

USE logos;

-- Clientes con ventas en 2026 (no borradas, tipo venta)
INSERT INTO clientes (id, nombre, cuit, condicion_iva, limite_credito, saldo_cuenta_corriente)
SELECT
    c.Id,
    CONVERT(c.Nombre USING utf8mb4)                    AS nombre,
    NULLIF(TRIM(c.NroCuit), '')                        AS cuit,
    CASE c.IVA
        WHEN 1 THEN 'Responsable Inscripto'
        WHEN 3 THEN 'Exento'
        WHEN 4 THEN 'No Responsable'
        WHEN 5 THEN 'Consumidor Final'
        WHEN 6 THEN 'Monotributista'
        ELSE         'Consumidor Final'
    END                                                AS condicion_iva,
    c.valormaxcc                                       AS limite_credito,
    c.cuentacorriente                                  AS saldo_cuenta_corriente
FROM professionalplus.clientes c
WHERE c.BORRADO = 0
  AND c.Id IN (
      SELECT DISTINCT ClienteID
      FROM   professionalplus.ventas
      WHERE  BORRADO  = 0
        AND  esventa  = 1
        AND  Fecha   >= 46023
  )
ON DUPLICATE KEY UPDATE
    nombre                 = VALUES(nombre),
    cuit                   = VALUES(cuit),
    condicion_iva          = VALUES(condicion_iva),
    limite_credito         = VALUES(limite_credito),
    saldo_cuenta_corriente = VALUES(saldo_cuenta_corriente);

SELECT CONCAT('Clientes migrados: ', COUNT(*)) AS resultado FROM logos.clientes;
