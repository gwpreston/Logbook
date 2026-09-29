<?php

declare(strict_types=1);

namespace Logbook\Support\Zip;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A ZIP archive written as it is read (spec.md §7.19): a PSR-7 stream the
 * response emitter pulls from, so files go from UPLOAD_PATH to the browser
 * without a temporary copy and without PHP's zip extension.
 *
 * Entries are stored, not compressed (invoices and photos are compressed
 * already), which is what lets every size and CRC be known before the first
 * byte: each file is hashed once up front, then read again while it is
 * sent. Names are flagged UTF-8 (general purpose bit 11). No ZIP64: an
 * archive (or file) of 4 GiB or more is refused, far beyond any
 * MAX_UPLOAD_MB.
 *
 * Read only and forward only; rewind() starts again from the first byte.
 */
final class StoredZipStream implements StreamInterface
{
    private const int UTF8_NAMES = 0x0800;
    private const int VERSION = 20;
    private const int LIMIT = 0xFFFFFFFF;

    /** @var list<array{0: string}|array{0: null, 1: string, 2: int}> header strings and file parts */
    private array $parts = [];
    private int $size = 0;
    private int $position = 0;
    private int $part = 0;
    private int $offsetInPart = 0;
    /** @var resource|null */
    private $handle;
    private bool $closed = false;

    /**
     * @param list<ZipEntry> $entries
     */
    public function __construct(array $entries)
    {
        $central = '';
        $offset = 0;
        foreach ($entries as $entry) {
            if ($entry->path !== null) {
                $size = @filesize($entry->path);
                $hash = @hash_file('crc32b', $entry->path);
                if ($size === false || $hash === false) {
                    throw new RuntimeException(sprintf('Cannot read "%s" for a ZIP.', $entry->name));
                }
                $crc = (int) hexdec($hash);
            } else {
                $size = strlen((string) $entry->contents);
                $crc = crc32((string) $entry->contents);
            }
            [$time, $date] = self::dosTime($entry);
            // Version needed, flags, method (0: stored), time, date, CRC, both sizes, name length.
            $common = pack('vvvvv', self::VERSION, self::UTF8_NAMES, 0, $time, $date)
                . pack('VVVv', $crc, $size, $size, strlen($entry->name));

            $local = pack('V', 0x04034b50) . $common . pack('v', 0) . $entry->name;
            $this->parts[] = [$local];
            $this->parts[] = $entry->path !== null ? [null, $entry->path, $size] : [(string) $entry->contents];
            // Made by: version 2.0 on Unix; external attributes: a regular file, rw-r--r--.
            $central .= pack('Vv', 0x02014b50, (3 << 8) | self::VERSION) . $common
                . pack('vvvvVV', 0, 0, 0, 0, (0100644 << 16), $offset) . $entry->name;
            $offset += strlen($local) + $size;
            if ($offset > self::LIMIT) {
                throw new RuntimeException('The ZIP would be 4 GiB or more.');
            }
        }
        $count = count($entries);
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
        $this->parts[] = [$central . $end];
        $this->size = $offset + strlen($central) + strlen($end);
    }

    public function __toString(): string
    {
        try {
            $this->rewind();

            return $this->getContents();
        } catch (RuntimeException) {
            return '';
        }
    }

    public function close(): void
    {
        $this->closeHandle();
        $this->closed = true;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->closed || $this->position >= $this->size;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($offset === 0 && $whence === SEEK_SET) {
            $this->rewind();

            return;
        }
        throw new RuntimeException('A ZIP stream only rewinds.');
    }

    public function rewind(): void
    {
        $this->closeHandle();
        $this->position = 0;
        $this->part = 0;
        $this->offsetInPart = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('A ZIP stream is read only.');
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function read(int $length): string
    {
        if ($this->closed) {
            throw new RuntimeException('The ZIP stream is closed.');
        }
        $out = '';
        while (strlen($out) < $length && isset($this->parts[$this->part])) {
            $part = $this->parts[$this->part];
            $want = $length - strlen($out);
            if ($part[0] !== null) {
                $chunk = substr($part[0], $this->offsetInPart, $want);
                $partSize = strlen($part[0]);
            } else {
                $chunk = $this->readFile($part[1], $part[2], $want);
                $partSize = $part[2];
            }
            $out .= $chunk;
            $this->offsetInPart += strlen($chunk);
            if ($this->offsetInPart >= $partSize) {
                $this->closeHandle();
                $this->part++;
                $this->offsetInPart = 0;
            }
        }
        $this->position += strlen($out);

        return $out;
    }

    public function getContents(): string
    {
        $out = '';
        while (!$this->eof()) {
            $out .= $this->read(1024 * 1024);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|mixed
     */
    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    private function readFile(string $path, int $size, int $want): string
    {
        if ($this->handle === null) {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('A file went missing while the ZIP was sent.');
            }
            $this->handle = $handle;
        }
        $chunk = fread($this->handle, max(1, min($want, $size - $this->offsetInPart)));
        if ($chunk === false || ($chunk === '' && $this->offsetInPart < $size)) {
            throw new RuntimeException('A file changed while the ZIP was sent.');
        }

        return $chunk;
    }

    private function closeHandle(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * MS-DOS time and date fields (2-second resolution, 1980 to 2107).
     *
     * @return array{0: int, 1: int}
     */
    private static function dosTime(ZipEntry $entry): array
    {
        $t = $entry->modified;
        $year = max(1980, min(2107, (int) $t->format('Y')));
        $time = ((int) $t->format('G') << 11) | ((int) $t->format('i') << 5) | intdiv((int) $t->format('s'), 2);
        $date = (($year - 1980) << 9) | ((int) $t->format('n') << 5) | (int) $t->format('j');

        return [$time, $date];
    }
}
