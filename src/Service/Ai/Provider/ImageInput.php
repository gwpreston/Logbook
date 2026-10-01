<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

/**
 * An image sent to a model: its bytes and media type.
 */
final readonly class ImageInput
{
    public function __construct(
        public string $bytes,
        public string $mediaType,
    ) {
    }

    public function base64(): string
    {
        return base64_encode($this->bytes);
    }

    public function dataUri(): string
    {
        return 'data:' . $this->mediaType . ';base64,' . $this->base64();
    }
}
