<?php

declare(strict_types=1);

namespace Tests;

use App\Import\TypeLiturgique;
use PHPUnit\Framework\TestCase;

final class TypeLiturgiqueTest extends TestCase
{
    public function testDeduireSansCoteResteSurLeLibelle(): void
    {
        $this->assertSame('communion', TypeLiturgique::deduire('Chant de communion'));
        $this->assertSame('psaume', TypeLiturgique::deduire('Psaume responsorial'));
        $this->assertSame('entree', TypeLiturgique::deduire(''));
        $this->assertSame('entree', TypeLiturgique::deduire('Veillée pascale'));
    }

    public function testCoteSecliRattrapeUnLibelleMuet(): void
    {
        // Libellé non reconnu → défaut « entree » sans la cote ; la cote tranche.
        $this->assertSame('communion', TypeLiturgique::deduire('Veillée pascale', 'D 5'));
        $this->assertSame('envoi', TypeLiturgique::deduire('', 'T 282'));
        $this->assertSame('psaume', TypeLiturgique::deduire('Cantique', 'ZL 24'));
    }

    public function testCoteSecliSureLemporteSurLeLibelle(): void
    {
        // Le libellé dit « communion », la cote dit « entrée » (rite A) : la cote gagne.
        $this->assertSame('entree', TypeLiturgique::deduire('Chant de communion', 'A 12'));
    }

    public function testCoteAmbigueLaisseLeLibelleDecider(): void
    {
        // Rite C = ordinaire, sans distinction de partie → le libellé tranche.
        $this->assertSame('gloria', TypeLiturgique::deduire('Gloire à Dieu', 'C 5'));
        $this->assertSame('kyrie', TypeLiturgique::deduire('Rite pénitentiel', 'C 5'));
        // Ordinaire mais libellé muet → défaut.
        $this->assertSame('entree', TypeLiturgique::deduire('', 'C 5'));
    }

    public function testCoteEtLibelleDaccord(): void
    {
        $this->assertSame('psaume', TypeLiturgique::deduire('Psaume 22', 'ZL 24'));
    }
}
