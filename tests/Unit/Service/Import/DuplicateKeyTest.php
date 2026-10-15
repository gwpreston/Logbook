<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Import;

use DateTimeImmutable;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Service\Import\DuplicateKey;
use PHPUnit\Framework\TestCase;

/**
 * Text saved before 3.15.0 may keep `\r\n` line breaks, while the same text
 * sent today is stored with `\n` (spec.md §8 *Validation*, #433): both give
 * the same duplicate key, so a re-import or a retried API call adds nothing.
 */
final class DuplicateKeyTest extends TestCase
{
    public function testLineBreaksDoNotMakeTheSameEntryLookNew(): void
    {
        $on = new DateTimeImmutable('2026-03-01');
        $key = static fn (string $note): string
            => DuplicateKey::of(new ExpenseEntryData($on, ExpenseCategory::Parking, '4.500', $note));

        self::assertSame($key("Legacy\r\nnote"), $key("legacy\nnote"));
        self::assertSame($key("a\rb"), $key("a\nb"));
        self::assertNotSame($key("legacy\nnote"), $key('legacy note'));
    }
}
