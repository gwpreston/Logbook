<?php

declare(strict_types=1);

namespace Logbook\Support\Database;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use UnexpectedValueException;

/**
 * Converts between PHP date-times and `datetime` columns, which hold UTC
 * without an offset on every engine (spec.md §6.1). Never rely on PHP's
 * default time zone at this boundary: always go through here.
 */
final class UtcDateTime
{
    public static function toDatabase(DateTimeInterface $value, AbstractPlatform $platform): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format($platform->getDateTimeFormatString());
    }

    public static function fromDatabase(mixed $value, AbstractPlatform $platform): DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new UnexpectedValueException('Expected a non-empty date-time string from the database.');
        }

        $utc = new DateTimeZone('UTC');
        $parsed = DateTimeImmutable::createFromFormat('!' . $platform->getDateTimeFormatString(), $value, $utc);
        if ($parsed === false) {
            // Tolerate fractional seconds some engines return.
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, $utc);
        }

        if ($parsed === false) {
            throw new UnexpectedValueException(sprintf('Unparseable date-time "%s" from the database.', $value));
        }

        return $parsed;
    }
}
