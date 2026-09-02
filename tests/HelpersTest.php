<?php

declare(strict_types=1);

namespace Tests;

use App\Aelf;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testSlugify(): void
    {
        $this->assertSame('paroisse-saint-jean', slugify('Paroisse Saint-Jean'));
        $this->assertSame('notre-dame-de-la-garde', slugify('Notre-Dame de la Garde'));
        $this->assertSame('eglise-ste-therese', slugify('Église Ste Thérèse'));
    }

    public function testDatetimeSlugRoundTrip(): void
    {
        $slug = datetime_slug('2026-09-06 18:30:00');
        $this->assertSame('2026-09-06-1830', $slug);
        $this->assertSame('2026-09-06 18:30', parse_datetime_slug($slug)->format('Y-m-d H:i'));
    }

    public function testParseDatetimeSlugVariantes(): void
    {
        $this->assertSame('2026-09-06 18:30', parse_datetime_slug('2026-09-06-18h30')->format('Y-m-d H:i'));
        $this->assertSame('2026-09-06 00:00', parse_datetime_slug('2026-09-06')->format('Y-m-d H:i'));
        $this->assertNull(parse_datetime_slug('n-importe-quoi'));
    }

    public function testAelfHtmlVersTexte(): void
    {
        $html = "<p>Venez, crions de joie<br />\nacclamons notre Rocher&nbsp;!</p>\n\n<p>Entrez, inclinez-vous</p>";
        $texte = Aelf::htmlVersTexte($html);
        $this->assertSame(
            "Venez, crions de joie\nacclamons notre Rocher !\n\nEntrez, inclinez-vous",
            $texte
        );
    }

    public function testStripGuillemetsAvecEspacesInsecables(): void
    {
        // «   Si tu n'avertis...   »
        $titre = "\u{00AB}\u{00A0}Si tu n\u{2019}avertis pas le m\u{00E9}chant\u{00A0}\u{00BB}";
        $propre = strip_guillemets($titre);

        $this->assertSame("Si tu n\u{2019}avertis pas le m\u{00E9}chant", $propre);
        $this->assertTrue(mb_check_encoding($propre, 'UTF-8'));
    }

    public function testCleanHtmlRetireScript(): void
    {
        $sale = '<p>Bonjour</p><script>alert(1)</script><a href="javascript:evil()" onclick="x()">lien</a>';
        $propre = clean_html($sale);
        $this->assertStringNotContainsString('<script>', $propre);
        $this->assertStringNotContainsString('onclick', $propre);
        $this->assertStringNotContainsString('javascript:', $propre);
        $this->assertStringContainsString('<p>Bonjour</p>', $propre);
    }
}
