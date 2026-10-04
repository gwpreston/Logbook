<?php

declare(strict_types=1);

namespace Logbook\Service\Import\App\Fuelio;

use Logbook\Domain\Access\VehicleAbility;
use Logbook\Domain\Feature\Feature;
use Logbook\Domain\User\User;
use Logbook\Domain\User\Username;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\UserRepository;
use Logbook\Service\Access\AccessContext;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Import\App\AppArchive;
use Logbook\Service\Import\App\AppImportOptions;
use Logbook\Service\Import\App\AppRowStatus;
use Logbook\Service\Import\App\AppVehiclePreview;
use Logbook\Service\Import\App\ArchiveReader;
use Logbook\Service\Import\App\ArchiveRefused;
use Logbook\Service\Import\App\SectionSplitter;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Csv\CsvTooLong;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * `php bin/import-app.php` (spec.md §7.13 *Web and command line*): imports
 * a Fuelio CSV or backup ZIP, photos included, with the detected and
 * default mappings, printing the preview; `--dry-run` writes nothing.
 */
final readonly class FuelioCommand
{
    public const string USAGE = "Usage: php bin/import-app.php <file.csv|file.zip> [--vehicle <id> | --create]"
        . " [--as <username>] [--schedules] [--dry-run]\n";

    public function __construct(
        private FuelioImporter $importer,
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
        $vehicleId = null;
        $create = false;
        $username = null;
        $schedules = false;
        $dryRun = false;
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            match (true) {
                $arg === '--vehicle' && isset($args[$i + 1]) && ctype_digit($args[$i + 1]) => $vehicleId = (int) $args[++$i],
                $arg === '--as' && isset($args[$i + 1]) => $username = $args[++$i],
                $arg === '--create' => $create = true,
                $arg === '--schedules' => $schedules = true,
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
        $archive = null;
        try {
            $isZip = str_starts_with((string) file_get_contents($path, false, null, 0, 4), "PK\x03\x04");
            if ($isZip) {
                $archive = $this->archives->open($path);
                $files = $archive->csv;
                foreach ($archive->ignored as $name) {
                    fwrite($out, sprintf("Ignored in the archive: %s\n", $name));
                }
            } else {
                $files = [['name' => basename($path), 'contents' => (string) file_get_contents($path)]];
            }

            $exports = [];
            foreach ($files as $file) {
                $split = SectionSplitter::split($file['name'], $file['contents']);
                if ($split === null || !FuelioReader::detect($split)) {
                    fwrite($out, sprintf("Not a Fuelio export, ignored: %s\n", $file['name']));
                    continue;
                }
                $exports[] = FuelioReader::read($split);
            }
            if ($exports === []) {
                fwrite($err, $this->translator->trans('import_app.error.not_fuelio') . "\n");

                return 1;
            }
            if ($vehicleId !== null && count($exports) > 1) {
                fwrite($err, "--vehicle needs an export of one vehicle; this one has " . count($exports) . ".\n");

                return 3;
            }

            $previews = $this->previews($user, $exports, $vehicleId, $create, $schedules, $archive, $err);
            if ($previews === null) {
                return 1;
            }
            foreach ($previews as $preview) {
                $this->printPreview($preview, $out);
            }
            if ($dryRun) {
                fwrite($out, "Dry run: nothing was written.\n");

                return 0;
            }
            $total = array_sum(array_map(static fn (AppVehiclePreview $p): int => $p->total(AppRowStatus::Import), $previews));
            if ($total === 0) {
                fwrite($out, $this->translator->trans('import_app.error.nothing') . "\n");

                return 0;
            }

            $done = $this->importer->import($user, $previews, $archive);
            foreach ($done as $result) {
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
        } catch (ArchiveRefused $e) {
            fwrite($err, $this->translator->trans($e->key, $e->params) . "\n");

            return 1;
        } catch (CsvTooLong $e) {
            fwrite($err, sprintf("More than %d rows in one file.\n", $e->maxRows));

            return 1;
        } catch (Throwable $e) {
            fwrite($err, 'Import failed, nothing was written: ' . $e->getMessage() . "\n");

            return 1;
        } finally {
            $archive?->close();
        }
    }

    /**
     * @param list<FuelioExport> $exports
     * @param resource $err
     * @return list<AppVehiclePreview>|null
     */
    private function previews(
        User $user,
        array $exports,
        ?int $vehicleId,
        bool $create,
        bool $schedules,
        ?AppArchive $archive,
        $err,
    ): ?array {
        $manageable = array_values(array_filter(
            $this->vehicles->listWith($user, VehicleAbility::Manage),
            static fn (Vehicle $v): bool => !$v->isArchived(),
        ));
        $previews = [];
        foreach ($exports as $export) {
            $guess = $this->importer->guess($user, $export, $manageable);
            $vehicle = match (true) {
                $vehicleId !== null => (string) $vehicleId,
                $create => AppImportOptions::NEW_VEHICLE,
                default => $guess->vehicle,
            };
            $options = AppImportOptions::fromQuery(
                ['vehicle' => $vehicle] + ($schedules ? ['schedules' => '1'] : []),
                $guess,
            );
            $target = null;
            if ($options->vehicleId() !== null) {
                foreach ($manageable as $candidate) {
                    if ($candidate->id === $options->vehicleId()) {
                        $target = $candidate;
                    }
                }
                if ($target === null) {
                    fwrite($err, sprintf(
                        "Vehicle %d isn't an active vehicle %s can manage.\n",
                        $options->vehicleId(),
                        $user->username,
                    ));

                    return null;
                }
            }
            $previews[] = $this->importer->analyse($user, $export, $options, $target, $archive);
        }

        return $previews;
    }

    /**
     * @param resource $out
     */
    private function printPreview(AppVehiclePreview $preview, $out): void
    {
        $v = $preview->export->vehicle;
        fwrite($out, sprintf(
            "\n%s (%s): %s\n",
            $preview->export->fileName,
            trim(implode(' ', array_filter([$v->name, $v->make, $v->model, $v->plate]))),
            $preview->target !== null ? 'into ' . $preview->target->name() : 'a new vehicle',
        ));
        $sanity = $preview->sanity;
        if ($sanity !== null) {
            fwrite($out, sprintf(
                "  Units: %s and %s; %.1f %s per 100 km over %d tanks%s%s\n",
                $preview->options->distanceUnit->value,
                $preview->options->volumeUnit->value,
                $sanity->per100Km(),
                match ($sanity->kind->value) {
                    'electric' => 'kWh',
                    'gas' => 'kg',
                    default => 'L',
                },
                $sanity->tanks,
                match ($sanity->matchesApp()) {
                    true => "; Fuelio's own figures agree",
                    false => "; Fuelio's own figures DISAGREE: check the units",
                    null => '',
                },
                $sanity->isUnusual() ? ' (unusual: check the units)' : '',
            ));
        }
        foreach (AppVehiclePreview::SECTIONS as $section) {
            $rows = $preview->rows($section);
            if ($rows === []) {
                continue;
            }
            $parts = [];
            foreach (AppRowStatus::cases() as $status) {
                $count = $preview->count($section, $status);
                if ($count > 0) {
                    $parts[] = $this->translator->trans('import_app.count.' . $status->value, ['count' => $count]);
                }
            }
            $t = $this->translator->trans(...);
            fwrite($out, sprintf("  %s: %s\n", $t('import_app.section.' . $section), implode(', ', $parts)));
            foreach ($rows as $row) {
                if ($row->status === AppRowStatus::Import) {
                    continue;
                }
                $why = $row->errors !== []
                    ? implode('; ', array_map(
                        static fn (array $e): string => $e['field'] . ': ' . $t($e['key'], $e['params']),
                        $row->errors,
                    ))
                    : ($row->note === null
                        ? $t('import_app.reason.' . $row->status->value)
                        : $t($row->note['key'], $row->note['params']));
                fwrite($out, sprintf("    line %d, %s: %s\n", $row->line, $t('import_app.status.' . $row->status->value), $why));
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
