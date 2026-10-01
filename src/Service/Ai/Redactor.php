<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

/**
 * Removes secrets from provider error text before it reaches a page, the
 * usage log or the log file (spec.md §7.25 *Secrets*): the connection's
 * own secret values, and anything shaped like a bearer token or API key.
 */
final class Redactor
{
    public const string MASK = '[redacted]';

    private const array PATTERNS = [
        '/(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{4,}/i' => '$1 ' . self::MASK,
        '/\b(sk|pk|rk|gsk|xai|sk-ant|sk-or)-[A-Za-z0-9_-]{8,}/' => self::MASK,
        '/\bAIza[0-9A-Za-z_-]{20,}/' => self::MASK,
        '/([?&](?:key|api_key|apikey|token)=)[^&\s"]+/i' => '$1' . self::MASK,
    ];

    /**
     * @param list<string> $secrets the plain values to remove
     */
    public static function redact(string $text, array $secrets = []): string
    {
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($secrets as $secret) {
            if (strlen($secret) >= 4) {
                $text = str_replace($secret, self::MASK, $text);
            }
        }
        foreach (self::PATTERNS as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }
}
