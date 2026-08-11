-- Migración 71: descuentos de compra persistidos + flags de actualización.
--
-- Hasta ahora el descuento (por ítem y general) solo se calculaba en el
-- navegador para mostrar el total en pantalla — nunca se mandaba al costo
-- que queda cargado en productos.costo_actual (que se pisaba con el costo
-- de lista, sin descuento) ni se guardaba en la base, así que editar una
-- compra vieja perdía el descuento por completo. Ver ComprasController.php.

ALTER TABLE compra_items
  ADD COLUMN descuento_porcentaje DECIMAL(5,2)  NOT NULL DEFAULT 0.00   AFTER costo_unitario,
  ADD COLUMN descuento_monto      DECIMAL(14,4) NOT NULL DEFAULT 0.0000 AFTER descuento_porcentaje;

ALTER TABLE compras
  ADD COLUMN descuento_general_porcentaje DECIMAL(5,2)  NULL              AFTER percepcion_iibb_monto,
  ADD COLUMN descuento_general_monto      DECIMAL(14,4) NOT NULL DEFAULT 0.0000 AFTER descuento_general_porcentaje,
  ADD COLUMN actualiza_stock             TINYINT(1)     NOT NULL DEFAULT 1 AFTER descuento_general_monto,
  ADD COLUMN actualiza_costos            TINYINT(1)     NOT NULL DEFAULT 1 AFTER actualiza_stock;
