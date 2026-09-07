<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\SectionTypes;

/**
 * Statistiques d'utilisation des chants du répertoire sur les feuilles de
 * messe. Seuls les chants liés au répertoire (chants.repertoire_id) sont
 * comptabilisés : c'est la seule clé fiable pour reconnaître un même chant
 * d'une feuille à l'autre (deux sections tapées à la main avec le même titre
 * ne sont pas nécessairement le même chant — App\Models\RepertoireChant est
 * l'identité canonique). Seules les messes déjà passées comptent (même règle
 * que App\Models\FeuilleChant::pastForParoisse).
 */
final class Statistique
{
    private const PASSEE = 'f.date_heure < (NOW() - INTERVAL 6 HOUR)';

    /**
     * Dernières messes (paroisse) où ce chant du répertoire a été repris.
     *
     * @return array<int,array{date_heure:string,clocher_nom:string}>
     */
    public static function dernieresMesses(int $repertoireId, int $paroisseId, int $limit = 5): array
    {
        return Database::all(
            'SELECT f.date_heure, c.nom AS clocher_nom
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             WHERE ch.repertoire_id = ? AND c.paroisse_id = ? AND ' . self::PASSEE . '
             ORDER BY f.date_heure DESC
             LIMIT ' . max(1, $limit),
            [$repertoireId, $paroisseId]
        );
    }

    /** Nombre de messes (paroisse) où ce chant a été repris sur les $mois derniers mois. */
    public static function nombreUtilisations(int $repertoireId, int $paroisseId, int $mois = 12): int
    {
        return (int) Database::value(
            'SELECT COUNT(*)
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             WHERE ch.repertoire_id = ? AND c.paroisse_id = ?
               AND ' . self::PASSEE . '
               AND f.date_heure >= (NOW() - INTERVAL ? MONTH)',
            [$repertoireId, $paroisseId, $mois]
        );
    }

    /**
     * Chants du répertoire les plus utilisés pour une section (paroisse) sur
     * les $mois derniers mois, du plus au moins utilisé.
     *
     * @return array<int,array{repertoire_id:int,titre:string,nom:?string,utilisations:int}>
     */
    public static function topSection(string $type, int $paroisseId, int $limit = 5, int $mois = 12): array
    {
        return Database::all(
            'SELECT r.id AS repertoire_id, r.titre, r.nom, COUNT(*) AS utilisations
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             JOIN repertoire_chants r ON r.id = ch.repertoire_id
             WHERE r.type = ? AND c.paroisse_id = ?
               AND ' . self::PASSEE . '
               AND f.date_heure >= (NOW() - INTERVAL ? MONTH)
             GROUP BY r.id, r.titre, r.nom
             ORDER BY utilisations DESC, r.titre ASC
             LIMIT ' . max(1, $limit),
            [$type, $paroisseId, $mois]
        );
    }

    /**
     * Top général (toutes sections confondues) sur $mois mois. $paroisseId
     * null = toute la base (toutes paroisses confondues).
     *
     * Exclut les ordinaires de messe (Kyrie, Gloria, Sanctus, Anamnèse, Agnus —
     * App\SectionTypes::ORDINAIRE) : leur répertoire est un petit ensemble figé
     * par messe (quelques dizaines de fiches en tout), repris à l'identique
     * semaine après semaine, alors que les chants proprement dits (entrée,
     * communion…) se choisissent parmi des milliers de fiches. Mélangés, les
     * ordinaires écraseraient systématiquement le classement par leur nombre
     * de reprises sans rapport avec leur popularité réelle ; ils restent
     * visibles individuellement via topParSection().
     *
     * @return array<int,array{repertoire_id:int,titre:string,nom:?string,type:string,utilisations:int}>
     */
    public static function topGeneral(?int $paroisseId, int $limit = 10, int $mois = 12): array
    {
        [$where, $params] = self::filtreParoisse($paroisseId);
        [$exclOrdinaire, $paramsOrdinaire] = self::exclureOrdinaire();
        $params = [...$params, ...$paramsOrdinaire, $mois];

        return Database::all(
            "SELECT r.id AS repertoire_id, r.titre, r.nom, r.type, COUNT(*) AS utilisations
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             JOIN repertoire_chants r ON r.id = ch.repertoire_id
             WHERE $where AND $exclOrdinaire AND " . self::PASSEE . '
               AND f.date_heure >= (NOW() - INTERVAL ? MONTH)
             GROUP BY r.id, r.titre, r.nom, r.type
             ORDER BY utilisations DESC, r.titre ASC
             LIMIT ' . max(1, $limit),
            $params
        );
    }

    /**
     * Top par section (jusqu'à $limit par type) sur $mois mois, regroupé par
     * type de section — seuls les types ayant au moins une utilisation
     * figurent dans le résultat.
     *
     * @return array<string,array<int,array{repertoire_id:int,titre:string,nom:?string,utilisations:int}>>
     */
    public static function topParSection(?int $paroisseId, int $limit = 5, int $mois = 12): array
    {
        [$where, $params] = self::filtreParoisse($paroisseId);
        $params[] = $mois;

        $rows = Database::all(
            "SELECT r.type, r.id AS repertoire_id, r.titre, r.nom, COUNT(*) AS utilisations
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             JOIN repertoire_chants r ON r.id = ch.repertoire_id
             WHERE $where AND " . self::PASSEE . '
               AND f.date_heure >= (NOW() - INTERVAL ? MONTH)
             GROUP BY r.type, r.id, r.titre, r.nom
             ORDER BY r.type ASC, utilisations DESC, r.titre ASC',
            $params
        );

        $parType = [];
        foreach ($rows as $row) {
            $type = (string) $row['type'];
            if (!isset($parType[$type])) {
                $parType[$type] = [];
            }
            if (count($parType[$type]) < $limit) {
                $parType[$type][] = $row;
            }
        }

        return $parType;
    }

    /**
     * Chants introduits dans les $jours derniers jours (première utilisation
     * jamais antérieure à cette fenêtre), groupés par section.
     *
     * @return array<string,array<int,array{repertoire_id:int,titre:string,nom:?string,premiere_utilisation:string}>>
     */
    public static function nouveauxParSection(?int $paroisseId, int $jours = 30): array
    {
        [$where, $params] = self::filtreParoisse($paroisseId);
        $params[] = $jours;

        $rows = Database::all(
            "SELECT r.type, r.id AS repertoire_id, r.titre, r.nom, MIN(f.date_heure) AS premiere_utilisation
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             JOIN repertoire_chants r ON r.id = ch.repertoire_id
             WHERE $where AND " . self::PASSEE . '
             GROUP BY r.type, r.id, r.titre, r.nom
             HAVING premiere_utilisation >= (NOW() - INTERVAL ? DAY)
             ORDER BY r.type ASC, premiere_utilisation DESC',
            $params
        );

        $parType = [];
        foreach ($rows as $row) {
            $parType[(string) $row['type']][] = $row;
        }

        return $parType;
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private static function filtreParoisse(?int $paroisseId): array
    {
        return $paroisseId !== null ? ['c.paroisse_id = ?', [$paroisseId]] : ['1=1', []];
    }

    /** @return array{0:string,1:array<int,string>} */
    private static function exclureOrdinaire(): array
    {
        $placeholders = implode(',', array_fill(0, count(SectionTypes::ORDINAIRE), '?'));

        return ["r.type NOT IN ($placeholders)", SectionTypes::ORDINAIRE];
    }
}
