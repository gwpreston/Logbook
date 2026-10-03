<?php

declare(strict_types=1);

namespace Logbook\Service\FuelPrices;

/**
 * A feed's opening times as one line in OpenStreetMap's `opening_hours`
 * syntax ("Mo-Fr 06:00-22:00; Sa 07:00-21:00; Su off"), which reads the
 * same in every language and fits a station's free-text hours (spec.md
 * §7.34 *Linking stations*). Consecutive days with the same hours share a
 * range; every day open all day is "24/7".
 */
final class OpeningHours
{
    private const array DAYS = [
        'monday' => 'Mo',
        'tuesday' => 'Tu',
        'wednesday' => 'We',
        'thursday' => 'Th',
        'friday' => 'Fr',
        'saturday' => 'Sa',
        'sunday' => 'Su',
    ];

    /**
     * @param array<string, mixed>|null $hours as ProviderStation keeps them
     */
    public static function text(?array $hours, int $limit = 200): ?string
    {
        $days = is_array($hours['usual_days'] ?? null) ? $hours['usual_days'] : [];
        $spans = [];
        foreach (self::DAYS as $day => $short) {
            $entry = $days[$day] ?? null;
            $spans[$short] = is_array($entry) ? self::span($entry) : null;
        }
        if (array_filter($spans, static fn (?string $span): bool => $span !== null) === []) {
            return null;
        }
        if (count(array_unique($spans)) === 1 && reset($spans) === '00:00-24:00') {
            return '24/7';
        }

        // Runs of consecutive days with the same hours: [first, last, span].
        $runs = [];
        foreach ($spans as $short => $span) {
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last][2] === $span) {
                $runs[$last][1] = $short;
                continue;
            }
            $runs[] = [$short, $short, $span];
        }
        $parts = [];
        foreach ($runs as [$first, $lastDay, $span]) {
            if ($span !== null) {
                $parts[] = ($first === $lastDay ? $first : $first . '-' . $lastDay) . ' ' . $span;
            }
        }
        $text = implode('; ', $parts);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }

    /**
     * @param array<mixed> $entry
     */
    private static function span(array $entry): ?string
    {
        if (($entry['is_24_hours'] ?? false) === true) {
            return '00:00-24:00';
        }
        $open = is_string($entry['open'] ?? null) ? $entry['open'] : null;
        $close = is_string($entry['close'] ?? null) ? $entry['close'] : null;
        if ($open === null || $close === null) {
            return null;
        }
        if ($open === $close) {
            return 'off';
        }

        return $open . '-' . ($close === '00:00' ? '24:00' : $close);
    }
}
