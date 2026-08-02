-- Permite asignar un pago de CC a un cargo manual (e.g. costo de envío de nota de envío).
-- El cargo_id referencia cuenta_corriente_movimientos.id (tipo='cargo' sin venta asociada).
ALTER TABLE cc_asignaciones
  ADD COLUMN cargo_id INT(11) DEFAULT NULL AFTER compra_id,
  ADD KEY idx_cargo (cargo_id),
  ADD CONSTRAINT fk_cca_cargo FOREIGN KEY (cargo_id)
      REFERENCES cuenta_corriente_movimientos(id) ON DELETE CASCADE;
