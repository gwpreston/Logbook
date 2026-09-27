<?php

declare(strict_types=1);

namespace Logbook\Service\Notification;

/**
 * One reminder inside a notification, already worded for the owner.
 */
final readonly class NotificationItem
{
    public function __construct(
        public int $reminderId,
        /** "Annual service — Golf GTI" */
        public string $title,
        /** "Due in 5 days (2 Oct 2026)" */
        public string $detail,
        /** upcoming | due | overdue */
        public string $status,
        /** Calendar date, Y-m-d. */
        public ?string $dueOn,
    ) {
    }

    /**
     * @return array{reminder_id: int, title: string, detail: string, status: string, due_on: string|null}
     */
    public function toArray(): array
    {
        return [
            'reminder_id' => $this->reminderId,
            'title' => $this->title,
            'detail' => $this->detail,
            'status' => $this->status,
            'due_on' => $this->dueOn,
        ];
    }
}
