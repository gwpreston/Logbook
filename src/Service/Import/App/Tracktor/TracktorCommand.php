<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Tracktor;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\User\Username;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\UserRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Import\App\AppArchive;
use Logbook\Service\Import\App\AppFiles;
use Logbook\Service\Import\App\AppRowStatus;
use Logbook\Service\Import\App\ArchiveReader;
use Logbook\Service\Import\App\ArchiveRefused;
use Logbook\Service\Import\App\ImportAppCommand;
use Logbook\Service\Vehicle\VehicleService;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * `php bin/import-app.php <export.json>` (spec.md §7.13 *The Tracktor
 * reader*): imports a Tracktor export, with its uploads folder or a ZIP of
 * it (`--files`), with the mapping the web page would propose, printing
 * the preview; `--dry-run` writes nothing. As on the web page, rows with
 * problems stop the import unless `--skip-invalid` says to import the
 * others (K65).
 */
final readonly class TracktorCommand
{
    public const string USAGE = "Usage: php bin/import-app.php <export.json> [--files <folder|zip>]"
        . " [--vehicle <id> | --create] [--as <username>] [--schedules] [--skip-invalid] [--dry-run]\n";

    /**
     * The form fields a row's errors name → the Tracktor export's field
     * they come from, per section (K65, as K11 for Fuelio): the owner knows
     * the file, not Logbook's fields.
     */
    private const array COLUMNS = [
        TracktorVehiclePreview::FILLS => [
            'filled_at' => 'date', 'odometer' => 'odometer', 'volume' => 'fuelAmount', 'price' => 'rate',
            'total' => 'cost', 'partial' => 'filled', 'missed_previous' => 'missedLast', 'notes' => 'notes',
        ],
        TracktorVehiclePreview::SERVICES => [
            'performed_on' => 'date', 'odometer' => 'odometer', 'title' => 'notes', 'description' => 'notes',
            'cost' => 'cost', 'vendor' => 'serviceCenter',
        ],
        TracktorVehiclePreview::DOCUMENTS => [
            'title' => 'otherLabel', 'provider' => 'issuer/provider/testingCenter',
            'reference' => 'documentNumber/policyNumber/certificateNumber', 'start_on' => 'startDate/issueDate',
            'expiry_on' => 'endDate/expiryDate', 'cost' => 'cost', 'notes' => 'notes',
        ],
        TracktorVehiclePreview::REMINDERS => ['title' => 'type', 'due_on' => 'dueDate', 'lead_time_days' => 'remindSchedule'],
    ];

    public function __construct(
        private TracktorImporter $importer,
        private ArchiveReader $archives,
        private VehicleService $vehicles,
        private UserRepository $users,
        private AccessContext $access,
        private FeatureToggles $features,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<string> $args the arguments after the script's name
     * @param resource $out
     * @param resource $err
     * @return int 0 imported (or previewed), 1 refused or failed, 3 usage
     */
    public function run(array $args, $out, $err): int
    {
        $path = null;
        $filesPath = null;
        $vehicleId = null;
        $create = false;
        $username = null;
        $schedules = false;
        $skipInvalid = false;
        $dryRun = false;
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            match (true) {
                $arg === '--files' && isset($args[$i + 1]) => $filesPath = $args[++$i],
                $arg === '--vehicle' && isset($args[$i + 1]) && ctype_digit($args[$i + 1]) => $vehicleId = (int) $args[++$i],
                $arg === '--as' && isset($args[$i + 1]) => $username = $args[++$i],
                $arg === '--create' => $create = true,
                $arg === '--schedules' => $schedules = true,
                $arg === '--skip-invalid' => $skipInvalid = true,
                $arg === '--dry-run' => $dryRun = true,
                !str_starts_with($arg, '-') && $path === null => $path = $arg,
                default => $path = '',
            };
        }
        if ($path === null || $path === '' || ($create && $vehicleId !== null)) {
            fwrite($err, self::USAGE);

            return 3;
        }
        if (!$this->features->isEnabled(Feature::Fuel)) {
            fwrite($err, "The fuel module is switched off (Settings → Modules).\n");

            return 1;
        }
        $user = $this->user($username, $err);
        if ($user === null) {
            return 1;
        }
        $this->access->apply($user);
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($user->preferences->locale);
        }
        if (!is_file($path)) {
            fwrite($err, sprintf("Cannot read %s.\n", $path));

            return 1;
        }

        $files = null;
        try {
            $export = TracktorReader::read(basename($path), (string) file_get_contents($path), $user->preferences->timeZone());
            if ($vehicleId !== null && count($export->vehicles) > 1) {
                fwrite($err, "--vehicle needs an export of one vehicle; this one has " . count($export->vehicles) . ".\n");

                return 3;
            }
            if ($filesPath !== null) {
                $files = $this->files($filesPath);
                foreach ($files instanceof AppArchive ? $files->ignored : [] as $name) {
                    fwrite($out, sprintf("Ignored in the archive: %s\n", $name));
                }
            }

            $preview = $this->preview($user, $export, $vehicleId, $create, $schedules, $files, $err);
            if ($preview === null) {
                return 1;
            }
            $this->printPreview($preview, $out);
            if ($dryRun) {
                fwrite($out, "Dry run: nothing was written.\n");

                return 0;
            }
            // Rows with problems stop the run even when nothing else is left
            // to import (K126).
            $total = $preview->total(AppRowStatus::Import);
            $invalid = $preview->total(AppRowStatus::Invalid);
            if ($invalid > 0 && !$skipInvalid) {
                fwrite($err, ImportAppCommand::invalidRows($invalid, $total));

                return 1;
            }
            if ($total === 0) {
                fwrite($out, $this->translator->trans('import_app.error.nothing') . "\n");

                return 0;
            }

            $done = $this->importer->import($user, $preview, $files);
            foreach ($done->vehicles as $result) {
                if ($result->written !== null) {
                    fwrite($out, sprintf(
                        "Imported %d row(s) into %s (vehicle %d).%s\n",
                        $result->total(AppRowStatus::Import),
                        $result->written->name(),
                        $result->written->id,
                        $result->unusualFills > 0
                            ? sprintf(' %d imported fill-up(s) look unusual: see its Fuel tab.', $result->unusualFills)
                            : '',
                    ));
                }
            }

            return 0;
        } catch (TracktorRefused $e) {
            fwrite($err, $this->translator->trans($e->key, $e->params) . "\n");

            return 1;
        } catch (ArchiveRefused $e) {
            fwrite($err, $this->translator->trans($e->key, $e->params) . "\n");

            return 1;
        } catch (Throwable $e) {
            fwrite($err, 'Import failed, nothing was written: ' . $e->getMessage() . "\n");

            return 1;
        } finally {
            $files?->close();
        }
    }

    /**
     * A folder of Tracktor's uploads, or a ZIP of it.
     *
     * @throws ArchiveRefused
     */
    private function files(string $path): AppFiles
    {
        return is_dir($path) ? $this->archives->openFolder($path) : $this->archives->openUploads($path);
    }

    /**
     * @param resource $err
     */
    private function preview(
        User $user,
        TracktorExport $export,
        ?int $vehicleId,
        bool $create,
        bool $schedules,
        ?AppFiles $files,
        $err,
    ): ?TracktorPreview {
        $manageable = array_values(array_filter(
            $this->vehicles->listWith($user, VehicleAbility::Manage),
            static fn (Vehicle $v): bool => !$v->isArchived(),
        ));
        $guess = $this->importer->guess($user, $export, $manageable);
        $vehicles = [];
        foreach ($export->vehicles as $vehicle) {
            $vehicles[$vehicle->id] = match (true) {
                $vehicleId !== null => (string) $vehicleId,
                $create => TracktorImportOptions::NEW_VEHICLE,
                default => $guess->vehicles[$vehicle->id] ?? TracktorImportOptions::NEW_VEHICLE,
            };
        }
        $options = TracktorImportOptions::fromQuery(
            ['vehicle' => $vehicles, 'reminders' => '1'] + ($schedules ? ['schedules' => '1'] : []),
            $guess,
        );
        $targets = [];
        foreach ($export->vehicles as $vehicle) {
            $id = $options->vehicleId($vehicle->id);
            if ($id === null) {
                continue;
            }
            foreach ($manageable as $candidate) {
                if ($candidate->id === $id) {
                    $targets[$vehicle->id] = $candidate;
                }
            }
            if (!isset($targets[$vehicle->id])) {
                fwrite($err, sprintf("Vehicle %d isn't an active vehicle %s can manage.\n", $id, $user->username));

                return null;
            }
        }

        return $this->importer->analyse($user, $export, $options, $targets, $manageable, $files);
    }

    /**
     * @param resource $out
     */
    private function printPreview(TracktorPreview $preview, $out): void
    {
        $t = $this->translator->trans(...);
        $options = $preview->options;
        fwrite($out, sprintf(
            "%s: %d vehicle(s); units %s and %s.\n",
            $preview->export->fileName,
            count($preview->vehicles),
            $options->distanceUnit->value,
            $options->volumeUnit->value,
        ));
        if ($preview->export->unread !== []) {
            fwrite($out, sprintf("  Not read: %s\n", implode(', ', $preview->export->unread)));
        }
        foreach ($preview->vehicles as $vehicle) {
            fwrite($out, sprintf(
                "\n%s: %s\n",
                $vehicle->source->name(),
                match (true) {
                    $vehicle->skipped => 'skipped',
                    $vehicle->target !== null => 'into ' . $vehicle->target->name(),
                    default => 'a new vehicle',
                },
            ));
            if ($vehicle->skipped) {
                continue;
            }
            foreach ($vehicle->warnings as $warning) {
                fwrite($out, '  ! ' . $t($warning['key'], $warning['params']) . "\n");
            }
            $sanity = $vehicle->sanity;
            if ($sanity !== null) {
                fwrite($out, sprintf(
                    "  Economy: %.1f %s per 100 km over %d tanks%s\n",
                    $sanity->per100Km(),
                    match ($sanity->kind->value) {
                        'electric' => 'kWh',
                        'gas' => 'kg',
                        default => 'L',
                    },
                    $sanity->tanks,
                    $sanity->isUnusual() ? ' (unusual: check the units)' : '',
                ));
            }
            foreach (TracktorVehiclePreview::SECTIONS as $section) {
                $rows = $vehicle->rows($section);
                if ($rows === []) {
                    continue;
                }
                $parts = [];
                foreach (AppRowStatus::cases() as $status) {
                    $count = $vehicle->count($section, $status);
                    if ($count > 0) {
                        $parts[] = $t('import_app.count.' . $status->value, ['count' => $count]);
                    }
                }
                fwrite($out, sprintf("  %s: %s\n", $t('import_app.section.' . $section), implode(', ', $parts)));
                $columns = self::COLUMNS[$section] ?? [];
                foreach ($rows as $row) {
                    if ($row->status === AppRowStatus::Import) {
                        continue;
                    }
                    $why = $row->errors !== []
                        ? implode('; ', array_map(
                            static fn (array $e): string
                                => ($columns[$e['field']] ?? $e['field']) . ': ' . $t($e['key'], $e['params']),
                            $row->errors,
                        ))
                        : ($row->note === null
                            ? $t('import_app.reason.' . $row->status->value)
                            : $t($row->note['key'], $row->note['params']));
                    $status = $t('import_app.status.' . $row->status->value);
                    fwrite($out, sprintf("    row %d, %s: %s\n", $row->line, $status, $why));
                }
            }
        }
    }

    /**
     * @param resource $err
     */
    private function user(?string $username, $err): ?User
    {
        if ($username !== null) {
            $user = $this->users->findByUsername(Username::normalise($username));
            if ($user === null) {
                fwrite($err, sprintf("There is no user \"%s\".\n", $username));
            }

            return $user;
        }
        $all = $this->users->listAll();
        if (count($all) === 1) {
            return $all[0];
        }
        fwrite($err, "This install has more than one user: say whose import it is with --as <username>.\n");

        return null;
    }
}
