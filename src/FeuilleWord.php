<?php

declare(strict_types=1);

namespace App;

use RuntimeException;
use ZipArchive;

/**
 * Export Word (.docx) d'une feuille de chant.
 *
 * Mise en page : le titre de chaque section occupe toute la largeur, puis le
 * texte d'un chant passe sur deux colonnes, dans un tableau sans bordure à une
 * ligne insécable dont on répartit nous-mêmes les couplets (voir repartir()).
 * On évite ainsi les colonnes Word à sauts de section continus : Word n'y
 * respecte pas « solidaire du suivant » à travers le saut, ce qui laisse des
 * titres orphelins en bas de page. Les lectures, l'évangile et les chants
 * courts (moins de SEUIL_COLONNES lignes) restent sur une seule colonne.
 *
 * Le document est écrit à la main (WordprocessingML minimal) plutôt que via
 * une bibliothèque : on n'a besoin que de paragraphes, gras/italique et
 * d'un tableau à deux cellules.
 */
final class FeuilleWord
{
    /** En dessous de ce nombre de lignes, un chant reste sur une seule colonne. */
    public const SEUIL_COLONNES = 5;

    /** A4 portrait, marges de 1,9 cm (en twips). */
    private const PAGE_L = 11906;
    private const PAGE_H = 16838;
    private const MARGE = 1080;
    private const ESPACE_COLONNES = 567;
    private const LARGEUR_TEXTE = self::PAGE_L - 2 * self::MARGE;

    /**
     * Caractères par ligne dans une demi-colonne (Cambria 12 pt) — sert à
     * estimer les lignes repliées pour équilibrer les colonnes.
     */
    private const CARACTERES_PAR_LIGNE = 40;

    /**
     * Surcoût (en lignes) d'une coupure au milieu d'un couplet : on ne coupe
     * une partie que si l'équilibre y gagne nettement (Gloria d'un seul bloc).
     */
    private const PENALITE_COUPE_PARTIE = 4;

    /** @var list<string> fragments XML du corps du document */
    private array $corps = [];

    /**
     * @param array<int,array<string,mixed>> $sections sections déjà filtrées, dans l'ordre
     * @return string contenu binaire du .docx
     */
    public static function generer(array $sections): string
    {
        $fichier = tempnam(sys_get_temp_dir(), 'docx');
        if ($fichier === false) {
            throw new RuntimeException('Impossible de créer un fichier temporaire.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($fichier, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossible de créer l\'archive Word.');
            }
            $zip->addFromString('[Content_Types].xml', self::contentTypes());
            $zip->addFromString('_rels/.rels', self::rels());
            $zip->addFromString('word/_rels/document.xml.rels', self::documentRels());
            $zip->addFromString('word/styles.xml', self::styles());
            $zip->addFromString('word/settings.xml', self::settings());
            $zip->addFromString('word/document.xml', self::documentXml($sections));
            $zip->close();

            return (string) file_get_contents($fichier);
        } finally {
            @unlink($fichier);
        }
    }

    /** @param array<int,array<string,mixed>> $sections */
    public static function documentXml(array $sections): string
    {
        $doc = new self();
        foreach ($sections as $s) {
            $doc->section($s);
        }
        // Le corps doit se terminer par un paragraphe, pas par un tableau.
        if ($doc->corps !== [] && str_starts_with((string) end($doc->corps), '<w:tbl>')) {
            $doc->paragraphe('Normal', '');
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . implode('', $doc->corps) . self::sectPr() . '</w:body></w:document>';
    }

    /** Doit-on afficher ce texte de chant sur deux colonnes ? */
    public static function surDeuxColonnes(?string $chant): bool
    {
        $lignes = array_filter(
            explode("\n", str_replace("\r\n", "\n", (string) $chant)),
            static fn ($l) => trim($l) !== ''
        );

        return count($lignes) >= self::SEUIL_COLONNES;
    }

    /** @param array<string,mixed> $s */
    private function section(array $s): void
    {
        $comportement = SectionTypes::comportement((string) $s['type']);
        $this->paragraphe('TitreSection', self::run((string) $s['nom']));

        if (in_array($comportement, ['chant', 'ordinaire', 'psaume'], true)) {
            if ($comportement === 'psaume' && trim((string) $s['reference']) !== '') {
                $this->paragraphe('Reference', self::run((string) $s['reference']));
            }
            $this->chant((string) $s['chant']);
        } elseif ($comportement === 'lecture') {
            $this->titreLecture($s);
            $this->referenceLecture($s);
            $this->html((string) $s['contenu']);
        } elseif ($comportement === 'evangile') {
            $this->html((string) $s['acclamation'], 'Acclamation');
            $this->referenceLecture($s);
            $this->titreLecture($s);
            $this->html((string) $s['contenu']);
        } elseif (trim((string) $s['contenu']) !== '') {
            $this->html((string) $s['contenu']);
        } else {
            $this->chant((string) $s['chant']);
        }
    }

    /**
     * Texte libre d'un chant : parties séparées par une ligne vide, refrain
     * (« R/ » ou « R. ») en gras — mêmes règles que render_chant().
     */
    private function chant(string $texte): void
    {
        $parties = self::parties($texte);
        if ($parties === []) {
            return;
        }

        if (!self::surDeuxColonnes($texte)) {
            foreach ($parties as $partie) {
                $this->partie($partie);
            }

            return;
        }

        $cellules = '';
        foreach (self::repartir($parties) as $i => $colonne) {
            $avant = $this->corps;
            $this->corps = [];
            foreach ($colonne as $partie) {
                $this->partie($partie);
            }
            $marge = $i === 0 ? '<w:right w:w="' . intdiv(self::ESPACE_COLONNES, 2) . '" w:type="dxa"/>'
                : '<w:left w:w="' . intdiv(self::ESPACE_COLONNES, 2) . '" w:type="dxa"/>';
            $cellules .= '<w:tc><w:tcPr><w:tcW w:w="' . intdiv(self::LARGEUR_TEXTE, 2) . '" w:type="dxa"/>'
                . '<w:tcMar>' . $marge . '</w:tcMar></w:tcPr>' . implode('', $this->corps) . '</w:tc>';
            $this->corps = $avant;
        }

        $sansBordure = '';
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $cote) {
            $sansBordure .= '<w:' . $cote . ' w:val="nil"/>';
        }
        $this->corps[] = '<w:tbl><w:tblPr><w:tblW w:w="' . self::LARGEUR_TEXTE . '" w:type="dxa"/>'
            . '<w:tblBorders>' . $sansBordure . '</w:tblBorders><w:tblLayout w:type="fixed"/>'
            . '<w:tblCellMar><w:left w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar>'
            . '<w:tblLook w:val="0000"/></w:tblPr>'
            . '<w:tblGrid>' . str_repeat('<w:gridCol w:w="' . intdiv(self::LARGEUR_TEXTE, 2) . '"/>', 2) . '</w:tblGrid>'
            // Ligne insécable : un chant n'est jamais coupé entre deux pages.
            . '<w:tr><w:trPr><w:cantSplit/></w:trPr>' . $cellules . '</w:tr></w:tbl>';
    }

    /**
     * @return list<array{refrain:bool,lignes:list<string>}>
     */
    private static function parties(string $texte): array
    {
        $parties = [];
        foreach (preg_split('/\n{2,}/', trim(str_replace("\r\n", "\n", $texte))) ?: [] as $partie) {
            $partie = trim($partie);
            if ($partie !== '') {
                $parties[] = [
                    'refrain' => (bool) preg_match('/^\s*R\s*[\/.]/u', $partie),
                    'lignes'  => array_map('rtrim', explode("\n", $partie)),
                ];
            }
        }

        return $parties;
    }

    /** @param array{refrain:bool,lignes:list<string>} $partie */
    private function partie(array $partie): void
    {
        $runs = [];
        foreach ($partie['lignes'] as $i => $ligne) {
            $runs[] = ($i > 0 ? '<w:r><w:br/></w:r>' : '') . self::run($ligne);
        }
        $this->paragraphe($partie['refrain'] ? 'Refrain' : 'Couplet', implode('', $runs));
    }

    /**
     * Répartit les parties d'un chant sur deux colonnes de hauteurs aussi
     * proches que possible, la première au moins aussi haute que la seconde.
     * On coupe de préférence entre deux parties ; couper à l'intérieur d'une
     * partie (long Gloria d'un seul bloc…) est pénalisé, et jamais en laissant
     * une ligne isolée.
     *
     * @param list<array{refrain:bool,lignes:list<string>}> $parties
     * @return array{0:list<array{refrain:bool,lignes:list<string>}>,1:list<array{refrain:bool,lignes:list<string>}>}
     */
    public static function repartir(array $parties): array
    {
        // Lignes à plat : [partie, ligne, hauteur estimée].
        $lignes = [];
        foreach ($parties as $p => $partie) {
            foreach ($partie['lignes'] as $l => $texte) {
                $lignes[] = [$p, $l, max(1, (int) ceil(mb_strlen($texte) / self::CARACTERES_PAR_LIGNE))];
            }
        }
        $hauteur = static function (array $tranche): float {
            // Une demi-ligne d'espacement entre deux parties.
            return array_sum(array_column($tranche, 2)) + 0.5 * (count(array_unique(array_column($tranche, 0))) - 1);
        };

        $meilleur = null;
        $coupe = 1;
        for ($k = 1, $n = count($lignes); $k < $n; $k++) {
            $gauche = $hauteur(array_slice($lignes, 0, $k));
            $droite = $hauteur(array_slice($lignes, $k));
            $score = max($gauche, $droite) + ($gauche < $droite ? 0.01 : 0);
            [$p, $l] = $lignes[$k];
            if ($l > 0) {
                $isolee = $l === 1 || $l === count($parties[$p]['lignes']) - 1;
                $score += $isolee ? 100 : self::PENALITE_COUPE_PARTIE;
            }
            if ($meilleur === null || $score < $meilleur) {
                $meilleur = $score;
                $coupe = $k;
            }
        }

        [$p, $l] = $lignes[$coupe];
        $gauche = array_slice($parties, 0, $p);
        $droite = array_slice($parties, $p);
        if ($l > 0) {
            $gauche[] = ['refrain' => $parties[$p]['refrain'], 'lignes' => array_slice($parties[$p]['lignes'], 0, $l)];
            $droite[0] = ['refrain' => $parties[$p]['refrain'], 'lignes' => array_slice($parties[$p]['lignes'], $l)];
        }

        return [$gauche, $droite];
    }

    /** @param array<string,mixed> $s */
    private function titreLecture(array $s): void
    {
        $titre = strip_guillemets((string) $s['titre']);
        if ($titre !== '') {
            $this->paragraphe('TitreLecture', self::run("«\u{00A0}{$titre}\u{00A0}»"));
        }
    }

    /** @param array<string,mixed> $s */
    private function referenceLecture(array $s): void
    {
        $texte = trim((string) $s['introduction']);
        if (trim((string) $s['reference']) !== '') {
            $texte .= ' (' . trim((string) $s['reference']) . ')';
        }
        if (trim($texte) !== '') {
            $this->paragraphe('Reference', self::run(trim($texte)));
        }
    }

    /**
     * Fragment HTML (lectures AELF) : chaque bloc <p>/<div> devient un
     * paragraphe, <br> un saut de ligne, <strong>/<em> du gras/italique.
     */
    private function html(string $html, string $style = 'Lecture'): void
    {
        $gras = 0;
        $italique = 0;
        $runs = '';
        $morceaux = preg_split('#(<[^>]*>)#', clean_html($html), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($morceaux as $morceau) {
            if (!preg_match('#^<\s*(/?)\s*([a-z0-9]+)#i', $morceau, $m)) {
                if ($morceau[0] === '<') {
                    continue; // commentaire, doctype…
                }
                $texte = (string) preg_replace('/[ \t\r\n]+/', ' ', html_entity_decode($morceau, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($runs === '' || str_ends_with($runs, '<w:br/></w:r>')) {
                    $texte = ltrim($texte, ' ');
                }
                $runs .= self::run($texte, $gras > 0, $italique > 0);
                continue;
            }

            $fermante = $m[1] === '/';
            $tag = strtolower($m[2]);
            if ($tag === 'br') {
                $runs .= '<w:r><w:br/></w:r>';
            } elseif (in_array($tag, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'li'], true)) {
                $this->viderParagraphe($style, $runs);
            } elseif (in_array($tag, ['strong', 'b'], true)) {
                $gras = max(0, $gras + ($fermante ? -1 : 1));
            } elseif (in_array($tag, ['em', 'i'], true)) {
                $italique = max(0, $italique + ($fermante ? -1 : 1));
            }
        }
        $this->viderParagraphe($style, $runs);
    }

    private function viderParagraphe(string $style, string &$runs): void
    {
        // Retire les sauts de ligne en début/fin de paragraphe.
        $runs = (string) preg_replace('#^(<w:r><w:br/></w:r>)+|(<w:r><w:br/></w:r>)+$#', '', $runs);
        if ($runs !== '') {
            $this->paragraphe($style, $runs);
        }
        $runs = '';
    }

    private function paragraphe(string $style, string $runs): void
    {
        $this->corps[] = '<w:p><w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>' . $runs . '</w:p>';
    }

    private static function sectPr(): string
    {
        return '<w:sectPr>'
            . '<w:pgSz w:w="' . self::PAGE_L . '" w:h="' . self::PAGE_H . '"/>'
            . '<w:pgMar w:top="' . self::MARGE . '" w:right="' . self::MARGE . '" w:bottom="' . self::MARGE
            . '" w:left="' . self::MARGE . '" w:header="720" w:footer="720" w:gutter="0"/>'
            . '</w:sectPr>';
    }

    private static function run(string $texte, bool $gras = false, bool $italique = false): string
    {
        if ($texte === '') {
            return '';
        }
        $rPr = ($gras ? '<w:b/><w:bCs/>' : '') . ($italique ? '<w:i/><w:iCs/>' : '');

        return '<w:r>' . ($rPr !== '' ? "<w:rPr>{$rPr}</w:rPr>" : '')
            . '<w:t xml:space="preserve">' . htmlspecialchars($texte, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r>';
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
            . '</Types>';
    }

    private static function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
    }

    private static function documentRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
            . '</Relationships>';
    }

    private static function settings(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:defaultTabStop w:val="720"/><w:characterSpacingControl w:val="doNotCompress"/>'
            . '</w:settings>';
    }

    /** Styles repris de la feuille modèle (Cambria, titres gris centrés et soulignés). */
    private static function styles(): string
    {
        $style = static fn (string $id, string $nom, string $pPr, string $rPr = '', string $base = 'Normal'): string =>
            '<w:style w:type="paragraph" w:customStyle="1" w:styleId="' . $id . '"><w:name w:val="' . $nom . '"/>'
            . '<w:basedOn w:val="' . $base . '"/><w:qFormat/><w:pPr>' . $pPr . '</w:pPr>'
            . ($rPr !== '' ? '<w:rPr>' . $rPr . '</w:rPr>' : '') . '</w:style>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Cambria" w:hAnsi="Cambria" w:eastAsia="Cambria" w:cs="Cambria"/>'
            . '<w:color w:val="21242B"/><w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="fr-FR"/>'
            . '</w:rPr></w:rPrDefault><w:pPrDefault><w:pPr>'
            . '<w:spacing w:after="0" w:line="264" w:lineRule="auto"/>'
            . '</w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
            . $style(
                'TitreSection',
                'Titre de section',
                '<w:keepNext/><w:keepLines/><w:pBdr><w:bottom w:val="single" w:sz="5" w:space="1" w:color="E3E4EA"/></w:pBdr>'
                . '<w:spacing w:before="240" w:after="120"/><w:jc w:val="center"/><w:outlineLvl w:val="1"/>',
                '<w:b/><w:bCs/><w:color w:val="5B6472"/><w:sz w:val="32"/><w:szCs w:val="32"/>'
            )
            . $style('Couplet', 'Couplet', '<w:keepLines/><w:spacing w:after="160"/>')
            . $style('Refrain', 'Refrain', '', '<w:b/><w:bCs/>', 'Couplet')
            . $style('TitreLecture', 'Titre de lecture', '<w:keepNext/><w:spacing w:after="120"/>', '<w:b/><w:bCs/>')
            . $style('Reference', 'Référence', '<w:keepNext/><w:spacing w:after="120"/>', '<w:i/><w:iCs/><w:color w:val="5B6472"/>')
            . $style('Acclamation', 'Acclamation', '<w:keepNext/><w:spacing w:after="120"/>')
            . $style('Lecture', 'Lecture', '<w:spacing w:after="160"/>')
            . '</w:styles>';
    }
}
