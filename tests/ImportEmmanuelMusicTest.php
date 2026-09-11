<?php

declare(strict_types=1);

namespace Tests;

use App\Import\EmmanuelMusic as Site;
use PHPUnit\Framework\TestCase;

final class ImportEmmanuelMusicTest extends TestCase
{
    public function testParseListeNeGardeQueLeFrancais(): void
    {
        $html = <<<'HTML'
        <h2><span class="fi fi-id"></span> <a href="https://emmanuelmusic.net/produit/puji-dia/">Puji Dia</a> </h2>
        <h2><span class="fi fi-fr"></span> <a href="https://emmanuelmusic.net/produit/comme-un-soleil-mp3/">Comme un soleil [MP3]</a> </h2>
        <h2><span class="fi fi-ar"></span> <a href="https://emmanuelmusic.net/produit/xyz/">افتح عيوني [MP3]</a> </h2>
        <h2><span class="fi fi-fr"></span> <a href="https://emmanuelmusic.net/produit/comme-un-soleil-partiev/">Comme un soleil [PARTIEV]</a> </h2>
        HTML;

        $chants = Site::parseListe($html);

        $this->assertCount(2, $chants);
        $this->assertSame('comme-un-soleil-mp3', $chants[0]['ref']);
        $this->assertSame('Comme un soleil [MP3]', $chants[0]['titre']);
        $this->assertSame('comme-un-soleil-partiev', $chants[1]['ref']);
    }

    public function testDedupliquerGardeLeMp3PlutotQueLaPartition(): void
    {
        $fiches = [
            ['ref' => 'comme-un-soleil-partiev', 'url' => 'https://emmanuelmusic.net/produit/comme-un-soleil-partiev/', 'titre' => 'Comme un soleil [PARTIEV]'],
            ['ref' => 'comme-un-soleil-mp3', 'url' => 'https://emmanuelmusic.net/produit/comme-un-soleil-mp3/', 'titre' => 'Comme un soleil [MP3]'],
            ['ref' => 'unique-orchiev', 'url' => 'https://emmanuelmusic.net/produit/unique-orchiev/', 'titre' => 'Chant seul [ORCHIEV]'],
        ];

        $chants = Site::dedupliquer($fiches);

        $this->assertCount(2, $chants);
        $this->assertArrayHasKey('comme-un-soleil-mp3', $chants);
        $this->assertArrayNotHasKey('comme-un-soleil-partiev', $chants);
        $this->assertArrayHasKey('unique-orchiev', $chants);
    }

    public function testNombreDePages(): void
    {
        $html = '{&quot;currentPage&quot;:1,&quot;maxPages&quot;:8,&quot;postsPerPage&quot;:999,&quot;foundPosts&quot;:7742}';

        $this->assertSame(8, Site::nombreDePages($html));
        $this->assertSame(1, Site::nombreDePages('<html></html>'));
    }

    public function testParseProduitComplet(): void
    {
        $html = <<<'HTML'
        <span class="elementor-post-info__item-prefix">rubrique :</span>
        <span class="elementor-post-info__terms-list">
            <a href="https://emmanuelmusic.net/categorie-produit/1/" class="elementor-post-info__terms-list-item">Louange</a>
        </span>
        <h4 class="product_title entry-title elementor-heading-title elementor-size-default">Comme un soleil [MP3]</h4>
        <div class="elementor-heading-title elementor-size-medium"><p>Paroles et musique : Communaut&eacute; de l&rsquo;Emmanuel</p></div>
        <div class="elementor-widget-woocommerce-product-content" data-widget_type="woocommerce-product-content.default">
        <div class="elementor-widget-container">
        <p><strong>R. </strong><strong>Comme un soleil, tu nous &eacute;merveilles<br />Et tu nous r&eacute;veilles, Esprit de Dieu.<br /></strong><br />1. Merci pour cette f&ecirc;te<br />Qu&rsquo;en ton nom nous c&eacute;l&eacute;brons.</p>
        <p>2. Merci pour cette paix,<br />Dont le monde a tant besoin.</p>
        </div>
        </div>
        <div class="elementor-heading-title elementor-size-default"><p>&copy; 1977, &Eacute;ditions de l&rsquo;Emmanuel, 98 boulevard Blanqui, 75013 Paris</p></div>
        <div class="elementor-shortcode"><section id="refs-produit">
            <p><strong>COTE IEV:</strong> <em>01-08-FR</em></p>
        </section></div>
        HTML;

        $data = Site::parseProduit($html);

        $this->assertNotNull($data);
        $this->assertSame('Comme un soleil', $data['titre']);
        $this->assertSame('Communauté de l’Emmanuel', $data['auteur']);
        $this->assertSame('Louange', $data['categorie']);
        $this->assertSame('IEV 01-08', $data['code_repertoire']);
        $this->assertSame('', $data['code']);
        $this->assertStringContainsString('R. Comme un soleil', $data['chant']);
        $this->assertStringContainsString('1. Merci pour cette fête', $data['chant']);
        $this->assertStringContainsString('2. Merci pour cette paix', $data['chant']);
    }

    public function testParseProduitAvecCoteSecliEtCreditsMultiples(): void
    {
        $html = <<<'HTML'
        <h2 class="product_title entry-title elementor-heading-title elementor-size-default">Notre P&egrave;re (EXO) [PARTIEV]</h2>
        <div class="elementor-heading-title elementor-size-medium"><p>Paroles : Bienheureux Henri Suso &#8211; Musique : XIVe si&egrave;cle<br /> Adaptation : Communaut&eacute; de l&rsquo;Emmanuel</p></div>
        <div class="elementor-widget-woocommerce-product-content" data-widget_type="woocommerce-product-content.default">
        <div class="elementor-widget-container">
        <p>1. In dulci iubilo,<br />Chantons le chant nouveau !</p>
        </div>
        </div>
        <div class="elementor-shortcode"><section id="refs-produit">
            <p><strong>COTE IEV:</strong> <em>29-27-FR-PARTIEV</em></p> <p><strong>COTE SECLI:</strong> <em>DEV48-10</em></p>
        </section></div>
        HTML;

        $data = Site::parseProduit($html);

        $this->assertNotNull($data);
        $this->assertSame('Notre Père (EXO)', $data['titre']);
        $this->assertSame('Bienheureux Henri Suso / XIVe siècle / Communauté de l’Emmanuel', $data['auteur']);
        $this->assertSame('DEV48-10', $data['code']);
        $this->assertSame('IEV 29-27', $data['code_repertoire']);
    }

    public function testParseProduitSansParolesRenvoieNull(): void
    {
        // Fiche « Apprendre ce chant » : aucun widget woocommerce-product-content.
        $html = <<<'HTML'
        <h4 class="product_title entry-title elementor-heading-title elementor-size-default">Caritas et amor [PARTIEV]</h4>
        <div class="elementor-heading-title elementor-size-default">Apprendre ce chant</div>
        HTML;

        $this->assertNull(Site::parseProduit($html));
    }

    public function testParseProduitNonChantRenvoieNull(): void
    {
        // Produit hors chant (album, recharge de partitions…) : titre présent
        // mais pas de widget de paroles.
        $html = '<h2 class="product_title entry-title elementor-heading-title elementor-size-default">La prière de Jésus</h2>';

        $this->assertNull(Site::parseProduit($html));
    }
}
