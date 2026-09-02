<?php

declare(strict_types=1);

namespace App;

use App\Models\Chant;
use App\Models\FeuilleChant;
use DateTimeImmutable;

/**
 * Création / copie / resynchronisation d'une feuille de messe.
 */
final class FeuilleService
{
    /** Types de sections dont le contenu provient d'AELF. */
    private const SECTIONS_AELF = ['premiere_lecture', 'deuxieme_lecture', 'psaume', 'evangile'];

    /** Crée une feuille vierge : sections par défaut + snapshot AELF. */
    public static function creer(int $chantreId, int $clocherId, DateTimeImmutable $dateHeure): int
    {
        $aelf = Aelf::snapshot($dateHeure);

        return Database::transaction(function () use ($chantreId, $clocherId, $dateHeure, $aelf) {
            $feuilleId = FeuilleChant::create([
                'chantre_id'       => $chantreId,
                'clocher_id'       => $clocherId,
                'date_heure'       => $dateHeure->format('Y-m-d H:i:s'),
                'annee'            => $aelf['informations']['annee'] ?? null,
                'semaine'          => $aelf['informations']['semaine'] ?? null,
                'couleur'          => $aelf['informations']['couleur'] ?? null,
                'titre_liturgique' => $aelf['informations']['titre_liturgique'] ?? null,
                'aelf_date'        => $aelf['aelf_date'],
                'aelf_json'        => $aelf['raw'],
            ]);

            $position = 0;
            foreach (SectionTypes::DEFAUT as $section) {
                $data = [
                    'feuille_id' => $feuilleId,
                    'nom'        => $section['nom'],
                    'type'       => $section['type'],
                    'position'   => $position++,
                ];
                $data += self::donneesAelfPour($section['type'], $aelf['sections']);
                Chant::create($data);
            }

            return $feuilleId;
        });
    }

    /**
     * Copie une feuille vers un nouveau clocher / une nouvelle date.
     * Les chants sont repris tels quels ; les lectures sont recalculées depuis AELF.
     */
    public static function copier(array $source, int $chantreId, int $clocherId, DateTimeImmutable $dateHeure): int
    {
        $aelf = Aelf::snapshot($dateHeure);
        $sectionsSource = Chant::forFeuille((int) $source['id']);

        return Database::transaction(function () use ($source, $chantreId, $clocherId, $dateHeure, $aelf, $sectionsSource) {
            $feuilleId = FeuilleChant::create([
                'chantre_id'       => $chantreId,
                'clocher_id'       => $clocherId,
                'date_heure'       => $dateHeure->format('Y-m-d H:i:s'),
                'annee'            => $aelf['informations']['annee'] ?? null,
                'semaine'          => $aelf['informations']['semaine'] ?? null,
                'couleur'          => $aelf['informations']['couleur'] ?? null,
                'titre_liturgique' => $aelf['informations']['titre_liturgique'] ?? null,
                'aelf_date'        => $aelf['aelf_date'],
                'aelf_json'        => $aelf['raw'],
            ]);

            foreach ($sectionsSource as $section) {
                $data = [
                    'feuille_id' => $feuilleId,
                    'nom'        => $section['nom'],
                    'type'       => $section['type'],
                    'position'   => $section['position'],
                ];

                if (in_array($section['type'], self::SECTIONS_AELF, true)) {
                    $data += self::donneesAelfPour($section['type'], $aelf['sections']);
                } else {
                    foreach (Chant::FIELDS as $f) {
                        $data[$f] = $section[$f];
                    }
                }
                Chant::create($data);
            }

            return $feuilleId;
        });
    }

    /** Recharge les infos AELF et écrase les sections de lecture. */
    public static function resync(array $feuille): bool
    {
        $dateHeure = new DateTimeImmutable($feuille['date_heure']);
        $aelf = Aelf::snapshot($dateHeure);
        if (!$aelf['ok']) {
            return false;
        }

        Database::transaction(function () use ($feuille, $aelf) {
            FeuilleChant::update((int) $feuille['id'], [
                'annee'            => $aelf['informations']['annee'] ?? $feuille['annee'],
                'semaine'          => $aelf['informations']['semaine'] ?? $feuille['semaine'],
                'couleur'          => $aelf['informations']['couleur'] ?? $feuille['couleur'],
                'titre_liturgique' => $aelf['informations']['titre_liturgique'] ?? $feuille['titre_liturgique'],
                'aelf_date'        => $aelf['aelf_date'],
                'aelf_json'        => $aelf['raw'],
            ]);

            foreach (Chant::forFeuille((int) $feuille['id']) as $section) {
                if (!in_array($section['type'], self::SECTIONS_AELF, true)) {
                    continue;
                }
                $data = self::donneesAelfPour($section['type'], $aelf['sections']);
                if ($data !== []) {
                    Chant::update((int) $section['id'], $data);
                }
            }
        });

        return true;
    }

    /** @return array<string,string> */
    private static function donneesAelfPour(string $type, array $sectionsAelf): array
    {
        $src = $sectionsAelf[$type] ?? null;
        if ($src === null) {
            return [];
        }

        $data = [];
        foreach (['titre', 'reference', 'introduction', 'contenu', 'acclamation', 'chant'] as $f) {
            if (array_key_exists($f, $src)) {
                $data[$f] = $src[$f];
            }
        }

        return $data;
    }
}
