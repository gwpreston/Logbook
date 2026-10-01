<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Scan;

use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\ExifJpeg;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\ScanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The fixture set (spec.md §7.27 *Tests*, tests/Fixtures/scans): each of
 * twenty synthetic documents, uploaded as a user would, read through the
 * real gateway and adapter with the scripted provider replaying the
 * expected reply, lands on the right form for the right vehicle with the
 * expected values filled in and marked. Text PDFs go as text through
 * `read_text`; photos and scanned PDFs as pictures through `read_document`.
 */
final class ScanFixturesTest extends ScanTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (self::fixtureNames() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('fixtures')]
    public function testTheDocumentFillsTheRightForm(string $name): void
    {
        [$fixture, $bytes] = self::fixture($name);
        $locale = is_string($fixture['locale'] ?? null) ? $fixture['locale'] : 'en_GB';
        $this->scanApp([], $locale === 'de_DE' ? self::german() : null);
        $expect = is_array($fixture['expect'] ?? null) ? $fixture['expect'] : [];
        $reply = is_array($fixture['reply'] ?? null) ? $fixture['reply'] : [];
        $this->reply($reply);

        $fields = [];
        if (is_string($fixture['chosen'] ?? null)) {
            $fields['vehicle_id'] = (string) $this->garage[$fixture['chosen']]->id;
        }
        $type = (string) ($fixture['type'] ?? '');
        $file = (string) ($fixture['file'] ?? '');
        $mime = str_ends_with($file, '.pdf') ? 'application/pdf' : 'image/jpeg';
        [$page, $path] = $this->land($this->scan($bytes, $file, $mime, $fields));

        if (($expect['vehicle'] ?? null) === null) {
            self::assertStringContainsString('Which vehicle is it for?', self::body($page), 'the user picks the vehicle');
            $pick = (string) ($fixture['pick'] ?? 'Golf');
            $token = self::token($path);
            [$page, $more] = $this->land($this->browser->get('/scan/' . $token . '?vehicle=' . $this->garage[$pick]->id));
            $path = [...$path, ...$more];
            $vehicle = $this->garage[$pick];
        } else {
            $vehicle = $this->garage[(string) $expect['vehicle']];
        }
        self::assertSame(200, $page->getStatusCode(), implode(' → ', $path));

        $form = (string) ($expect['form'] ?? '');
        $last = (string) end($path);
        $expectedPath = match ($form) {
            'maintenance' => '/vehicles/' . $vehicle->id . '/maintenance/new?',
            'fuel' => '/vehicles/' . $vehicle->id . '/fuel/new?',
            'document' => '/vehicles/' . $vehicle->id . '/documents/new?',
            'vehicle' => '/scan/' . self::token($path) . '/vehicle?vehicle=' . $vehicle->id,
            default => self::fail('unknown form ' . $form),
        };
        self::assertStringStartsWith($expectedPath, $last);

        $html = self::body($page);
        $values = $form === 'vehicle'
            ? $this->vehicleRows($html)
            : Html::formValues(Html::element(Html::document($html), 'form.form'));
        foreach (is_array($expect['values'] ?? null) ? $expect['values'] : [] as $field => $value) {
            self::assertSame((string) $value, $values[(string) $field] ?? null, $name . ': ' . $field);
        }
        foreach (is_array($expect['absent'] ?? null) ? $expect['absent'] : [] as $field) {
            self::assertSame('', $values[(string) $field] ?? '', $name . ': ' . $field . ' is left empty');
        }
        $text = $values['description'] ?? $values['notes'] ?? '';
        foreach (is_array($expect['description'] ?? null) ? $expect['description'] : (is_array($expect['notes'] ?? null) ? $expect['notes'] : []) as $part) {
            self::assertStringContainsString((string) $part, $text, $name);
        }
        foreach (is_array($expect['check'] ?? null) ? $expect['check'] : [] as $message) {
            self::assertStringContainsString((string) $message, html_entity_decode($html), $name);
        }
        if (is_string($expect['warning'] ?? null)) {
            self::assertStringContainsString($expect['warning'], html_entity_decode($html), $name);
        }
        if ($form !== 'vehicle') {
            self::assertStringContainsString('field__from-file', $html, 'scanned fields are marked');
            self::assertSame(self::token($path), $values['scan'] ?? null, 'the form carries the scan back');
        }

        // What was sent: text for a text PDF, pictures for the rest.
        self::assertCount(1, $this->provider->requests);
        $images = $this->sentImages(0);
        if ($type === 'text_pdf') {
            self::assertSame([], $images, 'a text PDF is read as text');
            self::assertStringContainsString('<<<', $this->sentText(0));
            self::assertSame('llama3.2:3b', $this->request(0)['model'] ?? null, 'through read_text');
        } else {
            self::assertCount(1, $images);
            self::assertFalse(ExifJpeg::hasExif($images[0]), 'no EXIF (and so no GPS) is sent');
            self::assertSame('qwen2.5vl:7b', $this->request(0)['model'] ?? null, 'through read_document');
            self::assertSame($type === 'scan_pdf' ? 1 : 0, $this->renderer->calls);
        }
        if (is_string($expect['no_reference'] ?? null)) {
            $reference = $expect['no_reference'];
            self::assertStringNotContainsString($reference, $html, 'the V5C reference is never shown');
            self::assertStringNotContainsString(str_replace(' ', '', $reference), $this->storedResults());
            self::assertStringNotContainsString(str_replace(' ', '', $reference), str_replace(' ', '', $this->sentText(0)));
        }
    }

    /**
     * The V5C page's found values, by field.
     *
     * @return array<string, string>
     */
    private function vehicleRows(string $html): array
    {
        $values = [];
        $document = Html::document($html);
        foreach ($document->querySelectorAll('input[name="update[]"]') as $box) {
            $field = (string) $box->getAttribute('value');
            $strong = $box->parentElement?->querySelector('strong');
            $values[$field] = trim((string) $strong?->textContent);
        }
        // Dates are shown formatted; compare as stored.
        if (isset($values['first_registered_on'])) {
            $parsed = date_create_immutable_from_format('j M Y', $values['first_registered_on']);
            $values['first_registered_on'] = $parsed === false ? $values['first_registered_on'] : $parsed->format('Y-m-d');
        }

        return $values;
    }

    private function storedResults(): string
    {
        $rows = $this->connection($this->app)->fetchFirstColumn('SELECT result FROM pending_uploads');

        return implode("\n", array_map(static fn (mixed $r): string => is_string($r) ? $r : '', $rows));
    }

    /**
     * @param list<string> $path
     */
    private static function token(array $path): string
    {
        foreach ($path as $location) {
            if (preg_match('#/scan/([a-f0-9]{32})#', $location, $m) === 1 || preg_match('#[?&]scan=([a-f0-9]{32})#', $location, $m) === 1) {
                return $m[1];
            }
        }
        self::fail('no scan token in ' . implode(' → ', $path));
    }

    private static function german(): DisplayPreferences
    {
        $preset = UnitPreset::Metric;

        return new DisplayPreferences('de_DE', 'Europe/Berlin', $preset->distance(), $preset->volume(), $preset->consumption(), 'EUR');
    }
}
