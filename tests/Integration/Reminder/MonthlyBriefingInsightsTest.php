<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Reminder;

use DateTimeImmutable;
use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\AiTaskAssignment;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\ConnectionSettings;
use Logbook\Domain\Ai\Insights\AiInsight;
use Logbook\Domain\Ai\Insights\AiInsightSet;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiInsightRepository;
use Logbook\Repository\AiModelRepository;
use Logbook\Repository\AiTaskRepository;
use Logbook\Repository\UserRepository;
use Logbook\Service\Notification\ReminderNotifier;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Tests\Support\BriefingTestCase;

/**
 * The monthly briefing's *Insights* (spec.md §7.11 *The monthly briefing*,
 * #362, #365, Phase 43.3): the computed insights, the AI ones only from a
 * set made for the user's today or yesterday and never an unbacked one,
 * and no model call from the digest.
 */
final class MonthlyBriefingInsightsTest extends BriefingTestCase
{
    private const string MODEL_URL = 'http://192.168.1.20:11434/v1';

    public function testTheComputedInsightsAreListed(): void
    {
        $this->start();
        $this->twoCars();

        $this->runTasks();

        $json = $this->digestJson();
        $computed = $this->insightsFrom($json, 'computed');
        self::assertCount(1, $computed);
        self::assertSame('cheapest_to_run', $computed[0]['kind']);
        $text = $this->digestText();
        self::assertStringContainsString('One insight:', $text);
        $line = '• ' . self::text($computed[0]['title']) . ' — ' . self::text($computed[0]['body']);
        self::assertStringContainsString($line, $text);
        self::assertStringNotContainsString('• AI:', $text);
    }

    public function testNoInsightsLineWhenThereAreNone(): void
    {
        $this->start();
        $this->spend($this->car(), '2026-09-05', '30');

        $this->runTasks();

        self::assertSame([], $this->digestJson()['insights']);
        self::assertStringNotContainsString('insight', $this->digestText());
    }

    public function testTheInsightsBoxLeavesThemOut(): void
    {
        $this->start();
        [$golf] = $this->twoCars();
        $this->spend($golf, '2026-09-05', '30');
        $this->saveChannels($this->browser, 'owner', 'pat@example.com', [
            'digest_include_shown' => '1',
            'digest_include' => ['last_month'],
        ]);

        $this->runTasks();

        self::assertSame([], $this->digestJson()['insights']);
        self::assertStringNotContainsString('insight', $this->digestText());
    }

    public function testAnAiSetFromTodayIsListedAfterTheComputedOnesAndMarked(): void
    {
        $this->start();
        $this->twoCars();
        $this->withAi();
        $this->keep('2026-10-01', [new AiInsight('Fuel came to £70.25', 'In one fill-up.')]);

        $this->runTasks();

        $text = $this->digestText();
        self::assertStringContainsString('• AI: Fuel came to £70.25 — In one fill-up.', $text);
        self::assertStringContainsString('2 insights:', $text);
        self::assertLessThan(strpos($text, '• AI:'), strpos($text, 'cheapest') ?: 0);
        $ai = $this->insightsFrom($this->digestJson(), 'ai');
        self::assertCount(1, $ai);
        self::assertSame('other', $ai[0]['kind']);
    }

    public function testAnAiSetFromYesterdayIsListed(): void
    {
        $this->start();
        $this->car();
        $this->withAi();
        $this->keep('2026-09-30', [new AiInsight('Fuel came to £70.25', 'In one fill-up.')]);

        $this->runTasks();

        self::assertStringContainsString('• AI: Fuel came to £70.25', $this->digestText());
    }

    public function testAnAiSetFromTwoDaysAgoIsNotListed(): void
    {
        $this->start();
        $this->car();
        $this->withAi();
        $this->keep('2026-09-29', [new AiInsight('Fuel came to £70.25', 'In one fill-up.')]);

        $this->runTasks();

        self::assertSame([], $this->digestMails(), 'nothing else to say, so nothing is sent');
    }

    public function testAnAiSetTwoDaysBackForTheUserIsNotListedEvenIfItIsYesterdayInUtc(): void
    {
        $this->inAuckland();
        $this->keep('2026-09-29', [new AiInsight('Two days old', 'Not yesterday for Auckland.')]);

        $this->runTasks();

        self::assertSame([], $this->digestMails());
    }

    public function testAnAiSetFromTheUsersYesterdayIsListedWhateverUtcSays(): void
    {
        $this->inAuckland();
        $this->keep('2026-09-30', [new AiInsight('Yesterday there', 'Listed.')]);

        $this->runTasks();

        self::assertStringContainsString('• AI: Yesterday there — Listed.', $this->digestText());
    }

    public function testAnUngroundedAiInsightIsLeftOut(): void
    {
        $this->start();
        $this->car();
        $this->withAi();
        $this->keep('2026-10-01', [
            new AiInsight('Backed', 'A real figure.'),
            new AiInsight('Fuel is dear', 'About £999 a year more.', ungrounded: ['£999']),
        ]);

        $this->runTasks();

        $text = $this->digestText();
        self::assertStringContainsString('• AI: Backed — A real figure.', $text);
        self::assertStringNotContainsString('999', $text);
        self::assertStringNotContainsString('Fuel is dear', $text);
    }

    public function testAnAiInsightAboutOnlyOtherVehiclesIsLeftOut(): void
    {
        $this->start();
        $golf = $this->car();
        $polo = $this->car('Polo');
        $this->spend($golf, '2026-09-05', '30');
        $this->service($this->app, VehicleService::class)->archive($this->owner($this->app), $polo);
        $this->withAi();
        $this->keep('2026-10-01', [
            new AiInsight('Not yours here', 'About an archived vehicle.', vehicles: [$polo->id]),
            new AiInsight('About the Golf', 'Mine.', vehicles: [$golf->id]),
            new AiInsight('About all', 'No vehicle in particular.'),
        ]);

        $this->runTasks();

        $text = $this->digestText();
        self::assertStringNotContainsString('Not yours here', $text);
        self::assertStringContainsString('• AI: About the Golf — Mine.', $text);
        self::assertStringContainsString('• AI: About all', $text);
    }

    public function testAiInsightsAreLeftOutWithAiOff(): void
    {
        $this->start();
        $this->spend($this->car(), '2026-09-05', '30');
        $this->keep('2026-10-01', [new AiInsight('Fuel came to £70.25', 'In one fill-up.')]);

        $this->runTasks();

        self::assertStringNotContainsString('AI:', $this->digestText());
    }

    public function testTheDigestNeverCallsAModel(): void
    {
        $this->start();
        $this->twoCars();
        $this->withAi();
        // A call would be an error, as well as being recorded.
        $this->http->errorFor[self::MODEL_URL] = 'the model must not be called';
        $this->keep('2026-10-01', [new AiInsight('Backed', 'A real figure.')]);

        $sent = $this->service($this->app, ReminderNotifier::class)->digest($this->owner($this->app));

        self::assertTrue($sent);
        self::assertSame([], $this->http->to('http://192.168.1.20'), 'no request to the model');
        self::assertSame([], $this->http->to('https://api.'), 'nor to any provider');
        self::assertStringContainsString('• AI: Backed', $this->digestText());
    }

    public function testTheDigestWithNoRecentSetLeavesTheAiPartOutWithoutCallingAModel(): void
    {
        $this->start();
        $this->twoCars();
        $this->withAi();
        $this->http->errorFor[self::MODEL_URL] = 'the model must not be called';

        $sent = $this->service($this->app, ReminderNotifier::class)->digest($this->owner($this->app));

        self::assertTrue($sent);
        self::assertSame([], $this->http->to('http://192.168.1.20'));
        self::assertStringNotContainsString('AI:', $this->digestText());
        self::assertNull($this->service($this->app, AiInsightRepository::class)->find($this->owner($this->app)->id));
    }

    /**
     * Two cars of different cost per distance: *Cheapest to run* (§7.8).
     *
     * @return list<Vehicle>
     */
    private function twoCars(): array
    {
        $cars = [];
        foreach ([['Golf', '100'], ['Polo', '300']] as [$model, $cost]) {
            $vehicle = $this->car($model);
            $this->reading($vehicle, '10000', '2025-12-10');
            $this->reading($vehicle, '11000', '2026-08-10');
            $this->spend($vehicle, '2026-03-05', $cost);
            $cars[] = $vehicle;
        }

        return $cars;
    }

    /**
     * The owner in Auckland at 01:00 on 1 Oct (12:00 UTC on 30 Sep), AI on.
     */
    private function inAuckland(): void
    {
        $this->start();
        $this->car();
        $owner = $this->owner($this->app);
        $prefs = $owner->preferences;
        $this->service($this->app, UserRepository::class)->updateProfile(
            $owner->id,
            $owner->displayName,
            new DisplayPreferences(
                'en_GB',
                'Pacific/Auckland',
                $prefs->distanceUnit,
                $prefs->volumeUnit,
                $prefs->consumptionUnit,
                'GBP',
            ),
            new DateTimeImmutable(self::NOW),
        );
        $this->withAi();
        $this->clock->set(new DateTimeImmutable('2026-09-30T12:00:00Z'));
    }

    /**
     * @param array<string, mixed> $json
     * @return list<array<string, mixed>>
     */
    private function insightsFrom(array $json, string $source): array
    {
        $insights = $json['insights'] ?? null;
        self::assertIsArray($insights);
        $found = [];
        foreach ($insights as $insight) {
            if (is_array($insight) && ($insight['source'] ?? null) === $source) {
                /** @var array<string, mixed> $insight */
                $found[] = $insight;
            }
        }

        return $found;
    }


    private function withAi(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $connection = $this->service($this->app, AiConnectionRepository::class)->insert(new ConnectionSettings(
            name: 'Ollama on the desktop',
            adapter: AdapterType::OpenAiCompatible,
            baseUrl: self::MODEL_URL,
            location: Location::Network,
            headerNames: [],
            timeoutSeconds: Location::Network->defaultTimeout(),
            verifyTls: true,
            caBundle: null,
            maxRequestMb: 8,
            monthlyTokenCap: null,
            enabled: true,
        ), $now);
        $models = $this->service($this->app, AiModelRepository::class);
        $model = $models->add($connection, 'llama3.2:3b', $now);
        $models->setCapabilities($model, [Capability::Tools], $now);
        $this->service($this->app, AiTaskRepository::class)
            ->assign(new AiTaskAssignment(AiTaskName::Ask, $model, null, null), $now);
    }

    /**
     * @param list<AiInsight> $insights
     */
    private function keep(string $day, array $insights): void
    {
        $this->service($this->app, AiInsightRepository::class)->save($this->owner($this->app)->id, new AiInsightSet(
            $day,
            $insights,
            [],
            'Ollama on the desktop',
            Location::Network,
            'llama3.2:3b',
            null,
            new DateTimeImmutable(self::NOW),
        ));
    }
}
