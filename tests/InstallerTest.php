<?php

declare(strict_types=1);

namespace Tests;

use App\Installer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class InstallerTest extends TestCase
{
    /** @param array<string,string> $overrides */
    private static function invoke(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(Installer::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, ...$args);
    }

    /** @return array<string,string> */
    private static function values(array $overrides = []): array
    {
        return array_merge([
            'db_host'        => 'localhost',
            'db_name'        => 'gdc',
            'db_user'        => 'app',
            'db_pass'        => 'secret',
            'smtp_host'      => 'smtp.example.fr',
            'smtp_port'      => '587',
            'smtp_user'      => 'no-reply@example.fr',
            'smtp_pass'      => 'pw',
            'smtp_secure'    => 'tls',
            'smtp_from'      => 'no-reply@example.fr',
            'smtp_from_name' => 'Feuilles de messe',
            'app_base_url'   => 'https://paroisse.fr',
            'test_email'     => 'cure@paroisse.fr',
        ], $overrides);
    }

    public function testRenderConfigProduitDuPhpValideRenvoyantLaConfiguration(): void
    {
        $php = self::invoke('renderConfig', self::values([
            // Caractères qui casseraient une génération naïve.
            'db_pass' => "quote'\"and\\slash",
        ]));

        $config = eval('?>' . $php);

        $this->assertIsArray($config);
        $this->assertSame('localhost', $config['db']['host']);
        $this->assertSame("quote'\"and\\slash", $config['db']['pass']);
        $this->assertSame(587, $config['smtp']['port']);
        $this->assertSame('tls', $config['smtp']['secure']);
        $this->assertSame('https://paroisse.fr', $config['app']['base_url']);
        $this->assertFalse($config['app']['debug']);
    }

    public function testRenderConfigUtiliseLUtilisateurSmtpQuandLExpediteurEstVide(): void
    {
        $php = self::invoke('renderConfig', self::values([
            'smtp_from'      => '',
            'smtp_from_name' => '',
        ]));
        $config = eval('?>' . $php);

        $this->assertSame('no-reply@example.fr', $config['smtp']['from']);
        $this->assertSame('Feuilles de messe', $config['smtp']['from_name']);
    }

    public function testValidateDbSignaleLesChampsManquants(): void
    {
        $errors = self::invoke('validateDb', self::values(['db_name' => '', 'db_user' => '']));

        $this->assertCount(2, $errors);
    }

    public function testValidateAppRefuseUneUrlInvalide(): void
    {
        $this->assertNotEmpty(self::invoke('validateApp', self::values(['app_base_url' => 'pas-une-url'])));
        $this->assertSame([], self::invoke('validateApp', self::values()));
    }

    public function testValidateMailExigeUneAdresseDeTestValideUniquementPourLeTest(): void
    {
        $withTest = self::invoke('validateMail', self::values(['test_email' => 'invalide']), true);
        $withoutTest = self::invoke('validateMail', self::values(['test_email' => 'invalide']), false);

        $this->assertNotEmpty($withTest);
        $this->assertSame([], $withoutTest);
    }
}
