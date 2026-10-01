<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Service\Access\AccessContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `scan_available()` for the entry points: the *Log entry* chooser and
 * each create form's *Fill from a file* (spec.md §7.27).
 */
final class ScanTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly AccessContext $context,
        private readonly ScanAvailability $availability,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('scan_available', fn (): bool => $this->availability->isAvailable($this->context->user())),
        ];
    }
}
