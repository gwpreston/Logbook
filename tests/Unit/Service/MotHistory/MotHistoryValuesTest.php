<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\MotHistory;

use DateTimeImmutable;
use Logbook\Domain\MotHistory\MotDataSource;
use Logbook\Domain\MotHistory\MotDefectType;
use Logbook\Domain\MotHistory\MotTestResult;
use Logbook\Domain\MotHistory\OdometerState;
use Logbook\Domain\MotHistory\RecallState;
use Logbook\Service\MotHistory\MotHistoryErrorCode;
use Logbook\Service\MotHistory\MotHistoryFailure;
use Logbook\Service\MotHistory\MotHistoryRegistry;
use Logbook\Service\MotHistory\MotHistoryStatus;
use Logbook\Service\MotHistory\Uk\DvsaProvider;
use Logbook\Service\MotHistory\VehicleIdentifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The small pieces of MOT history (spec.md §6, §7.38): identifiers as they
 * may be sent, DVSA's codes, which defects open an issue (#324, #328,
 * #333), and the stored last call.
 */
final class MotHistoryValuesTest extends TestCase
{
    public function testRegistrationsAreTidiedOrRefused(): void
    {
        self::assertSame('AB12CDE', VehicleIdentifier::registration(' ab12 cde '));
        self::assertSame('A1', VehicleIdentifier::registration('a-1'));
        self::assertNull(VehicleIdentifier::registration('AB12CDE9X'));
        self::assertNull(VehicleIdentifier::registration('AB/12'));
        self::assertNull(VehicleIdentifier::registration(''));
        self::assertNull(VehicleIdentifier::registration(null));
    }

    public function testVinsAreTidiedOrRefused(): void
    {
        self::assertSame('WVWZZZ1KZCW123456', VehicleIdentifier::vin('wvwzzz1kzcw123456'));
        self::assertSame('ABC12', VehicleIdentifier::vin('abc 12'));
        self::assertNull(VehicleIdentifier::vin('ABC1'));
        self::assertNull(VehicleIdentifier::vin(str_repeat('A', 21)));
        self::assertNull(VehicleIdentifier::vin('WVW?ZZ1KZCW123456'));
    }

    public function testDefectTypesAndWhichOpenAnIssue(): void
    {
        self::assertSame(MotDefectType::NonSpecific, MotDefectType::fromDvsa('NON SPECIFIC'));
        self::assertSame(MotDefectType::SystemGenerated, MotDefectType::fromDvsa('system-generated'));
        self::assertSame(MotDefectType::UserEntered, MotDefectType::fromDvsa('USER ENTERED'));
        self::assertSame(MotDefectType::NonSpecific, MotDefectType::fromDvsa(null));
        self::assertSame(MotDefectType::NonSpecific, MotDefectType::fromDvsa('PRS'));

        $open = array_values(array_filter(MotDefectType::cases(), static fn (MotDefectType $type): bool => $type->opensIssue()));
        self::assertSame([MotDefectType::Major, MotDefectType::Dangerous, MotDefectType::Fail], $open);
        self::assertSame('mot_history.defect.user_entered', MotDefectType::UserEntered->labelKey());
    }

    public function testDvsaCodes(): void
    {
        self::assertSame(OdometerState::Read, OdometerState::fromDvsa('READ'));
        self::assertSame(OdometerState::Unreadable, OdometerState::fromDvsa('unreadable'));
        self::assertSame(OdometerState::None, OdometerState::fromDvsa('NO_ODOMETER'));
        self::assertSame(OdometerState::None, OdometerState::fromDvsa(null));
        self::assertSame(RecallState::Yes, RecallState::fromDvsa('Yes'));
        self::assertSame(RecallState::Unavailable, RecallState::fromDvsa(true));
        self::assertSame('mot_history.recall.no', RecallState::No->labelKey());
        self::assertSame(MotDataSource::DvaNi, MotDataSource::fromDvsa('DVA NI'));
        self::assertSame(MotDataSource::Cvs, MotDataSource::fromDvsa('CVS'));
        self::assertSame(MotDataSource::Dvsa, MotDataSource::fromDvsa('DVSA'));
        self::assertSame('mot_history.result.passed', MotTestResult::Passed->labelKey());
        self::assertSame('mot_history.error.rate_limited', MotHistoryErrorCode::RateLimited->messageKey());
    }

    public function testTheLastCallRoundTrips(): void
    {
        $empty = MotHistoryStatus::fromStored(null);
        self::assertFalse($empty->ok());
        self::assertNull($empty->lastCallAt);

        $ok = $empty->succeeded(new DateTimeImmutable('2026-10-08T10:00:00Z'));
        $failed = $ok->failed(new DateTimeImmutable('2026-10-09T10:00:00Z'), MotHistoryErrorCode::Network, ['reason' => 'x']);
        self::assertTrue($ok->ok());
        self::assertFalse($failed->ok());

        $read = MotHistoryStatus::fromStored(json_decode((string) json_encode($failed->toStored()), true));
        self::assertSame(MotHistoryErrorCode::Network, $read->error);
        self::assertSame(['reason' => 'x'], $read->parameters);
        self::assertSame('2026-10-09T10:00:00+00:00', $read->lastCallAt?->format(DATE_ATOM));
        // A failure keeps the last success, which the keep-alive reads (#327).
        self::assertSame('2026-10-08T10:00:00+00:00', $read->lastSuccessAt?->format(DATE_ATOM));

        $odd = MotHistoryStatus::fromStored([
            'last_call_at' => 'not a time',
            'error' => 'nope',
            'parameters' => ['a' => 1, 'b' => 'c'],
        ]);
        self::assertNull($odd->lastCallAt);
        self::assertNull($odd->error);
        self::assertSame(['b' => 'c'], $odd->parameters);
    }

    public function testAFailureNamesItsCode(): void
    {
        $failure = new MotHistoryFailure(MotHistoryErrorCode::HttpStatus, ['status' => '502']);

        self::assertSame('http_status {"status":"502"}', $failure->getMessage());
        self::assertSame('timeout', (new MotHistoryFailure(MotHistoryErrorCode::Timeout))->getMessage());
    }

    public function testTheRegistryFindsByCode(): void
    {
        $dvsa = (new ReflectionClass(DvsaProvider::class))->newInstanceWithoutConstructor();
        $registry = new MotHistoryRegistry([$dvsa]);

        self::assertSame($dvsa, $registry->get('uk_dvsa'));
        self::assertNull($registry->get('nope'));
        self::assertNull($registry->get(null));
        self::assertSame([$dvsa], $registry->all());
    }
}
