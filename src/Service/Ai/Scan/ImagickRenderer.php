<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Throwable;

/**
 * Renders PDF pages with the Imagick extension, when it is loaded. Many
 * installs forbid PDFs in ImageMagick's policy.xml; any failure counts as
 * no renderer.
 */
final class ImagickRenderer implements PdfRenderer
{
    public function isAvailable(): bool
    {
        return class_exists(\Imagick::class);
    }

    public function render(string $pdfPath, int $pages, int $dpi): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $images = [];
        try {
            for ($page = 0; $page < $pages; $page++) {
                $imagick = new \Imagick();
                $imagick->setResolution($dpi, $dpi);
                try {
                    $imagick->readImage($pdfPath . '[' . $page . ']');
                } catch (Throwable) {
                    break;
                }
                $imagick->setImageBackgroundColor('white');
                $flat = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $flat->setImageFormat('jpeg');
                $flat->setImageCompressionQuality(85);
                $flat->stripImage();
                $images[] = $flat->getImageBlob();
                $imagick->clear();
            }
        } catch (Throwable) {
            return [];
        }

        return $images;
    }
}
