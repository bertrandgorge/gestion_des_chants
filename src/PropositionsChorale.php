<?php

declare(strict_types=1);

namespace App;

use App\Import\AnimationChorale;
use App\Import\ChoralePoleFontainebleau;
use App\Models\RepertoireChant;

/**
 * Propositions de chants du site de la Chorale du Pôle Missionnaire de
 * Fontainebleau (« Propositions pour l'animation liturgique ») pour une feuille
 * de messe donnée, rapprochées du répertoire local.
 *
 * L'analyse HTML pure est dans App\Import\AnimationChorale ; ici : récupération
 * réseau (mise en cache disque, comme App\Aelf), résolution de la fiche du
 * dimanche via l'agenda, et jointure avec import_journal / repertoire_chants.
 */
final class PropositionsChorale
{
    /** Source dans import_journal (voir bin/import_chorale_pole_fontainebleau.php). */
    private const SOURCE = 'chorale-pole-fontainebleau';

    private const TTL_AGENDA = 21600;   // 6 h
    private const TTL_EVENEMENT = 86400; // 24 h

    /**
     * @param array<string,mixed> $feuille  ligne feuilles_chant (aelf_date requis)
     * @return array{ok:bool, url:?string, chants:list<array{titre:string,url:string,repertoire_id:?int,code:string,auteur:string,chant:string,ordinaire:?string}>}
     */
    public static function suggestions(array $feuille, string $type): array
    {
        $vide = ['ok' => true, 'url' => null, 'chants' => []];

        $rubrique = AnimationChorale::rubriquePourType($type);
        if ($rubrique === null) {
            return $vide;
        }

        $date = trim((string) ($feuille['aelf_date'] ?? ''));
        if ($date === '') {
            return ['ok' => false, 'url' => null, 'chants' => []];
        }

        try {
            $urlEvenement = self::urlEvenement($date);
            if ($urlEvenement === null) {
                return ['ok' => false, 'url' => null, 'chants' => []];
            }

            $html = self::telecharger($urlEvenement, self::TTL_EVENEMENT);
            if ($html === null) {
                return ['ok' => false, 'url' => $urlEvenement, 'chants' => []];
            }

            $rubriques = AnimationChorale::parseEvenement($html);
            $proposes = $rubriques[$rubrique] ?? [];

            return [
                'ok'     => true,
                'url'    => $urlEvenement,
                'chants' => self::resoudre($proposes),
            ];
        } catch (\Throwable $e) {
            error_log('[gdc] propositions chorale : ' . $e->getMessage());

            return ['ok' => false, 'url' => null, 'chants' => []];
        }
    }

    /** URL de la fiche du dimanche $date (AAAA-MM-JJ) d'après l'agenda, ou null. */
    private static function urlEvenement(string $date): ?string
    {
        foreach (['', '?pno=2'] as $suffixe) {
            $html = self::telecharger(AnimationChorale::AGENDA_URL . $suffixe, self::TTL_AGENDA);
            if ($html === null) {
                continue;
            }
            $agenda = AnimationChorale::parseAgenda($html);
            if (isset($agenda[$date])) {
                return $agenda[$date];
            }
        }

        return null;
    }

    /**
     * Rapproche chaque chant proposé du répertoire local via import_journal
     * (source chorale-pole-fontainebleau, ref = identifiant numérique de la fiche).
     *
     * @param list<array{titre:string,url:string,ref:string}> $proposes
     * @return list<array{titre:string,url:string,repertoire_id:?int,code:string,auteur:string,chant:string,ordinaire:?string}>
     */
    private static function resoudre(array $proposes): array
    {
        if ($proposes === []) {
            return [];
        }

        $refs = array_values(array_unique(array_column($proposes, 'ref')));
        $placeholders = implode(',', array_fill(0, count($refs), '?'));
        $liens = Database::all(
            "SELECT ref, chant_id FROM import_journal
             WHERE source = ? AND ref IN ($placeholders) AND chant_id IS NOT NULL",
            [self::SOURCE, ...$refs]
        );
        $parRef = [];
        foreach ($liens as $l) {
            $parRef[(string) $l['ref']] = (int) $l['chant_id'];
        }

        $out = [];
        foreach ($proposes as $chant) {
            $repertoireId = $parRef[$chant['ref']] ?? null;
            $fiche = $repertoireId !== null ? RepertoireChant::find($repertoireId) : null;

            $out[] = [
                'titre'         => $fiche['titre'] ?? $chant['titre'],
                'url'           => $chant['url'],
                'repertoire_id' => $fiche !== null ? (int) $fiche['id'] : null,
                'code'          => (string) ($fiche['code'] ?? ''),
                'auteur'        => (string) ($fiche['auteur'] ?? ''),
                'chant'         => (string) ($fiche['chant'] ?? ''),
                'ordinaire'     => $fiche['ordinaire'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * GET HTTP avec cache disque (storage/cache), sur le modèle de App\Aelf.
     * Renvoie le corps ou null en cas d'échec.
     */
    private static function telecharger(string $url, int $ttl): ?string
    {
        $cacheFile = APP_ROOT . '/storage/cache/chorale-' . md5($url) . '.html';
        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < $ttl) {
            $cached = file_get_contents($cacheFile);
            if ($cached !== false) {
                return $cached;
            }
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'GestionDesChants/1.0 (paroisse)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html'],
            CURLOPT_ENCODING       => '',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            // Repli sur un cache périmé plutôt que rien.
            if (is_file($cacheFile)) {
                $stale = file_get_contents($cacheFile);

                return $stale === false ? null : $stale;
            }

            return null;
        }

        @file_put_contents($cacheFile, (string) $body);

        return (string) $body;
    }
}
