<?php

declare(strict_types=1);

namespace Logbook\Service\SalePack;

use Logbook\Domain\User\User;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Zip\StoredZipStream;
use Logbook\Support\Zip\ZipEntry;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The sale pack's paperwork as one ZIP (spec.md §7.19): the included files
 * under their readable names, and `contents.txt` listing each one with its
 * entry, date and odometer, in the owner's language and units.
 *
 * The archive is streamed (StoredZipStream): nothing is copied to a
 * temporary file. A file missing from UPLOAD_PATH is left out and named in
 * `contents.txt` as missing, rather than failing the whole download.
 */
final readonly class PaperworkArchive
{
    public const string CONTENTS = 'contents.txt';

    public function __construct(
        private AttachmentService $attachments,
        private DisplayFormatter $format,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    public function stream(User $user, Vehicle $vehicle, PaperworkSelection $selection): StoredZipStream
    {
        $zone = $user->preferences->timeZone();
        $now = LocalTime::fromUtc($this->clock->now(), $zone);
        $entries = [];
        $lines = [];
        foreach ($selection->included() as $file) {
            $path = $this->attachments->file($file->attachment);
            if ($path === null) {
                $lines[] = $this->line($file, true);
                continue;
            }
            // The entry's day at noon, so file managers sort the files by it.
            $entries[] = ZipEntry::file($file->name, $path, $file->date->setTime(12, 0));
            $lines[] = $this->line($file, false);
        }

        $registration = $vehicle->data->registration ?? '';
        $key = $registration === '' ? 'sale_pack.zip.heading' : 'sale_pack.zip.heading_registration';
        $heading = $this->translator->trans($key, [
            'name' => $vehicle->name(),
            'registration' => $registration,
            'date' => $this->format->date(LocalTime::today($this->clock, $zone)),
        ]);
        $body = $lines === [] ? $this->translator->trans('sale_pack.zip.empty') . "\r\n" : implode('', $lines);
        $text = $heading . "\r\n\r\n" . $body;
        $entries[] = ZipEntry::text(self::CONTENTS, $text, $now);

        return new StoredZipStream($entries);
    }

    /**
     * "Golf paperwork 2026-09-29.zip".
     */
    public function filename(User $user, Vehicle $vehicle): string
    {
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $stem = PaperworkSelector::sanitize($this->translator->trans('sale_pack.zip.filename', ['name' => $vehicle->name()]));

        return ($stem !== '' ? $stem . ' ' : '') . $today->format('Y-m-d') . '.zip';
    }

    private function line(PaperworkFile $file, bool $missing): string
    {
        $details = array_filter([
            $file->entry,
            $this->format->date($file->date),
            $file->odometerKm === null ? '' : $this->format->distance($file->odometerKm),
        ], static fn (string $s): bool => $s !== '');
        $line = $file->name . "\r\n    " . implode(' · ', $details) . "\r\n";

        return $missing
            ? $line . '    ' . $this->translator->trans('sale_pack.zip.missing') . "\r\n"
            : $line;
    }
}
