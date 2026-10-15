<?php

declare(strict_types=1);

namespace Logbook\Service\Part;

use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\Part;

/**
 * One line of *For your insurer* (spec.md §7.39): a current modification,
 * the parts it fitted and the parts it took off. Never a cost.
 */
final readonly class InsurerRow
{
    /**
     * @param list<Part> $fitted
     * @param list<Part> $replaced
     */
    public function __construct(
        public Modification $modification,
        public array $fitted,
        public array $replaced,
    ) {
    }

    public function isDeclared(): bool
    {
        return $this->modification->data->declaredOn !== null;
    }
}
