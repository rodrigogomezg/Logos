-- Datos de demo para el tour de Cuenta Corriente.
-- Crea CLIENTE DEMO (con venta CC) y PROVEEDOR DEMO (con compra CC de PRUEBA1/PRUEBA2).
-- Idempotente: detecta existencia por nombre y numero_afip/numero_comprobante.
-- Requiere: 29_tour_productos.sql ya ejecutado.

USE logos;

SET @p1 = (SELECT id FROM productos WHERE codigo = 'PRUEBA1' LIMIT 1);
SET @p2 = (SELECT id FROM productos WHERE codigo = 'PRUEBA2' LIMIT 1);

-- ── CLIENTE DEMO ──────────────────────────────────────────────────────────────

INSERT INTO clientes (nombre, cc_habilitada, activo)
SELECT 'CLIENTE DEMO', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM clientes WHERE nombre = 'CLIENTE DEMO');

SET @cli = (SELECT id FROM clientes WHERE nombre = 'CLIENTE DEMO' LIMIT 1);

-- Venta CC: 5x PRUEBA1 + 4x PRUEBA2 = $15.000
-- Marcador: numero_afip = 'TOUR_CC_CLI' (campo interno, no visible en UI de ventas)
INSERT INTO ventas (fecha, cliente_id, total, tipo_comprobante, tipo_pago, estado, numero_afip, sync_uuid)
SELECT CURDATE() - INTERVAL 5 DAY, @cli, 15000.00, 'REMITO', 'cc', 'completado', 'TOUR_CC_CLI', UUID()
WHERE @cli IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM ventas WHERE numero_afip = 'TOUR_CC_CLI');

SET @v_cc = LAST_INSERT_ID();

INSERT INTO venta_items (venta_id, producto_id, cantidad, precio_unitario, costo_unitario)
SELECT @v_cc, @p1, 5.0000, 1000.0000, 700.0000 WHERE @v_cc > 0 AND @p1 IS NOT NULL;

INSERT INTO venta_items (venta_id, producto_id, cantidad, precio_unitario, costo_unitario)
SELECT @v_cc, @p2, 4.0000, 2500.0000, 1800.0000 WHERE @v_cc > 0 AND @p2 IS NOT NULL;

-- Movimiento CC: cargo por la venta
INSERT INTO cuenta_corriente_movimientos (entidad_tipo, entidad_id, tipo, monto, referencia_id, fecha)
SELECT 'cliente', @cli, 'cargo', 15000.00, @v_cc, CURDATE() - INTERVAL 5 DAY
WHERE @v_cc > 0;

-- Actualizar saldo desnormalizado del cliente
UPDATE clientes SET saldo_cuenta_corriente = 15000.00 WHERE id = @cli AND @v_cc > 0;

-- ── PROVEEDOR DEMO ────────────────────────────────────────────────────────────

INSERT INTO proveedores (nombre, cc_habilitada, activo)
SELECT 'PROVEEDOR DEMO', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM proveedores WHERE nombre = 'PROVEEDOR DEMO');

SET @prov = (SELECT id FROM proveedores WHERE nombre = 'PROVEEDOR DEMO' LIMIT 1);

-- Compra CC: 5x PRUEBA1 + 2x PRUEBA2 = $7.100
-- Marcador: numero_comprobante = 'TOUR-CC-PROV'
INSERT INTO compras (fecha, proveedor_id, total, tipo_pago, estado, tipo_comprobante, numero_comprobante)
SELECT CURDATE() - INTERVAL 3 DAY, @prov, 7100.00, 'cc', 'completado', 'Compra', 'TOUR-CC-PROV'
WHERE @prov IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM compras WHERE numero_comprobante = 'TOUR-CC-PROV');

SET @c_cc = LAST_INSERT_ID();

INSERT INTO compra_items (compra_id, producto_id, cantidad, costo_unitario)
SELECT @c_cc, @p1, 5.0000, 700.0000 WHERE @c_cc > 0 AND @p1 IS NOT NULL;

INSERT INTO compra_items (compra_id, producto_id, cantidad, costo_unitario)
SELECT @c_cc, @p2, 2.0000, 1800.0000 WHERE @c_cc > 0 AND @p2 IS NOT NULL;

-- Movimiento CC: cargo por la compra
INSERT INTO cuenta_corriente_movimientos (entidad_tipo, entidad_id, tipo, monto, referencia_id, fecha)
SELECT 'proveedor', @prov, 'cargo', 7100.00, @c_cc, CURDATE() - INTERVAL 3 DAY
WHERE @c_cc > 0;

-- Actualizar saldo desnormalizado del proveedor
UPDATE proveedores SET saldo_cuenta_corriente = 7100.00 WHERE id = @prov AND @c_cc > 0;

SELECT
  CONCAT('Cliente: ', IF(@v_cc > 0, 'OK (venta CC $15.000)', 'ya existía')) AS cliente_demo,
  CONCAT('Proveedor: ', IF(@c_cc > 0, 'OK (compra CC $7.100)', 'ya existía')) AS proveedor_demo;
