<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Helpers de nettoyage / mise en forme des paroles, communs à tous les imports
 * (chantonseneglise.fr, catechisme-emmanuel.com…). Fonctions pures.
 */
final class Paroles
{
    /**
     * Met en forme le texte d'un chant au format attendu par l'application :
     *   - un refrain commence par « R/ » ;
     *   - un couplet commence par « 1. », « 2. »… ;
     *   - les parties sont séparées par une ligne vide, les vers par un simple saut.
     *
     * Sur les sites sources, ces indications sont des lignes isolées
     * (« REFRAIN », « 1 », « R. »…). Fonction idempotente.
     */
    public static function format(string $texte): string
    {
        $texte  = str_replace("\r\n", "\n", $texte);
        $parts  = [];
        $bloc   = null;   // ['prefixe' => string, 'vers' => list<string>]

        $flush = static function () use (&$parts, &$bloc): void {
            if ($bloc !== null && implode('', array_map('trim', $bloc['vers'])) !== '') {
                $parts[] = $bloc;
            }
            $bloc = null;
        };

        foreach (explode("\n", $texte) as $ligne) {
            $l = trim($ligne);
            if ($l === '') {
                $flush();
                continue;
            }

            [$prefixe, $reste] = self::marqueurPartie($l);
            if ($prefixe !== null) {
                $flush();
                $bloc = ['prefixe' => $prefixe, 'vers' => []];
                if ($reste !== '') {
                    $bloc['vers'][] = $reste;
                }
                continue;
            }

            $bloc ??= ['prefixe' => '', 'vers' => []];
            $bloc['vers'][] = $l;
        }
        $flush();

        $rendu = [];
        foreach ($parts as $p) {
            if ($p['vers'] === []) {
                continue;
            }
            $p['vers'][0] = $p['prefixe'] . $p['vers'][0];
            $rendu[] = implode("\n", $p['vers']);
        }

        return implode("\n\n", $rendu);
    }

    /**
     * Repère une ligne « marqueur de partie » et renvoie le préfixe à appliquer.
     *
     *   REFRAIN / R/ / R. / R- / R:  → « R/ »
     *   1 / 1. / 1) / 1- / 1/ / 1:  / Couplet 2  → « 1. » (etc.)
     *
     * @return array{0:?string,1:string} [préfixe ou null, texte restant sur la ligne]
     */
    private static function marqueurPartie(string $ligne): array
    {
        if (preg_match('~^(?:REFRAIN|R[EÉ]FRAIN|REFR\.?|R[ÉE]F\.?|R\s*[/.:–—\-])\s*[:./)–—\-]*\s*(.*)$~iu', $ligne, $m)) {
            return ['R/ ', trim($m[1])];
        }
        if (preg_match('~^(?:couplet|strophe)\s+(\d{1,2})\b[\s:./)–—\-]*(.*)$~iu', $ligne, $m)) {
            return [$m[1] . '. ', trim($m[2])];
        }
        if (preg_match('~^(\d{1,2})$~', $ligne, $m)) {
            return [$m[1] . '. ', ''];
        }
        // Un nombre suivi d'au moins une ponctuation séparatrice ( . ) ° - – — / : * _ ),
        // pas d'un chiffre (pour ne pas casser « 1000 à chanter »).
        if (preg_match('~^(\d{1,2})\s*[.)°:/*_ –—-]*[.)°:/*_–—-]\s*(\S.*)$~u', $ligne, $m)) {
            return [$m[1] . '. ', trim($m[2])];
        }

        return [null, ''];
    }

    /** HTML → texte sur une seule ligne. */
    public static function ligne(string $html): string
    {
        return trim((string) preg_replace('~\s+~u', ' ', self::multiligne($html)));
    }

    /** HTML → texte, en préservant les sauts de ligne (`<br>`, `</p>`, `<li>`). */
    public static function multiligne(string $html): string
    {
        $texte = str_replace(["\r\n", "\r"], "\n", $html);
        // Numérotation en liste ordonnée (fréquent sur catechisme-emmanuel.com) →
        // marqueur de couplet exploité par self::format().
        $texte = (string) preg_replace('~<ol\b[^>]*\bstart="(\d+)"[^>]*>\s*<li\b[^>]*>~i', "\n\n\$1. ", $texte);
        $texte = (string) preg_replace('~<ol\b[^>]*>\s*<li\b[^>]*>~i', "\n\n1. ", $texte);
        $texte = (string) preg_replace('~</li>\s*<li\b[^>]*>~i', "\n", $texte);
        // Bloc vide → séparateur de partie (ligne blanche voulue).
        $texte = (string) preg_replace('~<(?:p|div)\b[^>]*>(?:\s|&nbsp;|&#160;)*</(?:p|div)>~i', "\n\n", $texte);
        // Frontière entre deux blocs → simple saut de ligne : beaucoup de fiches
        // mettent un vers (voire une réponse d'assemblée) par bloc.
        $texte = (string) preg_replace('~</(?:p|li|div|h[1-6])\s*>\s*<(?:p|li|div|ol|ul|h[1-6])\b[^>]*>~i', "\n", $texte);
        // « ligne<br />\n » → un seul saut de ligne (le \n de la source est de la
        // mise en forme HTML) ; « <br /><br /> » → ligne vide (séparateur de partie).
        $texte = (string) preg_replace('~[ \t]*<br\s*/?>[ \t]*\n?~i', "\n", $texte);
        // Blocs restants (fin de refrain, dernier couplet…) → saut de ligne.
        $texte = (string) preg_replace('~</(?:p|li|ol|ul|h[1-6]|div)\s*>~i', "\n", $texte);
        $texte = (string) preg_replace('~<[^>]+>~', '', $texte);
        // Certaines fiches sont doublement encodées (« &amp;#8201; ») : 2 passes suffisent.
        for ($i = 0; $i < 2 && preg_match('~&(#\d+|#x[0-9a-f]+|[a-z]+);~i', $texte); $i++) {
            $texte = html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $texte = str_replace(
            ["\\'", "\u{00A0}", "\u{2009}", "\u{202F}", "\u{200B}", "\u{00B4}"],
            ["'", ' ', ' ', ' ', '', "'"],
            $texte
        );
        $texte = (string) preg_replace('~[ \t]+~', ' ', $texte);
        $texte = (string) preg_replace('~ *\n *~', "\n", $texte);
        $texte = (string) preg_replace('~\n{3,}~', "\n\n", $texte);

        return trim($texte);
    }

    public static function sansAccents(string $s): string
    {
        return strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
            'û' => 'u', 'ù' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
            '’' => "'", '‘' => "'",
        ]);
    }

    /** Fusionne plusieurs listes d'auteurs (« A, B » + « B / C » → « A / B / C »). */
    public static function fusionneAuteurs(string ...$auteurs): string
    {
        $noms = [];
        foreach ($auteurs as $bloc) {
            foreach (preg_split('~\s*[,;/]\s*~', $bloc, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $nom) {
                $nom = trim($nom);
                if ($nom !== '' && !in_array($nom, $noms, true)) {
                    $noms[] = $nom;
                }
            }
        }

        return implode(' / ', $noms);
    }
}
