<?php

declare(strict_types=1);

namespace Tests;

use App\Import\ChoralePoleFontainebleauOrdinaires as Site;
use PHPUnit\Framework\TestCase;

final class ImportChoralePoleFontainebleauOrdinairesTest extends TestCase
{
    public function testParseListeExtraitRefEtTitreDedupliques(): void
    {
        $html = <<<'HTML'
        <article id="post-5649" class="post-5649 post type-post status-publish category-messes">
          <ul><li>
            <h5 itemprop="headline"><a href="https://choralepolefontainebleau.org/bibliotheque/messes/sanctus-du-partage-5649/"
               title="Sanctus messe du partage" itemprop="url">
              Sanctus messe du partage</a>          </a></h5>
          </li></ul>
        </article>
        <article id="post-5253" class="post-5253 post type-post status-publish category-messes">
          <ul><li>
            <h5 itemprop="headline"><a href="https://choralepolefontainebleau.org/bibliotheque/messes/kyrie-du-partage-5253/"
               title="Kyrie messe du partage" itemprop="url">
              Kyrie messe du partage</a>          </a></h5>
          </li></ul>
        </article>
        <article id="post-5649-bis">
          <h5 itemprop="headline"><a href="https://choralepolefontainebleau.org/bibliotheque/messes/sanctus-du-partage-5649/" title="doublon">Doublon</a></h5>
        </article>
        HTML;

        $fiches = Site::parseListe($html);

        $this->assertCount(2, $fiches);
        $this->assertSame(
            [
                'ref'   => '5649',
                'url'   => 'https://choralepolefontainebleau.org/bibliotheque/messes/sanctus-du-partage-5649/',
                'titre' => 'Sanctus messe du partage',
            ],
            $fiches['5649']
        );
        $this->assertSame('Kyrie messe du partage', $fiches['5253']['titre']);
    }

    /** @return array<string,array{0:string,1:?string,2:?string}> */
    public static function titresFournisseur(): array
    {
        return [
            'sanctus, messe en minuscules'     => ['Sanctus messe du partage', 'sanctus', 'Messe du partage'],
            'kyrie, Messe déjà capitalisée'    => ['Kyrie Messe de Sainte Cécile', 'kyrie', 'Messe de Sainte Cécile'],
            'agnus, double espace'             => ['Agnus  messe de tous les saints', 'agnus', 'Messe de tous les saints'],
            'anamnèse accentuée'               => ['Anamnèse messe de la Trinité', 'anamnese', 'Messe de la Trinité'],
            'alléluia accentué'                => ['Alléluia messe de Saint Jean', 'alleluia', 'Messe de Saint Jean'],
            'kyrie en minuscules'              => ['kyrie messe des défunts (messe XVIII)', 'kyrie', 'Messe des défunts (messe XVIII)'],
            'gloria sans "messe" : ignoré'     => ['Gloria Milan', null, null],
            'kyrie sans "messe" : ignoré'      => ['Kyrie XVI', null, null],
            'partie non reconnue : ignorée'    => ['Acclamation à l’évangile : Gloire à Toi Seigneur', null, null],
        ];
    }

    /** @dataProvider titresFournisseur */
    public function testDetecter(string $titre, ?string $type, ?string $ordinaire): void
    {
        $resultat = Site::detecter($titre);

        if ($type === null) {
            $this->assertNull($resultat);
            return;
        }

        $this->assertNotNull($resultat);
        $this->assertSame($type, $resultat['type']);
        $this->assertSame($ordinaire, $resultat['ordinaire']);
    }
}
