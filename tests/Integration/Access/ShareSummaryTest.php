<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Access;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareAllowance;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Access\ShareSummaryLine;
use Logbook\Domain\Access\VehicleAbility;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Access\EntryAccess;
use Logbook\Service\Access\ShareSummary;
use Logbook\Service\Access\VehicleAccess;
use Logbook\Service\Sharing\SharingService;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a share allows, in plain words (spec.md §7.21, Phase 46): every
 * ability has a line; each line is a Can exactly when the policy allows
 * it, for every level and tick; lines follow the modules; and a request a
 * line says Can is allowed, one it says Can't is refused.
 */
final class ShareSummaryTest extends AppTestCase
{
    use CostFixtures;

    public function testEveryAbilityHasALine(): void
    {
        $described = array_map(static fn (array $line): VehicleAbility => $line[0], ShareSummary::ABILITY_LINES);
        foreach (VehicleAbility::cases() as $ability) {
            self::assertContains($ability, $described, $ability->value . ' needs a line in ShareSummary::ABILITY_LINES');
        }
    }

    /**
     * @return iterable<string, array{ShareLevel, bool, bool}>
     */
    public static function states(): iterable
    {
        foreach (ShareLevel::cases() as $level) {
            foreach ([false, true] as $costs) {
                foreach ([false, true] as $notify) {
                    $name = sprintf('%s costs=%d notify=%d', $level->value, (int) $costs, (int) $notify);
                    yield $name => [$level, $costs, $notify];
                }
            }
        }
    }

    #[DataProvider('states')]
    public function testEachLineAgreesWithThePolicy(ShareLevel $level, bool $costs, bool $notify): void
    {
        $app = $this->createApp(['FEATURES_TRIPS' => 'true']);
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $member = $this->createMember($app);
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, $level, $costs, $notify, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $access = $this->service($app, VehicleAccess::class);
        $entries = $this->service($app, EntryAccess::class);

        $allowance = $this->service($app, ShareSummary::class)->of($level, $costs, $notify);
        $lines = self::byKey($allowance);
        self::assertSame(
            ['see', 'documents', 'add', 'change_any', 'costs', 'tools', 'trips', 'incidents', 'reminders', 'own'],
            array_values(array_diff(array_keys($lines), ['change_own', 'own_amounts'])),
            'every module on: every line, in the spec\'s order',
        );
        foreach (ShareSummary::ABILITY_LINES as $key => [$ability]) {
            self::assertSame($access->can($member, $ability, $golf), $lines[$key]->can, $key);
        }
        self::assertSame(in_array($golf->id, $access->recipientVehicleIds($member), true), $lines['reminders']->can);

        // The own-entry lines say what EntryAccess allows beyond the ability.
        $changesOwn = $entries->canChange($member, $golf, $member->id) && !$entries->canChange($member, $golf, null);
        self::assertSame($changesOwn, isset($lines['change_own']), 'change_own');
        $seesOwn = $entries->canSeeAmount($member, $golf, $member->id) && !$entries->canSeeAmount($member, $golf, null);
        $logs = $access->can($member, VehicleAbility::Log, $golf);
        self::assertSame($seesOwn && $logs, isset($lines['own_amounts']), 'own_amounts');
        self::assertFalse($lines['own']->can, 'only the owner shares, transfers, archives or deletes');
    }

    public function testLinesFollowTheModules(): void
    {
        $app = $this->createApp([
            'FEATURES_COMPLIANCE' => 'false',
            'FEATURES_REMINDERS' => 'false',
            'FEATURES_REPORTS' => 'false',
            'FEATURES_FUEL' => 'false',
            'FEATURES_INCIDENTS' => 'false',
        ]);
        $allowance = $this->service($app, ShareSummary::class)->of(ShareLevel::Manage, false, true);
        $lines = self::byKey($allowance);

        self::assertSame(
            ['see', 'add', 'change_any', 'costs', 'tools', 'own'],
            array_keys($lines),
            'no documents, trips (off by default), incidents or reminders',
        );
        self::assertSame(['reminders' => 'no'], $lines['see']->params);
        self::assertSame(['fuel' => 'no', 'maintenance' => 'yes'], $lines['add']->params);
        self::assertSame(['reports' => 'no'], $lines['costs']->params);
    }

    public function testStatesCoverEveryFormStateAndLevelsHaveNoTicks(): void
    {
        $summary = $this->service($this->createApp(), ShareSummary::class);

        $states = $summary->states();
        self::assertCount(12, $states);
        self::assertTrue($states['manage-0-0']->canSeeCosts, 'Manage always sees costs');
        self::assertTrue($states['log-1-0']->canSeeCosts);
        self::assertTrue($states['view-0-1']->notify);

        $levels = $summary->levels();
        self::assertSame(ShareLevel::cases(), array_map(static fn (ShareAllowance $a): ShareLevel => $a->level, $levels));
        self::assertFalse($levels[1]->canSeeCosts);
        self::assertFalse($levels[1]->notify);
    }

    /**
     * For each line, a request it says Can is allowed and one it says
     * Can't is refused (the phase's acceptance criterion 3).
     */
    public function testRequestsMatchTheSummary(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $member = $this->createMember($app);
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::View, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $partner = $this->browserFor($app, 'partner');
        $base = '/vehicles/' . $golf->id;
        $requests = [
            'see' => $base,
            'documents' => $base . '/documents',
            'add' => $base . '/fuel/new',
            'change_any' => $base . '/edit',
            'costs' => $base . '/ownership',
            'tools' => $base . '/sale-pack',
            'own' => $base . '/transfer',
        ];
        $sharing = $this->service($app, SharingService::class);
        $summary = $this->service($app, ShareSummary::class);

        $shares = [
            [ShareLevel::View, false],
            [ShareLevel::View, true],
            [ShareLevel::Log, false],
            [ShareLevel::Log, true],
            [ShareLevel::Manage, false],
        ];
        foreach ($shares as [$level, $costs]) {
            self::assertTrue($sharing->update($golf, $member->id, $level, $costs, false));
            $lines = self::byKey($summary->of($level, $costs, false));
            foreach ($requests as $key => $path) {
                $status = $partner->get($path)->getStatusCode();
                self::assertSame(
                    $lines[$key]->can ? 200 : 403,
                    $status,
                    sprintf('%s (%s, costs %s): %s', $key, $level->value, $costs ? 'on' : 'off', $path),
                );
            }
        }
    }

    /**
     * @return array<string, ShareSummaryLine>
     */
    private static function byKey(ShareAllowance $allowance): array
    {
        $lines = [];
        foreach ($allowance->lines as $line) {
            $lines[$line->key] = $line;
        }

        return $lines;
    }
}
