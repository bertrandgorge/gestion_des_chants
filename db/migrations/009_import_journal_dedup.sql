-- Colonnes dérivées facilitant le dédoublonnage (App\Models\RepertoireChant),
-- calculées et mémorisées après coup (passe d'import — bin/import_repertoire.php),
-- jamais à la récupération (ENUM/FETCH restent volontairement sommaires : pas de
-- mise en forme des paroles, pour ne jamais avoir à refaire une requête vers un
-- site tiers si la logique de dédoublonnage évolue) :
--   - titre_reduit      : titre sans accents/ponctuation/casse (App\Models\Chant::reduire) ;
--   - empreinte_paroles : empreinte des deux premières lignes des paroles
--                         (App\Models\Chant::premieresLignes), repli quand le
--                         titre seul ne suffit pas à départager des doublons.
-- Un index sur chant_id accélère aussi les recherches par fiche du répertoire
-- (App\Models\RepertoireChant::urls/aSource/mergerDans/separerImport).
--
-- Migration idempotente.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_journal' AND COLUMN_NAME = 'titre_reduit') = 0,
    'ALTER TABLE import_journal ADD COLUMN titre_reduit VARCHAR(255) DEFAULT NULL AFTER titre',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_journal' AND COLUMN_NAME = 'empreinte_paroles') = 0,
    'ALTER TABLE import_journal ADD COLUMN empreinte_paroles VARCHAR(255) DEFAULT NULL AFTER chant',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_journal' AND INDEX_NAME = 'idx_import_journal_titre_reduit') = 0,
    'ALTER TABLE import_journal ADD KEY idx_import_journal_titre_reduit (titre_reduit)',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'import_journal' AND INDEX_NAME = 'idx_import_journal_chant_id') = 0,
    'ALTER TABLE import_journal ADD KEY idx_import_journal_chant_id (chant_id)',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
