-- Facturación electrónica AFIP (WSAA + WSFEv1)

-- Cache del Ticket de Acceso de WSAA (dura ~12 h; AFIP rechaza pedir uno
-- nuevo mientras el anterior siga vigente, por eso se persiste).
CREATE TABLE IF NOT EXISTS afip_tokens (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  servicio VARCHAR(20)  NOT NULL,
  entorno  VARCHAR(20)  NOT NULL,
  token    TEXT         NOT NULL,
  sign     TEXT         NOT NULL,
  expira   DATETIME     NOT NULL,
  UNIQUE KEY uk_servicio_entorno (servicio, entorno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE ventas
  ADD COLUMN IF NOT EXISTS cae_vencimiento DATE         NULL AFTER cae,
  ADD COLUMN IF NOT EXISTS afip_error      VARCHAR(500) NULL AFTER cae_vencimiento;
