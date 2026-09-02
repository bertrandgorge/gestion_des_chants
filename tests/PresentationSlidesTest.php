<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class PresentationSlidesTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function section(string $type, array $extra = []): array
    {
        return array_merge([
            'type' => $type,
            'nom' => ucfirst($type),
            'chant' => '',
            'contenu' => '',
            'titre' => '',
            'reference' => '',
            'introduction' => '',
            'acclamation' => '',
        ], $extra);
    }

    public function testUneDiapoParCoupletAvecRefrainRepete(): void
    {
        $slides = presentation_slides([
            $this->section('entree', ['chant' => "R/ Gloire à Dieu\n\n1. Premier couplet\n\n2. Second couplet"]),
        ]);

        $this->assertCount(2, $slides);
        $this->assertSame('refrain', $slides[0]['blocs'][0]['type']);
        $this->assertSame('R/ Gloire à Dieu', $slides[0]['blocs'][0]['texte']);
        $this->assertSame('1. Premier couplet', $slides[0]['blocs'][1]['texte']);
        $this->assertSame('R/ Gloire à Dieu', $slides[1]['blocs'][0]['texte']);
        $this->assertSame('2. Second couplet', $slides[1]['blocs'][1]['texte']);
        $this->assertSame([1, 2], [$slides[0]['couplet'], $slides[1]['couplet']]);
        $this->assertSame([2, 2], [$slides[0]['couplets'], $slides[1]['couplets']]);
    }

    public function testDecompteAbsentQuandUnSeulCouplet(): void
    {
        $slides = presentation_slides([
            $this->section('kyrie', ['chant' => 'Kyrie eleison']),
            $this->section('offertoire', ['chant' => "R/ Refrain\n\n1. Couplet unique"]),
            $this->section('premiere_lecture', ['titre' => 'Lecture', 'contenu' => '<p>…</p>']),
        ]);

        $this->assertNull($slides[0]['couplet']);
        $this->assertNull($slides[1]['couplet']);
        $this->assertNull($slides[2]['couplet']);
    }

    public function testChantSansCoupletTientSurUneDiapo(): void
    {
        $slides = presentation_slides([
            $this->section('kyrie', ['chant' => "Kyrie eleison\nChriste eleison"]),
        ]);

        $this->assertCount(1, $slides);
        $this->assertSame('couplet', $slides[0]['blocs'][0]['type']);
    }

    public function testLectureNAfficheQueLeTitre(): void
    {
        $slides = presentation_slides([
            $this->section('premiere_lecture', [
                'titre' => 'Lecture du livre de la Genèse',
                'reference' => 'Gn 1, 1-5',
                'contenu' => '<p>Au commencement, Dieu créa le ciel et la terre…</p>',
            ]),
        ]);

        $this->assertCount(1, $slides);
        $textes = array_column($slides[0]['blocs'], 'texte');
        $this->assertStringContainsString('Lecture du livre de la Genèse', implode(' ', $textes));
        $this->assertStringContainsString('Gn 1, 1-5', implode(' ', $textes));
        $this->assertStringNotContainsString('commencement', implode(' ', $textes));
    }

    public function testEvangileAfficheAcclamationEtTitreMaisPasLeTexte(): void
    {
        $slides = presentation_slides([
            $this->section('evangile', [
                'acclamation' => '<p>Alléluia. Alléluia.</p>',
                'titre' => 'Évangile de Jésus Christ selon saint Jean',
                'contenu' => '<p>En ce temps-là…</p>',
            ]),
        ]);

        $this->assertCount(1, $slides);
        $this->assertSame('acclamation', $slides[0]['blocs'][0]['type']);
        $this->assertSame('Alléluia. Alléluia.', $slides[0]['blocs'][0]['texte']);
        $joined = implode(' ', array_column($slides[0]['blocs'], 'texte'));
        $this->assertStringContainsString('selon saint Jean', $joined);
        $this->assertStringNotContainsString('temps-là', $joined);
    }

    public function testSectionVideIgnoree(): void
    {
        $this->assertSame([], presentation_slides([
            $this->section('offertoire'),
        ]));
    }
}
