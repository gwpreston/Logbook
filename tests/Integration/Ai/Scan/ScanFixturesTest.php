<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Scan;

use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\Units\UnitPreset;
use Logbook\Tests\Support\ExifJpeg;
use Logbook\Tests\Support\Html;
use Logbook\Tests\Support\ScanFixture;
use Logbook\Tests\Support\ScanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The fixture set (spec.md §7.27 *Tests*, tests/Fixtures/scans): each of
 * twenty-four synthetic documents, uploaded as a user would, read through the
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
        foreach (ScanFixture::names() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('fixtures')]
    public function testTheDocumentFillsTheRightForm(string $name): void
    {
        $fixture = ScanFixture::load($name);
        $this->scanApp([], $fixture->locale === 'de_DE' ? self::german() : null);
        $this->reply($fixture->reply);

        $fields = $fixture->chosen === null ? [] : ['vehicle_id' => (string) $this->garage[$fixture->chosen]->id];
        [$page, $path] = $this->land($this->scan($fixture->bytes(), $fixture->file, $fixture->mime(), $fields));

        $expectedVehicle = $fixture->expected('vehicle');
        if ($expectedVehicle === null) {
            self::assertStringContainsString('Which vehicle is it for?', self::body($page), 'the user picks the vehicle');
            $vehicle = $this->garage[$fixture->pick ?? 'Golf'];
            [$page, $more] = $this->land($this->browser->get('/scan/' . self::token($path) . '?vehicle=' . $vehicle->id));
            $path = [...$path, ...$more];
        } else {
            $vehicle = $this->garage[$expectedVehicle];
        }
        self::assertSame(200, $page->getStatusCode(), implode(' → ', $path));

        $form = (string) $fixture->expected('form');
        $expectedPath = match ($form) {
            'maintenance' => '/vehicles/' . $vehicle->id . '/maintenance/new?',
            'fuel' => '/vehicles/' . $vehicle->id . '/fuel/new?',
            'document' => '/vehicles/' . $vehicle->id . '/documents/new?',
            'incident' => '/vehicles/' . $vehicle->id . '/incidents/new?',
            'vehicle' => '/scan/' . self::token($path) . '/vehicle?vehicle=' . $vehicle->id,
            default => self::fail('unknown form ' . $form),
        };
        self::assertStringStartsWith($expectedPath, (string) end($path));

        $html = self::body($page);
        $values = $form === 'vehicle'
            ? $this->vehicleRows($html)
            : Html::formValues(Html::element(Html::document($html), 'form.form'));
        foreach ($fixture->expectedMap('values') as $field => $value) {
            self::assertSame($value, $values[$field] ?? null, $name . ': ' . $field);
        }
        foreach ($fixture->expectedList('absent') as $field) {
            self::assertSame('', $values[$field] ?? '', $name . ': ' . $field . ' is left empty');
        }
        $text = ($values['description'] ?? '') . "\n" . ($values['notes'] ?? '');
        foreach ([...$fixture->expectedList('description'), ...$fixture->expectedList('notes')] as $part) {
            self::assertStringContainsString($part, $text, $name);
        }
        foreach ($fixture->expectedMap('check') as $message) {
            self::assertStringContainsString($message, html_entity_decode($html), $name);
        }
        $warning = $fixture->expected('warning');
        if ($warning !== null) {
            self::assertStringContainsString($warning, html_entity_decode($html), $name);
        }
        if ($form !== 'vehicle') {
            self::assertStringContainsString('field__from-file', $html, 'scanned fields are marked');
            self::assertSame(self::token($path), $values['scan'] ?? null, 'the form carries the scan back');
        }

        // What was sent: text for a text PDF, pictures for the rest.
        self::assertCount(1, $this->provider->requests);
        $images = $this->sentImages(0);
        if ($fixture->type === 'text_pdf') {
            self::assertSame([], $images, 'a text PDF is read as text');
            self::assertStringContainsString('<<<', $this->sentText(0));
            self::assertSame('llama3.2:3b', $this->request(0)['model'] ?? null, 'through read_text');
        } else {
            self::assertCount(1, $images);
            self::assertFalse(ExifJpeg::hasExif($images[0]), 'no EXIF (and so no GPS) is sent');
            self::assertSame('qwen2.5vl:7b', $this->request(0)['model'] ?? null, 'through read_document');
            self::assertSame($fixture->type === 'scan_pdf' ? 1 : 0, $this->renderer->calls);
        }
        $reference = $fixture->expected('no_reference');
        if ($reference !== null) {
            $compact = str_replace(' ', '', $reference);
            self::assertStringNotContainsString($reference, $html, 'the V5C reference is never shown');
            self::assertStringNotContainsString($compact, $this->storedResults(), 'nor stored');
            self::assertStringNotContainsString($compact, str_replace(' ', '', $this->sentText(0)), 'nor sent as text');
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
            if (preg_match('#(?:/scan/|[?&]scan=)([a-f0-9]{32})#', $location, $m) === 1) {
                return $m[1];
            }
        }
        self::fail('no scan token in ' . implode(' → ', $path));
    }

    private static function german(): DisplayPreferences
    {
        $preset = UnitPreset::Metric;

        return new DisplayPreferences(
            'de_DE',
            'Europe/Berlin',
            $preset->distance(),
            $preset->volume(),
            $preset->consumption(),
            'EUR',
        );
    }
}
