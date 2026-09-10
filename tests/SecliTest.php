<?php

declare(strict_types=1);

namespace Tests;

use App\Import\Secli;
use App\SectionTypes;
use PHPUnit\Framework\TestCase;

final class SecliTest extends TestCase
{
    /**
     * @dataProvider fournitCotes
     *
     * @param list<string> $themes
     */
    public function testAnalyseJeton(string $jeton, bool $estCote, ?string $rite, ?string $type, array $themes): void
    {
        $this->assertSame($estCote, Secli::estCote($jeton), "estCote($jeton)");
        $this->assertSame($rite, Secli::rite($jeton), "rite($jeton)");
        $this->assertSame($type, Secli::type($jeton), "type($jeton)");
        $this->assertSame($themes, Secli::themes($jeton), "themes($jeton)");
    }

    /** @return array<string,array{0:string,1:bool,2:?string,3:?string,4:list<string>}> */
    public static function fournitCotes(): array
    {
        return [
            'communion espace'      => ['D 68-39', true, 'D', 'communion', []],
            'communion collé'       => ['D68-39', true, 'D', 'communion', []],
            'psaume officiel'       => ['ZL 24', true, 'Z', 'psaume', []],
            'psaume'                => ['Z 8', true, 'Z', 'psaume', []],
            'envoi collé'           => ['T282', true, 'T', 'envoi', []],
            'entrée'                => ['A 220', true, 'A', 'entree', []],
            'acclamation → alléluia' => ['U23-50', true, 'U', 'alleluia', []],
            'offertoire'            => ['B 3', true, 'B', 'offertoire', []],
            'temps + rite'          => ['GA 14-56', true, 'A', 'entree', ['Carême']],
            'Pentecôte + envoi'     => ['KT60', true, 'T', 'envoi', ['Pentecôte']],
            'Saints + hymne'        => ['WP228', true, 'P', null, ['Saints']],
            'Pâques + hymne'        => ['IP113', true, 'P', null, ['Pâques']],
            'deux temps'            => ['SM107', true, null, null, ['Défunts', 'Trinité']],
            'rite X'                => ['AX112', true, 'X', null, []],
            'ordinaire C'           => ['C512', true, 'C', null, []],
            'ordinaire AL'          => ['AL 5', true, null, null, []],
            'Avent officiel'        => ['EL43-52', true, null, null, ['Avent']],
            'IEV jeton'             => ['IEV 19-06', false, null, null, []],
            'IEV collé'             => ['IEV19-06', false, null, null, []],
            'nombre nu'             => ['19-06', false, null, null, []],
            'code éditeur EDIT'     => ['EDIT15-78', false, null, null, []],
            'code éditeur DEV'      => ['DEV309', false, null, null, []],
            'vide'                  => ['', false, null, null, []],
            'texte libre'           => ['Veillée pascale', false, null, null, []],
        ];
    }

    public function testEstOrdinaire(): void
    {
        $this->assertTrue(Secli::estOrdinaire('C512'));
        $this->assertTrue(Secli::estOrdinaire('AL 5'));
        $this->assertTrue(Secli::estOrdinaire('GC 12'));           // Carême + ordinaire
        $this->assertFalse(Secli::estOrdinaire('D 68-39'));
        $this->assertFalse(Secli::estOrdinaire('AX112'));          // rite X, pas C
        $this->assertFalse(Secli::estOrdinaire('IEV 19-06'));
    }

    public function testListeDeCotes(): void
    {
        // Séparateurs virgule ET « / ».
        $this->assertNull(Secli::type('U23-50-1 / B23-50-1'));         // U (alléluia) ≠ B (offertoire) → indécis
        $this->assertSame('entree', Secli::type('Y29-45 / A29-45'));   // Y non sûr, A sûr
        $this->assertSame('envoi', Secli::type('T282 / I282'));        // I = temps, pas rite
        // IEV ignoré, cote SECLI retenue.
        $this->assertSame('communion', Secli::type('IEV 19-06, D 68-39'));
        // Plusieurs cotes sûres en désaccord → aucune décision.
        $this->assertNull(Secli::type('A 220, D 5'));
        // Union ordonnée des temps.
        $this->assertSame(['Carême'], Secli::themes('GA 14, GX 20'));
        $this->assertSame(['Carême', 'Pâques'], Secli::themes('GA 14, IB 20'));
    }

    public function testTypeEstToujoursUnSlugValide(): void
    {
        $exemples = ['A 1', 'B 1', 'D 1', 'T 1', 'U 1', 'Z 1', 'ZL 1', 'GA 1', 'KD 1-2'];
        foreach ($exemples as $code) {
            $type = Secli::type($code);
            if ($type !== null) {
                $this->assertContains(
                    $type,
                    array_merge(SectionTypes::typesDefaut(), ['alleluia']),
                    "« {$type} » (cote {$code}) doit être un type de section valide"
                );
            }
        }
    }
}
