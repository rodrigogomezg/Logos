-- Migración: ventas 2026
-- Criterio: BORRADO=0, esventa=1, Fecha >= 46023 (2026-01-01 en serial Delphi)
-- Incluye CAE de ventas_electronicas cuando existe

USE logos;

INSERT INTO ventas (id, fecha, cliente_id, vendedor_id, total, tipo_comprobante, numero_afip, cae, estado)
SELECT
    v.Id,
    DATE_ADD('1899-12-30', INTERVAL v.Fecha DAY)       AS fecha,
    NULLIF(v.ClienteID, 0)                             AS cliente_id,
    NULLIF(v.vendedorID, 0)                            AS vendedor_id,
    v.total,
    COALESCE(CONVERT(c.Nombre USING utf8mb4), 'Comprobante') AS tipo_comprobante,
    NULLIF(TRIM(v.nroFactura), '0')                    AS numero_afip,
    NULLIF(TRIM(ve.cae), '')                           AS cae,
    CASE WHEN v.devolucion = 1 THEN 'devolucion' ELSE 'completado' END AS estado
FROM professionalplus.ventas v
LEFT JOIN professionalplus.comprobantes         c  ON c.Id     = v.tipofactura
LEFT JOIN professionalplus.ventas_electronicas ve ON ve.ventaid = v.Id
WHERE v.BORRADO  = 0
  AND v.esventa  = 1
  AND v.Fecha   >= 46023
ON DUPLICATE KEY UPDATE
    total            = VALUES(total),
    tipo_comprobante = VALUES(tipo_comprobante),
    numero_afip      = VALUES(numero_afip),
    cae              = VALUES(cae);

SELECT CONCAT('Ventas migradas: ', COUNT(*)) AS resultado FROM logos.ventas;
