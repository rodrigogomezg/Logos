-- Migración 42: plazo de pago por cliente
-- Espeja proveedores.plazo_pago_dias (que ya existía). Define a los cuántos
-- días "vence" cada cargo de cuenta corriente del cliente, para las alertas
-- de vencimiento y el reporte de antigüedad de saldos (aging).
-- NULL = sin plazo definido: la deuda no vence, no genera alertas.

ALTER TABLE clientes
  ADD COLUMN plazo_pago_dias INT NULL AFTER limite_credito;
