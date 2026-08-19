-- Migración 78: "cheque" como medio_pago válido en caja_movimientos.
--
-- Pedido de Rodrigo (19/08/2026): un pago de cuenta corriente por tarjeta,
-- Mercado Pago o cheque debe impactar en caja igual que ya hace hoy efectivo/
-- transferencia. tarjeta y mercado_pago ya estaban en el ENUM (se usan desde
-- otros flujos de caja), pero cheque nunca se había necesitado ahí — el pago
-- de CC con cheque hoy solo guarda los datos del cheque como JSON libre en
-- cuenta_corriente_movimientos.pago_datos, sin tocar caja_movimientos.

ALTER TABLE caja_movimientos
  MODIFY medio_pago ENUM('efectivo','transferencia','tarjeta','mercado_pago','cheque') NULL;
