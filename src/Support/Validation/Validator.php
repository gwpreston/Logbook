<?php

declare(strict_types=1);

namespace Logbook\Support\Validation;

use BackedEnum;
use DateTimeImmutable;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Number\Decimal;
use Logbook\Support\Number\DecimalParser;

/**
 * Reads and validates submitted form fields into typed values, collecting
 * one clear error per field.
 *
 * Deliberately permissive about legitimate edge values (CLAUDE.md §8): zero
 * is accepted wherever it is meaningful, surplus decimals are rounded rather
 * than rejected, and optional fields may always be left blank (→ null).
 */
final class Validator
{
    private readonly ValidationErrors $errors;

    /**
     * @param array<array-key, mixed> $input parsed request body
     * @param string $locale for locale-formatted numbers ("1.234,5")
     */
    public function __construct(private readonly array $input, private readonly string $locale)
    {
        $this->errors = new ValidationErrors();
    }

    public function errors(): ValidationErrors
    {
        return $this->errors;
    }

    /**
     * @param array<string, int|string> $params
     */
    public function addError(string $field, string $key, array $params = []): void
    {
        $this->errors->add($field, $key, $params);
    }

    /**
     * The submitted value as a trimmed string ('' when absent or not a string).
     */
    public function raw(string $field): string
    {
        $value = $this->input[$field] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    public function string(string $field, bool $required = false, int $maxLength = 255): ?string
    {
        $value = $this->raw($field);
        if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            $this->errors->add($field, 'validation.invalid');

            return null;
        }
        if ($value === '') {
            return $this->missing($field, $required);
        }
        if (mb_strlen($value) > $maxLength) {
            $this->errors->add($field, 'validation.too_long', ['max' => $maxLength]);

            return null;
        }

        return $value;
    }

    public function integer(string $field, bool $required = false, ?int $min = null, ?int $max = null): ?int
    {
        $value = $this->raw($field);
        if ($value === '') {
            return $this->missing($field, $required);
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            $this->errors->add($field, 'validation.integer');

            return null;
        }

        return $this->withinRange($field, $int, $min, $max) ? $int : null;
    }

    /**
     * A decimal number as a canonical string rounded to $scale places.
     *
     * @param string|null $min inclusive lower bound (canonical); '0' by default,
     *                         so zero is valid and negatives are not
     * @param int $maxWholeDigits digits allowed before the point (the column's precision - scale)
     */
    public function decimal(
        string $field,
        bool $required = false,
        int $scale = 3,
        ?string $min = '0',
        ?string $max = null,
        int $maxWholeDigits = 9,
    ): ?string {
        $value = $this->raw($field);
        if ($value === '') {
            return $this->missing($field, $required);
        }

        $parsed = DecimalParser::parse($value, $this->locale);
        if ($parsed === null) {
            $this->errors->add($field, 'validation.number');

            return null;
        }

        $whole = explode('.', ltrim($parsed, '-'))[0];
        if (strlen($whole) > $maxWholeDigits) {
            $this->errors->add($field, 'validation.too_large');

            return null;
        }

        $rounded = Decimal::round($parsed, $scale);
        if ($min !== null && Decimal::compare($rounded, $min) < 0) {
            $this->errors->add($field, 'validation.min', ['min' => Decimal::trim($min)]);

            return null;
        }
        if ($max !== null && Decimal::compare($rounded, $max) > 0) {
            $this->errors->add($field, 'validation.max', ['max' => Decimal::trim($max)]);

            return null;
        }

        return $rounded;
    }

    /**
     * A calendar date in Y-m-d form (as `<input type="date">` submits it).
     */
    public function date(string $field, bool $required = false): ?DateTimeImmutable
    {
        $value = $this->raw($field);
        if ($value === '') {
            return $this->missing($field, $required);
        }

        $date = LocalTime::parseDate($value);
        if ($date === null) {
            $this->errors->add($field, 'validation.date');
        }

        return $date;
    }

    /**
     * @template T of BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    public function enum(string $field, string $enum, bool $required = false): ?BackedEnum
    {
        $value = $this->raw($field);
        if ($value === '') {
            return $this->missing($field, $required);
        }

        $case = $enum::tryFrom($value);
        if ($case === null) {
            $this->errors->add($field, 'validation.choice');
        }

        return $case;
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $field, array $allowed, bool $required = false): ?string
    {
        $value = $this->raw($field);
        if ($value === '') {
            return $this->missing($field, $required);
        }

        if (!in_array($value, $allowed, true)) {
            $this->errors->add($field, 'validation.choice');

            return null;
        }

        return $value;
    }

    public function checkbox(string $field): bool
    {
        $value = $this->input[$field] ?? null;

        return $value !== null && $value !== '' && $value !== '0' && $value !== false;
    }

    /**
     * A password: never trimmed (spaces are legitimate), length-checked only.
     */
    public function password(string $field, int $minLength = 8, int $maxLength = 1024): ?string
    {
        $value = $this->input[$field] ?? '';
        $value = is_string($value) ? $value : '';

        if ($value === '') {
            return $this->missing($field, true);
        }
        $length = mb_strlen($value);
        if ($length < $minLength || $length > $maxLength) {
            // Strings, so ICU does not format 1024 as "1,024".
            $this->errors->add($field, 'validation.password_length', [
                'min' => (string) $minLength,
                'max' => (string) $maxLength,
            ]);

            return null;
        }

        return $value;
    }

    private function missing(string $field, bool $required): null
    {
        if ($required) {
            $this->errors->add($field, 'validation.required');
        }

        return null;
    }

    private function withinRange(string $field, int $value, ?int $min, ?int $max): bool
    {
        if ($min !== null && $value < $min) {
            $this->errors->add($field, 'validation.min', ['min' => (string) $min]);

            return false;
        }
        if ($max !== null && $value > $max) {
            $this->errors->add($field, 'validation.max', ['max' => (string) $max]);

            return false;
        }

        return true;
    }
}
