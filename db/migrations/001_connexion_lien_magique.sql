-- Connexion sans mot de passe : lien magique envoyé par email + cookie de
-- connexion illimité. Migration idempotente (exécutable après un schema.sql
-- déjà à jour comme sur une base existante).

-- 1. Nouveau type de jeton « login » (lien magique de connexion).
ALTER TABLE tokens MODIFY COLUMN type ENUM('invitation','reset','login') NOT NULL;

-- 2. Table des connexions persistantes.
CREATE TABLE IF NOT EXISTS connexions (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilisateur_id       INT UNSIGNED NOT NULL,
    selecteur            CHAR(24) NOT NULL,
    validateur_hash      CHAR(64) NOT NULL,
    user_agent           VARCHAR(255) DEFAULT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    derniere_utilisation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_connexions_selecteur (selecteur),
    KEY idx_connexions_utilisateur (utilisateur_id),
    CONSTRAINT fk_connexions_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Colonne « derniere_connexion_at » (remplace « pass_hash IS NOT NULL »).
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateurs'
                   AND COLUMN_NAME = 'derniere_connexion_at');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE utilisateurs ADD COLUMN derniere_connexion_at DATETIME DEFAULT NULL AFTER paroisse_id',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Les comptes déjà pourvus d'un mot de passe sont considérés actifs,
--    puis la colonne pass_hash est supprimée.
SET @has_pass := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateurs'
                    AND COLUMN_NAME = 'pass_hash');

SET @sql := IF(@has_pass = 1,
    'UPDATE utilisateurs SET derniere_connexion_at = created_at WHERE pass_hash IS NOT NULL AND derniere_connexion_at IS NULL',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(@has_pass = 1,
    'ALTER TABLE utilisateurs DROP COLUMN pass_hash',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
