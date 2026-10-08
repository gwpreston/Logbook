<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask\Tools;

use DateTimeZone;
use Logbook\Domain\Issue\IssueData;
use Logbook\Domain\Issue\IssueStatus;
use Logbook\Repository\IssueRepository;
use Logbook\Service\Ai\Ask\ToolRegistry;
use Logbook\Service\Ai\Draft\DraftStore;
use Logbook\Service\Issue\IssueService;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Date\LocalTime;
use Logbook\Tests\Support\JsonDoc;

/**
 * `issues` and `draft_issue` (Phase 40.2, spec.md §7.26, §7.37): the
 * owner's notes, safety first, open and watching by default, and a draft
 * card whose Add writes the issue. Nothing offers a cause.
 */
final class IssuesToolTest extends ToolsBTestCase
{
    public function testWhatIssuesAreOpenOnTheGolf(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app);
        $fiesta = $this->vehicle($app, 'Ford', 'Fiesta');
        $zone = new DateTimeZone('Europe/London');
        $issues = $this->service($app, IssueService::class);
        $day = static fn (string $date) => LocalTime::parseDate($date) ?? throw new \LogicException($date);
        $issues->create($golf, new IssueData($day('2026-08-12'), 'Knock from front left', odometerKm: '41000.000'), $zone);
        $pipes = new IssueData($day('2026-09-01'), 'Brake pipes corroded', IssueStatus::Watching, affectsSafety: true);
        $issues->create($golf, $pipes, $zone);
        $fixed = $issues->create($golf, new IssueData($day('2026-07-01'), 'Squeak'), $zone);
        $issues->fixWithoutRecord($golf, $fixed, $day('2026-07-20'), 'Went away');
        $issues->create($fiesta, new IssueData($day('2026-09-10'), 'Slow leak, rear right'), $zone);
        $this->assertSchemaAccepts($app, $owner, 'issues', ['vehicles' => [$golf->id], 'status' => 'fixed']);

        $open = $this->toolResult($app, $owner, 'issues', ['vehicles' => [$golf->id]]);
        $data = new JsonDoc($open->data);
        self::assertSame(2, $data->int('issues'));
        self::assertSame(1, $data->int('affecting_safety'));
        self::assertSame('Brake pipes corroded', $data->get('rows', 0, 'title'), 'safety first');
        self::assertTrue($data->get('rows', 0, 'affects_safety'));
        self::assertSame('Watching', $data->get('rows', 0, 'status'));
        self::assertSame('Knock from front left', $data->get('rows', 1, 'title'));
        self::assertSame('/vehicles/' . $golf->id . '/issues', $open->link);
        self::assertStringContainsString('qualified mechanic', $data->string('note'));

        $fixedOnly = new JsonDoc(
            $this->toolResult($app, $owner, 'issues', ['vehicles' => [$golf->id], 'status' => 'fixed'])->data,
        );
        self::assertSame(1, $fixedOnly->int('issues'));
        self::assertTrue($fixedOnly->get('rows', 0, 'fixed_without_record'));

        $everyone = $this->toolResult($app, $owner, 'issues');
        self::assertSame(3, (new JsonDoc($everyone->data))->int('issues'), 'every vehicle');
        self::assertSame('/issues', $everyone->link);
        $this->service($app, VehicleService::class)->archive($owner, $fiesta);
        $archived = new JsonDoc($this->toolResult($app, $owner, 'issues')->data);
        self::assertSame(3, $archived->int('issues'), 'archived ones still read');
    }

    public function testADraftIssueIsACardUntilAddAndKeepsTheUsersWords(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app);

        $result = $this->toolResult($app, $owner, 'draft_issue', [
            'vehicle' => $golf->id,
            'title' => 'Knock from front left under braking',
            'description' => 'Only when cold',
            'category' => 'brakes',
            'affects_safety' => true,
        ]);
        $card = new JsonDoc($result->data);
        $before = $this->service($app, IssueRepository::class)->listForVehicle($golf->id);
        self::assertSame([], $before, 'nothing saved yet');

        $this->service($app, DraftStore::class)->apply($owner, $card->int('draft_id'));
        $saved = $this->service($app, IssueRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $saved);
        self::assertSame('Knock from front left under braking', $saved[0]->data->title);
        self::assertSame('Only when cold', $saved[0]->data->description);
        self::assertTrue($saved[0]->data->affectsSafety);
        self::assertSame(IssueStatus::Open, $saved[0]->status());
    }

    public function testTheDraftToolNeverSuggestsACause(): void
    {
        [$app, $owner] = $this->askApp();
        $this->vehicle($app);
        foreach ($this->service($app, ToolRegistry::class)->definitions($owner) as $offered) {
            if (in_array($offered->name, ['issues', 'draft_issue'], true)) {
                $never = '/never (add )?a (cause|diagnosis)/i';
                self::assertMatchesRegularExpression($never, $offered->description, $offered->name);
            }
        }
    }

    public function testTheModuleOffHidesBothTools(): void
    {
        [$app, $owner] = $this->askApp(['FEATURES_ISSUES' => 'false']);
        $this->vehicle($app);

        self::assertFalse($this->isOffered($app, $owner, 'issues'));
        self::assertFalse($this->isOffered($app, $owner, 'draft_issue'));
    }
}
