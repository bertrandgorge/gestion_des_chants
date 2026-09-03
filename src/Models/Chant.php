<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Chant
{
    /** Colonnes recopiées d'une section à l'autre (duplication de feuille). */
    public const FIELDS = ['titre', 'code', 'auteur', 'chant', 'nb_couplets', 'introduction', 'contenu', 'acclamation', 'reference', 'url'];

    /** @return array<int,array<string,mixed>> */
    public static function forFeuille(int $feuilleId): array
    {
        return Database::all(
            'SELECT * FROM chants WHERE feuille_id = ? ORDER BY position ASC, id ASC',
            [$feuilleId]
        );
    }

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM chants WHERE id = ?', [$id]);
    }

    /** Section + feuille + paroisse (contrôle multi-tenant). */
    public static function findForParoisse(int $id, int $paroisseId): ?array
    {
        return Database::one(
            'SELECT ch.*, f.id AS feuille_id
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             WHERE ch.id = ? AND c.paroisse_id = ?',
            [$id, $paroisseId]
        );
    }

    public static function create(array $data): int
    {
        return Database::insert('chants', $data);
    }

    public static function update(int $id, array $data): void
    {
        Database::update('chants', $data, ['id' => $id]);
    }

    public static function delete(int $id): void
    {
        Database::delete('chants', ['id' => $id]);
    }

    public static function nextPosition(int $feuilleId): int
    {
        return (int) Database::value(
            'SELECT COALESCE(MAX(position), -1) + 1 FROM chants WHERE feuille_id = ?',
            [$feuilleId]
        );
    }

    /**
     * Clé de dédoublonnage d'un chant : titre + première ligne du refrain,
     * réduits à leurs seuls caractères significatifs (lettres et chiffres, sans
     * accents ni casse). Un même chant importé de plusieurs sources — titres
     * ponctués différemment, cote Secli absente ou divergente — produit la même
     * clé ; deux chants homonymes mais distincts (refrains différents) non.
     */
    public static function cleDedup(string $titre, ?string $chant): string
    {
        return self::reduire($titre) . '|' . self::reduire(self::ligneRefrain((string) $chant));
    }

    /**
     * id d'une fiche de catalogue (feuille_id NULL, url renseignée) partageant la
     * clé de dédoublonnage, ou null. $exclureId permet d'ignorer une fiche (p. ex.
     * celle qu'on est en train de mettre à jour).
     */
    public static function catalogueParCle(string $titre, string $chant, ?int $exclureId = null): ?int
    {
        $cle = self::cleDedup($titre, $chant);
        foreach (Database::all(
            "SELECT id, titre, chant FROM chants WHERE feuille_id IS NULL AND url IS NOT NULL"
        ) as $row) {
            if ((int) $row['id'] !== $exclureId
                && self::cleDedup((string) $row['titre'], (string) $row['chant']) === $cle
            ) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    /**
     * Nombre de lignes « parasites » (crédits, copyright, mentions éditoriales)
     * dans des paroles. Sert à départager deux fiches d'un même chant : la plus
     * propre l'emporte, à couplets égaux.
     */
    public static function scoreQualite(?string $chant): int
    {
        $n = 0;
        foreach (explode("\n", (string) $chant) as $ligne) {
            $l = trim($ligne);
            if ($l === '') {
                continue;
            }
            if (str_contains($l, '©')
                || preg_match(
                    '~^(?:\(c\)\s*\d{4}'
                    . '|paroles\s+et\s+musique\b'
                    . '|(?:paroles|musique|texte|traduction|harmonisation|adaptation|arrangements?|orchestration|arr\.)\s*[:/]'
                    . '|titre original\b'
                    . '|\d{4},?\s+[ÉEée]dition)~iu',
                    $l
                )
            ) {
                $n++;
            }
        }

        return $n;
    }

    /** Réduit une chaîne à ses lettres/chiffres, sans accents ni casse. */
    private static function reduire(string $s): string
    {
        if (function_exists('transliterator_transliterate')) {
            $s = (string) transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        } else {
            $s = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
        }

        return (string) preg_replace('~[^a-z0-9]+~', '', $s);
    }

    /**
     * Première ligne du refrain d'un chant mis en forme (parties séparées par une
     * ligne vide, refrain préfixé « R/ », couplets « 1. »…). À défaut de refrain :
     * première ligne du premier couplet ; à défaut : première ligne non vide.
     */
    private static function ligneRefrain(string $chant): string
    {
        $parties = preg_split('~\n\s*\n~', trim(str_replace("\r\n", "\n", $chant))) ?: [];
        $refrain = $couplet = null;
        foreach ($parties as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if ($refrain === null && preg_match('~^R\s*/\s*(.*)~s', $p, $m)) {
                $refrain = $m[1];
            } elseif ($couplet === null && preg_match('~^\d{1,2}\s*[.)]\s*(.*)~s', $p, $m)) {
                $couplet = $m[1];
            }
        }
        $bloc = $refrain ?? $couplet ?? (string) ($parties[0] ?? '');

        return trim(explode("\n", trim($bloc))[0]);
    }

    /**
     * Recherche dans l'historique des chants d'une paroisse (feuilles passées).
     * Regroupe par clé de dédoublonnage et conserve la version avec le plus de couplets.
     *
     * Sources : les autres feuilles de la paroisse (prioritaires) et les chants
     * de catalogue importés (feuille_id NULL, url renseignée). La feuille en cours
     * d'édition est exclue via $excludeFeuilleId. Le champ « url » n'est exposé
     * qu'ici, pour l'interface chantre.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function historique(int $paroisseId, string $q, ?string $type = null, ?int $excludeFeuilleId = null): array
    {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        $historique = Database::all(
            "SELECT ch.titre, ch.code, ch.auteur, ch.chant, ch.nb_couplets, ch.type, ch.feuille_id
             FROM chants ch
             JOIN feuilles_chant f ON f.id = ch.feuille_id
             JOIN clochers c ON c.id = f.clocher_id
             WHERE c.paroisse_id = ?
               AND f.id <> ?
               AND ch.chant IS NOT NULL AND ch.chant <> ''
               AND (ch.titre LIKE ? OR ch.code LIKE ?)
             ORDER BY f.date_heure DESC
             LIMIT 300",
            [$paroisseId, $excludeFeuilleId ?? 0, $like, $like]
        );

        $catalogue = Database::all(
            "SELECT ch.titre, ch.code, ch.auteur, ch.chant, ch.nb_couplets, ch.type, ch.url
             FROM chants ch
             WHERE ch.feuille_id IS NULL AND ch.url IS NOT NULL
               AND ch.chant IS NOT NULL AND ch.chant <> ''
               AND (ch.titre LIKE ? OR ch.code LIKE ?)
             ORDER BY ch.titre ASC
             LIMIT 300",
            [$like, $like]
        );

        $groups = [];
        $typesByKey = [];
        foreach ([...$historique, ...$catalogue] as $row) {
            $key = self::cleDedup((string) $row['titre'], (string) $row['chant']);
            $typesByKey[$key][$row['type']] = true;

            // Version la plus complète : on privilégie le plus de couplets (hors refrain).
            $couplets = isset($row['nb_couplets']) && $row['nb_couplets'] !== null
                ? (int) $row['nb_couplets']
                : count_couplets($row['chant'], false);
            if (!isset($groups[$key]) || $couplets > $groups[$key]['_couplets']) {
                $groups[$key] = [
                    'titre'      => $row['titre'],
                    'code'       => $row['code'],
                    'auteur'     => $row['auteur'],
                    'chant'      => $row['chant'],
                    'feuille_id' => isset($row['feuille_id']) ? (int) $row['feuille_id'] : null,
                    'url'        => $groups[$key]['url'] ?? ($row['url'] ?? null),
                    '_couplets'  => $couplets,
                ];
            } elseif (($row['url'] ?? null) !== null && ($groups[$key]['url'] ?? null) === null) {
                $groups[$key]['url'] = $row['url'];
            }
        }

        $result = [];
        foreach ($groups as $key => $g) {
            $g['types'] = array_keys($typesByKey[$key]);
            unset($g['_couplets']);
            $result[] = $g;
        }

        // Les correspondances du type demandé d'abord.
        if ($type !== null) {
            usort($result, static fn ($a, $b) => (in_array($type, $b['types'], true) <=> in_array($type, $a['types'], true)));
        }

        return array_slice($result, 0, 20);
    }
}
