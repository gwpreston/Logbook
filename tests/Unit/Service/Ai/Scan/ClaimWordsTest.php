<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ai\Scan;

use Logbook\Domain\Incident\ClaimStatus;
use Logbook\Domain\Incident\WriteOffCategory;
use Logbook\Service\Ai\Scan\IncidentMatcher;
use Logbook\Service\Ai\Scan\Mapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A claim letter's words (spec.md §7.27 *Mapping*, Phase 27.2): where the
 * claim stands, the write-off category, and claim numbers as compared.
 * Anything unclear is left empty rather than guessed.
 */
final class ClaimWordsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?ClaimStatus}>
     */
    public static function statuses(): iterable
    {
        yield 'settled' => ['Settled', ClaimStatus::Settled];
        yield 'payment issued' => ['Payment issued', ClaimStatus::Settled];
        yield 'claim settled with a number' => ['Claim no. 4417 settled', ClaimStatus::Settled];
        yield 'paid' => ['Your claim has been paid.', ClaimStatus::Settled];
        yield 'declined' => ['declined', ClaimStatus::Declined];
        yield 'rejected' => ['Claim rejected', ClaimStatus::Declined];
        yield 'under review' => ['under review', null];
        yield 'a settlement offer' => ['Settlement offer of £8,500', null];
        yield 'not yet settled' => ['Not yet settled', null];
        yield 'payment pending' => ['Payment pending', null];
        yield 'both' => ['Part paid, part declined', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('statuses')]
    public function testClaimStatusWords(string $words, ?ClaimStatus $expected): void
    {
        self::assertSame($expected, Mapper::claimStatus($words));
    }

    /**
     * @return iterable<string, array{string, ?WriteOffCategory}>
     */
    public static function writeOffs(): iterable
    {
        yield 'Cat S' => ['Cat S', WriteOffCategory::CatS];
        yield 'Category N' => ['Category N (non-structural)', WriteOffCategory::CatN];
        yield 'cat. b' => ['cat. b', WriteOffCategory::CatB];
        yield 'Cat A' => ['CAT A', WriteOffCategory::CatA];
        yield 'structural only' => ['structural damage, repairable', WriteOffCategory::CatS];
        yield 'non-structural only' => ['non-structural', WriteOffCategory::CatN];
        yield 'none' => ['repairable, not a write-off', null];
        yield 'a cat in a word' => ['category unknown', null];
    }

    #[DataProvider('writeOffs')]
    public function testWriteOffWords(string $words, ?WriteOffCategory $expected): void
    {
        self::assertSame($expected, Mapper::writeOff($words));
    }

    public function testClaimNumbersMatchIgnoringCaseSpacesAndDashes(): void
    {
        self::assertSame(IncidentMatcher::claimKey('CLM 4417'), IncidentMatcher::claimKey('clm-4417'));
        self::assertSame('HSC55102', IncidentMatcher::claimKey(' hsc/55102 '));
        self::assertNotSame(IncidentMatcher::claimKey('CLM 4417'), IncidentMatcher::claimKey('CLM 4418'));
    }
}
