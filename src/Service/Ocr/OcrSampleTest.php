<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;

/**
 * Settings' *Test it* (spec.md §7.27 *Reading text from images*): reads
 * the sample receipt that comes with Logbook (`resources/ocr/`) under the
 * admin's lock, and says how long it took.
 */
final readonly class OcrSampleTest
{
    public const string SAMPLE = 'resources/ocr/sample-receipt.png';

    public function __construct(
        private OcrReader $reader,
        private AppSettings $settings,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{result: OcrResult, seconds: float}
     */
    public function run(int $userId, string $locale): array
    {
        $started = $this->clock->now();
        $result = $this->reader->read($userId, $this->settings->rootDir . '/' . self::SAMPLE, 'image/png', $locale);
        $seconds = (float) $this->clock->now()->format('U.u') - (float) $started->format('U.u');

        return ['result' => $result, 'seconds' => round(max(0.0, $seconds), 1)];
    }
}
