<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use Logbook\Service\Ocr\GdImagePreparer;
use Logbook\Service\Ocr\ImagePreparer;
use Logbook\Service\Ocr\PreparedImage;

/**
 * An ImagePreparer that counts its calls and prepares with GD, or gives
 * null when constructed with false.
 */
final class CountingPreparer implements ImagePreparer
{
    public int $calls = 0;

    public function __construct(private readonly bool $works = true)
    {
    }

    public function prepare(string $bytes): ?PreparedImage
    {
        $this->calls++;

        return !$this->works ? null : (new GdImagePreparer())->prepare($bytes);
    }
}
