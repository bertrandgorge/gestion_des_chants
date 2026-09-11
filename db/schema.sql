-- Schéma de la base « gestion des chants ».
-- Idempotent : exécutable plusieurs fois sans erreur (CREATE TABLE IF NOT EXISTS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS paroisses (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nom                 VARCHAR(150) NOT NULL,
    slug                VARCHAR(100) NOT NULL,
    impression_sections TEXT DEFAULT NULL,          -- JSON « type de section => booléen » : sélection d'impression mémorisée
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_paroisses_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clochers (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    paroisse_id  INT UNSIGNED NOT NULL,
    nom          VARCHAR(150) NOT NULL,
    slug         VARCHAR(100) NOT NULL,
    ad_hoc       TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 1 = lieu ponctuel créé pour une feuille (« - Autres - »), masqué des listes
    jour_defaut  TINYINT UNSIGNED DEFAULT NULL,   -- 1 = lundi ... 7 = dimanche (ISO-8601)
    heure_defaut TIME DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_clochers_slug (paroisse_id, slug),
    KEY idx_clochers_paroisse (paroisse_id),
    CONSTRAINT fk_clochers_paroisse FOREIGN KEY (paroisse_id) REFERENCES paroisses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS utilisateurs (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email                 VARCHAR(190) NOT NULL,
    type                  ENUM('admin','chantre') NOT NULL DEFAULT 'chantre',
    paroisse_id           INT UNSIGNED NOT NULL,
    derniere_connexion_at DATETIME DEFAULT NULL,          -- NULL tant que l'invitation n'a pas été acceptée
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_utilisateurs_email (email),
    KEY idx_utilisateurs_paroisse (paroisse_id),
    CONSTRAINT fk_utilisateurs_paroisse FOREIGN KEY (paroisse_id) REFERENCES paroisses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tokens (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilisateur_id  INT UNSIGNED NOT NULL,
    token_hash      CHAR(64) NOT NULL,               -- sha256 hex du token envoyé par email
    type            ENUM('invitation','reset','login') NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tokens_hash (token_hash),
    KEY idx_tokens_utilisateur (utilisateur_id),
    CONSTRAINT fk_tokens_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Connexions persistantes : cookie « se souvenir de moi » illimité.
CREATE TABLE IF NOT EXISTS connexions (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    utilisateur_id       INT UNSIGNED NOT NULL,
    selecteur            CHAR(24) NOT NULL,              -- clé de recherche (dans le cookie)
    validateur_hash      CHAR(64) NOT NULL,              -- sha256 hex du validateur (dans le cookie)
    user_agent           VARCHAR(255) DEFAULT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    derniere_utilisation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_connexions_selecteur (selecteur),
    KEY idx_connexions_utilisateur (utilisateur_id),
    CONSTRAINT fk_connexions_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id) ON DELETE CASCADE
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

-- Répertoire partagé de chants (résultat dédoublonné des imports externes,
-- complétable manuellement). Voir aussi `chants.repertoire_id`.
CREATE TABLE IF NOT EXISTS repertoire_chants (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    titre        VARCHAR(255) NOT NULL,
    code         VARCHAR(190) DEFAULT NULL,           -- codes distincts (Secli...), séparés par ", "
    auteur       VARCHAR(190) DEFAULT NULL,
    type         VARCHAR(60) NOT NULL DEFAULT 'entree', -- slug App\SectionTypes::DEFAUT
    nom          VARCHAR(120) DEFAULT NULL,           -- libellé affiché quand repris sur une feuille
    ordinaire    VARCHAR(190) DEFAULT NULL,           -- nom de l'ordinaire de messe (Kyrie/Gloria/Sanctus/Anamnèse/Agnus partageant une musique), le cas échéant
    chant        LONGTEXT DEFAULT NULL,
    nb_couplets  SMALLINT UNSIGNED DEFAULT NULL,
    mots_cles    VARCHAR(500) DEFAULT NULL,           -- catégories sources, séparées par ", "
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_repertoire_titre (titre),
    KEY idx_repertoire_code (code),
    KEY idx_repertoire_ordinaire (ordinaire)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chants (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    feuille_id    INT UNSIGNED DEFAULT NULL,           -- NULL uniquement pour d'anciennes lignes ; toute nouvelle ligne appartient à une feuille
    repertoire_id INT UNSIGNED DEFAULT NULL,           -- fiche du répertoire dont ce chant est issu, le cas échéant
    nom           VARCHAR(120) NOT NULL,               -- libellé affiché de la section
    type          VARCHAR(60) NOT NULL,                -- slug de comportement (immuable) : entree, kyrie, psaume...
    position      INT NOT NULL DEFAULT 0,
    titre         VARCHAR(255) DEFAULT NULL,           -- titre de la section (libellé local ; code / auteur / partitions viennent de la fiche du répertoire liée)
    chant         LONGTEXT DEFAULT NULL,               -- texte du chant / psaume (refrains + couplets)
    nb_couplets   SMALLINT UNSIGNED DEFAULT NULL,      -- couplets hors refrain (choix de la version la plus complète)
    introduction  VARCHAR(255) DEFAULT NULL,           -- « Lecture du livre... » / phrase introductive
    contenu       LONGTEXT DEFAULT NULL,               -- contenu HTML des lectures / évangile
    acclamation   LONGTEXT DEFAULT NULL,               -- verset d'acclamation (évangile)
    reference     VARCHAR(120) DEFAULT NULL,           -- référence biblique (Ez 33, 7-9)
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_chants_feuille_position (feuille_id, position),
    KEY idx_chants_repertoire (repertoire_id),
    KEY idx_chants_titre (titre),
    CONSTRAINT fk_chants_feuille FOREIGN KEY (feuille_id) REFERENCES feuilles_chant (id) ON DELETE CASCADE,
    CONSTRAINT fk_chants_repertoire FOREIGN KEY (repertoire_id) REFERENCES repertoire_chants (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des scripts d'import de catalogues (chantonseneglise.fr…). Sert à la
-- fois de suivi (relance sans refaire les requêtes) et de zone de préparation :
-- l'import se fait en 2 passes (récupération réseau, puis création des fiches).
-- ENUM/FETCH restent volontairement sommaires (paroles stockées telles quelles,
-- sans mise en forme) pour ne jamais avoir à refaire une requête vers un site
-- tiers si la logique de mise en forme / dédoublonnage évolue : cette
-- transformation se fait après coup, dans bin/import_repertoire.php.
CREATE TABLE IF NOT EXISTS import_journal (
    source            VARCHAR(40)  NOT NULL,        -- ex. « chantonseneglise », « catechisme-emmanuel »
    ref               VARCHAR(190) NOT NULL,        -- identifiant du chant sur le site source (id ou slug)
    statut            VARCHAR(20)  NOT NULL,        -- a-importer | avec-paroles | sans-paroles | importe | complete | erreur
    chant_id          INT UNSIGNED DEFAULT NULL,    -- fiche repertoire_chants créée / complétée le cas échéant
    url               VARCHAR(255) DEFAULT NULL,
    titre             VARCHAR(255) DEFAULT NULL,    -- données parsées (passe FETCH), utilisées à la passe IMPORT
    titre_reduit      VARCHAR(255) DEFAULT NULL,    -- App\Models\Chant::reduire(titre) — calculé à l'import, pas au fetch
    code              VARCHAR(60)  DEFAULT NULL,
    code_repertoire   VARCHAR(60)  DEFAULT NULL,    -- code IEV (Emmanuel) : rapprochement entre imports
    auteur            VARCHAR(190) DEFAULT NULL,
    type              VARCHAR(60)  DEFAULT NULL,    -- slug App\SectionTypes::DEFAUT (entree, communion…)
    categorie         VARCHAR(255) DEFAULT NULL,    -- libellé brut lu sur le site (re-classification hors-ligne)
    nom               VARCHAR(120) DEFAULT NULL,
    ordinaire         VARCHAR(190) DEFAULT NULL,    -- nom de l'ordinaire de messe (source emmanuel-ordinaires)
    chant             LONGTEXT     DEFAULT NULL,    -- paroles brutes (non mises en forme — App\Import\Paroles::format)
    empreinte_paroles VARCHAR(255) DEFAULT NULL,    -- App\Models\Chant::premieresLignes(chant) — calculé à l'import
    traite_le         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (source, ref),
    KEY idx_import_journal_statut (source, statut),
    KEY idx_import_journal_repertoire (code_repertoire),
    KEY idx_import_journal_titre_reduit (titre_reduit),
    KEY idx_import_journal_chant_id (chant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
