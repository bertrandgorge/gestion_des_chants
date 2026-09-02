-- Supprime la colonne « logo » de la table paroisses : la gestion du logo de
-- paroisse a été retirée.
--
-- Migration idempotente.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'paroisses' AND COLUMN_NAME = 'logo') = 1,
    'ALTER TABLE paroisses DROP COLUMN logo',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
