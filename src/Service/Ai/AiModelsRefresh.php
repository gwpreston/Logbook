<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Logbook\Domain\Ai\AiConnection;
use Logbook\Repository\AiModelRepository;
use Psr\Clock\ClockInterface;

/**
 * *Refresh models* (spec.md §7.25 *Models*): the connection's list, stored
 * so added models keep their ticks and the rest follow the provider.
 */
final readonly class AiModelsRefresh
{
    public function __construct(
        private AiGateway $gateway,
        private AiModelRepository $models,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int how many models the provider listed
     * @throws AiFailure
     */
    public function refresh(AiConnection $connection): int
    {
        $listed = $this->gateway->listModels($connection);
        $this->models->syncListed($connection->id, $listed, $this->clock->now());

        return count($listed);
    }
}
