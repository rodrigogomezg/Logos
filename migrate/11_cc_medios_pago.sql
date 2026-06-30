ALTER TABLE cuenta_corriente_movimientos
  ADD COLUMN medio_pago   VARCHAR(20)  NULL AFTER observaciones,
  ADD COLUMN pago_datos   TEXT         NULL AFTER medio_pago,
  ADD COLUMN comprobante  VARCHAR(500) NULL AFTER pago_datos;
