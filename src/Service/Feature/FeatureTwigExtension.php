<?php

declare(strict_types=1);

namespace Logbook\Service\Feature;

use Logbook\Domain\Feature\Feature;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `feature_enabled('reports')` for templates (the navigation), backed by
 * FeatureToggles. Unknown names count as enabled.
 */
final class FeatureTwigExtension extends AbstractExtension
{
    public function __construct(private readonly FeatureToggles $features)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('feature_enabled', function (string $name): bool {
                $feature = Feature::tryFrom($name);

                return $feature === null || $this->features->isEnabled($feature);
            }),
        ];
    }
}
