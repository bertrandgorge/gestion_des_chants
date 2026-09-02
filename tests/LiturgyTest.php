<?php

declare(strict_types=1);

namespace Tests;

use App\Liturgy;
use App\Models\Clocher;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LiturgyTest extends TestCase
{
    public function testSamediSoirPrendLeDimanche(): void
    {
        $messe = new DateTimeImmutable('2026-09-05 18:30'); // samedi
        $this->assertSame('2026-09-06', Liturgy::dateLiturgique($messe)->format('Y-m-d'));
    }

    public function testSamediMatinResteLeSamedi(): void
    {
        $messe = new DateTimeImmutable('2026-09-05 09:00');
        $this->assertSame('2026-09-05', Liturgy::dateLiturgique($messe)->format('Y-m-d'));
    }

    public function testSamedi17hPileEstAnticipe(): void
    {
        $messe = new DateTimeImmutable('2026-09-05 17:00');
        $this->assertSame('2026-09-06', Liturgy::dateLiturgique($messe)->format('Y-m-d'));
    }

    public function testMercrediResteLeJourMeme(): void
    {
        $messe = new DateTimeImmutable('2026-09-09 19:30');
        $this->assertSame('2026-09-09', Liturgy::dateLiturgique($messe)->format('Y-m-d'));
    }

    public function testProchaineDateParDefaut(): void
    {
        // Jeudi 3 septembre 2026, 12:00
        $now = new DateTimeImmutable('2026-09-03 12:00');
        $clocher = ['jour_defaut' => 6, 'heure_defaut' => '18:30:00']; // samedi 18h30
        $next = Clocher::prochaineDateParDefaut($clocher, $now);
        $this->assertSame('2026-09-05 18:30', $next->format('Y-m-d H:i'));
    }

    public function testProchaineDateParDefautSauteAujourdhuiSiPasse(): void
    {
        $now = new DateTimeImmutable('2026-09-05 19:00'); // samedi soir, après 18h30
        $clocher = ['jour_defaut' => 6, 'heure_defaut' => '18:30:00'];
        $next = Clocher::prochaineDateParDefaut($clocher, $now);
        $this->assertSame('2026-09-12 18:30', $next->format('Y-m-d H:i'));
    }
}
