-- Notas de Crédito + mejoras de auditoría ARCA (ex AFIP)
-- Ejecutar una sola vez. Los ADD COLUMN IF NOT EXISTS requieren MySQL 5.7+.

USE logos;

ALTER TABLE ventas
  ADD COLUMN IF NOT EXISTS afip_response         MEDIUMTEXT   NULL AFTER afip_error,
  ADD COLUMN IF NOT EXISTS numero_afip_pendiente INT UNSIGNED  NULL AFTER numero_afip,
  ADD COLUMN IF NOT EXISTS punto_venta           SMALLINT     NULL AFTER numero_afip_pendiente,
  ADD COLUMN IF NOT EXISTS cbte_asoc_tipo        TINYINT      NULL AFTER punto_venta,
  ADD COLUMN IF NOT EXISTS cbte_asoc_pto_vta     SMALLINT     NULL AFTER cbte_asoc_tipo,
  ADD COLUMN IF NOT EXISTS cbte_asoc_nro         INT UNSIGNED NULL AFTER cbte_asoc_pto_vta;

-- Índice para consultas de saldo pendiente (cuánto ya se acreditó contra una factura)
-- Omitir si ya existe.
CREATE INDEX idx_cbte_asoc ON ventas (cbte_asoc_tipo, cbte_asoc_pto_vta, cbte_asoc_nro);
