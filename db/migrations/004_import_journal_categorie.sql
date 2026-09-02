-- Mémorise la « catégorie » brute lue sur le site source (champ texte libre) pour
-- pouvoir re-déduire le type interne / le libellé sans nouvelle requête réseau
-- (voir --reclassify de bin/import_chantonseneglise.php).
--
-- Migration idempotente.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_journal' AND COLUMN_NAME = 'categorie') = 0,
    'ALTER TABLE import_journal ADD COLUMN categorie VARCHAR(255) DEFAULT NULL AFTER type',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
