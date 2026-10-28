-- Sessions en base (SESSION_DRIVER=database), pour un hébergement sans disque partagé (Vercel).
-- Migration additive : ne touche à aucune donnée existante.
CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(128) NOT NULL PRIMARY KEY,
  payload MEDIUMTEXT NOT NULL,
  expires_at INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_sessions_expires ON sessions (expires_at);
