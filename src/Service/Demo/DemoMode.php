<?php

declare(strict_types=1);

namespace Logbook\Service\Demo;

use Logbook\Repository\UserRepository;
use Logbook\Support\Config\AppSettings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The guard (spec.md §7.36). `DEMO_MODE` is only a request: what it means
 * depends on what is in the database. Only an instance that was seeded as a
 * demo (it carries the marker) is ever a demo, and so ever reset; the same
 * switch on a database that holds real data changes nothing.
 *
 * The answer is worked out once per process (`forget()` for a long-lived
 * one, such as a test).
 */
final class DemoMode
{
    private ?DemoStatus $status = null;

    public function __construct(
        private readonly AppSettings $settings,
        private readonly DemoMarkers $markers,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function status(): DemoStatus
    {
        return $this->status ??= $this->work();
    }

    public function state(): DemoState
    {
        return $this->status()->state;
    }

    /** The demo is running: the marker is present and `DEMO_MODE` is on. */
    public function isActive(): bool
    {
        // The switch off: nothing to ask the database, on every request.
        return $this->settings->demo->enabled && $this->status()->isActive();
    }

    /** Whether a demo visitor may not do this (always false outside an active demo). */
    public function blocks(DemoRestriction $restriction): bool
    {
        return $this->isActive();
    }

    public function forget(): void
    {
        $this->status = null;
    }

    private function work(): DemoStatus
    {
        try {
            $marker = $this->markers->find();
            if (!$this->settings->demo->enabled) {
                return new DemoStatus($marker === null ? DemoState::Off : DemoState::Inert, null, $marker);
            }
            if (!$this->settings->demo->passwordUsable()) {
                return new DemoStatus(DemoState::Refused, DemoRefusal::Password, $marker);
            }
            if ($marker !== null) {
                return new DemoStatus(DemoState::Active, null, $marker);
            }

            return $this->users->exists()
                ? new DemoStatus(DemoState::Refused, DemoRefusal::RealData)
                : new DemoStatus(DemoState::NeedsSeed);
        } catch (Throwable $e) {
            // No schema yet (before the first migration), or no database: not a demo.
            $this->logger->debug('Demo mode could not be checked: {message}', ['message' => $e->getMessage()]);

            return new DemoStatus(DemoState::Off);
        }
    }
}
