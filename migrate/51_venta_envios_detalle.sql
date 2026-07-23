-- Guarda el detalle de envíos individuales en remitos unificados
-- para que el comprobante los muestre con fecha en lugar de un único monto total.
ALTER TABLE ventas
    ADD COLUMN envios_detalle JSON NULL DEFAULT NULL AFTER envio_precio;
