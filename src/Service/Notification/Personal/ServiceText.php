<?php

declare(strict_types=1);

namespace Logbook\Service\Notification\Personal;

use Closure;
use Logbook\Service\Notification\Notification;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The few words a sender adds to a notification itself, in the language
 * it was written in: "…and 3 more" and the link's label.
 */
final readonly class ServiceText
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @return Closure(int): string
     */
    public function more(Notification $notification): Closure
    {
        return fn (int $count): string => $this->translator->trans(
            'notifications.more',
            ['count' => $count],
            null,
            $notification->locale,
        );
    }

    public function openLink(Notification $notification): string
    {
        return $this->translator->trans('notifications.open_link', [], null, $notification->locale);
    }

    /**
     * The title, a blank line and the body's lines: one plain-text message.
     *
     * @return list<string>
     */
    public static function plainLines(Notification $notification): array
    {
        return array_merge([$notification->title, ''], $notification->lines());
    }
}
