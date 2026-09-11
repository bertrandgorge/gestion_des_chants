<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;
use DateTimeImmutable;

final class Clocher
{
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM clochers WHERE id = ?', [$id]);
    }

    /** Clocher appartenant à la paroisse (sécurité multi-tenant). */
    public static function findForParoisse(int $id, int $paroisseId): ?array
    {
        return Database::one(
            'SELECT * FROM clochers WHERE id = ? AND paroisse_id = ?',
            [$id, $paroisseId]
        );
    }

    /**
     * Clochers « normaux » de la paroisse (hors lieux ponctuels ad hoc, cf.
     * self::trouverOuCreerAdHoc) : listes de création / copie / admin.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forParoisse(int $paroisseId): array
    {
        return Database::all(
            'SELECT * FROM clochers WHERE paroisse_id = ? AND ad_hoc = 0 ORDER BY nom',
            [$paroisseId]
        );
    }

    /**
     * Clocher « ad hoc » pour un lieu ponctuel saisi en texte libre à la
     * création/copie d'une feuille (« - Autres - », issue #8). Réutilise une
     * fiche existante de même nom, sinon en crée une avec un slug unique.
     */
    public static function trouverOuCreerAdHoc(int $paroisseId, string $nom): int
    {
        $existant = Database::one(
            'SELECT id FROM clochers WHERE paroisse_id = ? AND ad_hoc = 1 AND LOWER(nom) = LOWER(?)',
            [$paroisseId, $nom]
        );
        if ($existant !== null) {
            return (int) $existant['id'];
        }

        $base = slugify($nom);
        $slug = $base;
        $i = 2;
        while (self::slugExists($paroisseId, $slug)) {
            $slug = $base . '-' . $i++;
        }

        return self::create([
            'paroisse_id' => $paroisseId,
            'nom'         => $nom,
            'slug'        => $slug,
            'ad_hoc'      => 1,
        ]);
    }

    /** Supprime un clocher ad hoc devenu orphelin (plus aucune feuille). */
    public static function supprimerAdHocSansFeuille(int $id): void
    {
        $clocher = self::find($id);
        if ($clocher !== null && (int) $clocher['ad_hoc'] === 1 && !self::hasFeuilles($id)) {
            self::delete($id);
        }
    }

    public static function findBySlugs(string $paroisseSlug, string $clocherSlug): ?array
    {
        return Database::one(
            'SELECT c.* FROM clochers c
             JOIN paroisses p ON p.id = c.paroisse_id
             WHERE p.slug = ? AND c.slug = ?',
            [$paroisseSlug, $clocherSlug]
        );
    }

    public static function slugExists(int $paroisseId, string $slug, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM clochers WHERE paroisse_id = ? AND slug = ?';
        $params = [$paroisseId, $slug];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }

        return (int) Database::value($sql, $params) > 0;
    }

    public static function create(array $data): int
    {
        return Database::insert('clochers', $data);
    }

    public static function update(int $id, array $data): void
    {
        Database::update('clochers', $data, ['id' => $id]);
    }

    public static function hasFeuilles(int $id): bool
    {
        return (int) Database::value('SELECT COUNT(*) FROM feuilles_chant WHERE clocher_id = ?', [$id]) > 0;
    }

    public static function delete(int $id): void
    {
        Database::delete('clochers', ['id' => $id]);
    }

    /**
     * Prochaine occurrence (>= maintenant) du jour/heure par défaut du clocher.
     * Retourne « demain 18:00 » par défaut si le clocher n'a pas de valeur.
     */
    public static function prochaineDateParDefaut(array $clocher, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now = $now ?? new DateTimeImmutable('now');
        $jour = $clocher['jour_defaut'] !== null ? (int) $clocher['jour_defaut'] : null;
        $heure = $clocher['heure_defaut'] ?? null;

        if ($jour === null || $heure === null) {
            return $now->modify('+1 day')->setTime(18, 0);
        }

        [$h, $m] = array_map('intval', explode(':', (string) $heure));
        $candidate = $now->setTime($h, $m);
        // ISO-8601 : N = 1 (lundi) .. 7 (dimanche)
        $delta = ($jour - (int) $candidate->format('N') + 7) % 7;
        $candidate = $candidate->modify("+{$delta} day");
        if ($candidate <= $now) {
            $candidate = $candidate->modify('+7 day');
        }

        return $candidate;
    }
}
