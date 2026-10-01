<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

/**
 * Synthetic documents for the scan tests (spec.md §7.27 *Tests*): text
 * PDFs with a real text layer, scanned PDFs that are one picture per page
 * and nothing else, and phone photos with EXIF (orientation and GPS). All
 * invented garages, insurers and plates. tests/Fixtures/scans/build.php
 * writes the fixture set with these.
 */
final class ScanFiles
{
    /**
     * A one-page PDF whose text layer is these lines (Helvetica,
     * WinAnsiEncoding, so "£" and "€" survive).
     *
     * @param list<string> $lines
     */
    public static function textPdf(array $lines): string
    {
        $content = "BT\n/F1 10 Tf\n14 TL\n50 800 Td\n";
        foreach ($lines as $line) {
            $content .= '(' . self::pdfString($line) . ") Tj T*\n";
        }
        $content .= "ET\n";

        return self::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            self::stream('', $content),
        ]);
    }

    /**
     * A one-page PDF that is only a picture of these lines: no text layer,
     * as a flatbed scan gives.
     *
     * @param list<string> $lines
     */
    public static function scannedPdf(array $lines): string
    {
        $jpeg = self::render($lines, 600, 850);
        $image = imagecreatefromstring($jpeg);
        $width = $image === false ? 600 : imagesx($image);
        $height = $image === false ? 850 : imagesy($image);

        return self::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>',
            self::stream(sprintf(
                '/Type /XObject /Subtype /Image /Width %d /Height %d'
                    . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode',
                $width,
                $height,
            ), $jpeg),
            self::stream('', "q 595 0 0 842 0 0 cm /Im1 Do Q\n"),
        ]);
    }

    /**
     * A phone photo of these lines: a JPEG with EXIF orientation 1 and a
     * GPS position, as a receipt photographed on the driveway carries.
     *
     * @param list<string> $lines
     */
    public static function photo(array $lines): string
    {
        return ExifJpeg::withExif(self::render($lines, 900, 1200), 1);
    }

    /**
     * The lines drawn black on white (GD's built-in font, scaled up).
     *
     * @param list<string> $lines
     */
    public static function render(array $lines, int $width, int $height): string
    {
        $small = imagecreatetruecolor(max(1, intdiv($width, 2)), max(1, intdiv($height, 2)));
        imagefill($small, 0, 0, (int) imagecolorallocate($small, 255, 255, 255));
        $ink = (int) imagecolorallocate($small, 20, 20, 20);
        foreach ($lines as $i => $line) {
            imagestring($small, 3, 12, 12 + $i * 16, self::ascii($line), $ink);
        }
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagecopyresampled($image, $small, 0, 0, 0, 0, $width, $height, imagesx($small), imagesy($small));
        ob_start();
        imagejpeg($image, null, 88);

        return (string) ob_get_clean();
    }

    /**
     * @param list<string> $objects bodies of objects 1…n
     */
    private static function pdf(array $objects): string
    {
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }

    private static function stream(string $dictionary, string $data): string
    {
        return '<< ' . ltrim($dictionary . ' /Length ' . strlen($data)) . " >>\nstream\n" . $data . "\nendstream";
    }

    private static function pdfString(string $text): string
    {
        $encoded = (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return strtr($encoded, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private static function ascii(string $text): string
    {
        return strtr($text, [
            '£' => 'GBP ',
            '€' => 'EUR ',
            '–' => '-',
            '’' => "'",
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
        ]);
    }
}
