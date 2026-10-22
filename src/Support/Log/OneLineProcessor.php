<?php

declare(strict_types=1);

namespace Logbook\Support\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Stringable;
use Throwable;

/**
 * Outside text on one log line (spec.md §7.9, K45, #461): CR and LF in the
 * message and in every string context value become a space, so a sign-in
 * username with a line break can't write a line fail2ban would read as a
 * failed sign-in from another address. Runs before
 * `PsrLogMessageProcessor`, so what it puts into the message is already one
 * line. An exception keeps its stack trace: that is the formatter's.
 */
final class OneLineProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = self::line($value);
            } elseif ($value instanceof Stringable && !$value instanceof Throwable) {
                $context[$key] = self::line((string) $value);
            }
        }

        return $record->with(message: self::line($record->message), context: $context);
    }

    public static function line(string $text): string
    {
        return str_replace(["\r\n", "\r", "\n"], ' ', $text);
    }
}
