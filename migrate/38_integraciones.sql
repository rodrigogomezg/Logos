-- Nuevas columnas de integración en configuracion
ALTER TABLE configuracion
  ADD COLUMN mp_access_token   VARCHAR(500) NULL AFTER tipos_habilitados,
  ADD COLUMN mp_webhook_secret VARCHAR(255) NULL AFTER mp_access_token,
  ADD COLUMN wa_phone_id       VARCHAR(100) NULL AFTER mp_webhook_secret,
  ADD COLUMN wa_token          VARCHAR(500) NULL AFTER wa_phone_id,
  ADD COLUMN wa_template_name  VARCHAR(100) NULL DEFAULT 'envio_comprobante' AFTER wa_token;

-- Extender medio_pago para incluir MercadoPago
ALTER TABLE caja_movimientos
  MODIFY COLUMN medio_pago ENUM('efectivo','transferencia','tarjeta','mercado_pago') NULL;
