-- Relato Estratégico · esquema inicial del panel administrativo
-- Base de datos: cre116835_relato_admin · MySQL/MariaDB con InnoDB y utf8mb4.
-- Idempotente: puede ejecutarse más de una vez sin borrar datos.

CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','editor','reader') NOT NULL DEFAULT 'reader',
  active        TINYINT(1)   NOT NULL DEFAULT 1,
  failed_logins INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until  DATETIME     NULL,
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- id = SHA-256 del token de sesión (el token en claro solo existe en la cookie).
CREATE TABLE IF NOT EXISTS admin_sessions (
  id            CHAR(64)     NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  csrf_token    CHAR(64)     NOT NULL,
  created_at    DATETIME     NOT NULL,
  last_activity DATETIME     NOT NULL,
  expires_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_admin_sessions_user (user_id),
  CONSTRAINT fk_admin_sessions_user FOREIGN KEY (user_id)
    REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Solo se guarda el SHA-256 del token de recuperación.
CREATE TABLE IF NOT EXISTS password_resets (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED    NOT NULL,
  token_hash  CHAR(64)        NOT NULL,
  created_at  DATETIME        NOT NULL,
  expires_at  DATETIME        NOT NULL,
  used_at     DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_resets_token (token_hash),
  KEY idx_password_resets_user (user_id),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id)
    REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentos de acceso (para límites por IP y por cuenta). IP y correo van como HMAC.
CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_hash      CHAR(64)        NOT NULL,
  email_hash   CHAR(64)        NOT NULL,
  success      TINYINT(1)      NOT NULL DEFAULT 0,
  attempted_at DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY idx_login_attempts_ip (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoría de seguridad y cambios de contenido. Nunca guarda contraseñas ni tokens.
CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event      VARCHAR(60)     NOT NULL,
  user_id    INT UNSIGNED    NULL,
  ip_hash    CHAR(64)        NOT NULL,
  detail     VARCHAR(255)    NOT NULL DEFAULT '',
  created_at DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_event (event, created_at),
  KEY idx_audit_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Artículos del blog administrados desde el panel.
CREATE TABLE IF NOT EXISTS posts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title        VARCHAR(200) NOT NULL,
  slug         VARCHAR(120) NOT NULL,
  category     VARCHAR(100) NOT NULL DEFAULT '',
  excerpt      VARCHAR(500) NOT NULL DEFAULT '',
  body         MEDIUMTEXT   NOT NULL,
  image_url    VARCHAR(500) NOT NULL DEFAULT '',
  status       ENUM('borrador','publicado') NOT NULL DEFAULT 'borrador',
  published_at DATETIME     NULL,
  created_at   DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL,
  updated_by   INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_posts_slug (slug),
  KEY idx_posts_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes generales del sitio (clave/valor con lista blanca en la API).
CREATE TABLE IF NOT EXISTS site_settings (
  setting_key   VARCHAR(60)  NOT NULL,
  setting_value TEXT         NOT NULL,
  updated_at    DATETIME     NOT NULL,
  updated_by    INT UNSIGNED NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
