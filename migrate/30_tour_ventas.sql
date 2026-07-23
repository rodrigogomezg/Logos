-- Ventas de demo para el tour guiado.
-- Requiere que 29_tour_productos.sql ya haya sido ejecutado (productos PRUEBA1/PRUEBA2).
-- Idempotente: detecta existencia por numero_afip = 'TOUR_DEMO_1' (campo interno, no visible en UI).

USE logos;

SET @p1 = (SELECT id FROM productos WHERE codigo = 'PRUEBA1' LIMIT 1);
SET @p2 = (SELECT id FROM productos WHERE codigo = 'PRUEBA2' LIMIT 1);

-- Venta 1: 1x PRUEBA 1 + 2x PRUEBA 2 = $6.000, efectivo, hoy
INSERT INTO ventas (fecha, cliente_id, total, tipo_comprobante, tipo_pago, estado, numero_afip, sync_uuid)
SELECT CURDATE(), NULL, 6000.00, 'REMITO', 'efectivo', 'completado', 'TOUR_DEMO_1', UUID()
WHERE @p1 IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM ventas WHERE numero_afip = 'TOUR_DEMO_1');

SET @v1 = LAST_INSERT_ID();

INSERT INTO venta_items (venta_id, producto_id, cantidad, precio_unitario, costo_unitario)
SELECT @v1, @p1, 1.0000, 1000.0000, 700.0000 WHERE @v1 > 0;

INSERT INTO venta_items (venta_id, producto_id, cantidad, precio_unitario, costo_unitario)
SELECT @v1, @p2, 2.0000, 2500.0000, 1800.0000 WHERE @v1 > 0 AND @p2 IS NOT NULL;

-- Venta 2: 3x PRUEBA 1 + 1x PRUEBA 2 = $5.500, transferencia, ayer
INSERT INTO ventas (fecha, cliente_id, total, tipo_comprobante, tipo_pago, estado, numero_afip, sync_uuid)
SELECT CURDATE() - INTERVAL 1 DAY, NULL, 5500.00, 'REMITO', 'transferencia', 'completado', 'TOUR_DEMO_2', UUID()
WHERE @v1 > 0;

SET @v2 = LAST_INSERT_ID();

INSERT INTO venta_items (venta_id, producto_id, cantidad, precio_unitario, costo_unitario)
SELECT @v2, @p1, 3.0000, 1000.0000, 700.0000 WHERE @v2 > 0 AND @v2 != @v1;

INSERT INTO venta_items (venta_id, producto_id, cantidad, precio_unitario, costo_unitario)
SELECT @v2, @p2, 1.0000, 2500.0000, 1800.0000 WHERE @v2 > 0 AND @v2 != @v1 AND @p2 IS NOT NULL;

SELECT CONCAT('Ventas demo insertadas: ', IF(@v1 > 0, 2, 0)) AS resultado;
