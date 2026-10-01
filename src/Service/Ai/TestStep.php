<?php

declare(strict_types=1);

namespace Logbook\Service\Ai;

/**
 * One step of a connection test (spec.md §7.25 *Test*).
 */
enum TestStep: string
{
    case List = 'list';
    case Completion = 'completion';
    case Tools = 'tools';
    case Images = 'images';
    case Json = 'json';

    public function labelKey(): string
    {
        return 'ai.test.step.' . $this->value;
    }
}
