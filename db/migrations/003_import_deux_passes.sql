-- Import de catalogue en deux passes (le site source est lent) :
--   1. référencement de tous les chants dans import_journal (statut « a-importer ») ;
--   2. récupération réseau      → statut « avec-paroles » / « sans-paroles » ;
--   3. création des fiches      → statut « importe ».
-- import_journal sert de zone de préparation : on y stocke les données parsées
-- pour que la 3e passe (création des fiches) ne refasse aucune requête.
--
-- Migration idempotente.

-- 1. Colonnes de préparation dans import_journal.
SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_journal' AND COLUMN_NAME = 'titre') = 0,
    'ALTER TABLE import_journal
        ADD COLUMN titre  VARCHAR(255) DEFAULT NULL,
        ADD COLUMN code   VARCHAR(60)  DEFAULT NULL,
        ADD COLUMN auteur VARCHAR(190) DEFAULT NULL,
        ADD COLUMN type   VARCHAR(60)  DEFAULT NULL,
        ADD COLUMN nom    VARCHAR(120) DEFAULT NULL,
        ADD COLUMN chant  LONGTEXT     DEFAULT NULL',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Nombre de couplets (hors refrain) sur les fiches : privilégie la version la
--    plus complète d'un chant lors de la sélection dans l'éditeur.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chants' AND COLUMN_NAME = 'nb_couplets') = 0,
    'ALTER TABLE chants ADD COLUMN nb_couplets SMALLINT UNSIGNED DEFAULT NULL AFTER chant',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
