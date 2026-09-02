-- Ajoute le code de répertoire IEV (Emmanuel) au journal d'import et élargit la
-- clé « ref » : les slugs de catechisme-emmanuel.com peuvent dépasser 60 caractères.
--
-- Migration idempotente.

SET @db := DATABASE();

-- 1. import_journal.ref : VARCHAR(60) → VARCHAR(190).
SET @len := (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_journal' AND COLUMN_NAME = 'ref');
SET @sql := IF(@len < 190,
    'ALTER TABLE import_journal MODIFY COLUMN ref VARCHAR(190) NOT NULL',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. import_journal.code_repertoire : code IEV « IEV 19-06 » (Emmanuel), sert au
--    rapprochement entre les imports catechisme-emmanuel.com et chantonseneglise.fr.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_journal' AND COLUMN_NAME = 'code_repertoire') = 0,
    'ALTER TABLE import_journal
        ADD COLUMN code_repertoire VARCHAR(60) DEFAULT NULL AFTER code,
        ADD KEY idx_import_journal_repertoire (code_repertoire)',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
