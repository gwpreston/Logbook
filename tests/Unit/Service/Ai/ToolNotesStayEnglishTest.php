<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai;

use PHPUnit\Framework\TestCase;

/**
 * A tool result's `note` strings are hints for the model, so they stay English whatever the user's
 * language (spec.md §7.26, §7.28, K72, #488). This fails if one is read from a translation catalogue.
 */
final class ToolNotesStayEnglishTest extends TestCase
{
    public function testNoToolNoteIsTranslated(): void
    {
        $files = glob(__DIR__ . '/../../../../src/Service/Ai/Ask/Tool/*.php');
        self::assertIsArray($files);
        self::assertNotEmpty($files);

        $translated = [];
        $seen = 0;
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            // Each note's own expression: from its key to the next key or the end of the array.
            $count = preg_match_all("/'note'\\s*=>(.*?)(?=\\n\\s*(?:'[a-z_]+'\\s*=>|\\.\\.\\.)|\\n\\s*\\]|;)/s", $code, $notes);
            $seen += (int) $count;
            foreach ($notes[1] as $expression) {
                if (preg_match('/->trans\\(|translator|->t\\(|\\bt\\(/i', $expression) === 1) {
                    $translated[] = basename($file);
                }
            }
        }

        self::assertGreaterThan(10, $seen, 'the notes are still found');
        self::assertSame([], $translated, 'these tools translate a note; the notes stay English (#488)');
    }
}
