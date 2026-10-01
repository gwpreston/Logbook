<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

use Closure;
use Logbook\Domain\Ai\AiConnection;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\ChatResult;

/**
 * Calls made while AiGateway::session() holds the user's lock: the same
 * routing, checks and usage log as AiGateway::run(), without taking the
 * lock again.
 */
final readonly class AiSession
{
    /**
     * @param Closure(ChatRequest): ChatResult $send
     */
    public function __construct(
        public AiConnection $connection,
        public string $model,
        private Closure $send,
    ) {
    }

    /**
     * @throws AiFailure
     */
    public function chat(ChatRequest $request): ChatResult
    {
        return ($this->send)($request);
    }
}
