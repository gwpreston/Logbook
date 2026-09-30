<?php

declare(strict_types=1);

namespace Logbook\Service\Api;

use Logbook\Domain\Api\ApiKey;

/**
 * A key just created, with its token: the only time the token exists
 * outside the client (spec.md §7.20). Show it once; never store it.
 */
final readonly class CreatedApiKey
{
    public function __construct(
        public ApiKey $key,
        #[\SensitiveParameter]
        public string $token,
    ) {
    }
}
