<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DateTimeImmutable;
use Logbook\Service\Demo\SampleData;
use RuntimeException;

/**
 * A seeding that writes a file and then fails: half the sample data.
 */
final class FailingSampleData implements SampleData
{
    public ?string $written = null;

    public function __construct(private readonly string $directory)
    {
    }

    public function seed(string $password, DateTimeImmutable $today): void
    {
        $this->written = $this->directory . '/attachments/' . bin2hex(random_bytes(16)) . '.png';
        if (!is_dir(dirname($this->written))) {
            mkdir(dirname($this->written), 0775, true);
        }
        file_put_contents($this->written, 'half');

        throw new RuntimeException('The seeding failed part-way.');
    }
}
