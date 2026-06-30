ALTER TABLE productos ADD COLUMN iva_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 21.00;

ALTER TABLE compras
  ADD COLUMN tipo_comprobante VARCHAR(20) NULL,
  ADD COLUMN numero_comprobante VARCHAR(50) NULL,
  ADD COLUMN subtotal DECIMAL(14,4) NOT NULL DEFAULT 0,
  ADD COLUMN iva_monto DECIMAL(14,4) NOT NULL DEFAULT 0,
  ADD COLUMN percepcion_iibb_porcentaje DECIMAL(5,2) NULL,
  ADD COLUMN percepcion_iibb_monto DECIMAL(14,4) NOT NULL DEFAULT 0,
  ADD COLUMN tipo_pago VARCHAR(20) NOT NULL DEFAULT 'efectivo';

ALTER TABLE compra_items
  ADD COLUMN iva_porcentaje DECIMAL(5,2) NOT NULL DEFAULT 21.00,
  ADD COLUMN iva_monto DECIMAL(14,4) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS compra_pagos (
    id        INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    compra_id INT NOT NULL,
    tipo_pago VARCHAR(20) NOT NULL,
    monto     DECIMAL(14,4) NOT NULL,
    KEY idx_compra (compra_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cc_asignaciones
  ADD COLUMN compra_id INT NULL,
  ADD CONSTRAINT fk_cca_com FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
  MODIFY venta_id INT NULL;
