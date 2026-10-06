<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use DateTimeImmutable;
use Logbook\Support\Config\AppSettings;
use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the templates ask of demo mode (spec.md §7.36): the banner, the
 * sign-in page's credentials, whether a link or a file field is offered.
 * Every function answers "no" outside an active demo.
 */
final class DemoTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly DemoMode $mode,
        private readonly AppSettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('demo_active', fn (): bool => $this->mode->isActive()),
            new TwigFunction('demo_banner', $this->banner(...)),
            new TwigFunction('demo_credentials', $this->credentials(...)),
            // A navigation link to a route the demo blocks is left out.
            new TwigFunction(
                'demo_blocked',
                fn (string $route): bool => $this->mode->isActive() && DemoRoutes::isBlocked($route),
            ),
            // File fields are not offered in the demo (#214).
            new TwigFunction('demo_no_uploads', fn (): bool => $this->mode->blocks(DemoRestriction::Uploads)),
        ];
    }

    /**
     * When the demo next resets, as a relative figure while it is near.
     *
     * @return array{at: DateTimeImmutable, minutes: int}|null
     */
    public function banner(): ?array
    {
        $marker = $this->mode->status()->marker;
        if (!$this->mode->isActive() || $marker === null) {
            return null;
        }
        $at = $marker->lastResetAt->modify(sprintf('+%d hours', $this->settings->demo->resetHours));
        $minutes = max(0, intdiv($at->getTimestamp() - $this->clock->now()->getTimestamp() + 59, 60));

        return ['at' => $at, 'minutes' => $minutes];
    }

    /**
     * @return array{username: string, password: string}|null
     */
    public function credentials(): ?array
    {
        return $this->mode->isActive()
            ? ['username' => 'demo', 'password' => $this->settings->demo->password]
            : null;
    }
}
