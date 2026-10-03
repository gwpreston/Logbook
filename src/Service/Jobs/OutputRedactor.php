<?php

declare(strict_types=1);

namespace Logbook\Service\Jobs;

use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiSecretRepository;
use Logbook\Repository\FuelPriceSecretRepository;
use Logbook\Service\Ai\SecretBox;
use Logbook\Support\Config\AppSettings;
use Throwable;

/**
 * Takes secrets out of a job's output before a line is stored or printed
 * (spec.md §5 *Jobs*, *Redaction*): the values of environment variables
 * whose names contain PASSWORD, SECRET, TOKEN or KEY, every stored AI
 * connection secret, and anything shaped like a Logbook API key.
 */
final class OutputRedactor
{
    public const string MASK = '••••';

    private const string NAMES = '/PASSWORD|SECRET|TOKEN|KEY/i';
    private const string API_KEY = '/lbk_[A-Za-z0-9_\-]+/';
    private const int SHORTEST = 4;
    /**
     * Settings words, not secrets: masking them would garble every line.
     * `logbook` is the shipped compose files' database password, public
     * already, and part of every file name the jobs print.
     */
    private const array PLAIN = ['true', 'false', 'none', 'null', 'explicit', 'username', 'identity', 'logbook'];

    /** @var list<string>|null longest first, gathered on first use */
    private ?array $secrets = null;

    public function __construct(
        private readonly AppSettings $settings,
        private readonly AiConnectionRepository $connections,
        private readonly AiSecretRepository $aiSecrets,
        private readonly SecretBox $box,
        private readonly FuelPriceSecretRepository $fuelPriceSecrets,
    ) {
    }

    public function redact(string $text): string
    {
        foreach ($this->secrets() as $secret) {
            $text = str_replace($secret, self::MASK, $text);
        }

        return preg_replace(self::API_KEY, self::MASK, $text) ?? $text;
    }

    /**
     * @return list<string>
     */
    private function secrets(): array
    {
        if ($this->secrets !== null) {
            return $this->secrets;
        }

        $values = [];
        foreach ($this->settings->env->all() as $name => $value) {
            if (preg_match(self::NAMES, $name) === 1) {
                $values[] = trim($value);
            }
        }
        $values[] = $this->settings->sessionSecret;
        try {
            foreach ($this->connections->listAll() as $connection) {
                foreach ($this->aiSecrets->forConnection($connection->id) as $slot => $stored) {
                    try {
                        $values[] = $this->box->open($slot, $stored);
                    } catch (Throwable) {
                        // Unreadable here, so it cannot be printed either.
                    }
                }
            }
        } catch (Throwable) {
            // No database yet: the environment's secrets are still covered.
        }
        try {
            // Phase 30.2: a price provider's credentials (spec.md §7.34).
            foreach ($this->fuelPriceSecrets->all() as $stored) {
                try {
                    $values[] = $this->box->open('fuel_prices', $stored);
                } catch (Throwable) {
                    // Unreadable here, so it cannot be printed either.
                }
            }
        } catch (Throwable) {
            // No table yet (mid-upgrade).
        }

        $values = array_values(array_unique(array_filter(
            $values,
            static fn (string $v): bool => strlen($v) >= self::SHORTEST
                && !in_array(strtolower($v), self::PLAIN, true),
        )));
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $this->secrets = $values;
    }
}
