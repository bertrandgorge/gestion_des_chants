-- Regroupement des chants d'un même ordinaire de messe (ex. « ordinaire de
-- Saint Jean » : Kyrie, Gloria, Alléluia, Sanctus, Anamnèse, Agnus partageant
-- une même musique) au sein du répertoire partagé — voir App\Models\RepertoireChant
-- et l'import bin/import_emmanuel_ordinaires.php.
--
-- Migration idempotente.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'repertoire_chants' AND COLUMN_NAME = 'ordinaire') = 0,
    'ALTER TABLE repertoire_chants ADD COLUMN ordinaire VARCHAR(190) DEFAULT NULL AFTER nom',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'repertoire_chants' AND INDEX_NAME = 'idx_repertoire_ordinaire') = 0,
    'ALTER TABLE repertoire_chants ADD KEY idx_repertoire_ordinaire (ordinaire)',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_journal' AND COLUMN_NAME = 'ordinaire') = 0,
    'ALTER TABLE import_journal ADD COLUMN ordinaire VARCHAR(190) DEFAULT NULL AFTER nom',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
