<?php

declare(strict_types=1);

namespace Tests;

use App\Import\ChoralePoleFontainebleau as Site;
use PHPUnit\Framework\TestCase;

final class ImportChoralePoleFontainebleauTest extends TestCase
{
    public function testParseListeExtraitTitreThemeEtRef(): void
    {
        $html = <<<'HTML'
        <table id="index-chants" class="display" cellspacing="0" width="100%">
          <thead><tr><th>Titre</th><th>Thématique</th><th>N° de page</th></tr></thead>
          <tbody>
            <tr>
              <td><a href="https://choralepolefontainebleau.org/bibliotheque/chants/a-toi-puissance-et-gloire-507/" title="A toi puissance et gloire" itemprop="url">A toi puissance et gloire</a></td>
              <td>LOUANGES</td>
              <td></td>
            </tr>
            <tr>
              <td><a href="https://choralepolefontainebleau.org/bibliotheque/humblement-nous-venons-a-toi-72627/" title="Humblement" itemprop="url">Humblement nous venons &#224; toi</a></td>
              <td>OFFERTOIRE, ACTION DE GRACE &#8211; MEDITATION</td>
              <td>Sheridan</td>
            </tr>
            <tr>
              <td><a href="https://choralepolefontainebleau.org/bibliotheque/chants/a-toi-puissance-et-gloire-507/" title="doublon" itemprop="url">Doublon</a></td>
              <td></td>
              <td></td>
            </tr>
          </tbody>
        </table>
        HTML;

        $chants = Site::parseListe($html);

        $this->assertCount(2, $chants);
        $this->assertSame(
            [
                'ref'   => '507',
                'url'   => 'https://choralepolefontainebleau.org/bibliotheque/chants/a-toi-puissance-et-gloire-507/',
                'titre' => 'A toi puissance et gloire',
                'theme' => 'LOUANGES',
            ],
            $chants['507']
        );
        $this->assertSame('OFFERTOIRE, ACTION DE GRACE – MEDITATION', $chants['72627']['theme']);
        $this->assertSame('Humblement nous venons à toi', $chants['72627']['titre']);
    }

    public function testParseChantComplet(): void
    {
        $html = <<<'HTML'
        <main><section class="wrap"><article id="post-507" itemprop="articleBody" class="post-507 category-chants">
          <header><h1>A toi puissance et gloire</h1></header>
          <ul><li>
            <h4>Références de la partition:</h4>
            <span><p>A Toi puissance et gloire<br />
        Cote SECLI: Y 29-45<br />
        P &amp; M: E. Baranger<br />
        Ed:<a href="#">Editions de l&#8217;Emmanuel</a></p>
        </span>
          </li></ul>
          <div><h3>Paroles :</h3>
        <p><b>R. A Toi puissance et gloire,<br />
        A Toi honneur et force,</b></p>
        <p><b>1.</b> Toi l&#8217;agneau immolé(bis)<br />
        Tu t&#8217;es livré pour nous(bis)</p>
                  </div>
          <h3>Documentation:</h3><div id="document"><p>Matthieu 6.13</p></div>
        </article></section></main>
        HTML;

        $data = Site::parseChant($html, 'LOUANGES');

        $this->assertNotNull($data);
        $this->assertSame('A toi puissance et gloire', $data['titre']);
        $this->assertSame('Y 29-45', $data['code']);
        $this->assertSame('E. Baranger', $data['auteur']);
        $this->assertSame('Editions de l’Emmanuel', $data['editeur']);
        $this->assertSame('entree', $data['type']);
        $this->assertSame(
            "R/ A Toi puissance et gloire,\n"
            . "A Toi honneur et force,\n"
            . "\n"
            . "1. Toi l’agneau immolé(bis)\n"
            . "Tu t’es livré pour nous(bis)",
            $data['chant']
        );
        $this->assertStringNotContainsString('<', $data['chant']);
        $this->assertStringNotContainsString('Matthieu', $data['chant']);
    }

    public function testParseChantGabaritParolesEtReferencesEnDiv(): void
    {
        $html = <<<'HTML'
        <article id="post-10633"><header><h1>Louange à Toi, Seigneur du monde</h1></header>
          <ul><li><h4>Références de la partition:</h4>
            <span><div>Auteurs : Claude Bernard / François d&#8217;Assise</div>
        <div>Compositeurs : JO. Akepsimas</div>
        <div>Ancienne Cote SECLI : L29-13</div>
        <div>Editeur : Bayard <a href="#">Chantons en église</a></div>
        </span></li></ul>
          <div><h3>Paroles :</h3><div class="modal-body"><div class="chantons">
        <p class="py-4"><strong>Refrain : Louange à Toi, Seigneur du monde,</strong></p>
        <p>1- Pour l&#8217;univers, l&#8217;espace et le firmament,<br />
        Pour le ciel, le soleil, la lune,</p>
        </div></div>
        <p><i>Ce contenu est diffusé à des fins pédagogiques.</i></p>
        </div>
        </article>
        HTML;

        $data = Site::parseChant($html, 'ACTION DE GRACE – MEDITATION, LOUANGES');

        $this->assertNotNull($data);
        $this->assertSame('L 29-13', $data['code']);
        $this->assertSame('Claude Bernard / François d’Assise / JO. Akepsimas', $data['auteur']);
        $this->assertSame('Bayard Chantons en église', $data['editeur']);
        $this->assertSame('entree', $data['type']);
        $this->assertSame(
            "R/ Louange à Toi, Seigneur du monde,\n"
            . "\n"
            . "1. Pour l’univers, l’espace et le firmament,\n"
            . "Pour le ciel, le soleil, la lune,",
            $data['chant']
        );
        $this->assertStringNotContainsString('pédagogiques', $data['chant']);
    }

    public function testParseChantSansParoles(): void
    {
        $html = '<article id="post-3450"><header><h1>Notre Père Darasse</h1></header>'
            . '<ul><li><h4>Références de la partition:</h4><span><p>T: Domaine Public<br />'
            . 'M: Darasse<br />Ed: Société Saint Jean-Marie Vianney</p></span></li></ul>'
            . '</article>';

        $this->assertNull(Site::parseChant($html, 'PRIERES'));
    }

    public function testRef(): void
    {
        $this->assertSame('73656', Site::ref('https://choralepolefontainebleau.org/bibliotheque/salve-regina-1vp-73656/'));
        $this->assertSame('69786', Site::ref('https://choralepolefontainebleau.org/bibliotheque/69786-69786/'));
        $this->assertSame('', Site::ref('https://choralepolefontainebleau.org/bibliotheque/chants/'));
    }

    public function testUrlListe(): void
    {
        $this->assertSame(
            'https://choralepolefontainebleau.org/category/bibliotheque/chants/',
            Site::urlListe()
        );
    }

    public function testTypeInterneEtNomSection(): void
    {
        $this->assertSame('communion', Site::typeInterne('COMMUNION'));
        $this->assertSame('entree', Site::typeInterne('OUVERTURE – ENVOI, LOUANGES'));
        $this->assertSame('offertoire', Site::typeInterne('OFFERTOIRE, ACTION DE GRACE – MEDITATION'));
        $this->assertSame('entree', Site::typeInterne('LOUANGES'));
        $this->assertSame('Communion', Site::nomSection('COMMUNION', 'communion'));
        $this->assertSame("Chant d'entrée", Site::nomSection('LOUANGES', 'entree'));
    }
}
