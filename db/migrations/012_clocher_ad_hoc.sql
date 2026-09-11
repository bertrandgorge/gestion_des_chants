-- Clocher « ad hoc » : lieu ponctuel (chapelle de camp, messe en plein air…)
-- créé à la volée quand on choisit « - Autres - » à la création d'une feuille
-- (issue #8). Marqué ad_hoc = 1 : masqué de la liste des clochers (création,
-- copie, admin), supprimé quand sa dernière feuille l'est.
--
-- Migration idempotente.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clochers' AND COLUMN_NAME = 'ad_hoc') = 0,
    'ALTER TABLE clochers ADD COLUMN ad_hoc TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER slug',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
