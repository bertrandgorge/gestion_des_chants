<?php

declare(strict_types=1);

namespace Tests;

use App\Models\RepertoireChant;
use PHPUnit\Framework\TestCase;

/**
 * Les autres méthodes de App\Models\RepertoireChant nécessitent une base de
 * données (comme le reste des modèles de l'application, non couverts ici) :
 * on ne teste que ses fonctions pures.
 */
final class RepertoireChantTest extends TestCase
{
    public function testFusionnerListeDedupliqueEtPreserveLaCasse(): void
    {
        $this->assertSame(
            'D 68-39, y 29-45',
            RepertoireChant::fusionnerListe('D 68-39', 'y 29-45, d 68-39')
        );
    }

    public function testFusionnerListeAvecUneListeVide(): void
    {
        $this->assertSame('Chant d\'entrée', RepertoireChant::fusionnerListe('', "Chant d'entrée"));
        $this->assertSame('Chant d\'entrée', RepertoireChant::fusionnerListe("Chant d'entrée", ''));
        $this->assertSame('', RepertoireChant::fusionnerListe('', ''));
    }

    public function testMeilleurPrivilegieLesParolesLesPlusPropres(): void
    {
        $propre = ['id' => 2, 'chant' => "R/ Criez de joie\n\n1. Au milieu de notre nuit", 'nb_couplets' => 1];
        $sale   = ['id' => 1, 'chant' => "Paroles et musique : X\n\nR/ Criez de joie\n\n1. Au milieu de notre nuit", 'nb_couplets' => 1];

        [$survivant, $perdant] = RepertoireChant::meilleur($sale, $propre);

        $this->assertSame(2, $survivant['id']);
        $this->assertSame(1, $perdant['id']);
    }

    public function testMeilleurPrivilegieLePlusDeCouplets(): void
    {
        $court = ['id' => 1, 'chant' => "1. Un seul couplet", 'nb_couplets' => 1];
        $long  = ['id' => 2, 'chant' => "1. Premier\n\n2. Second", 'nb_couplets' => 2];

        [$survivant, $perdant] = RepertoireChant::meilleur($court, $long);

        $this->assertSame(2, $survivant['id']);
        $this->assertSame(1, $perdant['id']);
    }

    public function testMeilleurDepartageParLePlusPetitId(): void
    {
        $a = ['id' => 5, 'chant' => "1. Identique", 'nb_couplets' => 1];
        $b = ['id' => 3, 'chant' => "1. Identique", 'nb_couplets' => 1];

        [$survivant] = RepertoireChant::meilleur($a, $b);

        $this->assertSame(3, $survivant['id']);
    }

    public function testContientCodeInsensibleALaCasseEtAuxEspaces(): void
    {
        $this->assertTrue(RepertoireChant::contientCode('D 68-39, IEV 19-06', ' iev 19-06 '));
        $this->assertFalse(RepertoireChant::contientCode('D 68-39', 'IEV 19-06'));
        $this->assertFalse(RepertoireChant::contientCode('', 'IEV 19-06'));
        $this->assertFalse(RepertoireChant::contientCode('D 68-39', ''));
    }

    public function testIndiceEmmanuelParCodeRepertoireCommun(): void
    {
        $source = ['code_repertoire' => 'IEV 19-06'];
        $candidatAvecCode = ['code' => 'D 68-39, IEV 19-06', 'auteur' => 'Jean Dupont'];
        $candidatSansRapport = ['code' => 'D 68-39', 'auteur' => 'Jean Dupont'];

        $this->assertTrue(RepertoireChant::indiceEmmanuel($source, $candidatAvecCode));
        $this->assertFalse(RepertoireChant::indiceEmmanuel($source, $candidatSansRapport));
    }

    public function testIndiceEmmanuelParMentionDansLesCredits(): void
    {
        $source = ['code_repertoire' => null];
        $candidat = ['code' => '', 'auteur' => "Communauté de l'Emmanuel (A. Fleury)"];

        $this->assertTrue(RepertoireChant::indiceEmmanuel($source, $candidat));
        $this->assertFalse(RepertoireChant::indiceEmmanuel($source, ['code' => '', 'auteur' => 'Jean Dupont']));
    }

    public function testIndiceCodeSurUneCoteCommune(): void
    {
        $source = ['code' => 'Y 29-45'];
        $this->assertTrue(RepertoireChant::indiceCode($source, ['code' => 'D 68-39, Y 29-45']));
        $this->assertFalse(RepertoireChant::indiceCode($source, ['code' => 'D 68-39']));
    }

    public function testMeilleurCandidatUnSeulTitreCorrespondant(): void
    {
        $source = ['titre' => "Je vous salue, Marie", 'chant' => 'R/ Je vous salue, Marie'];
        $candidats = [
            ['cle' => 1, 'titre' => 'Je vous salue Marie', 'chant' => 'peu importe'],
            ['cle' => 2, 'titre' => 'Un autre chant', 'chant' => 'peu importe'],
        ];

        $resultat = RepertoireChant::meilleurCandidat($source, $candidats, static fn () => false);

        $this->assertSame(1, $resultat);
    }

    public function testMeilleurCandidatAucunTitreCorrespondant(): void
    {
        $source = ['titre' => 'Chant introuvable', 'chant' => 'peu importe'];
        $candidats = [['cle' => 1, 'titre' => 'Autre chose', 'chant' => 'peu importe']];

        $this->assertNull(RepertoireChant::meilleurCandidat($source, $candidats, static fn () => true));
    }

    public function testMeilleurCandidatDesambiguisePlusieursTitresParLeCallback(): void
    {
        $source = ['titre' => 'Kyrie', 'chant' => 'peu importe', 'code_repertoire' => 'IEV 19-06'];
        $candidats = [
            ['cle' => 'a', 'titre' => 'Kyrie', 'chant' => 'x', 'code' => 'D 1-1', 'auteur' => ''],
            ['cle' => 'b', 'titre' => 'Kyrie', 'chant' => 'y', 'code' => 'D 2-2, IEV 19-06', 'auteur' => ''],
        ];

        $resultat = RepertoireChant::meilleurCandidat(
            $source,
            $candidats,
            static fn (array $s, array $c) => RepertoireChant::indiceEmmanuel($s, $c)
        );

        $this->assertSame('b', $resultat);
    }

    public function testMeilleurCandidatReplieSurLesDeuxPremieresLignesDesParoles(): void
    {
        $chantCible = "R/ Criez de joie, vous les pauvres de cœur\nVous les enfants bien-aimés du Seigneur";
        $source = ['titre' => 'Criez de joie', 'chant' => $chantCible];
        $candidats = [
            ['cle' => 'a', 'titre' => 'Criez de joie', 'chant' => "R/ Autre chose\nSans rapport", 'code' => '', 'auteur' => ''],
            ['cle' => 'b', 'titre' => 'Criez de joie', 'chant' => $chantCible, 'code' => '', 'auteur' => ''],
        ];

        $resultat = RepertoireChant::meilleurCandidat($source, $candidats, static fn () => false);

        $this->assertSame('b', $resultat);
    }

    public function testMeilleurCandidatAmbiguSansAucuneDesambiguisationRenvoieNull(): void
    {
        $source = ['titre' => 'Kyrie', 'chant' => 'R/ Un texte'];
        $candidats = [
            ['cle' => 'a', 'titre' => 'Kyrie', 'chant' => 'R/ Autre texte', 'code' => '', 'auteur' => ''],
            ['cle' => 'b', 'titre' => 'Kyrie', 'chant' => 'R/ Encore un autre', 'code' => '', 'auteur' => ''],
        ];

        $resultat = RepertoireChant::meilleurCandidat($source, $candidats, static fn () => false);

        $this->assertNull($resultat);
    }
}
