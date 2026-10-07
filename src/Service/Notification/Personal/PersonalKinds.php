<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use InvalidArgumentException;

/**
 * Every kind of personal channel, from the `notification.personal` DI list
 * (config/dependencies.php), in the order the page shows them.
 */
final readonly class PersonalKinds
{
    /** Keys of channels that are not personal kinds (email, the server's webhook). */
    private const array RESERVED = ['email', 'webhook'];

    /** @var array<string, PersonalSender> */
    private array $senders;

    /**
     * @param iterable<PersonalSender> $senders
     */
    public function __construct(iterable $senders)
    {
        $byKey = [];
        foreach ($senders as $sender) {
            $key = $sender->definition()->key;
            if (isset($byKey[$key]) || in_array($key, self::RESERVED, true)) {
                throw new InvalidArgumentException(sprintf('The channel kind "%s" is used twice.', $key));
            }
            $byKey[$key] = $sender;
        }
        $this->senders = $byKey;
    }

    /**
     * @return list<PersonalSender>
     */
    public function all(): array
    {
        return array_values($this->senders);
    }

    public function get(string $kind): ?PersonalSender
    {
        return $this->senders[$kind] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->senders);
    }
}
