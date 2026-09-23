<?php

declare(strict_types=1);

namespace Tests;

use App\FeuilleWord;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class FeuilleWordTest extends TestCase
{
    private const CHANT_LONG = "R/ Jubilez, chantez\nfamiliers du Seigneur\n\n1. Entonnez vos hymnes\nDites à ceux qui craignent\n\n2. Les montagnes chantent\nLes collines dansent";

    /** @param array<string,string> $champs */
    private static function section(string $type, string $nom, array $champs = []): array
    {
        return array_merge([
            'type' => $type, 'nom' => $nom, 'chant' => '', 'titre' => '', 'contenu' => '',
            'acclamation' => '', 'introduction' => '', 'reference' => '',
        ], $champs);
    }

    /**
     * Blocs du corps : paragraphes hors tableau (style + texte) et tableaux
     * (texte de chaque colonne).
     *
     * @return list<array{style:string,texte:string}|array{colonnes:list<string>}>
     */
    private static function blocs(string $xml): array
    {
        preg_match_all('#<w:tbl>.*?</w:tbl>|<w:p>.*?</w:p>#s', $xml, $m);

        return array_map(static function (string $bloc): array {
            if (str_starts_with($bloc, '<w:tbl>')) {
                preg_match_all('#<w:tc>(.*?)</w:tc>#s', $bloc, $cellules);

                return ['colonnes' => array_map([self::class, 'texte'], $cellules[1])];
            }
            preg_match('#w:pStyle w:val="(\w+)"#', $bloc, $style);

            return ['style' => $style[1] ?? '', 'texte' => self::texte($bloc)];
        }, $m[0]);
    }

    /** Texte d'un fragment : « | » entre les lignes, « / » entre les paragraphes. */
    private static function texte(string $xml): string
    {
        $xml = str_replace(['<w:br/>', '</w:p><w:p>'], ['<w:t>|</w:t>', '<w:t>/</w:t></w:p><w:p>'], $xml);
        preg_match_all('#<w:t[^>]*>([^<]*)</w:t>#', $xml, $t);

        return html_entity_decode(implode('', $t[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function testSeuilDeuxColonnes(): void
    {
        $this->assertFalse(FeuilleWord::surDeuxColonnes("1\n2\n\n3\n4"));
        $this->assertTrue(FeuilleWord::surDeuxColonnes("1\n2\n\n3\n4\n5"));
    }

    public function testChantLongSurDeuxColonnesSousUnTitrePleineLargeur(): void
    {
        $blocs = self::blocs(FeuilleWord::documentXml([
            self::section('entree', "Chant d'entrée", ['chant' => self::CHANT_LONG]),
        ]));

        $this->assertSame(['style' => 'TitreSection', 'texte' => "Chant d'entrée"], $blocs[0]);
        $this->assertSame(['colonnes' => [
            'R/ Jubilez, chantez|familiers du Seigneur/1. Entonnez vos hymnes|Dites à ceux qui craignent',
            '2. Les montagnes chantent|Les collines dansent',
        ]], $blocs[1]);
        // Le corps ne se termine pas sur un tableau.
        $this->assertSame('Normal', $blocs[2]['style']);
    }

    public function testRepartitionEntreDeuxParties(): void
    {
        $couplet = ['refrain' => false, 'lignes' => ['a', 'b', 'c', 'd']];
        [$gauche, $droite] = FeuilleWord::repartir(array_fill(0, 5, $couplet));

        // Pas de couplet coupé : 3 + 2 plutôt que 2,5 + 2,5.
        $this->assertCount(3, $gauche);
        $this->assertCount(2, $droite);
        $this->assertSame($couplet, $gauche[2]);
    }

    public function testRepartitionDansUnBlocUnique(): void
    {
        $lignes = array_map(static fn ($i) => "Ligne {$i}", range(1, 25));
        [$gauche, $droite] = FeuilleWord::repartir([['refrain' => true, 'lignes' => $lignes]]);

        $this->assertSame(array_slice($lignes, 0, 13), $gauche[0]['lignes']);
        $this->assertSame(array_slice($lignes, 13), $droite[0]['lignes']);
        $this->assertTrue($droite[0]['refrain']);
    }

    public function testRepartitionTientCompteDesLignesLongues(): void
    {
        $longue = str_repeat('x', 100); // ≈ 3 lignes une fois repliée
        $parties = [
            ['refrain' => false, 'lignes' => [$longue, $longue]],
            ['refrain' => false, 'lignes' => ['a', 'b']],
            ['refrain' => false, 'lignes' => ['c', 'd']],
            ['refrain' => false, 'lignes' => ['e', 'f']],
        ];
        [$gauche, $droite] = FeuilleWord::repartir($parties);

        $this->assertCount(1, $gauche);
        $this->assertCount(3, $droite);
    }

    public function testChantCourtEtLectureSurUneColonne(): void
    {
        $xml = FeuilleWord::documentXml([
            self::section('kyrie', 'Kyrie', ['chant' => "Kyrie eleison\nChriste eleison\nKyrie eleison"]),
            self::section('premiere_lecture', 'Première lecture', [
                'titre'        => '« Si le méchant se détourne »',
                'introduction' => 'Lecture du livre du prophète Ézékiel',
                'reference'    => 'Ez 18, 25-28',
                'contenu'      => "<p>Ainsi parle le Seigneur :<br />\n&nbsp;« Vous dites »</p>\n<p>– Parole du <strong>Seigneur</strong>.</p>",
            ]),
        ]);

        $this->assertStringNotContainsString('<w:tbl>', $xml);
        $blocs = self::blocs($xml);
        $this->assertSame(
            ['TitreSection', 'Couplet', 'TitreSection', 'TitreLecture', 'Reference', 'Lecture', 'Lecture'],
            array_column($blocs, 'style')
        );
        $this->assertSame("«\u{00A0}Si le méchant se détourne\u{00A0}»", $blocs[3]['texte']);
        $this->assertSame('Lecture du livre du prophète Ézékiel (Ez 18, 25-28)', $blocs[4]['texte']);
        $this->assertSame("Ainsi parle le Seigneur :|\u{00A0}« Vous dites »", $blocs[5]['texte']);
        $this->assertStringContainsString('<w:r><w:rPr><w:b/><w:bCs/></w:rPr><w:t xml:space="preserve">Seigneur</w:t></w:r>', $xml);
    }

    public function testPsaumeReferencePleineLargeur(): void
    {
        $blocs = self::blocs(FeuilleWord::documentXml([
            self::section('psaume', 'Psaume', ['reference' => 'Ps 24', 'chant' => self::CHANT_LONG]),
        ]));

        $this->assertSame(['TitreSection', 'Reference'], [$blocs[0]['style'], $blocs[1]['style']]);
        $this->assertArrayHasKey('colonnes', $blocs[2]);
    }

    public function testEchappementXml(): void
    {
        $xml = FeuilleWord::documentXml([self::section('entree', 'A & B', ['chant' => '1. <script> & co'])]);
        $this->assertStringContainsString('A &amp; B', $xml);
        $this->assertStringContainsString('1. &lt;script&gt; &amp; co', $xml);
    }

    public function testArchiveDocxValide(): void
    {
        $fichier = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($fichier, FeuilleWord::generer([self::section('entree', 'Entrée', ['chant' => self::CHANT_LONG])]));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($fichier));
        foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml', 'word/styles.xml', 'word/settings.xml'] as $part) {
            $contenu = $zip->getFromName($part);
            $this->assertNotFalse($contenu, $part);
            $this->assertNotFalse(simplexml_load_string($contenu), $part);
        }
        $zip->close();
        unlink($fichier);
    }
}
