-- Impression de la feuille de chant : mémorise, par paroisse, quelles sections
-- sont cochées pour l'impression (JSON « type de section => booléen »).
-- Réutilisé d'une feuille à l'autre pour préremplir la sélection.
--
-- Migration idempotente.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'paroisses' AND COLUMN_NAME = 'impression_sections') = 0,
    'ALTER TABLE paroisses ADD COLUMN impression_sections TEXT DEFAULT NULL AFTER slug',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
