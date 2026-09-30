<?php

declare(strict_types=1);

namespace Logbook\Service\Trip;

use Logbook\Domain\Trip\TaxYear;
use Logbook\Support\I18n\Region;

/**
 * A user's trip settings (spec.md §6 *Trip settings*): the tax year start
 * and the claim report's declaration. Stored as the user-scope setting
 * `trips`, with whether the GB rates have been provided (so they are
 * provided once, and never again after the user deletes them).
 */
final readonly class TripSettings
{
    public const string GB_TAX_YEAR = '04-06';
    public const string CALENDAR_YEAR = '01-01';
    public const int DECLARATION_MAX = 1000;

    public function __construct(
        public string $taxYearStart = self::CALENDAR_YEAR,
        public ?string $declaration = null,
        public bool $ratesProvided = false,
    ) {
    }

    /**
     * The defaults for someone in this locale: 6 April in GB, else 1 January.
     */
    public static function defaultsFor(string $locale): self
    {
        return new self(self::defaultTaxYearStart($locale));
    }

    public static function defaultTaxYearStart(string $locale): string
    {
        return Region::of($locale) === 'GB' ? self::GB_TAX_YEAR : self::CALENDAR_YEAR;
    }

    public static function fromArray(mixed $value, string $locale): self
    {
        $defaults = self::defaultsFor($locale);
        if (!is_array($value)) {
            return $defaults;
        }
        $start = $value['tax_year_start'] ?? null;
        $declaration = $value['declaration'] ?? null;

        return new self(
            taxYearStart: is_string($start) && TaxYear::isValidStart($start) ? $start : $defaults->taxYearStart,
            declaration: is_string($declaration) && trim($declaration) !== '' ? $declaration : null,
            ratesProvided: ($value['rates_provided'] ?? false) === true,
        );
    }

    /**
     * @return array{tax_year_start: string, declaration: ?string, rates_provided: bool}
     */
    public function toArray(): array
    {
        return [
            'tax_year_start' => $this->taxYearStart,
            'declaration' => $this->declaration,
            'rates_provided' => $this->ratesProvided,
        ];
    }

    public function with(string $taxYearStart, ?string $declaration): self
    {
        return new self($taxYearStart, $declaration, $this->ratesProvided);
    }

    public function withRatesProvided(): self
    {
        return new self($this->taxYearStart, $this->declaration, true);
    }
}
