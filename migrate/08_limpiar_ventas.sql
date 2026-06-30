-- Elimina TODAS las ventas de la base de datos Logos.
-- Revierte el efecto en stock y en cuenta corriente.
-- Clientes, productos y proveedores NO se tocan.
--
-- Ejecutar desde phpMyAdmin: importar este archivo en la BD logos
-- o desde consola: mysql -u root -P 3306 logos < 08_limpiar_ventas.sql

START TRANSACTION;

-- 1. Revertir stock de los ítems que sí tienen movimiento registrado
UPDATE productos p
JOIN (
    SELECT ms.producto_id, SUM(vi.cantidad) AS total
    FROM movimientos_stock ms
    JOIN venta_items vi ON vi.venta_id = ms.referencia_id
                      AND vi.producto_id = ms.producto_id
    WHERE ms.tipo = 'venta'
    GROUP BY ms.producto_id
) v ON p.id = v.producto_id
SET p.stock_actual = p.stock_actual + v.total;

-- 2. Revertir saldos de cuenta corriente vinculados a ventas
UPDATE clientes c
JOIN (
    SELECT ccm.entidad_id, SUM(ccm.monto) AS total
    FROM cuenta_corriente_movimientos ccm
    WHERE ccm.entidad_tipo = 'cliente'
      AND ccm.referencia_id IN (SELECT id FROM ventas)
    GROUP BY ccm.entidad_id
) cc ON c.id = cc.entidad_id
SET c.saldo_cuenta_corriente = c.saldo_cuenta_corriente - cc.total;

-- 3. Eliminar movimientos de CC vinculados a ventas
DELETE ccm FROM cuenta_corriente_movimientos ccm
WHERE ccm.entidad_tipo = 'cliente'
  AND ccm.referencia_id IN (SELECT id FROM ventas);

-- 4. Eliminar movimientos de stock de tipo venta
DELETE FROM movimientos_stock WHERE tipo = 'venta';

-- 5. Eliminar ítems de ventas
DELETE FROM venta_items;

-- 6. Eliminar ventas
DELETE FROM ventas;

-- Reiniciar el auto_increment para que el próximo remito empiece desde 1
ALTER TABLE ventas AUTO_INCREMENT = 1;

COMMIT;
