-- Migración 39: Almacena el link de cobro MercadoPago en la venta
-- Permite imprimir el QR de MP en el comprobante al momento de facturar.

ALTER TABLE ventas
  ADD COLUMN mp_init_point VARCHAR(500) NULL AFTER estado;
