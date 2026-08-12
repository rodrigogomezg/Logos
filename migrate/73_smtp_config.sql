-- Columnas SMTP usadas por ConfiguracionController::guardarSmtp() y Mail.php
-- desde que se agregó el envío de comprobantes por email, pero nunca habían
-- quedado versionadas acá (a diferencia de mp_*/wa_* en 38_integraciones.sql).
-- Sin esta migración, guardar/leer la config de Email SMTP tira "Unknown
-- column" en cualquier base que no las tenga agregadas a mano.
ALTER TABLE configuracion
  ADD COLUMN smtp_host      VARCHAR(255) NULL AFTER wa_template_name,
  ADD COLUMN smtp_puerto    INT(11) NOT NULL DEFAULT 587 AFTER smtp_host,
  ADD COLUMN smtp_seguridad ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls' AFTER smtp_puerto,
  ADD COLUMN smtp_usuario   VARCHAR(255) NULL AFTER smtp_seguridad,
  ADD COLUMN smtp_clave     VARCHAR(255) NULL AFTER smtp_usuario,
  ADD COLUMN smtp_de_nombre VARCHAR(255) NULL AFTER smtp_clave,
  ADD COLUMN smtp_de_email  VARCHAR(255) NULL AFTER smtp_de_nombre,
  ADD COLUMN smtp_reply_to  VARCHAR(255) NULL AFTER smtp_de_email;
