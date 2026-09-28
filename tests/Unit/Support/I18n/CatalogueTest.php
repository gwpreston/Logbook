<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\I18n;

use IntlException;
use Logbook\Kernel;
use Logbook\Support\I18n\TranslationFiles;
use MessageFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Translation completeness (spec.md §7.14): every key the code uses exists
 * in English, the fallback; every other catalogue uses English's keys with
 * the same placeholders and valid ICU syntax; templates hold no hard-coded
 * text.
 */
final class CatalogueTest extends TestCase
{
    /**
     * Text that legitimately appears in templates as-is (file => strings).
     */
    private const array LITERAL_TEXT = [
        // Commands to type are not translated.
        'backup/index.twig' => ['php bin/backup.php create', 'php bin/backup.php restore logbook-backup.zip --yes'],
        // A macro that prints attributes, not text.
        'macros/ui.twig' => ['aria-describedby=', 'aria-invalid="true"'],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (TranslationFiles::in(Kernel::rootDir() . '/translations') as $file) {
            yield $file['locale'] => [$file['locale']];
        }
    }

    #[DataProvider('locales')]
    public function testCatalogueMatchesEnglish(string $locale): void
    {
        $english = self::catalogue('en');
        $messages = self::catalogue($locale);
        $problems = [];

        foreach ($messages as $key => $message) {
            if (!array_key_exists($key, $english)) {
                $problems[] = $key . ': not an English key';
                continue;
            }
            if (self::placeholders($message) !== self::placeholders($english[$key])) {
                $problems[] = $key . ': placeholders differ from English';
            }
            try {
                $formatter = new MessageFormatter($locale, $message);
            } catch (IntlException) {
                $formatter = null;
            }
            if ($formatter === null) {
                $problems[] = $key . ': invalid ICU message';
            }
        }

        self::assertSame([], $problems);
    }

    public function testSecondLocaleIsComplete(): void
    {
        $missing = array_diff(array_keys(self::catalogue('en')), array_keys(self::catalogue('de')));

        self::assertSame([], array_values($missing), 'German is shipped complete; keep it so when adding strings');
    }

    public function testEveryKeyTheCodeUsesExists(): void
    {
        $english = self::catalogue('en');
        $used = self::usedKeys();
        self::assertGreaterThan(400, count($used), 'the scan finds the keys in use');
        $missing = [];
        foreach ($used as $key => $where) {
            if (!array_key_exists($key, $english)) {
                $missing[] = $key . ' (' . $where . ')';
            }
        }

        self::assertSame([], $missing);
    }

    public function testTemplatesHaveNoHardCodedText(): void
    {
        $found = [];
        foreach (self::files(Kernel::rootDir() . '/templates', 'twig') as $relative => $source) {
            $text = $source;
            foreach (self::LITERAL_TEXT[$relative] ?? [] as $literal) {
                $text = str_replace(htmlspecialchars($literal, ENT_QUOTES), '', str_replace($literal, '', $text));
            }
            $text = (string) preg_replace(
                [
                    '/\{#.*?#\}/s',             // Twig comments
                    '/\{%.*?%\}/s',             // Twig tags
                    '/\{\{.*?\}\}/s',           // Twig output
                    '/<script\b.*?<\/script>/s',
                    '/<style\b.*?<\/style>/s',
                    '/<[^>]*>/s',               // HTML tags and their attributes
                    '/&[a-z0-9#]+;/i',          // entities
                ],
                ' ',
                $text,
            );
            if (preg_match_all('/\p{L}{2,}[\p{L}\s\'’.,!?-]*/u', $text, $m) > 0) {
                $found[] = $relative . ': ' . implode(' | ', array_map('trim', $m[0]));
            }
        }

        self::assertSame([], $found, 'user-facing text belongs in translations/');
    }

    /**
     * Keys referenced literally in templates and PHP. Keys built at run time
     * ('import.title.' ~ module.value) are covered by the page tests.
     *
     * @return array<string, string> key → where it was found
     */
    private static function usedKeys(): array
    {
        $patterns = [
            'twig' => [
                "/'([a-z0-9_]+(?:\.[a-z0-9_]+)+)'\|trans\b/",
                "/\btrans\('([a-z0-9_]+(?:\.[a-z0-9_]+)+)'/",
            ],
            'php' => [
                "/->flash\('[a-z]+', '([a-z0-9_]+(?:\.[a-z0-9_]+)+)'/",
                "/->trans\('([a-z0-9_]+(?:\.[a-z0-9_]+)+)'/",
                "/addError\('[a-z_]+', '([a-z0-9_]+(?:\.[a-z0-9_]+)+)'/",
                "/new InvalidBackup\('([a-z0-9_]+(?:\.[a-z0-9_]+)+)'/",
                "/'key' => '([a-z0-9_]+(?:\.[a-z0-9_]+)+)'/",
            ],
        ];

        $keys = [];
        foreach (['templates' => 'twig', 'src' => 'php'] as $directory => $extension) {
            foreach (self::files(Kernel::rootDir() . '/' . $directory, $extension) as $relative => $source) {
                foreach ($patterns[$extension] as $pattern) {
                    preg_match_all($pattern, $source, $m);
                    foreach ($m[1] as $key) {
                        $keys[$key] ??= $directory . '/' . $relative;
                    }
                }
            }
        }
        ksort($keys);

        return $keys;
    }

    /**
     * Argument names used in an ICU message ({name}, {count, plural, …}).
     *
     * @return list<string>
     */
    private static function placeholders(string $message): array
    {
        // Plural and select branches ("one {row}") are text, not arguments.
        $message = (string) preg_replace('/(?:\b(?:zero|one|two|few|many|other)|=\d+)\s*\{/', '(', $message);
        preg_match_all('/\{\s*([A-Za-z0-9_]+)\s*[,}]/', $message, $m);
        $names = array_values(array_unique($m[1]));
        sort($names);

        return $names;
    }

    /**
     * @return array<string, string> flattened key → message
     */
    private static function catalogue(string $locale): array
    {
        /** @var array<string, mixed> $nested */
        $nested = require Kernel::rootDir() . '/translations/messages+intl-icu.' . $locale . '.php';

        return self::flatten($nested);
    }

    /**
     * @param array<array-key, mixed> $nested
     * @return array<string, string>
     */
    private static function flatten(array $nested, string $prefix = ''): array
    {
        $flat = [];
        foreach ($nested as $key => $value) {
            $name = $prefix . $key;
            if (is_array($value)) {
                $flat += self::flatten($value, $name . '.');
            } elseif (is_string($value)) {
                $flat[$name] = $value;
            }
        }

        return $flat;
    }

    /**
     * @return array<string, string> path relative to $directory → contents
     */
    private static function files(string $directory, string $extension): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === $extension) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($files);

        return $files;
    }
}
