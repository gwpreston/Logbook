<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Import;

use Logbook\Kernel;
use Logbook\Service\Export\ExportModule;
use Logbook\Service\Import\ImportField;
use Logbook\Service\Import\ImportMapper;
use Logbook\Service\Import\ImportOptions;
use Logbook\Service\Import\ImportVocabulary;
use Logbook\Support\Csv\CsvReader;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\TranslatorFactory;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mapping page's first guess (spec.md §7.13): units named in the
 * header's brackets are chosen, else the owner's.
 */
final class ImportMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, VolumeUnit, VolumeUnit}>
     */
    public static function volumeHeaders(): iterable
    {
        yield 'UK gallons by name' => ['Volume (UK gallons)', 'en_US', 'USD', VolumeUnit::Litre, VolumeUnit::UkGallon];
        yield 'litres for a gallon owner' => ['Quantity (litres)', 'en_US', 'USD', VolumeUnit::UsGallon, VolumeUnit::Litre];
        yield 'US gal by symbol' => ['Volume (US gal)', 'en_GB', 'GBP', VolumeUnit::Litre, VolumeUnit::UsGallon];
        yield 'bare gal, US owner' => ['Volume (gal)', 'en_US', 'USD', VolumeUnit::Litre, VolumeUnit::UsGallon];
        yield 'bare gallons, UK owner' => ['Volume (gallons)', 'en_GB', 'GBP', VolumeUnit::Litre, VolumeUnit::UkGallon];
        yield 'bare gal, Irish owner' => ['Volume (gal)', 'en_IE', 'EUR', VolumeUnit::Litre, VolumeUnit::UkGallon];
        yield 'bare gal, owner paying in pounds' => ['Volume (gal)', 'en_US', 'GBP', VolumeUnit::Litre, VolumeUnit::UkGallon];
        yield 'bare gal, German owner' => ['Volume (gal)', 'de_DE', 'EUR', VolumeUnit::Litre, VolumeUnit::UsGallon];
        yield 'bare gal, UK-gallon owner abroad' => ['Volume (gal)', 'en_US', 'USD', VolumeUnit::UkGallon, VolumeUnit::UkGallon];
        yield 'kWh keeps the owner\'s' => ['Energy (kWh)', 'en_GB', 'GBP', VolumeUnit::UkGallon, VolumeUnit::UkGallon];
        yield 'kg keeps the owner\'s' => ['Volume (kg)', 'en_US', 'USD', VolumeUnit::UsGallon, VolumeUnit::UsGallon];
        yield 'unknown keeps the owner\'s' => ['Volume (pints)', 'en_GB', 'GBP', VolumeUnit::Litre, VolumeUnit::Litre];
        // K124 (#544): common English words, whatever the owner's own units.
        foreach (
            [
                'litre' => VolumeUnit::Litre,
                'liter' => VolumeUnit::Litre,
                'Liters' => VolumeUnit::Litre,
                'ltr' => VolumeUnit::Litre,
                'UK gallon' => VolumeUnit::UkGallon,
                'UK gal' => VolumeUnit::UkGallon,
                'imperial gallons' => VolumeUnit::UkGallon,
                'imp gal' => VolumeUnit::UkGallon,
                'imp. gal' => VolumeUnit::UkGallon,
                'US gallon' => VolumeUnit::UsGallon,
                'US gallons' => VolumeUnit::UsGallon,
            ] as $word => $unit
        ) {
            yield $word . ', US-gallon owner' => ["Volume ({$word})", 'en_US', 'USD', VolumeUnit::UsGallon, $unit];
            yield $word . ', metric owner' => ["Volume ({$word})", 'en_GB', 'GBP', VolumeUnit::Litre, $unit];
        }
        $german = 'Menge (Gallonen)';
        yield 'German bare gallon, German owner' => [$german, 'de_DE', 'EUR', VolumeUnit::Litre, VolumeUnit::UsGallon];
        yield 'German bare gallon, UK-gallon owner' => [$german, 'de_DE', 'EUR', VolumeUnit::UkGallon, VolumeUnit::UkGallon];
        yield 'Tracktor\'s English bare gallon' => ['Volume (gallons)', 'en_GB', 'GBP', VolumeUnit::Litre, VolumeUnit::UkGallon];
    }

    /**
     * @return iterable<string, array{string, string, DistanceUnit}>
     */
    public static function distanceHeaders(): iterable
    {
        // K124 (#544): the singular and the American spelling, against the owner's own unit.
        yield 'mile, kilometre owner' => ['Odometer (mile)', 'de_DE', DistanceUnit::Mile];
        yield 'kilometers, mile owner' => ['Odometer (kilometers)', 'en_US', DistanceUnit::Kilometre];
        yield 'kilometre, mile owner' => ['Odometer (kilometre)', 'en_GB', DistanceUnit::Kilometre];
        yield 'Miles, kilometre owner' => ['Odometer (Miles)', 'de_DE', DistanceUnit::Mile];
    }

    #[DataProvider('distanceHeaders')]
    public function testTheDistanceUnitNamedInTheHeaderIsChosen(string $header, string $locale, DistanceUnit $expected): void
    {
        $options = $this->guess("Date,{$header},Volume\n2026-09-01,1000,40\n", $locale, 'EUR', VolumeUnit::Litre);

        self::assertSame(1, $options->column('odometer'), 'the column is mapped to the odometer');
        self::assertSame($expected, $options->distanceUnit);
    }

    #[DataProvider('volumeHeaders')]
    public function testTheVolumeUnitNamedInTheHeaderIsChosen(
        string $header,
        string $locale,
        string $currency,
        VolumeUnit $owners,
        VolumeUnit $expected,
    ): void {
        $options = $this->guess("Date,Odometer,{$header}\n2026-09-01,1000,40\n", $locale, $currency, $owners);

        self::assertSame(2, $options->column('volume'), 'the column is mapped to the volume');
        self::assertSame($expected, $options->volumeUnit);
    }

    public function testDistanceStillComesFromItsOwnHeader(): void
    {
        $options = $this->guess("Date,Odometer (Miles),Volume (litres)\n2026-09-01,1000,40\n", 'de_DE', 'EUR', VolumeUnit::Litre);

        self::assertSame(DistanceUnit::Mile, $options->distanceUnit);
        self::assertSame(VolumeUnit::Litre, $options->volumeUnit);
    }

    public function testAnUnmappedVolumeKeepsTheOwnersUnit(): void
    {
        $options = $this->guess("Date,Odometer\n2026-09-01,1000\n", 'en_GB', 'GBP', VolumeUnit::UkGallon);

        self::assertSame(VolumeUnit::UkGallon, $options->volumeUnit);
    }

    private function guess(string $contents, string $locale, string $currency, VolumeUnit $owners): ImportOptions
    {
        $csv = CsvReader::read($contents, 10);
        self::assertNotNull($csv);
        $defaults = DisplayPreferences::defaults($locale, 'UTC', $currency);
        $preferences = new DisplayPreferences(
            $defaults->locale,
            $defaults->timezone,
            $defaults->distanceUnit,
            $owners,
            $defaults->consumptionUnit,
            $defaults->currency,
        );
        $translator = TranslatorFactory::create(Kernel::rootDir() . '/translations', 'en', null, false);

        return ImportMapper::guess(
            ImportField::forModule(ExportModule::Fuel),
            $csv,
            $preferences,
            new ImportVocabulary($translator, $locale),
        );
    }
}
