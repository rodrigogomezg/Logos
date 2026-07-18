-- Productos de demo para el tour guiado. Idempotente: no duplica si ya existen.

INSERT INTO productos (codigo, nombre, precio_venta, costo_actual, stock_actual, stock_minimo, activo)
SELECT 'PRUEBA1', 'PRUEBA 1', 1000.00, 700.00, 999.00, 0.00, 1
WHERE NOT EXISTS (SELECT 1 FROM productos WHERE codigo = 'PRUEBA1');

INSERT INTO productos (codigo, nombre, precio_venta, costo_actual, stock_actual, stock_minimo, activo)
SELECT 'PRUEBA2', 'PRUEBA 2', 2500.00, 1800.00, 999.00, 0.00, 1
WHERE NOT EXISTS (SELECT 1 FROM productos WHERE codigo = 'PRUEBA2');
