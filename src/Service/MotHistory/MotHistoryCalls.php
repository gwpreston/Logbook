<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use Closure;
use Logbook\Service\Ai\SecretUnreadable;
use Psr\Clock\ClockInterface;

/**
 * Every call to the provider goes through here (spec.md §7.38 *Requests*):
 * it opens the credentials, signs in once, runs the work, and records the
 * outcome as the last call, with any secret taken out of the error.
 */
final readonly class MotHistoryCalls
{
    private const string MASK = '••••';

    public function __construct(
        private MotHistoryConfig $config,
        private MotHistorySecrets $secrets,
        private ClockInterface $clock,
    ) {
    }

    /**
     * *Test* (#327): signs in and makes the call that sends no vehicle.
     *
     * @throws MotHistoryFailure
     */
    public function test(MotHistoryProvider $provider): void
    {
        $this->run($provider, static function (MotHistoryClient $client): null {
            $client->ping();

            return null;
        });
    }

    /**
     * @template T
     * @param Closure(MotHistoryClient): T $work
     * @return T
     * @throws MotHistoryFailure
     */
    public function run(MotHistoryProvider $provider, Closure $work): mixed
    {
        $values = [];
        try {
            $credentials = $this->secrets->open($provider);
            $values = $credentials->values();
            $result = $work($provider->connect($credentials));
        } catch (SecretUnreadable) {
            $this->failed(new MotHistoryFailure(MotHistoryErrorCode::Credentials), $values);
        } catch (MotHistoryFailure $failure) {
            $this->failed($failure, $values);
        }
        $this->config->saveStatus($this->config->status()->succeeded($this->clock->now()));

        return $result;
    }

    /**
     * @param list<string> $secrets
     * @return never
     */
    private function failed(MotHistoryFailure $failure, array $secrets): never
    {
        $parameters = [];
        foreach ($failure->parameters as $name => $value) {
            foreach ($secrets as $secret) {
                $value = str_replace($secret, self::MASK, $value);
            }
            $parameters[$name] = $value;
        }
        $this->config->saveStatus($this->config->status()->failed($this->clock->now(), $failure->error, $parameters));

        throw new MotHistoryFailure($failure->error, $parameters);
    }
}
