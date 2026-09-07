<?php

declare(strict_types=1);

namespace Tests;

use App\Import\EmmanuelOrdinaires as Site;
use PHPUnit\Framework\TestCase;

final class ImportEmmanuelOrdinairesTest extends TestCase
{
    public function testParseListeExtraitLesSlugsDedupliques(): void
    {
        $html = <<<'HTML'
        <a href="https://emmanuel.info/ordinaire-de-messe/messe-saint-jean/">Saint Jean</a>
        <a href="https://emmanuel.info/ordinaire-de-messe/messe-emmanuel/" target="_blank">L'Emmanuel</a>
        <a href="https://emmanuel.info/ordinaire-de-messe/messe-saint-jean/">Saint Jean (encore)</a>
        <a href="https://emmanuel.info/autre-page/">Sans rapport</a>
        HTML;

        $ordinaires = Site::parseListe($html);

        $this->assertSame(
            [
                'messe-saint-jean' => 'https://emmanuel.info/ordinaire-de-messe/messe-saint-jean/',
                'messe-emmanuel'   => 'https://emmanuel.info/ordinaire-de-messe/messe-emmanuel/',
            ],
            $ordinaires
        );
    }

    public function testParseOrdinaireExtraitLeNomLesCreditsEtLesParties(): void
    {
        $html = <<<'HTML'
        <title>Ordinaire de messe : Saint Jean</title>
        <h2 class="elementor-heading-title elementor-size-default">PAROLES DE LA MESSE</h2>
        <div class="elementor-widget-container">
        <p><em>Paroles : A.E.L.F. (Gloria et Anamnèse) et Liturgie Catholique Romaine</em></p>
        <p><em>Musique : Communauté de l&#8217;Emmanuel (M. Hagemann) N°13-25</em></p>
        </div>
        <div class="elementor-widget-container">
        <p><strong>Kyrie</strong></p>
        <p>1. Kyrie eleison,<br />Kyrie eleison.</p>
        <p><strong>Gloria, gloria, in excelsis Deo ! </strong><br /><strong>Gloria, gloria !</strong></p>
        <p><strong>Alléluia</strong></p>
        <p>Alléluia, Alléluia !</p>
        <p><strong>Doxologie</strong></p>
        <p>Par lui, avec lui et en lui.</p>
        <p><strong>Agnus</strong></p>
        <p>1. Agnus Dei, <br />Miserere nobis.</p>
        <p> </p>
        <p><em>Titre original (DE) : Messe St Johannes</em><br /><em>© 1998, Gemeinschaft Emmanuel</em></p>
        </div>
        Retrouvez d'autres ordinaires de messes
        HTML;

        $data = Site::parseOrdinaire($html);

        $this->assertNotNull($data);
        $this->assertSame('Saint Jean', $data['nom']);
        $this->assertSame(
            "A.E.L.F. (Gloria et Anamnèse) et Liturgie Catholique Romaine / Communauté de l’Emmanuel (M. Hagemann)",
            $data['auteur']
        );

        // « Gloria, gloria… » n'est pas un titre de partie (le <p> contient
        // aussi les paroles) : il n'a donc pas été reconnu comme séparateur ;
        // « Doxologie » n'a pas de section de feuille dédiée et est ignorée.
        $types = array_column($data['chants'], 'type');
        $this->assertSame(['kyrie', 'alleluia', 'agnus'], $types);

        // Le paragraphe « Gloria, gloria… » n'est pas séparé par un titre
        // reconnu : ses paroles restent rattachées à la partie précédente.
        $kyrie = $data['chants'][0];
        $this->assertSame(
            "1. Kyrie eleison,\nKyrie eleison.\nGloria, gloria, in excelsis Deo !\nGloria, gloria !",
            $kyrie['chant']
        );

        $agnus = $data['chants'][2];
        $this->assertStringNotContainsString('Titre original', $agnus['chant']);
        $this->assertStringNotContainsString('©', $agnus['chant']);
        $this->assertSame("1. Agnus Dei,\nMiserere nobis.", $agnus['chant']);
    }

    public function testParseOrdinaireGereLeVariantePAROLESDEMESSEEtLeBrDansLeTitre(): void
    {
        $html = <<<'HTML'
        <title>Ordinaire de messe : Sacré Cœur</title>
        <h2 class="elementor-heading-title elementor-size-default">PAROLES DE MESSE<br></h2>
        <div class="elementor-widget-container">
        <p><strong>Kyrie</strong></p>
        <p>Seigneur, prends pitié.</p>
        </div>
        Retrouvez d'autres ordinaires de messes
        HTML;

        $data = Site::parseOrdinaire($html);

        $this->assertNotNull($data);
        $this->assertSame('Sacré Cœur', $data['nom']);
        $this->assertSame(['kyrie'], array_column($data['chants'], 'type'));
    }

    public function testParseOrdinaireRenvoieNullSansTitreReconnu(): void
    {
        $this->assertNull(Site::parseOrdinaire('<title>Une autre page</title>'));
    }
}
