<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Analyse des pages du site « Chantons en Église » (https://www.chantonseneglise.fr).
 *
 * Uniquement des fonctions pures (HTML → données) : la récupération réseau et
 * l'écriture en base sont gérées par bin/import_chantonseneglise.php.
 *
 *  - le catalogue par lettre : /catalogue/chant/A ... /catalogue/chant/Z
 *    (limité à 1000 résultats ; on redécoupe alors par préfixe : /catalogue/chant/AB)
 *  - la fiche paroles : /voir-texte/{id} (titre, cote Secli, auteurs, type, texte)
 */
final class ChantonsEnEglise
{
    public const BASE_URL = 'https://www.chantonseneglise.fr';

    /** Le catalogue par préfixe est tronqué à ce nombre de résultats. */
    public const LIMITE_CATALOGUE = 1000;

    /** Caractères testés pour redécouper un préfixe de catalogue tronqué. */
    public const CARACTERES_PREFIXE = [
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M',
        'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', "'", ' ', '-',
    ];

    /**
     * Extrait la liste des chants d'une page de catalogue.
     *
     * @return array<int,array{id:int,slug:string,titre:string}> indexé par id
     */
    public static function parseCatalogue(string $html): array
    {
        $chants = [];
        if (preg_match_all(
            '~<a class="nocolor" href="/chant/(\d+)/([^"]+)">\s*(.*?)\s*(?:\n|<)~s',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $id = (int) $m[1];
                if ($id === 0 || isset($chants[$id])) {
                    continue;
                }
                $chants[$id] = [
                    'id'    => $id,
                    'slug'  => $m[2],
                    'titre' => self::texteSimple($m[3]),
                ];
            }
        }

        return $chants;
    }

    /** La page de catalogue est-elle tronquée (trop de résultats) ? */
    public static function catalogueTronque(string $html): bool
    {
        return count(self::parseCatalogue($html)) >= self::LIMITE_CATALOGUE;
    }

    /**
     * Extrait les données d'une fiche /voir-texte/{id}.
     *
     * @return array{titre:string,code:string,auteur:string,categorie:string,type:string,chant:string}|null
     *         null si le chant n'a pas de paroles exploitables (raisons contractuelles…).
     */
    public static function parseVoirTexte(string $html): ?array
    {
        $h1        = self::premierGroupe('~<h1>(.*?)</h1>~s', $html);
        $auteur    = self::champLibelle($html, 'Auteur');
        $compo     = self::champLibelle($html, 'Compositeur');
        $code      = self::champLibelle($html, 'Cote Secli');
        $categorie = self::texteSimple(self::premierGroupe('~<div class="mt-3">(.*?)</div>~s', $html));

        $paroles = self::premierGroupe('~<p class="py-4">(.*?)</p>~s', $html);
        $chant   = self::nettoieTexte($paroles);

        if ($chant === ''
            || mb_strlen($chant) < 15
            || stripos($chant, 'raisons contractuelles') !== false
            || stripos($chant, 'ne sont pas disponibles') !== false
        ) {
            return null;
        }

        $code  = self::normaliseCode($code);
        $titre = self::titreSansCode(self::texteSimple($h1), $code);

        return [
            'titre'     => $titre,
            'code'      => $code,
            'auteur'    => self::fusionneAuteurs($auteur, $compo),
            'categorie' => $categorie,
            'type'      => self::typeInterne($categorie),
            'chant'     => self::formatParoles($chant),
        ];
    }

    /**
     * Met en forme le texte d'un chant au format attendu par l'application :
     *   - un refrain commence par « R/ » ;
     *   - un couplet commence par « 1. », « 2. »… ;
     *   - les parties sont séparées par une ligne vide, les vers par un simple saut.
     *
     * Sur le site, ces indications sont des lignes isolées (« REFRAIN », « 1 »…).
     * Fonction idempotente : ré-exécutable sur un texte déjà formaté.
     */
    public static function formatParoles(string $texte): string
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
        if (preg_match('~^(?:REFRAIN|R[EÉ]FRAIN|REFR\.?|R[ÉE]F\.?|R\s*[/.:\-])\s*[:./)\-]*\s*(.*)$~iu', $ligne, $m)) {
            return ['R/ ', trim($m[1])];
        }
        if (preg_match('~^(?:couplet|strophe)\s+(\d{1,2})\b[\s:./)\-]*(.*)$~iu', $ligne, $m)) {
            return [$m[1] . '. ', trim($m[2])];
        }
        if (preg_match('~^(\d{1,2})$~', $ligne, $m)) {
            return [$m[1] . '. ', ''];
        }
        // Un nombre suivi d'au moins une ponctuation séparatrice ( . ) ° - / : * _ ),
        // pas d'un chiffre (pour ne pas casser « 1000 à chanter »).
        if (preg_match('~^(\d{1,2})\s*[.)°:/*_ -]*[.)°:/*_-]\s*(\S.*)$~u', $ligne, $m)) {
            return [$m[1] . '. ', trim($m[2])];
        }

        return [null, ''];
    }

    /** Type retenu quand la catégorie du site ne permet aucune identification. */
    public const TYPE_DEFAUT = 'entree';

    /**
     * Fait correspondre le « type » indiqué sur le site à un slug de la liste
     * App\SectionTypes::DEFAUT (entree, kyrie, gloria, psaume, evangile,
     * priere_universelle, offertoire, sanctus, anamnese, communion, envoi…).
     * Retourne self::TYPE_DEFAUT si rien n'est reconnu.
     */
    public static function typeInterne(string $categorie): string
    {
        return self::motifType($categorie) ?? self::TYPE_DEFAUT;
    }

    /**
     * Type DEFAUT déduit des mots-clés de la catégorie, ou null si non reconnue.
     * (Sert aussi à savoir si la catégorie est un vrai libellé liturgique.)
     */
    private static function motifType(string $categorie): ?string
    {
        $n = self::sansAccents(mb_strtolower(trim($categorie), 'UTF-8'));
        if ($n === '') {
            return null;
        }

        // Ordre important : le premier motif trouvé gagne. Uniquement des slugs
        // de App\SectionTypes::DEFAUT (l'alléluia / acclamation → « evangile »,
        // l'Agneau de Dieu → « communion », faute de section dédiée).
        $regles = [
            'psaume'             => ['psaume responsorial', 'psaume'],
            'evangile'           => ['acclamation a l\'evangile', 'acclamation de l\'evangile', 'acclamation avant l\'evangile', 'alleluia', 'acclamation', 'sequence', 'verset de l\'evangile'],
            'kyrie'              => ['kyrie', 'seigneur prends pitie', 'rite penitentiel', 'penitentiel', 'demande de pardon', 'aspersion', 'preparation penitentielle'],
            'gloria'             => ['gloria', 'gloire a dieu'],
            'sanctus'            => ['sanctus', 'saint le seigneur', 'saint, le seigneur'],
            'anamnese'           => ['anamnese', 'proclamons le mystere'],
            'entree'             => ['chant d\'entree', 'd\'entree', 'rite d\'entree', 'rassemblement', 'ouverture', 'procession d\'entree'],
            'offertoire'         => ['offertoire', 'presentation des dons', 'preparation des dons', 'procession des offrandes'],
            'communion'          => ['communion', 'agneau de dieu', 'agnus', 'fraction du pain', 'fraction', 'agape'],
            'priere_universelle' => ['priere universelle', 'intercession', 'universelle'],
            'envoi'              => ['chant d\'envoi', 'd\'envoi', 'envoi', 'sortie', 'benediction finale', 'final'],
        ];

        foreach ($regles as $type => $motifs) {
            foreach ($motifs as $motif) {
                if (str_contains($n, $motif)) {
                    return $type;
                }
            }
        }

        return null;
    }

    /** Libellé affiché pour une section de catalogue (colonne chants.nom). */
    public static function nomSection(string $categorie, string $type): string
    {
        // Le champ « type » du site est du texte libre (souvent un commentaire) :
        // on ne l'utilise comme libellé que s'il désigne un vrai type liturgique
        // et qu'il reste court.
        $categorie = trim($categorie);
        if ($categorie !== '' && mb_strlen($categorie) <= 60 && self::motifType($categorie) !== null) {
            return mb_convert_case(mb_substr($categorie, 0, 1, 'UTF-8'), MB_CASE_UPPER, 'UTF-8')
                . mb_substr($categorie, 1, null, 'UTF-8');
        }

        return \App\SectionTypes::nomDefaut($type) ?? 'Chant';
    }

    public static function urlChant(int $id, string $slug): string
    {
        return self::BASE_URL . '/chant/' . $id . '/' . $slug;
    }

    public static function urlVoirTexte(int $id): string
    {
        return self::BASE_URL . '/voir-texte/' . $id;
    }

    public static function urlCatalogue(string $prefixe): string
    {
        return self::BASE_URL . '/catalogue/chant/' . rawurlencode($prefixe);
    }

    // --- Internes ---------------------------------------------------------

    private static function premierGroupe(string $regex, string $sujet): string
    {
        return preg_match($regex, $sujet, $m) ? $m[1] : '';
    }

    /** Récupère « <div>Libellé : valeur</div> ». */
    private static function champLibelle(string $html, string $libelle): string
    {
        $regex = '~<div>\s*' . preg_quote($libelle, '~') . '\s*:\s*(.*?)</div>~s';

        return self::texteSimple(self::premierGroupe($regex, $html));
    }

    /** HTML → texte sur une seule ligne. */
    private static function texteSimple(string $html): string
    {
        $texte = self::nettoieTexte($html);

        return trim((string) preg_replace('~\s+~u', ' ', $texte));
    }

    /** HTML → texte, en préservant les sauts de ligne (<br>). */
    private static function nettoieTexte(string $html): string
    {
        $texte = str_replace(["\r\n", "\r"], "\n", $html);
        // « ligne<br />\n » → un seul saut de ligne (le \n de la source est de la
        // mise en forme HTML) ; « <br /><br /> » → ligne vide (séparateur de partie).
        $texte = (string) preg_replace('~[ \t]*<br\s*/?>[ \t]*\n?~i', "\n", $texte);
        $texte = (string) preg_replace('~<[^>]+>~', '', $texte);
        // Certaines fiches sont doublement encodées (« &amp;#8201; ») : 2 passes suffisent.
        for ($i = 0; $i < 2 && preg_match('~&(#\d+|#x[0-9a-f]+|[a-z]+);~i', $texte); $i++) {
            $texte = html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $texte = str_replace(
            ["\\'", "\u{00A0}", "\u{2009}", "\u{202F}", "\u{200B}"],
            ["'", ' ', ' ', ' ', ''],
            $texte
        );
        $texte = (string) preg_replace('~[ \t]+~', ' ', $texte);
        $texte = (string) preg_replace('~ *\n *~', "\n", $texte);
        $texte = (string) preg_replace('~\n{3,}~', "\n\n", $texte);

        return trim($texte);
    }

    private static function sansAccents(string $s): string
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

    private static function normaliseCode(string $code): string
    {
        $code = trim((string) preg_replace('~\s*/\s*~', ' / ', $code));

        return trim((string) preg_replace('~\s+~', ' ', $code));
    }

    /** « Devenez ce que vous recevez - D68-39 » → « Devenez ce que vous recevez ». */
    private static function titreSansCode(string $titre, string $code): string
    {
        $premierCode = trim(explode('/', $code)[0]);
        if ($premierCode !== '') {
            $titre = (string) preg_replace(
                '~\s*[-–—]\s*' . preg_quote($premierCode, '~') . '\s*$~u',
                '',
                $titre
            );
        }

        return trim($titre);
    }

    private static function fusionneAuteurs(string ...$auteurs): string
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
