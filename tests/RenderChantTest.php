<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class RenderChantTest extends TestCase
{
    public function testRefrainSlashEnGras(): void
    {
        $html = render_chant("R/ Gloire à Dieu\n\n1. Premier couplet");
        $this->assertStringContainsString('<strong>R/ Gloire à Dieu</strong>', $html);
        $this->assertStringContainsString('chant-refrain', $html);
    }

    public function testRefrainPointEnGras(): void
    {
        $html = render_chant("R. Alléluia\n\n1. Couplet");
        $this->assertStringContainsString('<strong>R. Alléluia</strong>', $html);
    }

    public function testCoupletNonGras(): void
    {
        $html = render_chant("1. Un couplet simple\nsur deux lignes");
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringContainsString('Un couplet simple<br>sur deux lignes', $html);
    }

    public function testSeparationDoubleRetour(): void
    {
        $html = render_chant("R/ Refrain\n\n1. Couplet 1\n\n2. Couplet 2");
        $this->assertSame(3, substr_count($html, 'chant-partie'));
    }

    public function testEchappementHtml(): void
    {
        $html = render_chant('1. <script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCountCouplets(): void
    {
        $this->assertSame(0, count_couplets(''));
        $this->assertSame(3, count_couplets("R/ a\n\n1. b\n\n2. c"));
        // Hors refrain : ne compte que les couplets.
        $this->assertSame(2, count_couplets("R/ a\n\n1. b\n\n2. c", false));
        $this->assertSame(2, count_couplets("R. a\n\n1. b\n\n2. c", false));
        $this->assertSame(1, count_couplets("Un seul couplet sans refrain", false));
    }
}
