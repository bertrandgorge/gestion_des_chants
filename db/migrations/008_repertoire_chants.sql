-- Introduit la table « repertoire_chants » (schema.sql) comme seule source du
-- catalogue partagé de chants, en remplacement des lignes « chants » avec
-- feuille_id NULL. Cette migration :
--   1. ajoute la colonne chants.repertoire_id (fiche du répertoire dont le
--      chant d'une feuille est issu, le cas échéant) ;
--   2. bascule les anciennes fiches de catalogue (chants.feuille_id IS NULL)
--      dans repertoire_chants ;
--   3. reporte import_journal.chant_id sur les nouveaux id ;
--   4. supprime les anciennes lignes de catalogue de « chants ».
--
-- Migration idempotente pour l'étape 1 (gardée par information_schema, comme
-- les autres fichiers de ce dossier) ; les étapes 2-4 ne s'exécutent qu'une
-- fois puisque ce fichier n'est lui-même rejoué qu'une fois (table `migrations`).

SET @db := DATABASE();

-- 1. chants.repertoire_id + index + clé étrangère.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chants' AND COLUMN_NAME = 'repertoire_id') = 0,
    'ALTER TABLE chants ADD COLUMN repertoire_id INT UNSIGNED DEFAULT NULL AFTER feuille_id',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chants' AND INDEX_NAME = 'idx_chants_repertoire') = 0,
    'ALTER TABLE chants ADD KEY idx_chants_repertoire (repertoire_id)',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'chants' AND CONSTRAINT_NAME = 'fk_chants_repertoire') = 0,
    'ALTER TABLE chants ADD CONSTRAINT fk_chants_repertoire FOREIGN KEY (repertoire_id) REFERENCES repertoire_chants (id) ON DELETE SET NULL',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Bascule des anciennes fiches de catalogue vers repertoire_chants.
INSERT INTO repertoire_chants (titre, code, auteur, type, nom, chant, nb_couplets, created_at)
SELECT titre, code, auteur, type, nom, chant, nb_couplets, created_at
  FROM chants
 WHERE feuille_id IS NULL;

-- 3. import_journal.chant_id pointait vers l'ancien id « chants » : on le
--    reporte sur le nouveau id « repertoire_chants » du même chant (titre +
--    paroles + date de création identiques : suffisant pour ce rapprochement
--    ponctuel).
UPDATE import_journal j
  JOIN chants c ON c.id = j.chant_id AND c.feuille_id IS NULL
  JOIN repertoire_chants r
    ON r.created_at = c.created_at
   AND r.titre <=> c.titre
   AND r.chant <=> c.chant
   SET j.chant_id = r.id;

-- 4. Les anciennes fiches de catalogue n'ont plus leur place dans « chants ».
DELETE FROM chants WHERE feuille_id IS NULL;
