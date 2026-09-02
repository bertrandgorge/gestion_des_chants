-- Schéma de la base « gestion des chants ».
-- Idempotent : exécutable plusieurs fois sans erreur (CREATE TABLE IF NOT EXISTS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS paroisses (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nom        VARCHAR(150) NOT NULL,
    slug       VARCHAR(100) NOT NULL,
    logo       VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_paroisses_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clochers (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    paroisse_id  INT UNSIGNED NOT NULL,
    nom          VARCHAR(150) NOT NULL,
    slug         VARCHAR(100) NOT NULL,
    jour_defaut  TINYINT UNSIGNED DEFAULT NULL,   -- 1 = lundi ... 7 = dimanche (ISO-8601)
    heure_defaut TIME DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_clochers_slug (paroisse_id, slug),
    KEY idx_clochers_paroisse (paroisse_id),
    CONSTRAINT fk_clochers_paroisse FOREIGN KEY (paroisse_id) REFERENCES paroisses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS utilisateurs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email       VARCHAR(190) NOT NULL,
    pass_hash   VARCHAR(255) DEFAULT NULL,          -- NULL tant que l'invitation n'est pas acceptée
    type        ENUM('admin','chantre') NOT NULL DEFAULT 'chantre',
    paroisse_id INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_utilisateurs_email (email),
    KEY idx_utilisateurs_paroisse (paroisse_id),
    CONSTRAINT fk_utilisateurs_paroisse FOREIGN KEY (paroisse_id) REFERENCES paroisses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tokens (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilisateur_id  INT UNSIGNED NOT NULL,
    token_hash      CHAR(64) NOT NULL,               -- sha256 hex du token envoyé par email
    type            ENUM('invitation','reset') NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tokens_hash (token_hash),
    KEY idx_tokens_utilisateur (utilisateur_id),
    CONSTRAINT fk_tokens_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feuilles_chant (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    chantre_id       INT UNSIGNED NOT NULL,
    clocher_id       INT UNSIGNED NOT NULL,
    date_heure       DATETIME NOT NULL,
    annee            VARCHAR(30) DEFAULT NULL,        -- « A », « B », « C » (informations.annee AELF)
    semaine          VARCHAR(120) DEFAULT NULL,       -- informations.semaine
    couleur          VARCHAR(30) DEFAULT NULL,        -- vert, blanc, rouge, violet, rose...
    titre_liturgique VARCHAR(255) DEFAULT NULL,       -- informations.jour_liturgique_nom
    aelf_date        DATE DEFAULT NULL,               -- date réellement interrogée (messe anticipée)
    aelf_json        LONGTEXT DEFAULT NULL,           -- snapshot brut de la réponse AELF
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_feuilles_clocher_date (clocher_id, date_heure),
    KEY idx_feuilles_chantre (chantre_id),
    CONSTRAINT fk_feuilles_chantre FOREIGN KEY (chantre_id) REFERENCES utilisateurs (id),
    CONSTRAINT fk_feuilles_clocher FOREIGN KEY (clocher_id) REFERENCES clochers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chants (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    feuille_id   INT UNSIGNED NOT NULL,
    nom          VARCHAR(120) NOT NULL,               -- libellé affiché de la section
    type         VARCHAR(60) NOT NULL,                -- slug de comportement (immuable) : entree, kyrie, psaume...
    position     INT NOT NULL DEFAULT 0,
    titre        VARCHAR(255) DEFAULT NULL,
    code         VARCHAR(60) DEFAULT NULL,
    auteur       VARCHAR(190) DEFAULT NULL,
    chant        LONGTEXT DEFAULT NULL,               -- texte du chant / psaume (refrains + couplets)
    introduction VARCHAR(255) DEFAULT NULL,           -- « Lecture du livre... » / phrase introductive
    contenu      LONGTEXT DEFAULT NULL,               -- contenu HTML des lectures / évangile
    acclamation  LONGTEXT DEFAULT NULL,               -- verset d'acclamation (évangile)
    reference    VARCHAR(120) DEFAULT NULL,           -- référence biblique (Ez 33, 7-9)
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_chants_feuille_position (feuille_id, position),
    KEY idx_chants_titre (titre),
    KEY idx_chants_code (code),
    CONSTRAINT fk_chants_feuille FOREIGN KEY (feuille_id) REFERENCES feuilles_chant (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
