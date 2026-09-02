-- Chants de catalogue : préremplissage de la base à partir de sites de paroles
-- (chantonseneglise.fr, etc.). Ces chants ne sont rattachés à aucune feuille
-- (feuille_id NULL) et portent l'URL de leur fiche source.
--
-- Migration idempotente (colonnes/index ajoutés seulement s'ils manquent).

-- 1. feuille_id devient nullable.
SET @nullable := (SELECT IS_NULLABLE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chants'
                    AND COLUMN_NAME = 'feuille_id');
SET @sql := IF(@nullable = 'NO',
    'ALTER TABLE chants MODIFY COLUMN feuille_id INT UNSIGNED DEFAULT NULL',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Colonne url : fiche source (partitions, enregistrements).
--    Affichée uniquement dans l'interface chantre, jamais pour les paroissiens.
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chants'
                   AND COLUMN_NAME = 'url');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE chants ADD COLUMN url VARCHAR(255) DEFAULT NULL AFTER reference',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Index sur url (recherche / déduplication des chants importés).
SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chants'
                   AND INDEX_NAME = 'idx_chants_url');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE chants ADD KEY idx_chants_url (url)',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4. Journal d'import : mémorise chaque fiche traitée (importée, sans paroles,
--    en erreur) pour que les scripts d'import soient relançables sans refaire
--    les requêtes déjà faites.
CREATE TABLE IF NOT EXISTS import_journal (
    source     VARCHAR(40)  NOT NULL,             -- ex. « chantonseneglise »
    ref        VARCHAR(60)  NOT NULL,             -- identifiant du chant sur le site source
    statut     VARCHAR(20)  NOT NULL,             -- importe | sans-paroles | erreur
    chant_id   INT UNSIGNED DEFAULT NULL,         -- ligne chants créée le cas échéant
    url        VARCHAR(255) DEFAULT NULL,
    traite_le  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (source, ref),
    KEY idx_import_journal_statut (source, statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
