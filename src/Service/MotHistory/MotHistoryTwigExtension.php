<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * MOT history in templates (spec.md §7.38): whether Settings → MOT history
 * is offered, whether a provider is on, and which.
 */
final class MotHistoryTwigExtension extends AbstractExtension
{
    public function __construct(private readonly MotHistoryConfig $config)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mot_history_available', $this->config->available(...)),
            new TwigFunction('mot_history_enabled', $this->config->enabled(...)),
            // For the attribution wherever its data shows (History's MOT lines).
            new TwigFunction('mot_history_provider', $this->config->provider(...)),
        ];
    }
}
