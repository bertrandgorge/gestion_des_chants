<?php

declare(strict_types=1);

namespace Tests;

use App\SectionTypes;
use PHPUnit\Framework\TestCase;

final class SectionTypesTest extends TestCase
{
    public function testImprimableParDefautSuitLeComportement(): void
    {
        $this->assertTrue(SectionTypes::imprimableParDefaut('entree'));       // chant
        $this->assertTrue(SectionTypes::imprimableParDefaut('gloria'));       // ordinaire
        $this->assertTrue(SectionTypes::imprimableParDefaut('psaume'));       // psaume
        $this->assertTrue(SectionTypes::imprimableParDefaut('meditation'));   // personnalisée -> chant

        $this->assertFalse(SectionTypes::imprimableParDefaut('premiere_lecture'));
        $this->assertFalse(SectionTypes::imprimableParDefaut('evangile'));
        $this->assertFalse(SectionTypes::imprimableParDefaut('priere_universelle'));
    }

    public function testSelectionImpressionAppliqueLesDefautsSansPreference(): void
    {
        $sections = [
            ['id' => 1, 'type' => 'entree'],
            ['id' => 2, 'type' => 'premiere_lecture'],
            ['id' => 3, 'type' => 'meditation'],
        ];

        $this->assertSame(
            [1 => true, 2 => false, 3 => true],
            SectionTypes::selectionImpression([], $sections)
        );
    }

    public function testSelectionImpressionLaPreferenceParoisseLEmporte(): void
    {
        $sections = [
            ['id' => 10, 'type' => 'entree'],
            ['id' => 11, 'type' => 'premiere_lecture'],
        ];
        $prefs = ['entree' => false, 'premiere_lecture' => true];

        $this->assertSame(
            [10 => false, 11 => true],
            SectionTypes::selectionImpression($prefs, $sections)
        );
    }
}
