-- Migración 40: registro idempotente de pagos MercadoPago
-- MP reintenta las notificaciones del webhook, y además existe la confirmación
-- manual desde el POS: sin este registro el mismo pago podía quedar ingresado
-- dos o más veces en caja_movimientos. La UNIQUE KEY sobre payment_id garantiza
-- que cada pago se registre una sola vez (la confirmación manual usa la clave
-- sintética 'manual-{venta_id}').

CREATE TABLE IF NOT EXISTS mp_pagos (
  id INT(11) NOT NULL AUTO_INCREMENT,
  payment_id VARCHAR(64) NOT NULL,
  venta_id INT(11) DEFAULT NULL,
  monto DECIMAL(14,2) NOT NULL,
  origen ENUM('webhook','manual') NOT NULL,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment (payment_id),
  KEY idx_venta (venta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
