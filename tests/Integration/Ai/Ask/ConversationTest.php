<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask;

use Logbook\Domain\Ai\Ask\AskMessage;
use Logbook\Domain\Ai\Ask\AskRole;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Repository\AiThreadRepository;
use Logbook\Service\Ai\Ask\Conversation;
use Logbook\Service\Ai\Ask\ToolKit;
use Logbook\Tests\Support\AskTestCase;
use Logbook\Tests\Support\ScriptedProvider as Script;

/**
 * The Ask loop with a scripted model (spec.md §7.26 *Loop*).
 */
final class ConversationTest extends AskTestCase
{
    public function testAToolCallThenAnAnswerIsKeptWithItsSource(): void
    {
        [$app, $owner] = $this->askApp();
        $bmw = $this->vehicle($app, 'BMW', '320d', fuel: FuelType::Diesel);
        $this->fillUp($app, $bmw, '2025-03-01T09:00:00Z', '10000', '50', '70.25');
        $this->fillUp($app, $bmw, '2025-06-01T09:00:00Z', '10600', '45', '62.10');
        $this->fillUp($app, $bmw, '2026-01-10T09:00:00Z', '11200', '40', '55.00');
        $this->provider->queue(
            Script::tools(['costs', ['period' => 'last_year', 'category' => 'fuel']]),
            Script::answer('You spent £132.35 on fuel in 2025.'),
        );

        $outcome = $this->service($app, Conversation::class)
            ->ask($owner, null, 'How much did I spend on fuel in 2025?');

        self::assertNull($outcome->answer->error);
        self::assertSame('You spent £132.35 on fuel in 2025.', $outcome->answer->content);
        self::assertSame([], $outcome->answer->ungrounded);
        self::assertSame(Location::Network, $outcome->answer->location);
        self::assertSame('Ollama on the desktop', $outcome->answer->connectionName);
        self::assertCount(1, $outcome->answer->toolRuns);
        $run = $outcome->answer->toolRuns[0];
        self::assertNotNull($run->result);
        self::assertSame('/reports?range=custom&from=2025-01-01&to=2025-12-31&include_archived=1&group=fuel', $run->result->link);
        self::assertSame('Costs · All vehicles · 1 Jan 2025 – 31 Dec 2025 · Fuel · by category', $run->result->source);
        self::assertSame(['£132.35'], $run->result->figures);

        // The model saw the context and the tool's result.
        $system = $this->sent(0)[0]['content'];
        self::assertStringContainsString('"today":"2026-10-15"', $system);
        self::assertStringContainsString('"name":"BMW 320d"', $system);
        self::assertStringContainsString('"display":"£132.35"', $this->toolReplies(1)[0]);

        $messages = $this->service($app, AiThreadRepository::class)->messages($outcome->thread);
        $roles = array_map(static fn (AskMessage $m): AskRole => $m->role, $messages);
        self::assertSame([AskRole::User, AskRole::Assistant], $roles);
        self::assertSame('£132.35', $messages[1]->toolRuns[0]->result?->figures[0]);
    }

    public function testAnInventedFigureIsFlagged(): void
    {
        [$app, $owner] = $this->askApp();
        $bmw = $this->vehicle($app, 'BMW', '320d', fuel: FuelType::Diesel);
        $this->fillUp($app, $bmw, '2025-03-01T09:00:00Z', '10000', '50', '70.25');
        $this->provider->queue(
            Script::tools(['costs', ['period' => 'last_year']]),
            Script::answer('About £1,300 on your 320d in 2025, £70.25 of it on fuel.'),
        );

        $outcome = $this->service($app, Conversation::class)->ask($owner, null, 'What did the BMW cost in 2025?');

        self::assertSame(['1,300'], $outcome->answer->ungrounded);
    }

    public function testMoreThanEightToolCallsAreToldToAnswer(): void
    {
        [$app, $owner] = $this->askApp();
        $this->vehicle($app, 'BMW', '320d');
        $calls = array_fill(0, 5, ['find_vehicles', ['query' => 'BMW']]);
        $this->provider->queue(
            Script::tools(...$calls),
            Script::tools(...$calls),
            Script::answer('You have one BMW.'),
        );

        $outcome = $this->service($app, Conversation::class)->ask($owner, null, 'Which BMWs do I have?');

        self::assertCount(Conversation::MAX_TOOL_CALLS, $outcome->answer->toolRuns);
        $replies = $this->toolReplies(2);
        self::assertCount(10, $replies);
        self::assertStringContainsString(Conversation::ENOUGH, $replies[9]);
        self::assertSame('You have one BMW.', $outcome->answer->content);
    }

    public function testToolErrorsGoBackToTheModelAsPlainMessages(): void
    {
        [$app, $owner] = $this->askApp();
        $this->provider->queue(
            Script::tools(['maintenance', ['vehicle' => 999]], ['no_such_tool', []]),
            Script::answer('I could not find that vehicle.'),
        );

        $outcome = $this->service($app, Conversation::class)->ask($owner, null, 'When was the oil changed?');

        $replies = $this->toolReplies(1);
        self::assertStringContainsString('No vehicle with that id', $replies[0]);
        self::assertStringContainsString('There is no tool called', $replies[1]);
        self::assertSame(ToolKit::NOT_FOUND, $outcome->answer->toolRuns[0]->error);
    }

    public function testAProviderFailureIsKeptWithTheToolCallsSoFar(): void
    {
        [$app, $owner] = $this->askApp();
        $this->vehicle($app, 'BMW', '320d');
        $this->provider->queue(Script::tools(['find_vehicles', ['query' => 'BMW']]), 'openai/error_rate_limit');

        $outcome = $this->service($app, Conversation::class)->ask($owner, null, 'Which BMWs do I have?');

        self::assertSame(ErrorCode::RateLimited, $outcome->answer->error);
        self::assertSame('', $outcome->answer->content);
        self::assertSame('/garage', $outcome->answer->firstLink());
        self::assertCount(2, $this->provider->requests, 'nothing is retried');
    }
}
