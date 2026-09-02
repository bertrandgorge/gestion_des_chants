<?php

declare(strict_types=1);

namespace Tests;

use App\Import\ChantonsEnEglise as Site;
use PHPUnit\Framework\TestCase;

final class ImportChantonsEnEgliseTest extends TestCase
{
    private function voirTexte(string $h1, string $corps, string $paroles): string
    {
        return <<<HTML
        <div class="modal-body">
            <div class="chantons">
                <div class="my-3"></div>
                <div class="small text-muted">
                    <h1>{$h1}</h1>
                    {$corps}
                </div>
                <p class="py-4">
                    {$paroles}
                </p>
            </div>
        </div>
        HTML;
    }

    public function testParseCatalogueExtraitEtDedoublonne(): void
    {
        $html = '
            <div><a class="nocolor" href="/chant/14091/devenez-ce-que-vous-recevez">
                Devenez ce que vous recevez
                (D68-39/AELF)
            </a></div>
            <div><a class="nocolor" href="/chant/663/alleluia-tu-nous-aimes">
                Alleluia, tu nous aimes
                (I15-11)
            </a></div>
            <div><a class="nocolor" href="/chant/663/alleluia-tu-nous-aimes">
                Alleluia, tu nous aimes
            </a></div>';

        $chants = Site::parseCatalogue($html);

        $this->assertCount(2, $chants);
        $this->assertSame(
            ['id' => 14091, 'slug' => 'devenez-ce-que-vous-recevez', 'titre' => 'Devenez ce que vous recevez'],
            $chants[14091]
        );
        $this->assertSame('Alleluia, tu nous aimes', $chants[663]['titre']);
    }

    public function testCatalogueTronque(): void
    {
        $court = '<a class="nocolor" href="/chant/1/a">A</a>';
        $this->assertFalse(Site::catalogueTronque($court));

        $long = '';
        for ($i = 1; $i <= Site::LIMITE_CATALOGUE; $i++) {
            $long .= '<a class="nocolor" href="/chant/' . $i . '/x">Titre ' . $i . "</a>\n";
        }
        $this->assertTrue(Site::catalogueTronque($long));
    }

    public function testParseVoirTexteComplet(): void
    {
        $corps = '
            <div>Auteur : Jean-Louis Fradon</div>
            <div>Compositeur : Bruno Ben</div>
            <div>Editeur : Éditions de l&#039;Emmanuel</div>
            <div>Cote Secli : D68-39</div>
            <div class="mt-3">Chant de communion</div>
            <div class="my-1"><span>Publié dans</span></div>';
        // Lignes séparées par un simple <br /> + \r\n (comme sur le site), partie par <br /><br />.
        $paroles = "REFRAIN<br />\r\nDevenez ce que vous recevez,<br />\r\nVous &#234;tes le corps du Christ.<br />\r\n<br />\r\n1<br />\r\nBaptis&#233;s en un seul Esprit,<br />\r\nnous ne formons qu'un seul corps.";

        $data = Site::parseVoirTexte($this->voirTexte('Devenez ce que vous recevez - D68-39', $corps, $paroles));

        $this->assertNotNull($data);
        $this->assertSame('Devenez ce que vous recevez', $data['titre']);
        $this->assertSame('D68-39', $data['code']);
        $this->assertSame('Jean-Louis Fradon / Bruno Ben', $data['auteur']);
        $this->assertSame('Chant de communion', $data['categorie']);
        $this->assertSame('communion', $data['type']);
        $this->assertSame(
            "R/ Devenez ce que vous recevez,\n"
            . "Vous êtes le corps du Christ.\n"
            . "\n"
            . "1. Baptisés en un seul Esprit,\n"
            . "nous ne formons qu'un seul corps.",
            $data['chant']
        );
        $this->assertStringNotContainsString('<br', $data['chant']);
    }

    public function testFormatParolesRefrainEtCouplets(): void
    {
        // Format « site » : marqueurs sur des lignes isolées, pas de ligne vide.
        $brut = "REFRAIN\nÀ la table du Seigneur\nCelui qui cherche Dieu\n1\nDieu souverain,\nRends-nous participants\n2\nReçois les prières\nQue cette liturgie";

        $attendu = "R/ À la table du Seigneur\n"
            . "Celui qui cherche Dieu\n"
            . "\n"
            . "1. Dieu souverain,\n"
            . "Rends-nous participants\n"
            . "\n"
            . "2. Reçois les prières\n"
            . "Que cette liturgie";

        $this->assertSame($attendu, Site::formatParoles($brut));
        $this->assertSame($attendu, Site::formatParoles($attendu), 'idempotent');
    }

    public function testFormatParolesVariantesDeMarqueurs(): void
    {
        $this->assertSame(
            "R/ Gloire à Dieu\n\n1. Paix sur la terre",
            Site::formatParoles("R/ Gloire à Dieu\n\nCouplet 1\nPaix sur la terre")
        );
        $this->assertSame(
            "R/ Refrain déjà en clair\n\n1. Premier vers",
            Site::formatParoles("Refrain : Refrain déjà en clair\n1) Premier vers")
        );
        // Un vers commençant par un nombre sans ponctuation n'est pas un marqueur.
        $this->assertSame(
            "1. Nous sommes\n1000 à chanter",
            Site::formatParoles("1\nNous sommes\n1000 à chanter")
        );
        // Ponctuations séparatrices variées : « 1.- », « 3 - », « 1/ », « 1- ».
        $this->assertSame(
            "1. Premier vers\n\n3. Troisième vers",
            Site::formatParoles("1.- Premier vers\n\n3 - Troisième vers")
        );
        $this->assertSame(
            "1. Début du couplet\n\n2. Suite",
            Site::formatParoles("1/ Début du couplet\n\n2- Suite")
        );
        // « R. » / « R- » / « R: » en début de ligne → « R/ ».
        $this->assertSame(
            "R/ Alléluia\nchantez au Seigneur",
            Site::formatParoles("R. Alléluia\nchantez au Seigneur")
        );
        $this->assertSame("R/ Amen", Site::formatParoles("R- Amen"));
        $this->assertSame("R/ Amen", Site::formatParoles("R : Amen"));
    }

    public function testParseVoirTexteCodeMultipleDansLeTitre(): void
    {
        $corps = '<div>Auteur : X</div><div>Cote Secli : U23-50-1 / B23-50-1</div>';
        $data = Site::parseVoirTexte($this->voirTexte(
            'A la prière de sainte Marie - U23-50-1',
            $corps,
            "Couplet un<br />\nCouplet deux, assez long pour passer le filtre."
        ));

        $this->assertNotNull($data);
        $this->assertSame('A la prière de sainte Marie', $data['titre']);
        $this->assertSame('U23-50-1 / B23-50-1', $data['code']);
    }

    public function testParseVoirTexteEntitesDoublementEncodees(): void
    {
        $data = Site::parseVoirTexte($this->voirTexte(
            'Alleluia',
            '<div>Auteur : X</div><div>Cote Secli : EL43-52</div>',
            'Alléluia&amp;#8201;! Pour l&#039;univers des jours nouveaux&amp;#8201;!'
        ));

        $this->assertNotNull($data);
        $this->assertSame("Alléluia ! Pour l'univers des jours nouveaux !", $data['chant']);
    }

    public function testParseVoirTexteSansParoles(): void
    {
        $contractuel = $this->voirTexte(
            'Alleluja ! Magnificat !',
            '<div>Auteur : Communauté du Chemin Neuf</div>',
            '<div class="text-danger">NB. Pour des raisons contractuelles, les paroles de ce chant ne sont pas disponibles.</div>'
        );
        $this->assertNull(Site::parseVoirTexte($contractuel));

        $vide = $this->voirTexte('A la prière de sainte Marie', '<div>Cote Secli : U23-50</div>', '');
        $this->assertNull(Site::parseVoirTexte($vide));
    }

    /**
     * @dataProvider fournitTypes
     */
    public function testTypeInterne(string $categorie, string $attendu): void
    {
        $type = Site::typeInterne($categorie);
        $this->assertSame($attendu, $type);
        $this->assertTrue(
            \App\SectionTypes::estTypeDefaut($type),
            "« {$type} » doit être un type de SectionTypes::DEFAUT"
        );
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function fournitTypes(): array
    {
        return [
            'communion'      => ['Chant de communion', 'communion'],
            'entrée'         => ["Chant d'entrée", 'entree'],
            'rassemblement'  => ['Chant de rassemblement et d’ouverture.', 'entree'],
            'envoi'          => ["Chant d'envoi", 'envoi'],
            'offertoire'     => ['Offertoire', 'offertoire'],
            // Pas de section dédiée : l'acclamation/alléluia → evangile, l'Agneau → communion.
            'acclamation'    => ["acclamation de l'Évangile", 'evangile'],
            'alléluia'       => ['Alléluia', 'evangile'],
            'agneau'         => ['Agneau de Dieu', 'communion'],
            'psaume'         => ['Psaume responsorial', 'psaume'],
            'pénitentiel'    => ['Rite pénitentiel.', 'kyrie'],
            'gloria'         => ['Gloire à Dieu', 'gloria'],
            // Non reconnu / vide → type par défaut.
            'inconnu'        => ['Veillée Pascale', Site::TYPE_DEFAUT],
            'vide'           => ['', Site::TYPE_DEFAUT],
        ];
    }

    public function testNomSection(): void
    {
        $this->assertSame('Chant de communion', Site::nomSection('chant de communion', 'communion'));
        $this->assertSame('Communion', Site::nomSection('', 'communion'));
        // Type non reconnu dans DEFAUT → « Chant ».
        $this->assertSame('Chant', Site::nomSection('', 'xyz'));
        // Catégorie = commentaire libre (non identifiable) → libellé du type.
        $this->assertSame("Chant d'entrée", Site::nomSection('USCAE 0010', 'entree'));
        $this->assertSame("Chant d'entrée", Site::nomSection('Premier tableau.', 'entree'));
        // Catégorie trop longue → libellé du type.
        $long = str_repeat('description très détaillée ', 10);
        $this->assertSame('Évangile', Site::nomSection($long, 'evangile'));
    }

    public function testUrls(): void
    {
        $this->assertSame('https://www.chantonseneglise.fr/chant/14091/devenez', Site::urlChant(14091, 'devenez'));
        $this->assertSame('https://www.chantonseneglise.fr/voir-texte/14091', Site::urlVoirTexte(14091));
        $this->assertSame("https://www.chantonseneglise.fr/catalogue/chant/L%27", Site::urlCatalogue("L'"));
    }
}
