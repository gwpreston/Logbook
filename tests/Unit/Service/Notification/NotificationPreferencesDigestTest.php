<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Notification;

use Logbook\Service\Notification\ChannelCategories;
use Logbook\Service\Notification\DigestSection;
use Logbook\Service\Notification\NotificationPreferences;
use Logbook\Service\Notification\QuietHours;
use PHPUnit\Framework\TestCase;

/**
 * The `digest_include` preference (spec.md §6, §7.11 *The monthly
 * briefing*, #363): absent reads as every section, `digest` stays a boolean.
 */
final class NotificationPreferencesDigestTest extends TestCase
{
    public function testNoStoredChoiceReadsAsAllSections(): void
    {
        $preferences = NotificationPreferences::fromArray(['channels' => null, 'digest' => true]);

        self::assertNull($preferences->digestSections);
        foreach (DigestSection::cases() as $section) {
            self::assertTrue($preferences->digestIncludes($section));
        }
        self::assertArrayNotHasKey('digest_include', $preferences->toArray());
        self::assertArrayNotHasKey('digest_include', NotificationPreferences::fromArray(null)->toArray());
    }

    public function testAnEmptyChoiceIncludesNothing(): void
    {
        $preferences = NotificationPreferences::fromArray(['digest' => true, 'digest_include' => []]);

        self::assertSame([], $preferences->digestSections);
        foreach (DigestSection::cases() as $section) {
            self::assertFalse($preferences->digestIncludes($section));
        }
        self::assertSame([], $preferences->toArray()['digest_include'] ?? null);
    }

    public function testAChoiceRoundTripsAndIsPutInTheMessageOrder(): void
    {
        $preferences = NotificationPreferences::fromArray(['digest' => true, 'digest_include' => ['insights', 'attention']]);

        self::assertSame([DigestSection::Attention, DigestSection::Insights], $preferences->digestSections);
        self::assertTrue($preferences->digestIncludes(DigestSection::Insights));
        self::assertFalse($preferences->digestIncludes(DigestSection::LastMonth));
        self::assertSame(['attention', 'insights'], $preferences->toArray()['digest_include'] ?? null);
        $again = NotificationPreferences::fromArray($preferences->toArray());
        self::assertEquals($preferences, $again);
    }

    public function testUnknownNonStringAndRepeatedValuesAreIgnored(): void
    {
        $preferences = NotificationPreferences::fromArray(
            ['digest_include' => ['last_month', 'bogus', 7, null, ['x'], 'last_month', 'due']],
        );

        self::assertSame([DigestSection::LastMonth], $preferences->digestSections);
    }

    public function testAnythingButAListReadsAsAll(): void
    {
        self::assertNull(NotificationPreferences::fromArray(['digest_include' => 'attention'])->digestSections);
        self::assertNull(NotificationPreferences::fromArray(['digest_include' => null])->digestSections);
    }

    public function testDigestStaysABoolean(): void
    {
        $with = NotificationPreferences::fromArray(['digest' => true, 'digest_include' => ['insights']]);
        self::assertTrue($with->toArray()['digest']);
        self::assertFalse($with->withDigest(false)->toArray()['digest']);
        self::assertFalse(NotificationPreferences::fromArray(['digest' => 'yes', 'digest_include' => []])->digest);
        self::assertFalse(NotificationPreferences::fromArray(['digest' => 1])->digest);
    }

    public function testWithDigestSectionsNormalisesAndEveryOtherWitherKeepsThem(): void
    {
        $preferences = (new NotificationPreferences())
            ->withDigestSections([DigestSection::Insights, DigestSection::Attention, DigestSection::Insights]);
        self::assertSame([DigestSection::Attention, DigestSection::Insights], $preferences->digestSections);

        $kept = [
            $preferences->withChannel('email', false),
            $preferences->withDigest(true),
            $preferences->withoutLegacyGotifyToken(),
            $preferences->withEmailCategories(ChannelCategories::all()),
            $preferences->withQuiet(QuietHours::fromStored(['start' => '22:00', 'end' => '07:00'])),
        ];
        foreach ($kept as $next) {
            self::assertSame([DigestSection::Attention, DigestSection::Insights], $next->digestSections);
        }
        self::assertSame([], $preferences->withDigestSections([])->digestSections);
    }
}
