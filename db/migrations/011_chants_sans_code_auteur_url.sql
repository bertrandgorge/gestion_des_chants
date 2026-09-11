-- Une section de chant tire désormais son code (cote Secli…), son auteur et ses
-- URL de partition de la fiche du répertoire à laquelle elle est liée
-- (chants.repertoire_id → repertoire_chants). Les colonnes chants.code /
-- chants.auteur / chants.url ne servaient plus qu'à dupliquer ces informations
-- et pouvaient diverger : on les supprime (issue #14).
--
-- Migration idempotente.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chants' AND INDEX_NAME = 'idx_chants_code') > 0,
    'ALTER TABLE chants DROP KEY idx_chants_code',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chants' AND INDEX_NAME = 'idx_chants_url') > 0,
    'ALTER TABLE chants DROP KEY idx_chants_url',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chants' AND COLUMN_NAME = 'code') = 1,
    'ALTER TABLE chants DROP COLUMN code',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chants' AND COLUMN_NAME = 'auteur') = 1,
    'ALTER TABLE chants DROP COLUMN auteur',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chants' AND COLUMN_NAME = 'url') = 1,
    'ALTER TABLE chants DROP COLUMN url',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
