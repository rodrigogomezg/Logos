-- Migración: productos
-- Fuente: professionalplus.stock (tipo=0 → productos, tipo=1 → servicios)
-- Criterio:
--   activos (BORRADO=0)  → activo=1
--   borrados que aparecen en ventas 2026 (BORRADO=1) → activo=0 (referencia histórica)

USE bron;

INSERT INTO productos (id, codigo, nombre, categoria, subcategoria, precio_venta, costo_actual, stock_actual, stock_minimo, activo)
SELECT
    s.Id,
    COALESCE(NULLIF(TRIM(s.codigo), ''), CAST(s.Id AS CHAR))  AS codigo,
    CONVERT(s.nombre USING utf8mb4)                            AS nombre,
    CONVERT(f.nombre  USING utf8mb4)                           AS categoria,
    CONVERT(sf.nombre USING utf8mb4)                           AS subcategoria,
    s.Montoventaconiva                                         AS precio_venta,
    s.MontoCompra                                              AS costo_actual,
    GREATEST(s.Cantidad, 0)                                    AS stock_actual,
    s.cantminima                                               AS stock_minimo,
    (1 - s.BORRADO)                                            AS activo
FROM professionalplus.stock s
LEFT JOIN professionalplus.familia_subfamilia_producto fsp ON fsp.id_stock   = s.Id
LEFT JOIN professionalplus.familia_subfamilia           sf  ON sf.id          = fsp.id_subfamilia
LEFT JOIN professionalplus.familia                      f   ON f.id           = sf.id_familia
WHERE s.tipo = 0
  AND (
      s.BORRADO = 0
      OR s.Id IN (
          -- productos borrados que aparecen en ventas 2026
          SELECT DISTINCT sm.productoid
          FROM   professionalplus.stock_movimiento sm
          INNER JOIN bron.ventas v ON v.id = sm.enlacepadreid
          WHERE  sm.tipo = 0 AND sm.enlacepadreid > 0 AND sm.movimiento <> 0
      )
  )
ON DUPLICATE KEY UPDATE
    nombre        = VALUES(nombre),
    precio_venta  = VALUES(precio_venta),
    costo_actual  = VALUES(costo_actual),
    stock_actual  = VALUES(stock_actual),
    stock_minimo  = VALUES(stock_minimo);

SELECT CONCAT('Productos migrados: ', COUNT(*)) AS resultado FROM bron.productos;
