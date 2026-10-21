<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The PHP extensions a bare-PHP install needs are the ones Composer asks for
 * and the ones docs/deployment.md lists (spec.md §10, Phase 53.9, K123,
 * #528): an `mb_*` call that symfony/polyfill-mbstring doesn't define
 * (`mb_strcut()`, `mb_strimwidth()`) needs `ext-mbstring` in composer.json,
 * so an install without it is refused up front rather than failing at its
 * first AI request.
 */
final class RequiredExtensionsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** Part of PHP itself since 8.0: it can't be left out, so the docs don't name it. */
    private const ALWAYS_THERE = ['json'];

    public function testEveryMbstringCallIsPolyfilledOrTheExtensionIsRequired(): void
    {
        $calls = $this->mbstringCalls();
        self::assertContains('mb_strcut', $calls, 'the scan finds the calls');

        if (in_array('mbstring', $this->requiredExtensions(), true)) {
            return;
        }
        $missing = array_values(array_diff($calls, $this->polyfilled()));
        self::assertSame([], $missing, 'not in symfony/polyfill-mbstring, so composer.json must require ext-mbstring');
    }

    public function testTheDeploymentGuideListsEveryRequiredExtension(): void
    {
        $requirements = $this->bareRequirements();

        foreach (array_diff($this->requiredExtensions(), self::ALWAYS_THERE) as $extension) {
            self::assertStringContainsString('`' . $extension . '`', $requirements, $extension . ' in docs/deployment.md');
        }
        self::assertContains('mbstring', $this->requiredExtensions(), '#528');
    }

    /**
     * The `ext-*` names composer.json requires, without the prefix.
     *
     * @return list<string>
     */
    private function requiredExtensions(): array
    {
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require'] ?? null);

        $extensions = [];
        foreach (array_keys($composer['require']) as $package) {
            if (str_starts_with((string) $package, 'ext-')) {
                $extensions[] = substr((string) $package, 4);
            }
        }

        return $extensions;
    }

    /**
     * Every `mb_*` function called in src/ and bin/.
     *
     * @return list<string>
     */
    private function mbstringCalls(): array
    {
        $calls = [];
        foreach (['src', 'bin'] as $dir) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                preg_match_all('/(?<![\w>:$\\\\])\\\\?(mb_[a-z_]+)\s*\(/', (string) file_get_contents($file->getPathname()), $matches);
                array_push($calls, ...$matches[1]);
            }
        }
        $calls = array_values(array_unique($calls));
        sort($calls);

        return $calls;
    }

    /**
     * The functions symfony/polyfill-mbstring defines (none when it isn't
     * installed, which every call then needs the extension for).
     *
     * @return list<string>
     */
    private function polyfilled(): array
    {
        $functions = [];
        foreach (glob(self::ROOT . '/vendor/symfony/polyfill-mbstring/bootstrap*.php') ?: [] as $bootstrap) {
            preg_match_all('/function (mb_[a-z_]+)\s*\(/', (string) file_get_contents($bootstrap), $matches);
            array_push($functions, ...$matches[1]);
        }

        return array_values(array_unique($functions));
    }

    /** The *Requirements* list under *Bare PHP 8.4* in docs/deployment.md. */
    private function bareRequirements(): string
    {
        $guide = (string) file_get_contents(self::ROOT . '/docs/deployment.md');
        $start = strpos($guide, "## Bare PHP 8.4\n");
        self::assertNotFalse($start);
        $start = strpos($guide, "### Requirements\n", $start);
        self::assertNotFalse($start);
        $end = strpos($guide, "\n###", $start + 1);

        return substr($guide, $start, $end === false ? null : $end - $start);
    }
}
