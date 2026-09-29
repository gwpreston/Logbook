<?php

declare(strict_types=1);

namespace Logbook\Service\Fuel;

/**
 * The economy check's verdict on one full-to-full segment (spec.md §7.3).
 */
enum EconomyVerdict: string
{
    /** Too short, or too few earlier segments to compare with. */
    case NotChecked = 'not_checked';
    /** Inside the band. */
    case Normal = 'normal';
    /** Used noticeably more fuel than usual. */
    case More = 'more';
    /** Used noticeably less fuel than usual. */
    case Less = 'less';

    public function isFlag(): bool
    {
        return $this === self::More || $this === self::Less;
    }
}
