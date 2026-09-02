<?php

declare(strict_types=1);

namespace Tests;

use App\Import\CatechismeEmmanuel as Site;
use App\Import\Repertoire;
use PHPUnit\Framework\TestCase;

final class ImportCatechismeEmmanuelTest extends TestCase
{
    public function testParseListeExtraitThemeEtCodeIev(): void
    {
        $html = <<<'HTML'
        <div class="row category-section-wrap cat-style-1 liste-chants">
          <div class="cat-wrap shadow-1">
            <h5>Louange <i class="fa fa-music orange-1"></i></h5>
            <ul class="cat-list">
              <li><a href="https://catechisme-emmanuel.com/chants/a-toi-puissance-et-gloire/"><strong>&#192; toi, puissance et gloire</strong> <span><small style="font-size:0.8em">cd A </small>  (12-02)</span></a></li>
            </ul>
          </div>
          <div class="cat-wrap shadow-1">
            <h5>Esprit Saint <i class="fa fa-fire yellow-1"></i></h5>
            <ul class="cat-list">
              <li><a href="https://catechisme-emmanuel.com/chants/ensemble-au-cenacle-aupres-de-marie/"><strong>Ensemble au C&#233;nacle (aupr&#232;s de Marie)</strong> <span> (19-06)</span></a></li>
              <li><a href="https://catechisme-emmanuel.com/chants/sans-code/"><strong>Sans code</strong> <span></span></a></li>
            </ul>
          </div>
        </div>
        </section>
        HTML;

        $chants = Site::parseListe($html);

        $this->assertCount(3, $chants);
        $this->assertSame(
            [
                'slug'            => 'a-toi-puissance-et-gloire',
                'url'             => 'https://catechisme-emmanuel.com/chants/a-toi-puissance-et-gloire/',
                'titre'           => 'À toi, puissance et gloire',
                'theme'           => 'Louange',
                'code_repertoire' => 'IEV 12-02',
            ],
            $chants['a-toi-puissance-et-gloire']
        );
        $this->assertSame('Esprit Saint', $chants['ensemble-au-cenacle-aupres-de-marie']['theme']);
        $this->assertSame('IEV 19-06', $chants['ensemble-au-cenacle-aupres-de-marie']['code_repertoire']);
        $this->assertNull($chants['sans-code']['code_repertoire']);
    }

    public function testParseChantComplet(): void
    {
        $html = <<<'HTML'
        <div class="page-title-wrap bgred-1">
          <h1 class="white"><span>Chant</span>Ensemble au Cénacle (auprès de Marie)</h1>
        </div>
        <a class="white bgbrown-1" href="https://catechisme-emmanuel.com/chants">Thèmes des chants</a>
        <a class="white bgwhite-1" style="cursor:default;" href="#">Esprit Saint</a>
        <div class="entry-disc">
          <h2>Ensemble au Cénacle (auprès de Marie)<br><span style="font-size:0.8em;font-weight:normal;">(IEV 19-06)</span></h2>
          <div class="chant-contenu">
            <p>1. Auprès de Marie ensemble au Cénacle,<br />
        Nous levons les yeux vers le ciel.</p>
        <p><strong><b>R. Viens ! Souffle de Dieu,<br />
        &Ocirc; viens ! Esprit du Tr&egrave;s-Haut,</b></strong></p>
        <p>2. Esprit Créateur éclaire nos âmes,<br />
        Viens emplir nos cœurs de ta grâce.</p>
          </div>
          <div class='heateor_sss_sharing_container'>partage</div>
        </div>
        HTML;

        $data = Site::parseChant($html, 'IEV 99-99');

        $this->assertNotNull($data);
        $this->assertSame('Ensemble au Cénacle (auprès de Marie)', $data['titre']);
        $this->assertSame('IEV 19-06', $data['code_repertoire']);
        $this->assertSame('Esprit Saint', $data['theme']);
        $this->assertSame('entree', $data['type']);
        $this->assertSame('', $data['auteur']);
        $this->assertSame(
            "1. Auprès de Marie ensemble au Cénacle,\n"
            . "Nous levons les yeux vers le ciel.\n"
            . "\n"
            . "R/ Viens ! Souffle de Dieu,\n"
            . "Ô viens ! Esprit du Très-Haut,\n"
            . "\n"
            . "2. Esprit Créateur éclaire nos âmes,\n"
            . "Viens emplir nos cœurs de ta grâce.",
            $data['chant']
        );
        $this->assertStringNotContainsString('<', $data['chant']);
    }

    /**
     * @dataProvider fournitCredits
     */
    public function testAuteurDepuisLesCredits(string $credits, string $attendu): void
    {
        $html = '<p style="font-size:0.7em;border-top:2px solid #F4F4F4;">'
            . $credits
            . '<br>© 1985, Éditions de l’Emmanuel, Paris</p>';

        $this->assertSame($attendu, Site::auteur($html));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function fournitCredits(): array
    {
        return [
            'paroles et musique' => [
                '<em>Paroles et musique : Communauté de l&#039;Emmanuel (E. Baranger) </em>',
                "Communauté de l'Emmanuel (E. Baranger)",
            ],
            'paroles / musique séparés' => [
                '<em>Paroles : J.-L. Fradon - Musique : B. Ben</em>',
                'J.-L. Fradon / B. Ben',
            ],
            'communauté + compositeur' => [
                '<em>Paroles : Communauté de l&#039;Emmanuel (A. Dumont) - Musique : M. Dannaud</em>',
                "Communauté de l'Emmanuel (A. Dumont) / M. Dannaud",
            ],
            'sans mention' => ['', ''],
        ];
    }

    public function testParseChantReplisSurLeCodeDeLaListe(): void
    {
        $html = '<h1 class="white"><span>Chant</span>Danse de joie</h1>'
            . '<h2>Danse de joie<br><span></span></h2>'
            . '<div class="chant-contenu"><p>Que ma bouche chante ta louange, alléluia !</p></div><div></div>';

        $data = Site::parseChant($html, '(05-42)');

        $this->assertNotNull($data);
        $this->assertSame('IEV 05-42', $data['code_repertoire']);
    }

    public function testParseChantSansParoles(): void
    {
        $html = '<h1 class="white"><span>Chant</span>Vidéo seule</h1>'
            . '<h2>Vidéo seule<br><span>(10-10)</span></h2>'
            . '<div class="chant-contenu"><p></p></div><div></div>';

        $this->assertNull(Site::parseChant($html));
    }

    public function testUrls(): void
    {
        $this->assertSame('https://catechisme-emmanuel.com/tous-les-chants/', Site::urlListe());
        $this->assertSame(
            'https://catechisme-emmanuel.com/chants/danse-de-joie/',
            Site::urlChant('danse-de-joie')
        );
    }

    /**
     * @dataProvider fournitCodes
     */
    public function testRepertoireIev(string $texte, bool $avecJeton, ?string $attendu): void
    {
        $this->assertSame($attendu, Repertoire::iev($texte, $avecJeton));
    }

    /** @return array<string,array{0:string,1:bool,2:?string}> */
    public static function fournitCodes(): array
    {
        return [
            'texte libre'        => ['Esprit Saint Réf. IEV 19-06', true, 'IEV 19-06'],
            'entre parenthèses'  => ['(IEV 14-10)', true, 'IEV 14-10'],
            'code nu refusé'     => ['cd A  (12-02)', true, null],
            'code nu accepté'    => ['cd A  (12-02)', false, 'IEV 12-02'],
            'sous-numéro'        => ['IEV 23-50-1', true, 'IEV 23-50-1'],
            'rien'               => ['Chant de communion', false, null],
        ];
    }

    public function testRepertoireEstEmmanuel(): void
    {
        $this->assertTrue(Repertoire::estEmmanuel("Éditions de l'Emmanuel"));
        $this->assertTrue(Repertoire::estEmmanuel("Communauté de l'Emmanuel"));
        $this->assertFalse(Repertoire::estEmmanuel('Éditions de l\'Atelier'));
    }

    public function testRepertoireSansRefIev(): void
    {
        $this->assertSame('Esprit Saint', Repertoire::sansRefIev('Esprit Saint Réf. IEV 19-06'));
        $this->assertSame('Chant de communion', Repertoire::sansRefIev('Chant de communion'));
    }
}
