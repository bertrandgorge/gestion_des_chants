<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;

/**
 * Client de l'API AELF (https://api.aelf.org) + conversion vers les sections d'une feuille.
 *
 * Les réponses sont mises en cache sur disque (storage/cache) pour ménager l'API.
 */
final class Aelf
{
    private const BASE = 'https://api.aelf.org/v1';
    private const TTL = 43200; // 12 h

    /**
     * Récupère et prépare les données AELF pour la messe donnée.
     *
     * @return array{
     *   ok: bool,
     *   aelf_date: string,
     *   informations: array<string,mixed>,
     *   sections: array<string,array<string,string>>,
     *   raw: ?string,
     *   erreur: ?string
     * }
     */
    public static function snapshot(DateTimeImmutable $messe): array
    {
        $date = Liturgy::dateLiturgique($messe)->format('Y-m-d');

        $out = [
            'ok'           => false,
            'aelf_date'    => $date,
            'informations' => [],
            'sections'     => [],
            'raw'          => null,
            'erreur'       => null,
        ];

        try {
            $messes = self::fetch("/messes/{$date}/france");
            $infos = self::fetch("/informations/{$date}/france");
        } catch (\Throwable $e) {
            $out['erreur'] = $e->getMessage();

            return $out;
        }

        $out['raw'] = json_encode(
            ['messes' => $messes, 'informations' => $infos['informations'] ?? null],
            JSON_UNESCAPED_UNICODE
        ) ?: null;
        $out['informations'] = self::parseInformations($infos['informations'] ?? []);

        $messe = self::choisirMesse($messes['messes'] ?? []);
        if ($messe !== null) {
            $out['sections'] = self::parseLectures($messe['lectures'] ?? []);
            $out['ok'] = true;
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function fetch(string $path): array
    {
        $cacheFile = APP_ROOT . '/storage/cache/aelf-' . md5($path) . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < self::TTL) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $ch = curl_init(self::BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'GestionDesChants/1.0 (paroisse)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new \RuntimeException("AELF {$path} : HTTP {$status} {$err}");
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("AELF {$path} : réponse illisible");
        }

        @file_put_contents($cacheFile, $body);

        return $data;
    }

    /** @param array<int,array<string,mixed>> $messes */
    private static function choisirMesse(array $messes): ?array
    {
        foreach ($messes as $m) {
            if (($m['nom'] ?? '') === 'Messe du jour') {
                return $m;
            }
        }

        return $messes[0] ?? null;
    }

    /** @return array<string,mixed> */
    private static function parseInformations(array $infos): array
    {
        return [
            'annee'            => $infos['annee'] ?? null,
            'semaine'          => $infos['semaine'] ?? null,
            'couleur'          => $infos['couleur'] ?? null,
            'titre_liturgique' => $infos['jour_liturgique_nom'] ?? ($infos['ligne1'] ?? null),
        ];
    }

    /**
     * Transforme les lectures AELF en données de sections indexées par type de section.
     *
     * @param array<int,array<string,mixed>> $lectures
     * @return array<string,array<string,string>>
     */
    private static function parseLectures(array $lectures): array
    {
        $map = [
            'lecture_1' => 'premiere_lecture',
            'lecture_2' => 'deuxieme_lecture',
            'psaume'    => 'psaume',
            'evangile'  => 'evangile',
        ];

        $sections = [];
        foreach ($lectures as $lec) {
            $type = $lec['type'] ?? '';
            if (!isset($map[$type])) {
                continue;
            }
            $sectionType = $map[$type];

            if ($sectionType === 'psaume') {
                $sections[$sectionType] = [
                    'titre'     => self::texteBrut($lec['titre'] ?? 'Psaume'),
                    'reference' => self::texteBrut($lec['ref'] ?? ''),
                    'chant'     => self::psaumeVersTexte($lec),
                ];
                continue;
            }

            $data = [
                'titre'        => self::texteBrut($lec['titre'] ?? ''),
                'reference'    => self::texteBrut($lec['ref'] ?? ''),
                'introduction' => self::texteBrut($lec['intro_lue'] ?? ''),
                'contenu'      => (string) ($lec['contenu'] ?? ''),
            ];

            if ($sectionType === 'evangile') {
                $acc = trim((string) ($lec['verset_evangile'] ?? ''));
                if (($lec['ref_verset'] ?? '') !== '') {
                    $acc .= "\n<p><em>" . e((string) $lec['ref_verset']) . '</em></p>';
                }
                $data['acclamation'] = $acc;
            }

            $sections[$sectionType] = $data;
        }

        return $sections;
    }

    /** Champ texte AELF : entités décodées, espaces insécables normalisés, rognage. */
    private static function texteBrut(mixed $value): string
    {
        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $text); // nbsp, narrow nbsp
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return trim($text);
    }

    /** Refrain psalmique + strophes -> texte « R/ ... » + couplets. */
    private static function psaumeVersTexte(array $lec): string
    {
        $refrain = self::htmlVersTexte((string) ($lec['refrain_psalmique'] ?? ''));
        $strophes = self::htmlVersTexte((string) ($lec['contenu'] ?? ''));

        $parts = [];
        if ($refrain !== '') {
            $parts[] = 'R/ ' . str_replace("\n\n", "\n", $refrain);
        }
        if ($strophes !== '') {
            $parts[] = $strophes;
        }

        return implode("\n\n", $parts);
    }

    /** Convertit un fragment HTML AELF en texte : <p> -> double saut, <br> -> saut. */
    public static function htmlVersTexte(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $html = preg_replace('#\s*<br\s*/?>\s*#i', "\n", $html) ?? $html;
        $html = preg_replace('#</p>\s*<p[^>]*>#i', ' §§STROPHE§§ ', $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text); // &nbsp;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\s*§§STROPHE§§\s*/', "\n\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
