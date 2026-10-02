<?php

declare(strict_types=1);

namespace Logbook\Service\Updates;

use DateTimeImmutable;
use Exception;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Version\SemVer;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An update check's result in words (spec.md §7.31), in the reader's
 * language and time zone: the job's summary and the Updates page.
 */
final readonly class UpdateMessages
{
    public function __construct(
        private TranslatorInterface $translator,
        private DisplayFormatter $formatter,
    ) {
    }

    /**
     * "2.12.0 available (installed 2.11.0)", "Up to date (2.11.0)", "Newer
     * than the latest release (2.12.0-dev)", or the error.
     */
    public function summary(UpdateStatus $status, ?SemVer $installed, string $installedText): string
    {
        if ($status->error !== null) {
            return $this->error($status->error);
        }
        $latest = $status->latest === null ? null : SemVer::parse($status->latest);
        if ($latest === null) {
            return $this->translator->trans('updates.summary.none');
        }
        if ($installed === null) {
            return $this->translator->trans('updates.summary.unknown', [
                'latest' => (string) $latest,
                'installed' => $installedText,
            ]);
        }
        $order = $latest->compare($installed);

        return match (true) {
            $order > 0 => $this->translator->trans('updates.summary.available', [
                'latest' => (string) $latest,
                'installed' => (string) $installed,
            ]),
            $order === 0 => $this->translator->trans('updates.summary.current', ['installed' => (string) $installed]),
            default => $this->translator->trans('updates.summary.newer', ['installed' => (string) $installed]),
        };
    }

    public function error(UpdateError $error): string
    {
        $params = $error->params;
        if (isset($params['until'])) {
            try {
                $params['until'] = $this->formatter->dateTime(new DateTimeImmutable($params['until']));
            } catch (Exception) {
                $params['until'] = '';
            }
        }

        return $this->translator->trans('updates.error.' . $error->code->value, $params);
    }
}
