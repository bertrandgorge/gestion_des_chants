<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Chant;
use PHPUnit\Framework\TestCase;

final class ChantDedupTest extends TestCase
{
    /**
     * Le même chant importé de trois sources (ponctuation du titre différente,
     * écho du titre + ligne de crédits en tête pour l'une d'elles) doit produire
     * la même clé.
     */
    public function testMemeChantTroisSourcesMemeCle(): void
    {
        $catechisme = Chant::cleDedup(
            'Criez de joie, Christ est ressuscité',
            "R/ Criez de joie, Christ est ressuscité !\nIl est vivant, comme il l’avait promis.\n\n"
            . "1. Au milieu de notre nuit,\nLa lumière a resplendi."
        );
        $chorale = Chant::cleDedup(
            'Criez de joie Christ est ressuscité',
            "R/ Criez de joie, Christ est ressuscité !\nIl est vivant comme il l'avait promis.\n\n"
            . "1. Au milieu de notre nuit,\nLa lumière a resplendi."
        );
        $chantons = Chant::cleDedup(
            'Criez de joie, Christ est ressuscité',
            "Criez de joie, Christ est ressuscité\n\nParoles et musique : Cissy Suijkerbuijk\n\n"
            . "R/ Criez de joie, Christ est ressuscité !\nIl est vivant, comme il l’avait promis.\n\n"
            . "1. Au milieu de notre nuit,"
        );

        $this->assertSame($catechisme, $chorale);
        $this->assertSame($catechisme, $chantons);
    }

    public function testHomonymesRefrainsDifferentsClesDifferentes(): void
    {
        $a = Chant::cleDedup('Gloire à Dieu', "R/ Gloire à Dieu au plus haut des cieux\net paix sur la terre");
        $b = Chant::cleDedup('Gloire à Dieu', "R/ Gloire à Dieu, Seigneur des univers");

        $this->assertNotSame($a, $b);
    }

    public function testSansRefrainUtiliseLePremierCouplet(): void
    {
        $cle = Chant::cleDedup(
            'Psaume 102',
            "1. Béni sois-tu Seigneur\nDieu de tendresse\n\n2. Bénis le Seigneur ô mon âme"
        );

        $this->assertSame('psaume102|benisoistuseigneur', $cle);
    }

    public function testCleInsensibleAuxAccentsPonctuationCasse(): void
    {
        $this->assertSame(
            Chant::cleDedup('ÉCOUTE, ton Dieu t’appelle', 'R/ Écoute ! Ton Dieu t’appelle...'),
            Chant::cleDedup('Ecoute ton Dieu t appelle', "R/  ecoute ton dieu t appelle")
        );
    }

    public function testScoreQualiteCompteLesLignesDeCredits(): void
    {
        $propre = "R/ Criez de joie\n\n1. Au milieu de notre nuit";
        $sale   = "Criez de joie\n\nParoles et musique : Cissy Suijkerbuijk\n\nR/ Criez de joie\n\n"
            . "Titre original (NL) : Jubel en juich\n© 2000, Stichting Emmanuel Nederland\n"
            . "Traduction : © 2003, Éditions de l’Emmanuel";

        $this->assertSame(0, Chant::scoreQualite($propre));
        $this->assertSame(4, Chant::scoreQualite($sale));
    }

    public function testPremieresLignesIgnoreAccentsPonctuationCasseEtLignesVides(): void
    {
        $a = Chant::premieresLignes("\n\nR/ Je vous salue, Marie !\npleine de grâce...\n\n1. Suite");
        $b = Chant::premieresLignes("R/ je vous salue marie\nPLEINE DE GRACE\n\nAutre suite");

        $this->assertSame($a, $b);
    }

    public function testPremieresLignesDistingueDesParolesDifferentes(): void
    {
        $a = Chant::premieresLignes("R/ Je vous salue, Marie\npleine de grâce");
        $b = Chant::premieresLignes("1. Un deuil de plus à Béthanie\nl'homme n'est qu'herbe vaine");

        $this->assertNotSame($a, $b);
    }

    public function testPremieresLignesVideSiAucuneLigneExploitable(): void
    {
        $this->assertSame('', Chant::premieresLignes("\n\n   \n"));
    }
}
