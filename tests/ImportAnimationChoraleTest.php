<?php

declare(strict_types=1);

namespace Tests;

use App\Import\AnimationChorale as Site;
use PHPUnit\Framework\TestCase;

final class ImportAnimationChoraleTest extends TestCase
{
    public function testParseAgendaExtraitDatesEtUrls(): void
    {
        $html = <<<'HTML'
        <h3>A venir</h3><p>
        <li>13/09/2026 | <a href="https://choralepolefontainebleau.org/evenements/24-dimanche-ordinaire-ordo-a/">24&egrave;me dimanche</a> - </li>
        <li>14/09/2026 | <a href="https://choralepolefontainebleau.org/evenements/fete-de-la-croix-glorieuse/">F&ecirc;te de la Croix Glorieuse</a> - </li>
        </p>
        <h3>Pass&eacute;</h3>
        <li>06/09/2026 | <a href="https://choralepolefontainebleau.org/evenements/23-dimanche-ordinaire-ordo-a/">23&egrave;me dimanche</a> - </li>
        HTML;

        $agenda = Site::parseAgenda($html);

        $this->assertSame([
            '2026-09-13' => 'https://choralepolefontainebleau.org/evenements/24-dimanche-ordinaire-ordo-a/',
            '2026-09-14' => 'https://choralepolefontainebleau.org/evenements/fete-de-la-croix-glorieuse/',
            '2026-09-06' => 'https://choralepolefontainebleau.org/evenements/23-dimanche-ordinaire-ordo-a/',
        ], $agenda);
    }

    public function testParseAgendaGardeLaPremiereUrlPourUneDate(): void
    {
        $html = '<li>01/11/2026 | <a href="https://choralepolefontainebleau.org/evenements/toussaint/">Toussaint</a></li>'
            . '<li>01/11/2026 | <a href="https://choralepolefontainebleau.org/evenements/autre/">Autre</a></li>';

        $this->assertSame(
            ['2026-11-01' => 'https://choralepolefontainebleau.org/evenements/toussaint/'],
            Site::parseAgenda($html)
        );
    }

    public function testParseEvenementRegroupeLesChantsParRubrique(): void
    {
        $html = <<<'HTML'
        <div id="programme-aimations">
        <div class="print-only"><table><tbody><tr><td>
        <a href="https://www.choralepolefontainebleau.org/bibliotheque/messes/kyrie-saint-claude-colombiere-5271/">la messe de saint Claude La Colombi&egrave;re</a>
        <a href="https://www.choralepolefontainebleau.org/bibliotheque/psaumes/psaume-102-4-seigneur-tendresse-7589/">Psaume 102</a>
        </td></tr></tbody></table>
        <p><strong><u>Ouverture, Envoi</u></strong> :<br />
        <a href="https://www.choralepolefontainebleau.org/bibliotheque/chants/rejouis-toi-eglise-mere-4118/">R&eacute;jouis-toi, Eglise notre m&egrave;re</a> p8 ;
        <strong><a href="https://www.choralepolefontainebleau.org/bibliotheque/es-grand-dieu-saint-4423/"> Tu es grand Dieu saint</a> p12</strong> ;
        <a href="https://www.choralepolefontainebleau.org/bibliotheque/messes/messe-du-peuple-9999/">messe du peuple</a> ;
        <a href="https://www.choralepolefontainebleau.org/animations/hymne-de-la-misericorde-600/">Hymne de la mis&eacute;ricorde</a> p37 ;
        <a href="https://www.choralepolefontainebleau.org/bibliotheque/chants/rejouis-toi-eglise-mere-4118/">R&eacute;jouis-toi (doublon)</a></p>
        <p><strong>Refrain de PU:</strong><a href="https://choralepolefontainebleau.org/bibliotheque/jesus-dans-ta-misericorde-6455/">J&eacute;sus dans ta mis&eacute;ricorde</a></p>
        <p><strong><u>Offertoire, communion ac de Gr&acirc;ce</u></strong> :<br />
        <a href="https://www.choralepolefontainebleau.org/bibliotheque/chants/devenez-ce-que-vous-recevez-1948/">Devenez ce que vous recevez</a> p49;
        <a href="https://www.choralepolefontainebleau.org/bibliotheque/chants/recevez-corps-christ-4074/">Recevez le corps du Christ</a> p49;</p>
        </div></div>
        HTML;

        $rubriques = Site::parseEvenement($html);

        $this->assertSame(['ouverture_envoi', 'pu', 'offertoire_communion'], array_keys($rubriques));

        // Ouverture : les ordinaires de messe et le doublon sont écartés,
        // l'espace en tête de titre est rogné, une fiche /animations/ est gardée.
        $this->assertSame(
            [
                ['titre' => 'Réjouis-toi, Eglise notre mère', 'url' => 'https://www.choralepolefontainebleau.org/bibliotheque/chants/rejouis-toi-eglise-mere-4118/', 'ref' => '4118'],
                ['titre' => 'Tu es grand Dieu saint', 'url' => 'https://www.choralepolefontainebleau.org/bibliotheque/es-grand-dieu-saint-4423/', 'ref' => '4423'],
                ['titre' => 'Hymne de la miséricorde', 'url' => 'https://www.choralepolefontainebleau.org/animations/hymne-de-la-misericorde-600/', 'ref' => '600'],
            ],
            $rubriques['ouverture_envoi']
        );

        $this->assertSame('6455', $rubriques['pu'][0]['ref']);
        $this->assertSame('Jésus dans ta miséricorde', $rubriques['pu'][0]['titre']);

        $this->assertSame(['1948', '4074'], array_column($rubriques['offertoire_communion'], 'ref'));
    }

    public function testParseEvenementSansRubrique(): void
    {
        $this->assertSame([], Site::parseEvenement('<p>Rien ici</p>'));
    }

    public function testRubriquePourType(): void
    {
        $this->assertSame('ouverture_envoi', Site::rubriquePourType('entree'));
        $this->assertSame('ouverture_envoi', Site::rubriquePourType('envoi'));
        $this->assertSame('offertoire_communion', Site::rubriquePourType('offertoire'));
        $this->assertSame('offertoire_communion', Site::rubriquePourType('communion'));
        $this->assertSame('pu', Site::rubriquePourType('priere_universelle'));
        $this->assertNull(Site::rubriquePourType('psaume'));
        $this->assertNull(Site::rubriquePourType('kyrie'));
        $this->assertNull(Site::rubriquePourType('meditation-2'));
    }
}
