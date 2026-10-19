<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Scan\Rules;

use Logbook\Service\Ai\AiPreferences;
use Logbook\Tests\Support\QueryCounter;
use Logbook\Tests\Support\ScanTestCase;

/**
 * K118 (Phase 53.4): the Scan page works out how photos are read once,
 * and the AI status reads each model and connection once per page, so the
 * page costs the same with OCR on or off, and with rules or a model.
 */
final class ScanPageQueriesTest extends ScanTestCase
{
    private const string FAKE = __DIR__ . '/../../../Fixtures/ocr/fake-tesseract';
    /** Was 26 before Phase 53.4 (27 with a cross-checked Internet connection, K118). */
    private const int BUDGET = 11;

    public function testTheScanPageStaysWithinItsBudget(): void
    {
        foreach (['off', self::FAKE] as $binary) {
            foreach ([true, false] as $aiOn) {
                $this->scanApp(['TESSERACT_BINARY' => $binary]);
                $this->service($this->app, AiPreferences::class)->set($this->owner->id, $aiOn);
                if (!$aiOn && $binary === 'off') {
                    continue;
                }
                $this->browser->get('/scan');
                // Counted from a fresh connection, so the counter sees every statement.
                $this->connection($this->app)->close();
                $counter = QueryCounter::install($this->app);

                $queries = $counter->during(fn () => self::assertSame(200, $this->browser->get('/scan')->getStatusCode()));

                self::assertLessThanOrEqual(self::BUDGET, $queries, sprintf(
                    'OCR %s, AI %s: %s',
                    $binary === 'off' ? 'off' : 'on',
                    $aiOn ? 'on' : 'off',
                    implode("\n", $counter->statements),
                ));
            }
        }
    }
}
