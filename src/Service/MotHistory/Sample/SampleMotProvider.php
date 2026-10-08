<?php

declare(strict_types=1);

namespace Logbook\Service\MotHistory\Sample;

use Logbook\Domain\MotHistory\MotVehicleRecord;
use Logbook\Service\FuelPrices\ProviderCredentials;
use Logbook\Service\FuelPrices\ProviderLicence;
use Logbook\Service\MotHistory\MotHistoryClient;
use Logbook\Service\MotHistory\MotHistoryProvider;
use Logbook\Service\MotHistory\Uk\DvsaParser;
use Logbook\Service\MotHistory\VehicleIdentifier;

/**
 * *Sample MOT history (development)* (spec.md §7.38 *Sample provider*,
 * #335): answers from built-in records for the sample vehicles'
 * registrations, in DVSA's own shape so they go through DvsaParser. No
 * credentials, nothing sent anywhere. Registered only outside production
 * and never in demo mode; `bin/dev-setup.sh --with-sample-data` enables
 * it. The mileages sit between the sample data's own readings, so nothing
 * is flagged that isn't meant to be.
 */
final class SampleMotProvider implements MotHistoryProvider, MotHistoryClient
{
    public const string CODE = 'sample';

    /** @var array<string, array<string, mixed>> DVSA-shaped answers by registration (spaces removed) */
    private const array RECORDS = [
        'LB19KTR' => [
            'registration' => 'LB19KTR', 'make' => 'VOLKSWAGEN', 'model' => 'GOLF LIFE TSI', 'fuelType' => 'Petrol',
            'primaryColour' => 'Grey', 'firstUsedDate' => '2019-03-12', 'registrationDate' => '2019-03-12',
            'hasOutstandingRecall' => 'Yes',
            'motTests' => [
                ['completedDate' => '2026-03-05T10:30:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2027-03-04',
                    'odometerValue' => '42905', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '550126030501', 'registrationAtTimeOfTest' => 'LB19KTR', 'dataSource' => 'DVSA',
                    'defects' => [
                        [
                            'text' => 'Nearside Front Tyre worn close to legal limit/worn on edge (5.2.3 (e))',
                            'type' => 'ADVISORY',
                            'dangerous' => false,
                        ],
                        [
                            'text' => 'Offside Rear Brake pipe slightly corroded (1.1.11 (c))',
                            'type' => 'ADVISORY',
                            'dangerous' => false,
                        ],
                    ]],
                ['completedDate' => '2025-03-06T10:30:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2026-03-05',
                    'odometerValue' => '35914', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '440325030601', 'registrationAtTimeOfTest' => 'LB19KTR', 'dataSource' => 'DVSA',
                    'defects' => [
                        [
                            'text' => 'Nearside Front Tyre worn close to legal limit/worn on edge (5.2.3 (e))',
                            'type' => 'ADVISORY',
                            'dangerous' => false,
                        ],
                    ]],
                ['completedDate' => '2024-03-07T09:15:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2025-03-06',
                    'odometerValue' => '32467', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '330424030701', 'registrationAtTimeOfTest' => 'LB19KTR', 'dataSource' => 'DVSA',
                    'defects' => []],
                ['completedDate' => '2024-03-06T11:40:00.000Z', 'testResult' => 'FAILED', 'expiryDate' => null,
                    'odometerValue' => '32466', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '330424030601', 'registrationAtTimeOfTest' => 'LB19KTR', 'dataSource' => 'DVSA',
                    'defects' => [
                        [
                            'text' => 'Offside Front Position lamp not working (4.2.1 (a) (ii))',
                            'type' => 'MAJOR',
                            'dangerous' => false,
                        ],
                        [
                            'text' => 'Windscreen wiper does not clear the windscreen effectively (3.4 (b) (i))',
                            'type' => 'MINOR',
                            'dangerous' => false,
                        ],
                    ]],
                ['completedDate' => '2023-03-07T10:00:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2024-03-06',
                    'odometerValue' => '28210', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '220323030701', 'registrationAtTimeOfTest' => 'LB19KTR', 'dataSource' => 'DVSA',
                    'defects' => []],
                ['completedDate' => '2022-03-08T14:20:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2023-03-07',
                    'odometerValue' => '23985', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '110322030801', 'registrationAtTimeOfTest' => 'LB19KTR', 'dataSource' => 'DVSA',
                    'defects' => [
                        [
                            'text' => 'Front Exhaust has a minor leak of exhaust gases (7.1.2 (a))',
                            'type' => 'ADVISORY',
                            'dangerous' => false,
                        ],
                    ]],
            ],
        ],
        'LK22VXN' => [
            'registration' => 'LK22VXN', 'make' => 'TOYOTA', 'model' => 'COROLLA', 'fuelType' => 'Hybrid Electric (Clean)',
            'primaryColour' => 'White', 'firstUsedDate' => '2022-03-18', 'registrationDate' => '2022-03-18',
            'hasOutstandingRecall' => 'No',
            'motTests' => [
                ['completedDate' => '2025-09-12T08:45:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2026-09-11',
                    'odometerValue' => '19168', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '660925091201', 'registrationAtTimeOfTest' => 'LK22VXN', 'dataSource' => 'DVSA',
                    'defects' => [
                        [
                            'text' => 'Nearside Rear Tyre worn close to legal limit/worn on edge (5.2.3 (e))',
                            'type' => 'ADVISORY',
                            'dangerous' => false,
                        ],
                    ]],
            ],
        ],
        'MT20BKE' => [
            'registration' => 'MT20BKE', 'make' => 'TRIUMPH', 'model' => 'STREET TRIPLE R', 'fuelType' => 'Petrol',
            'primaryColour' => 'Black', 'firstUsedDate' => '2020-04-20', 'registrationDate' => '2020-04-20',
            'hasOutstandingRecall' => 'Unknown',
            'motTests' => [
                ['completedDate' => '2026-04-15T09:00:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2027-04-14',
                    'odometerValue' => '11868', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '770426041501', 'registrationAtTimeOfTest' => 'MT20BKE', 'dataSource' => 'DVSA',
                    'defects' => [
                        [
                            'text' => 'Drive chain slack (6.2.6 (c))',
                            'type' => 'ADVISORY',
                            'dangerous' => false,
                        ],
                    ]],
                ['completedDate' => '2025-04-17T09:00:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2026-04-16',
                    'odometerValue' => '10439', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '770425041701', 'registrationAtTimeOfTest' => 'MT20BKE', 'dataSource' => 'DVSA',
                    'defects' => []],
                ['completedDate' => '2024-04-18T09:00:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2025-04-17',
                    'odometerValue' => null, 'odometerUnit' => null, 'odometerResultType' => 'UNREADABLE',
                    'motTestNumber' => '770424041801', 'registrationAtTimeOfTest' => 'MT20BKE', 'dataSource' => 'DVSA',
                    'defects' => []],
            ],
        ],
        'EV23KIA' => [
            'registration' => 'EV23KIA', 'make' => 'KIA', 'model' => 'EV6', 'manufactureYear' => '2024',
            'fuelType' => 'Electric', 'primaryColour' => 'Blue', 'registrationDate' => '2024-02-09',
            'motTestDueDate' => '2027-02-09', 'hasOutstandingRecall' => 'Unknown',
        ],
        'PHV1' => [
            'registration' => 'PHV1', 'make' => 'MITSUBISHI', 'model' => 'OUTLANDER', 'fuelType' => 'Hybrid Electric (Clean)',
            'primaryColour' => 'Red', 'firstUsedDate' => '2021-06-10', 'registrationDate' => '2021-06-10',
            'hasOutstandingRecall' => 'No',
            'motTests' => [
                ['completedDate' => '2026-06-08T09:00:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2027-06-07',
                    'odometerValue' => '22910', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '880626060801', 'registrationAtTimeOfTest' => 'PHV1', 'dataSource' => 'DVSA',
                    'defects' => []],
                ['completedDate' => '2025-06-11T09:00:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2026-06-10',
                    'odometerValue' => '15783', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '880625061101', 'registrationAtTimeOfTest' => 'PHV1', 'dataSource' => 'DVSA',
                    'defects' => []],
                ['completedDate' => '2024-06-12T09:00:00.000Z', 'testResult' => 'PASSED', 'expiryDate' => '2025-06-11',
                    'odometerValue' => '9631', 'odometerUnit' => 'MI', 'odometerResultType' => 'READ',
                    'motTestNumber' => '880624061201', 'registrationAtTimeOfTest' => 'OU21XKE', 'dataSource' => 'DVSA',
                    'defects' => [
                        [
                            'text' => 'Oil leak, but not excessive (8.4.1 (a) (i))',
                            'type' => 'MINOR',
                            'dangerous' => false,
                        ],
                    ]],
            ],
        ],
    ];

    /**
     * The sample answer for a registration, as DVSA would give it; the
     * seeder stores these as a fetch would.
     */
    public static function record(string $registration): ?MotVehicleRecord
    {
        $plate = VehicleIdentifier::registration($registration);
        $raw = $plate === null ? null : (self::RECORDS[$plate] ?? null);

        return $raw === null ? null : DvsaParser::vehicle($raw);
    }

    /**
     * @return list<string> the registrations it answers for
     */
    public static function registrations(): array
    {
        return array_keys(self::RECORDS);
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function nameKey(): string
    {
        return 'mot_history.provider.sample.name';
    }

    public function descriptionKey(): string
    {
        return 'mot_history.provider.sample.description';
    }

    public function sendsKey(): string
    {
        return 'mot_history.provider.sample.sends';
    }

    public function licence(): ProviderLicence
    {
        return new ProviderLicence(
            'Open Government Licence v3.0',
            'mot_history.attribution',
            'https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/',
        );
    }

    public function credentials(): array
    {
        return [];
    }

    public function acceptsCredential(string $slot, string $value): bool
    {
        return true;
    }

    public function countries(): array
    {
        return ['GB'];
    }

    public function connect(ProviderCredentials $credentials): MotHistoryClient
    {
        return $this;
    }

    public function byRegistration(string $registration): ?MotVehicleRecord
    {
        return self::record($registration);
    }

    public function byVin(string $vin): ?MotVehicleRecord
    {
        return null;
    }

    public function ping(): void
    {
    }
}
