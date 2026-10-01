<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Ask;

use Logbook\Service\Ai\Ask\GroundingCheck;
use PHPUnit\Framework\TestCase;

/**
 * spec.md §7.26 *Grounding check*.
 */
final class GroundingCheckTest extends TestCase
{
    private const string RESULT = '{"total":{"amount":"1284.50","currency":"GBP","display":"£1,284.50"},'
        . '"distance":{"km":"12482.300","display":"7,756 mi"},"economy":{"display":"48.3 mpg"}}';

    public function testACorrectAnswerPasses(): void
    {
        self::assertSame([], $this->check('You spent £1,284.50 over 7,756 mi, at 48.3 mpg.'));
    }

    public function testAnInventedFigureIsFlagged(): void
    {
        self::assertSame(['1,300'], $this->check('About £1,300 in all; £1,284.50 of it logged.'));
    }

    public function testGermanFormatsMatchTheRawValue(): void
    {
        self::assertSame([], $this->check('Du hast 1.284,50 € ausgegeben.', 'de_DE'));
        self::assertSame(['1.300'], $this->check('Rund 1.300 €.', 'de_DE'));
    }

    public function testARawValueCopiedFromTheResultMatches(): void
    {
        self::assertSame([], $this->check('The total is 1284.50 GBP.'));
    }

    public function testRoundingToTheShownPrecisionMatches(): void
    {
        self::assertSame([], $this->check('About £1,285, or £1,284.5, over 12,482 km.'));
        self::assertSame(['12.5'], $this->check('That is 12.5 km.'), 'a decimal that rounds from nothing');
    }

    public function testDatesYearsTimesAndSmallCountsAreNotFlagged(): void
    {
        $answer = 'On 31 March 2025, 2025-03-31 or 31/03/2025 at 14:30, across 3 fill-ups in 12 months.';
        self::assertSame([], $this->check($answer));
        self::assertSame([], $this->check('Am 31. März 2025 um 14:30.', 'de_DE'));
    }

    public function testNumbersInTheQuestionAndContextAreAllowed(): void
    {
        $sources = [
            self::RESULT,
            '{"vehicles":[{"name":"BMW 320d","registration":"AB65 CDE"}]}',
            'What did I spend over 400 miles?',
        ];
        $answer = 'Your 320d (AB65 CDE) cost £1,284.50 over those 400 miles.';

        self::assertSame([], (new GroundingCheck())->ungrounded($answer, $sources, 'en_GB'));
    }

    public function testEachFlaggedFigureIsListedOnce(): void
    {
        self::assertSame(['99.99', '250'], $this->check('£99.99, then £99.99 again, and 250 mi.'));
    }

    /**
     * @return list<string>
     */
    private function check(string $answer, string $locale = 'en_GB'): array
    {
        return (new GroundingCheck())->ungrounded($answer, [self::RESULT], $locale);
    }

    public function testRoundFiguresAreCheckedWhateverAnotherReadingWouldBe(): void
    {
        self::assertSame(['3,000'], $this->check('About £3,000 a year.'));
        self::assertSame(['1,950'], $this->check('Roughly £1,950.'));
        self::assertSame(['10,000'], $this->check('Every 10,000 mi.'));
        self::assertSame(['3.000'], $this->check('Rund 3.000 € im Jahr.', 'de_DE'));
        self::assertSame(['1.000'], $this->check('Alle 1.000 km.', 'de_DE'));
        self::assertSame([], $this->check('In 2025, 3 fill-ups over 12 months.'));
    }
}
