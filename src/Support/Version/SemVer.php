<?php

declare(strict_types=1);

namespace Logbook\Support\Version;

/**
 * A release number, `major.minor.patch` with an optional `v` prefix and
 * `-suffix` (spec.md §7.31). A suffixed build (`2.11.0-dev`) is older than
 * the release it leads to (`2.11.0`); two suffixes of one release compare
 * as equal, since only stable releases are ever offered.
 */
final readonly class SemVer
{
    private const string PATTERN = '/^v?(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})(?:-([0-9A-Za-z.\-]+))?$/D';

    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public ?string $suffix,
    ) {
    }

    public static function parse(string $version): ?self
    {
        if (preg_match(self::PATTERN, trim($version), $m) !== 1) {
            return null;
        }

        return new self((int) $m[1], (int) $m[2], (int) $m[3], ($m[4] ?? '') === '' ? null : $m[4]);
    }

    /**
     * A stable release number only (`2.12.0`, `v2.12.0`): what a GitHub
     * release's tag must be.
     */
    public static function parseRelease(string $tag): ?self
    {
        return preg_match('/^v?\d+\.\d+\.\d+$/D', $tag) === 1 ? self::parse($tag) : null;
    }

    /**
     * Negative, zero or positive, as this version is older than, the same
     * as, or newer than $other.
     */
    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch, $this->suffix === null ? 1 : 0]
            <=> [$other->major, $other->minor, $other->patch, $other->suffix === null ? 1 : 0];
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    /** Without the `v`: `2.12.0`, `2.11.0-dev`. */
    public function __toString(): string
    {
        return sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch)
            . ($this->suffix === null ? '' : '-' . $this->suffix);
    }
}
