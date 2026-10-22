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

    /**
     * K47, #454: an answer's number is read in the user's locale, and the other way only when
     * written as a raw value, so a 1000× error is flagged.
     */
    public function testAnEnglishGroupedNumberIsNeverReadAsADecimal(): void
    {
        $result = ['{"price":{"per_litre":"1.459","display":"£1.459/l"},"volume":{"litres":"45.123","display":"45.12 l"}}'];
        $check = static fn (string $answer, string $locale): array => (new GroundingCheck())->ungrounded($answer, $result, $locale);

        self::assertSame(['1,459', '45,123'], $check('It cost £1,459 per litre for 45,123 litres.', 'en_GB'));
        self::assertSame([], $check('It cost £1.459 per litre for 45.123 litres.', 'en_GB'));
        self::assertSame([], $check('Es kostete 1,459 € pro Liter für 45,123 Liter.', 'de_DE'), 'the German reading');
        self::assertSame([], $check('Der Rohwert ist 1.459.', 'de_DE'), 'a raw value the model copied as it was');
        self::assertSame(['1,5'], $check('About 1,5 litres.', 'en_GB'), 'not a number in English, so not grounded');
    }

    /**
     * K59: a plain space groups thousands, and a space-grouped number is grounded by its whole
     * value or by each of its parts.
     */
    public function testAPlainSpaceGroupsThousands(): void
    {
        $result = ['{"total":{"amount":"1234.56","currency":"EUR"}}'];
        $check = static fn (string $answer, string $locale): array => (new GroundingCheck())->ungrounded($answer, $result, $locale);

        self::assertSame([], $check('Insgesamt 1 234,56 €.', 'de_DE'));
        self::assertSame([], $check('Total : 1 234,56 €.', 'fr_FR'));
        self::assertSame([], $check('Cost 1 234.56', 'en_GB'));
        self::assertSame(['1 299,00'], $check('Insgesamt 1 299,00 €.', 'de_DE'));

        $counts = ['{"fill_ups":3,"distance":{"km":"400"}}'];
        self::assertSame([], (new GroundingCheck())->ungrounded('3 400 km', $counts, 'en_GB'), 'two figures side by side');
        self::assertSame(['13 400'], (new GroundingCheck())->ungrounded('13 400 km', $counts, 'en_GB'), '13 is in neither');
        self::assertSame([], (new GroundingCheck())->ungrounded('3 and 400', ['Fill-ups 3 400 km'], 'en_GB'), 'a source’s parts count too');
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
