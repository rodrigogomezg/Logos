-- Migración: ítems de venta 2026
-- Fuente: stock_movimiento (cantidades) + precios_movimientos (precio histórico de lista)
--
-- El precio_unitario es el precio de lista vigente al momento de cada venta
-- (último registro en precios_movimientos con lp=0 antes de la FechaADD de la venta).
-- Si no hay historial de precio para ese producto, usa el precio actual de la tabla stock.
--
-- Limitación conocida: si la venta tenía un descuento general (ventas.descuentopor > 0),
-- los precios por ítem son de lista. El total del encabezado (ventas.total) sigue siendo exacto.

USE logos;

INSERT INTO venta_items (venta_id, producto_id, cantidad, precio_unitario, costo_unitario)
SELECT
    sm.enlacepadreid                                                AS venta_id,
    sm.productoid                                                   AS producto_id,
    ABS(sm.movimiento)                                              AS cantidad,

    -- Precio de venta histórico: último cambio registrado antes de la fecha de la venta
    COALESCE(
        (
            SELECT pm.monto_coniva
            FROM   professionalplus.precios_movimientos pm
            WHERE  pm.productoid = sm.productoid
              AND  pm.lp         = 0              -- lp=0 → precio de venta
              AND  pm.fecha     <= v_src.FechaADD
            ORDER BY pm.fecha DESC
            LIMIT 1
        ),
        p.precio_venta                                              -- fallback: precio actual
    )                                                               AS precio_unitario,

    -- Costo histórico: último cambio de costo antes de la fecha de la venta
    COALESCE(
        (
            SELECT pm.monto_siniva
            FROM   professionalplus.precios_movimientos pm
            WHERE  pm.productoid = sm.productoid
              AND  pm.lp         = 1              -- lp=1 → costo
              AND  pm.fecha     <= v_src.FechaADD
            ORDER BY pm.fecha DESC
            LIMIT 1
        ),
        p.costo_actual                                              -- fallback: costo actual
    )                                                               AS costo_unitario

FROM professionalplus.stock_movimiento sm
-- Solo movimientos de ventas 2026 ya migradas
INNER JOIN logos.ventas                  v     ON v.id     = sm.enlacepadreid
-- Necesitamos FechaADD (datetime exacto) para el lookup de precios
INNER JOIN professionalplus.ventas      v_src ON v_src.Id = sm.enlacepadreid
INNER JOIN logos.productos               p     ON p.id     = sm.productoid
WHERE sm.tipo          = 0              -- tipo=0 → movimiento por venta
  AND sm.enlacepadreid > 0
  AND sm.movimiento   <> 0;

SELECT CONCAT('Ítems de venta migrados: ', COUNT(*)) AS resultado FROM logos.venta_items;
