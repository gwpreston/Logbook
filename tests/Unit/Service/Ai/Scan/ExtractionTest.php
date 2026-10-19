<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Scan;

use Logbook\Domain\Ai\Scan\ScanKind;
use Logbook\Service\Ai\Scan\Extraction;
use Logbook\Service\Ai\Scan\OcrText;
use Logbook\Service\Ai\Scan\ReadBy;
use Logbook\Service\Ocr\OcrBox;
use Logbook\Service\Ocr\OcrLine;
use Logbook\Service\Ocr\OcrResult;
use Logbook\Service\Ocr\OcrWord;
use PHPUnit\Framework\TestCase;

/**
 * What a reading keeps of the cross-check (spec.md §7.27 *The cross-check*,
 * Phase 53.3): stored with the draft, kept when re-read as another kind.
 */
final class ExtractionTest extends TestCase
{
    public function testTheCrossCheckRoundTripsThroughStorage(): void
    {
        $reading = self::reading(['total'], ['found' => 2, 'flagged' => 1, 'unchecked' => 0]);

        $array = $reading->toArray();
        self::assertSame(['total'], $array['not_on_page']);
        self::assertSame(['found' => 2, 'flagged' => 1, 'unchecked' => 0], $array['cross_check']);

        $back = Extraction::fromStored($array);
        self::assertNotNull($back);
        self::assertSame(['total'], $back->notOnPage);
        self::assertTrue($back->isNotOnPage('total'));
        self::assertFalse($back->isNotOnPage('date'));
        self::assertSame(['found' => 2, 'flagged' => 1, 'unchecked' => 0], $back->crossCheck);
    }

    public function testAReadingWithoutACrossCheckStoresNone(): void
    {
        $array = (new Extraction(ScanKind::FuelReceipt, ['total' => ['value' => '10', 'evidence' => '10']]))->toArray();

        self::assertSame([], $array['not_on_page']);
        self::assertNull($array['cross_check']);
        self::assertNull(Extraction::fromStored($array)?->crossCheck);
    }

    public function testStoredUnknownFieldNamesAndBadCountsAreIgnored(): void
    {
        $stored = self::reading([], null)->toArray();
        $stored['not_on_page'] = ['total', 'no_such_field', 7];
        $stored['cross_check'] = ['found' => 3, 'flagged' => '1', 'unchecked' => 2.5];

        $back = Extraction::fromStored($stored);

        self::assertNotNull($back);
        self::assertSame(['total'], $back->notOnPage, 'only names the reading has');
        self::assertSame(['found' => 3, 'flagged' => 0, 'unchecked' => 0], $back->crossCheck, 'counts must be ints');

        $stored['cross_check'] = 'garbage';
        $stored['not_on_page'] = 'garbage';
        $again = Extraction::fromStored($stored);
        self::assertNotNull($again);
        self::assertNull($again->crossCheck);
        self::assertSame([], $again->notOnPage);
    }

    public function testReadingAsAnotherKindOrWithAReaderKeepsTheCrossCheck(): void
    {
        $reading = self::reading(['total'], ['found' => 1, 'flagged' => 1, 'unchecked' => 0]);

        foreach ([$reading->as(ScanKind::ServiceInvoice), $reading->withReadBy(new ReadBy('gpt-5', 'OpenAI'))] as $copy) {
            self::assertSame(['total'], $copy->notOnPage);
            self::assertSame(['found' => 1, 'flagged' => 1, 'unchecked' => 0], $copy->crossCheck);
        }
    }

    public function testCrossCheckedCountsEachValueAndNamesTheFlaggedOnes(): void
    {
        $reading = new Extraction(ScanKind::FuelReceipt, [
            'vendor' => ['value' => 'SHORELINE FUELS', 'evidence' => 'SHORELINE FUELS'],
            'total' => ['value' => 'GBP 68.28', 'evidence' => 'TOTAL GBP 68.28'],
            'date' => ['value' => 'zzzz qqqq', 'evidence' => 'zzzz qqqq'],
        ]);
        $page = [
            [['SHORELINE', 90.0], ['FUELS', 90.0], ['LTD', 90.0]],
            [['TOTAL', 90.0], ['GBP', 90.0], ['60.28', 90.0]],
        ];
        $lines = [];
        foreach ($page as $r => $words) {
            $out = [];
            foreach ($words as $w => [$text, $confidence]) {
                $out[] = new OcrWord($text, $confidence, new OcrBox(10 + 100 * $w, 10 + 30 * $r, 90, 20), 1);
            }
            $lines[] = new OcrLine($out, 1, 1);
        }

        $checked = $reading->crossChecked(OcrText::of(OcrResult::read($lines, 'eng', 'tesseract 5.3.0')));

        self::assertSame(['total', 'date'], $checked->notOnPage);
        self::assertSame(['found' => 1, 'flagged' => 2, 'unchecked' => 0], $checked->crossCheck);
    }

    /**
     * @param list<string> $notOnPage
     * @param array{found: int, flagged: int, unchecked: int}|null $counts
     */
    private static function reading(array $notOnPage, ?array $counts): Extraction
    {
        return new Extraction(
            ScanKind::FuelReceipt,
            ['total' => ['value' => '10.00', 'evidence' => 'TOTAL 10.00']],
            notOnPage: $notOnPage,
            crossCheck: $counts,
        );
    }
}
