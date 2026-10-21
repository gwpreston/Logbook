<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * The PHP extensions a bare-PHP install needs are the ones Composer asks for
 * and the ones docs/deployment.md lists (spec.md §10, Phase 53.9, K123,
 * #528): `src/` and `bin/` call `mb_strcut()` and `mb_strimwidth()`, which
 * symfony/polyfill-mbstring doesn't define, so composer.json requires
 * `ext-mbstring`. The polyfill declares that it provides `ext-mbstring`,
 * which would satisfy the requirement on a PHP without the extension, so
 * composer.json replaces it (#550) and no locked package may provide a
 * required extension: an install without mbstring is refused up front
 * rather than failing at its first AI request.
 */
final class RequiredExtensionsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** Part of PHP itself since 8.0: it can't be left out, so the docs don't name it. */
    private const ALWAYS_THERE = ['json'];

    public function testTheMbstringCallsNeedTheExtensionAndComposerRequiresIt(): void
    {
        $calls = $this->mbstringCalls();
        self::assertContains('mb_strcut', $calls, 'the scan finds the calls');

        self::assertContains('mbstring', $this->requiredExtensions(), '#528');
    }

    /**
     * A locked package that `provide`s a required extension (as
     * symfony/polyfill-mbstring does `ext-mbstring`) makes Composer accept a
     * PHP without it (#550).
     */
    public function testNoLockedPackageStandsInForARequiredExtension(): void
    {
        $lock = json_decode((string) file_get_contents(self::ROOT . '/composer.lock'), true);
        self::assertIsArray($lock);

        $standIns = [];
        foreach ([...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])] as $package) {
            if (!is_array($package)) {
                continue;
            }
            foreach (array_keys((array) ($package['provide'] ?? [])) as $provided) {
                $provided = (string) $provided;
                $required = in_array(substr($provided, 4), $this->requiredExtensions(), true);
                if (str_starts_with($provided, 'ext-') && $required) {
                    $name = $package['name'] ?? '?';
                    $standIns[] = (is_string($name) ? $name : '?') . ' provides ' . $provided;
                }
            }
        }

        self::assertSame([], $standIns, 'replace it in composer.json so Composer checks the real extension');
        self::assertArrayHasKey('symfony/polyfill-mbstring', (array) ($this->composer()['replace'] ?? []));
    }

    public function testTheDeploymentGuideListsEveryRequiredExtension(): void
    {
        $requirements = $this->bareRequirements();

        foreach (array_diff($this->requiredExtensions(), self::ALWAYS_THERE) as $extension) {
            self::assertStringContainsString('`' . $extension . '`', $requirements, $extension . ' in docs/deployment.md');
        }
    }

    /** @return array<mixed> */
    private function composer(): array
    {
        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true);
        self::assertIsArray($composer);

        return $composer;
    }

    /**
     * The `ext-*` names composer.json requires, without the prefix.
     *
     * @return list<string>
     */
    private function requiredExtensions(): array
    {
        $composer = $this->composer();
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
                $code = (string) file_get_contents($file->getPathname());
                preg_match_all('/(?<![\w>:$\\\\])\\\\?(mb_[a-z_]+)\s*\(/', $code, $matches);
                array_push($calls, ...$matches[1]);
            }
        }
        $calls = array_values(array_unique($calls));
        sort($calls);

        return $calls;
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
