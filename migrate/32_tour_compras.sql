-- Datos de demo para el tour de Compras.
-- Crea una Factura A de PROVEEDOR DEMO con PRUEBA1 y PRUEBA2.
-- Idempotente: detecta existencia por numero_comprobante = 'TOUR-COMPRAS-01'.
-- Requiere: 29_tour_productos.sql y 31_tour_cc.sql ya ejecutados.

USE logos;

SET @p1   = (SELECT id FROM productos   WHERE codigo = 'PRUEBA1'        LIMIT 1);
SET @p2   = (SELECT id FROM productos   WHERE codigo = 'PRUEBA2'        LIMIT 1);
SET @prov = (SELECT id FROM proveedores WHERE nombre = 'PROVEEDOR DEMO' LIMIT 1);

-- Factura A: 10x PRUEBA1 ($700 c/u) + 5x PRUEBA2 ($1.800 c/u) con IVA 21%
--   subtotal = $16.000 | IVA = $3.360 | total = $19.360
-- Marcador: numero_comprobante = 'TOUR-COMPRAS-01'
INSERT INTO compras
    (fecha, proveedor_id, total, subtotal, iva_monto, tipo_pago, estado, tipo_comprobante, numero_comprobante)
SELECT
    CURDATE() - INTERVAL 7 DAY, @prov,
    19360.00, 16000.00, 3360.00,
    'transferencia', 'completado', 'factura_a', 'TOUR-COMPRAS-01'
WHERE @prov IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM compras WHERE numero_comprobante = 'TOUR-COMPRAS-01');

SET @c_demo = LAST_INSERT_ID();

INSERT INTO compra_items (compra_id, producto_id, cantidad, costo_unitario, iva_porcentaje, iva_monto)
SELECT @c_demo, @p1, 10.0000, 700.0000, 21.00, 1470.0000
WHERE @c_demo > 0 AND @p1 IS NOT NULL;

INSERT INTO compra_items (compra_id, producto_id, cantidad, costo_unitario, iva_porcentaje, iva_monto)
SELECT @c_demo, @p2,  5.0000, 1800.0000, 21.00, 1890.0000
WHERE @c_demo > 0 AND @p2 IS NOT NULL;

SELECT
    CONCAT('Compra demo: ', IF(@c_demo > 0, 'OK (TOUR-COMPRAS-01, Factura A, $19.360)', 'ya existía')) AS resultado;
