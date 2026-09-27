<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\I18n;

use Logbook\Support\I18n\AvailableLocales;
use Logbook\Support\I18n\LocaleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocaleResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string, string}>
     */
    public static function cases(): iterable
    {
        yield 'no header uses APP_LOCALE' => [null, 'de', 'de'];
        yield 'exact match' => ['fr', 'en', 'fr'];
        yield 'known region kept for formatting' => ['de-AT,de;q=0.9', 'en', 'de_AT'];
        yield 'unknown region falls back to language' => ['de-XX', 'en', 'de'];
        yield 'regional APP_LOCALE' => [null, 'en-gb', 'en_GB'];
        yield 'regional catalogue preferred' => ['pt-BR', 'en', 'pt_BR'];
        yield 'q-values ordered' => ['fr;q=0.4, de;q=0.8, es;q=0.1', 'en', 'de'];
        yield 'unsupported falls back to default' => ['ja, zh;q=0.5', 'fr', 'fr'];
        yield 'q=0 is refused' => ['de;q=0, fr', 'en', 'fr'];
        yield 'wildcard ignored' => ['*', 'fr', 'fr'];
        yield 'garbage header' => [';;;,,', 'de', 'de'];
        yield 'unsupported default falls back to English' => [null, 'xx', 'en'];
    }

    #[DataProvider('cases')]
    public function testResolve(?string $header, string $default, string $expected): void
    {
        $resolver = new LocaleResolver(new AvailableLocales(['de', 'en', 'fr', 'pt_BR']), $default);

        self::assertSame($expected, $resolver->resolve($header));
    }

    public function testUserPreferenceWins(): void
    {
        $resolver = new LocaleResolver(new AvailableLocales(['de', 'en']), 'en');

        self::assertSame('de', $resolver->resolve('en', 'de'));
        self::assertSame('en_GB', $resolver->resolve('de', 'en_GB'));
        // A saved locale whose catalogue was removed no longer applies.
        self::assertSame('en', $resolver->resolve('en', 'fr_FR'));
    }

    public function testDiscoversLocalesFromCatalogueFiles(): void
    {
        $dir = sys_get_temp_dir() . '/logbook-i18n-' . bin2hex(random_bytes(4));
        mkdir($dir);
        touch($dir . '/messages+intl-icu.de.php');
        touch($dir . '/messages+intl-icu.pt_BR.php');
        touch($dir . '/README.md');

        try {
            self::assertSame(['de', 'en', 'pt_BR'], AvailableLocales::fromDirectory($dir)->locales);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
