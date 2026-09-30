<?php

declare(strict_types=1);

namespace Logbook\Action\Sharing;

use Logbook\Domain\Access\ShareLevel;

/**
 * A share's three fields as posted (spec.md §7.21): level, *Can see costs*
 * and *Send me its reminders*. An unknown level reads as View, the least.
 */
final readonly class ShareForm
{
    public function __construct(
        public ShareLevel $level,
        public bool $canSeeCosts,
        public bool $notify,
    ) {
    }

    /**
     * @param array<array-key, mixed> $form
     */
    public static function parse(array $form): self
    {
        $level = is_string($form['level'] ?? null) ? ShareLevel::tryFrom($form['level']) : null;

        return new self(
            $level ?? ShareLevel::View,
            ($form['can_see_costs'] ?? '') === '1',
            ($form['notify'] ?? '') === '1',
        );
    }
}
