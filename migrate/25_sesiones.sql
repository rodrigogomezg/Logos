-- Autenticación por token de sesión (reemplaza la confianza en X-Usuario-Id)

CREATE TABLE IF NOT EXISTS sesiones (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64)  NOT NULL,        -- sha256 del token; el token en claro nunca se guarda
  usuario_id INT       NOT NULL,
  creado_en  DATETIME  NOT NULL,
  ultimo_uso DATETIME  NOT NULL,
  expira     DATETIME  NOT NULL,
  UNIQUE KEY uk_token (token_hash),
  KEY idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate limiting del PIN: 5 intentos fallidos bloquean 5 minutos
CREATE TABLE IF NOT EXISTS login_intentos (
  usuario_id      INT PRIMARY KEY,
  intentos        INT      NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL,
  actualizado     DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
