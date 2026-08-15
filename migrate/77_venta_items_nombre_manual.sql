-- Migración 77: nombre editable por ítem de venta, independiente del
-- nombre del producto maestro.
--
-- Pedido de Rodrigo (15/08/2026): poder cambiar el nombre que se imprime en
-- el remito/factura y que se ve en el detalle de una venta puntual, SIN que
-- eso toque el producto real — el código nunca cambia, es el nombre el que
-- puede variar por venta. Stock y estadísticas de ganancia siguen atadas a
-- producto_id (no cambia nada de eso), esta columna es solo para mostrar/
-- imprimir: NULL = usar productos.nombre como siempre; con valor = ese
-- texto pisa el nombre del producto SOLO en esta venta.

ALTER TABLE venta_items ADD COLUMN nombre_manual VARCHAR(255) DEFAULT NULL;
