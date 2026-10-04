<?php

declare(strict_types=1);

/*
 * Anonymise real app exports into test fixtures (Phase 31, spec.md §7.13
 * *Importing from another app*; docs/phases/phase-31.md *Fixtures first*).
 *
 *   php bin/tools/anonymise-import.php <out-dir> <export.csv|backup.zip>... [--shift-days=-730]
 *
 * Every file given is anonymised in one run, so a row that is in more than
 * one of them (a CSV export and a backup of the same car) gets the same
 * replacement ids in each. It keeps the structure, every number and every
 * flag, and replaces:
 *
 * - the vehicle's name, registration, VIN, description and insurer;
 * - station names, places and Fuelio's station ids, the same way everywhere;
 * - coordinates: each distinct position becomes another (fictional) one, so
 *   a fill-up at a favourite station still sits exactly at it;
 * - notes ("Note 1", ...), every guid, photo names and `lastupdated`;
 * - dates, shifted by a constant number of days (Fuelio's 2011-01-01
 *   "no date" is left alone);
 * - photos, by tiny generated JPEGs that carry an EXIF block (so the
 *   import's stripping can be tested).
 *
 * Writes <out-dir>/export.csv for a CSV and <out-dir>/backup.fuelio.zip for
 * a ZIP. Maintainers only: the app never runs this, and only its output is
 * committed (the originals stay out of the repository).
 *
 * Exit code: 0 written, 1 an input could not be read, 3 usage.
 */

use Logbook\Service\Import\App\SectionSplitter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$args = array_values(array_filter(array_slice((array) ($_SERVER['argv'] ?? []), 1), 'is_string'));
$shiftDays = -730;
$inputs = [];
foreach ($args as $arg) {
    if (preg_match('/^--shift-days=(-?\d{1,5})$/', $arg, $m) === 1) {
        $shiftDays = (int) $m[1];
    } else {
        $inputs[] = $arg;
    }
}
$out = array_shift($inputs);
if ($out === null || $inputs === []) {
    fwrite(STDERR, "Usage: php bin/tools/anonymise-import.php <out-dir> <export.csv|backup.zip>... [--shift-days=-730]\n");
    exit(3);
}
if (!is_dir($out) && !mkdir($out, 0775, true)) {
    fwrite(STDERR, "Cannot create {$out}\n");
    exit(1);
}

$anon = new class ($shiftDays) {
    /** @var array<string, array<string, string>> */
    private array $maps = [];
    private int $notes = 0;
    private string $salt;

    public function __construct(private readonly int $shiftDays)
    {
        $this->salt = bin2hex(random_bytes(16));
    }

    /**
     * One CSV export, anonymised and written back in Fuelio's own format.
     */
    public function csv(string $name, string $contents): string
    {
        $file = SectionSplitter::split($name, $contents) ?? throw new RuntimeException($name . ' has no sections.');
        $lines = [];
        foreach ($file->sections as $section) {
            $lines[] = self::quoteAll(['## ' . $section->name]);
            $lines[] = self::quoteAll($section->header);
            foreach ($section->rows as $r) {
                $cells = $this->row($section->name, $r['cells']);
                $lines[] = self::quoteAll(array_map(static fn (string $h): string => $cells[$h] ?? '', $section->header));
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A tiny JPEG with an EXIF block naming its camera, which the import
     * must strip.
     */
    public static function jpeg(int $n): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ($n * 37) & 255, ($n * 91) & 255, ($n * 53) & 255));
        ob_start();
        imagejpeg($image, null, 80);
        $plain = (string) ob_get_clean();
        $make = "Fuelio fixture camera\0";
        $ifd = pack('v', 1) . pack('vvVV', 0x010F, 2, strlen($make), 26) . pack('V', 0);
        $exif = "Exif\0\0" . "II*\0" . pack('V', 8) . $ifd . $make;

        return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($plain, 2);
    }

    public function photo(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $extension = $extension === '' ? 'jpg' : $extension;

        return $this->map('photo', $name, static fn (int $n): string => sprintf('photo-%03d.%s', $n, $extension));
    }

    /**
     * @param array<string, string> $cells
     * @return array<string, string>
     */
    private function row(string $section, array $cells): array
    {
        if (isset($cells['guid'])) {
            $cells['guid'] = $this->guid($cells['guid']);
        }
        if (isset($cells['lastupdated'])) {
            $cells['lastupdated'] = $this->millis($cells['lastupdated']);
        }
        switch ($section) {
            case 'Vehicle':
                $cells['Name'] = 'Test car';
                $cells['Description'] = ($cells['Description'] ?? '') === '' ? '' : 'Description';
                $cells['VIN'] = '';
                $cells['Insurance'] = '';
                $cells['Plate'] = ($cells['Plate'] ?? '') === '' ? '' : 'AB12 CDE';
                break;
            case 'Log':
                $cells['Data'] = $this->date($cells['Data'] ?? '');
                $lat = null;
                $lon = null;
                foreach ($cells as $column => $value) {
                    if (str_starts_with($column, 'City')) {
                        $parts = explode(' - ', $value, 2);
                        $cells[$column] = $value === ''
                            ? ''
                            : $this->station($parts[0]) . (isset($parts[1]) ? ' - ' . $this->place($parts[1]) : '');
                    }
                    if (str_starts_with($column, 'Notes')) {
                        $cells[$column] = $this->note($value);
                    }
                    if (str_starts_with($column, 'StationID')) {
                        $cells[$column] = $this->stationId($value);
                    }
                    $lat = str_starts_with($column, 'latitude') ? $column : $lat;
                    $lon = str_starts_with($column, 'longitude') ? $column : $lon;
                }
                if ($lat !== null && $lon !== null) {
                    [$cells[$lat], $cells[$lon]] = $this->position($cells[$lat], $cells[$lon]);
                }
                break;
            case 'Costs':
                $cells['Date'] = $this->date($cells['Date'] ?? '');
                $cells['RemindDate'] = $this->date($cells['RemindDate'] ?? '');
                $cells['Notes'] = $this->note($cells['Notes'] ?? '');
                break;
            case 'FavStations':
                $cells['NameBrand'] = $this->station($cells['NameBrand'] ?? '');
                $cells['Description'] = $this->place($cells['Description'] ?? '');
                $cells['StationID'] = $this->stationId($cells['StationID'] ?? '');
                [$cells['Latitude'], $cells['Longitude']] = $this->position($cells['Latitude'] ?? '', $cells['Longitude'] ?? '');
                break;
            case 'Pictures':
                $cells['Filename'] = $this->photo($cells['Filename'] ?? '');
                $cells['Note'] = $this->note($cells['Note'] ?? '');
                break;
        }

        return $cells;
    }

    /**
     * @param callable(int): string $make
     */
    private function map(string $kind, string $value, callable $make): string
    {
        if ($value === '') {
            return '';
        }

        return $this->maps[$kind][$value] ??= $make(count($this->maps[$kind] ?? []) + 1);
    }

    private function guid(string $value): string
    {
        return $this->map('guid', $value, function () use ($value): string {
            $h = hash_hmac('sha256', $value, $this->salt);

            return sprintf(
                '%s-%s-4%s-a%s-%s',
                substr($h, 0, 8),
                substr($h, 8, 4),
                substr($h, 13, 3),
                substr($h, 17, 3),
                substr($h, 20, 12),
            );
        });
    }

    private function station(string $name): string
    {
        return $this->map(
            'station',
            trim($name),
            static fn (int $n): string => 'Station ' . chr(64 + min(26, $n)) . ($n > 26 ? (string) $n : ''),
        );
    }

    private function place(string $place): string
    {
        return $this->map('place', trim($place), static fn (int $n): string => 'Place ' . $n);
    }

    private function stationId(string $id): string
    {
        if ($id === '' || $id === '0') {
            return $id;
        }

        return $this->map('station_id', $id, static fn (int $n): string => (string) (900000 + $n));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function position(string $lat, string $lon): array
    {
        if (!is_numeric($lat) || !is_numeric($lon) || ((float) $lat === 0.0 && (float) $lon === 0.0)) {
            return [$lat, $lon];
        }
        $key = round((float) $lat, 5) . ',' . round((float) $lon, 5);
        $fake = $this->map('position', $key, function () use ($key): string {
            $h = hash_hmac('sha256', $key, $this->salt);

            // Somewhere in a 0.2° box no one lives in particular.
            return sprintf(
                '%.5f,%.5f',
                50.0 + hexdec(substr($h, 0, 6)) / 0xFFFFFF * 0.2,
                -3.5 + hexdec(substr($h, 6, 6)) / 0xFFFFFF * 0.2,
            );
        });
        $parts = explode(',', $fake, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private function note(string $text): string
    {
        return trim($text) === '' ? $text : 'Note ' . (++$this->notes);
    }

    private function date(string $value): string
    {
        $dated = preg_match('/^(\d{4}-\d{2}-\d{2})(.*)$/', $value, $m) === 1;
        if (!$dated || str_starts_with($value, '2011-01-01')) {
            return $value;
        }
        $date = (new DateTimeImmutable($m[1], new DateTimeZone('UTC')))->modify(sprintf('%+d days', $this->shiftDays));

        return $date->format('Y-m-d') . $m[2];
    }

    private function millis(string $value): string
    {
        return ctype_digit($value) ? (string) ((int) $value + $this->shiftDays * 86400000) : $value;
    }

    /**
     * @param list<string> $values
     */
    private static function quoteAll(array $values): string
    {
        $quote = static fn (string $v): string => $v === '' ? '' : '"' . str_replace('"', '""', $v) . '"';

        return implode(',', array_map($quote, $values));
    }
};

foreach ($inputs as $input) {
    if (!is_file($input)) {
        fwrite(STDERR, "Cannot read {$input}\n");
        exit(1);
    }
    if (strtolower(pathinfo($input, PATHINFO_EXTENSION)) === 'csv') {
        file_put_contents($out . '/export.csv', $anon->csv(basename($input), (string) file_get_contents($input)));
        echo "Wrote {$out}/export.csv\n";
        continue;
    }

    $zip = new ZipArchive();
    if ($zip->open($input, ZipArchive::RDONLY) !== true) {
        fwrite(STDERR, "Cannot open {$input}\n");
        exit(1);
    }
    $target = $out . '/backup.fuelio.zip';
    @unlink($target);
    $new = new ZipArchive();
    $new->open($target, ZipArchive::CREATE);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if (str_ends_with(strtolower($name), '.csv')) {
            $new->addFromString($name, $anon->csv($name, (string) $zip->getFromIndex($i)));
        } elseif (basename($name) === 'pictures.data') {
            $innerPath = (string) tempnam(sys_get_temp_dir(), 'anon');
            $copy = (string) tempnam(sys_get_temp_dir(), 'orig');
            file_put_contents($copy, (string) $zip->getFromIndex($i));
            $inner = new ZipArchive();
            $inner->open($copy, ZipArchive::RDONLY);
            $photos = new ZipArchive();
            $photos->open($innerPath, ZipArchive::OVERWRITE);
            for ($j = 0; $j < $inner->numFiles; $j++) {
                $renamed = $anon->photo(basename((string) $inner->getNameIndex($j)));
                $photos->addFromString($renamed, $anon::jpeg((int) preg_replace('/\D/', '', $renamed)));
            }
            $photos->close();
            $inner->close();
            $new->addFromString($name, (string) file_get_contents($innerPath));
            @unlink($innerPath);
            @unlink($copy);
        }
    }
    $new->close();
    $zip->close();
    echo "Wrote {$target}\n";
}
