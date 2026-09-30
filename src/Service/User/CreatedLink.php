<?php

declare(strict_types=1);

namespace Logbook\Service\User;

use Logbook\Domain\User\Invitation;

/**
 * A link just made: its token is shown once, on the answering page, and
 * never stored.
 */
final readonly class CreatedLink
{
    public function __construct(
        public Invitation $invitation,
        #[\SensitiveParameter]
        public string $token,
        public string $url,
    ) {
    }
}
