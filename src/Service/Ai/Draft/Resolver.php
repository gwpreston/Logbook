<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Draft;

use DateTimeImmutable;
use IntlDateFormatter;
use Logbook\Domain\Ai\Draft\DraftKind;
use Logbook\Domain\Compliance\ComplianceType;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Maintenance\MaintenanceCategory;
use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Import\ImportVocabulary;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\DecimalParser;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns the words a model passes on from the user's message into Logbook's
 * own values (spec.md §7.26 *Drafting entries*), so the model never works
 * out a date, a grade or a category itself:
 *
 * - vehicles the user may add this kind of entry to, as candidates;
 * - grades and categories: the exact code, then the label or short label
 *   in the user's language or English, then a translated list of synonyms;
 *   a word that fits several, or none, is a question back;
 * - dates as ISO or words ("yesterday", "last Tuesday", "3 days ago") in
 *   the user's time zone, and terms ("a year", "6 months");
 * - numbers as the user's forms read them ("51,5" in German).
 *
 * Word lists live in the translations (`ask.draft.words.*`), so each
 * language brings its own. No pattern here uses a lookbehind.
 */
final readonly class Resolver
{
    public function __construct(
        private VehicleService $vehicles,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Vehicles the user may add this kind of entry to (active ones only).
     *
     * @return list<Vehicle>
     */
    public function candidates(User $user, DraftKind $kind): array
    {
        return $this->vehicles->listWith($user, $kind->ability());
    }

    public function today(User $user): DateTimeImmutable
    {
        return LocalTime::today($this->clock, $user->preferences->timeZone());
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * A calendar date from ISO or words, in the user's time zone; null when
     * the words name no date Logbook knows.
     */
    public function date(User $user, string $words): ?DateTimeImmutable
    {
        $iso = LocalTime::parseDate($words);
        if ($iso !== null) {
            return $iso;
        }
        $today = $this->today($user);
        $text = $this->normal($words);
        $locale = $user->preferences->locale;

        if ($this->matches($text, 'today', $locale)) {
            return $today;
        }
        if ($this->matches($text, 'yesterday', $locale)) {
            return $today->modify('-1 day');
        }
        if ($this->matches($text, 'day_before_yesterday', $locale)) {
            return $today->modify('-2 days');
        }
        foreach (['days_ago' => 'day', 'weeks_ago' => 'week', 'months_ago' => 'month'] as $list => $unit) {
            $count = $this->counted($text, $list, $locale);
            if ($count !== null) {
                return $unit === 'month'
                    ? LocalTime::addMonths($today, -$count)
                    : $today->modify(sprintf('-%d %s', $count, $unit));
            }
        }
        $weekday = $this->weekday($text, $locale);
        if ($weekday !== null) {
            // The most recent such day before today: "last Tuesday" on a Tuesday is a week ago.
            $back = ((int) $today->format('N') - $weekday + 7) % 7;

            return $today->modify(sprintf('-%d days', $back === 0 ? 7 : $back));
        }

        return null;
    }

    /**
     * A term as months or days ("a year" → 12 months, "6 weeks" → 42 days);
     * null when it names none.
     *
     * @return array{months: int, days: int}|null
     */
    public function term(User $user, string $words): ?array
    {
        $text = $this->normal($words);
        $locale = $user->preferences->locale;
        foreach (['year' => [12, 0], 'month' => [1, 0], 'week' => [0, 7], 'day' => [0, 1]] as $unit => [$months, $days]) {
            $count = $this->counted($text, 'term_' . $unit, $locale);
            if ($count !== null) {
                return ['months' => $count * $months, 'days' => $count * $days];
            }
        }

        return null;
    }

    /**
     * A number as the user's forms read it, canonical ("51,5" → "51.5" in
     * German); null when it is not one.
     */
    public function number(User $user, string $value): ?string
    {
        return DecimalParser::parse(trim($value), $user->preferences->locale);
    }

    /**
     * The fuel and grade a word names, for this vehicle: a grade (its
     * family follows), a family alone ("diesel"), or a question.
     *
     * @return array{fuel: ?Fuel, grade: ?FuelGrade}|string an unresolved word's message key
     */
    public function fuel(User $user, Vehicle $vehicle, string $word): array|string
    {
        $locale = $user->preferences->locale;
        $synonym = $this->synonym($word, 'grades', $locale);
        $grade = $synonym === null
            ? $this->vocabulary($locale)->grade($word)
            : FuelGrade::tryFrom($synonym);
        if ($grade !== null) {
            return ['fuel' => $grade->family(), 'grade' => $grade];
        }
        $family = Fuel::tryFrom($synonym ?? '')
            ?? Fuel::tryFrom((string) $this->vocabulary($locale)->choice(Fuel::class, 'fuel.fuel.', $word));
        if ($family !== null) {
            return ['fuel' => $family, 'grade' => null];
        }
        // "Unleaded", "super": several grades fit; the user says which.
        return in_array($vehicle->data->fuelType->fittingFamilies()[0], [Fuel::Petrol, Fuel::Diesel], true)
            ? 'ask.draft.question.grade'
            : 'ask.draft.question.fuel';
    }

    /**
     * A category or type word as its code for this kind of entry, or null.
     */
    public function category(User $user, DraftKind $kind, string $word): ?string
    {
        $locale = $user->preferences->locale;
        [$enum, $prefix, $list] = match ($kind) {
            DraftKind::Maintenance => [MaintenanceCategory::class, 'maintenance.category.', 'maintenance'],
            DraftKind::Expense => [ExpenseCategory::class, 'expense.category.', 'expense'],
            DraftKind::Document => [ComplianceType::class, 'compliance.type.', 'documents'],
            default => throw new \LogicException('This kind has no categories.'),
        };
        $code = $this->vocabulary($locale)->choice($enum, $prefix, $word) ?? $this->synonym($word, $list, $locale);

        return $code !== null && $enum::tryFrom($code) !== null ? $code : null;
    }

    private function vocabulary(string $locale): ImportVocabulary
    {
        return new ImportVocabulary($this->translator, $locale);
    }

    /**
     * A synonym list's code for a word (`ask.draft.words.<list>`:
     * "super unleaded=e5_98; premium unleaded=e5_97; …").
     */
    private function synonym(string $word, string $list, string $locale): ?string
    {
        $text = $this->normal($word);
        foreach ($this->entries('synonyms_' . $list, $locale) as $entry) {
            [$words, $code] = array_pad(explode('=', $entry, 2), 2, '');
            if ($this->normal($words) === $text && trim($code) !== '') {
                return trim($code);
            }
        }

        return null;
    }

    /**
     * Whether the text is one of a word list's phrases.
     */
    private function matches(string $text, string $list, string $locale): bool
    {
        foreach ($this->entries($list, $locale) as $phrase) {
            if ($this->normal($phrase) === $text) {
                return true;
            }
        }

        return false;
    }

    /**
     * The count in a counted phrase ("# days ago" matches "3 days ago" and,
     * with the list's number words, "three days ago"); null when none fits.
     */
    private function counted(string $text, string $list, string $locale): ?int
    {
        $numbers = $this->numberWords($locale);
        foreach ($this->entries($list, $locale) as $phrase) {
            $parts = explode('#', $this->normal($phrase), 2);
            if (count($parts) !== 2) {
                // A phrase with no count ("a week ago") means one.
                if ($this->normal($phrase) === $text) {
                    return 1;
                }
                continue;
            }
            [$before, $after] = $parts;
            if (!str_starts_with($text, $before) || !str_ends_with($text, $after)) {
                continue;
            }
            $count = trim(substr($text, strlen($before), strlen($text) - strlen($before) - strlen($after)));
            if (ctype_digit($count)) {
                return (int) $count;
            }
            if (isset($numbers[$count])) {
                return $numbers[$count];
            }
        }

        return null;
    }

    /**
     * ISO weekday (1 = Monday) named in a weekday phrase ("last @", "on @", "@").
     */
    private function weekday(string $text, string $locale): ?int
    {
        $names = [];
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'EEEE');
        $english = new IntlDateFormatter('en', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, 'EEEE');
        // 2026-01-05 was a Monday.
        for ($day = 1; $day <= 7; $day++) {
            $at = (new DateTimeImmutable('2026-01-04', LocalTime::utc()))->modify('+' . $day . ' days');
            $names[$this->normal((string) $formatter->format($at))] = $day;
            $names[$this->normal((string) $english->format($at))] ??= $day;
        }
        foreach ($this->entries('weekday', $locale) as $phrase) {
            $parts = explode('@', $this->normal($phrase), 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$before, $after] = $parts;
            if (!str_starts_with($text, $before) || !str_ends_with($text, $after)) {
                continue;
            }
            $name = trim(substr($text, strlen($before), strlen($text) - strlen($before) - strlen($after)));
            if (isset($names[$name])) {
                return $names[$name];
            }
        }

        return null;
    }

    /**
     * @return array<string, int> number word → value ("two" → 2)
     */
    private function numberWords(string $locale): array
    {
        $words = [];
        foreach ($this->entries('numbers', $locale) as $entry) {
            [$word, $value] = array_pad(explode('=', $entry, 2), 2, '');
            if (ctype_digit(trim($value))) {
                $words[$this->normal($word)] = (int) trim($value);
            }
        }

        return $words;
    }

    /**
     * A word list's entries in the user's language, then English.
     *
     * @return list<string>
     */
    private function entries(string $list, string $locale): array
    {
        $entries = [];
        foreach (array_unique([$locale, 'en']) as $language) {
            $text = $this->translator->trans('ask.draft.words.' . $list, [], null, $language);
            foreach (explode(';', $text) as $entry) {
                if (trim($entry) !== '') {
                    $entries[] = trim($entry);
                }
            }
        }

        return $entries;
    }

    /**
     * Lower case, single spaces, no punctuation but the list markers.
     */
    private function normal(string $text): string
    {
        $text = preg_replace('/[^\p{L}\p{N}#@=]+/u', ' ', mb_strtolower($text)) ?? '';

        return trim($text);
    }
}
