<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Insights;

use Logbook\Domain\Vehicle\FuelType;
use Logbook\Repository\AiInsightRepository;
use Logbook\Service\Ai\Insights\AiInsightsJob;
use Logbook\Service\Jobs\JobContext;
use Logbook\Tests\Support\AskTestCase;
use Logbook\Tests\Support\ScriptedProvider as Script;
use Psr\Log\NullLogger;

/**
 * AI insights (spec.md §7.26 *AI insights*, Phase 33.4): nothing without
 * AI; the page's GET never calls a model; *Refresh* (or the first view's
 * background post) makes the day's set from the read tools, grounding-
 * checked and cached for the day; the dashboard reads the cache; the job.
 */
final class AiInsightsTest extends AskTestCase
{
    private const string ANSWER = '{"insights": [{"title": "Fuel came to £70.25 in 2025",'
        . ' "body": "That is about £999 a year more than you think.", "sources": ["costs"]}]}';

    public function testNothingIsMadeOrShownWithAiOff(): void
    {
        $app = $this->aiApp();
        $this->pinClock($app, self::NOW);
        $browser = $this->signedIn($app);
        $this->vehicle($app);

        $page = (string) $browser->get('/insights')->getBody();
        self::assertStringNotContainsString('data-ai-insights', $page);
        self::assertSame(404, $browser->post('/insights/refresh', [])->getStatusCode());
        $result = $this->job($app)->run(new JobContext(new NullLogger(), static fn (): bool => false));
        self::assertSame(['made' => 0, 'left' => 0, 'failed' => 0], $result->counts);
        self::assertNull($this->service($app, AiInsightRepository::class)->find($this->owner($app)->id));
        self::assertSame([], $this->provider->requests, 'no model call at all');
    }

    public function testRefreshMakesTheDaysSetFromTheReadToolsAndTheDayServesTheCache(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $bmw = $this->vehicle($app, 'BMW', '320d', fuel: FuelType::Diesel);
        $this->fillUp($app, $bmw, '2025-03-01T09:00:00Z', '10000', '50', '70.25');

        $first = (string) $browser->get('/insights')->getBody();
        self::assertStringContainsString('data-ai-insights-auto', $first, 'the first view asks in the background');
        self::assertStringContainsString('Today’s AI insights haven’t been made yet.', $first);
        self::assertSame([], $this->provider->requests, 'the GET never calls a model');

        $this->provider->queue(
            Script::tools(['costs', ['period' => 'last_year', 'category' => 'fuel']]),
            Script::answer("```json\n" . self::ANSWER . "\n```"),
        );
        $posted = $browser->post('/insights/refresh', [], headers: ['X-Insights' => '1']);
        self::assertSame(['saved' => true, 'error' => null], self::decoded($posted));
        self::assertCount(2, $this->provider->requests);
        $tools = $this->provider->requests[0]['json']['tools'] ?? [];
        self::assertIsArray($tools);
        /** @var list<string> $offered */
        $offered = array_column(array_column($tools, 'function'), 'name');
        self::assertContains('costs', $offered);
        $drafts = array_filter($offered, static fn (string $n): bool => str_starts_with($n, 'draft_'));
        self::assertSame([], $drafts, 'never a draft tool');
        $messages = $this->provider->requests[0]['json']['messages'] ?? [];
        self::assertIsArray($messages);
        $system = (string) json_encode($messages[0] ?? null, JSON_UNESCAPED_UNICODE);
        $rule = 'Never write about what may be causing an issue';
        self::assertStringContainsString($rule, $system, 'counts, never a cause (§7.37)');

        $page = (string) $browser->get('/insights')->getBody();
        self::assertStringNotContainsString('data-ai-insights-auto', $page);
        self::assertStringContainsString('Fuel came to £70.25 in 2025', $page);
        self::assertStringContainsString('<mark class="ask-unverified"', $page, '£999 did not come from Logbook');
        self::assertMatchesRegularExpression('/£<mark class="ask-unverified"[^>]*>999<\/mark>/', $page);
        self::assertStringNotContainsString('>70.25</mark>', $page, 'the tool’s own figure is not marked');
        self::assertStringContainsString('Costs · All vehicles · 1 Jan 2025 – 31 Dec 2025', $page, 'its source');
        self::assertStringContainsString('<span class="pill insight-card__ai">AI</span>', $page);
        self::assertStringContainsString('From llama3.2:3b on Ollama on the desktop', $page);

        $home = (string) $browser->get('/')->getBody();
        self::assertStringContainsString('data-ai-insight', $home, 'the widget reads the cache');
        $browser->get('/insights');
        self::assertCount(2, $this->provider->requests, 'the day is served from the cache');
    }

    public function testAnAnswerThatIsNotTheShapeIsKeptAsTheDaysFailure(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->vehicle($app, 'BMW', '320d');
        $this->provider->queue(Script::answer('Your BMW is doing fine, about £50 a month.'));

        $posted = $browser->post('/insights/refresh', []);
        self::assertSame('/insights#ai-insights', $posted->getHeaderLine('Location'));

        $page = (string) $browser->get('/insights')->getBody();
        self::assertStringContainsString('Couldn’t get AI insights today.', $page);
        self::assertDoesNotMatchRegularExpression('/\{(connection|model|seconds|variable)\}/', $page, 'the reason is filled in');
        self::assertStringNotContainsString('about £50 a month', $page, 'nothing half-read is shown');
        self::assertStringContainsString('>Refresh<', $page);
    }

    public function testADraftToolCallIsRefusedAndNothingIsDrafted(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->vehicle($app, 'BMW', '320d');
        $this->provider->queue(
            Script::tools(['draft_fill_up', ['vehicle' => 1, 'litres' => 40, 'total' => 60]]),
            Script::answer('{"insights": []}'),
        );

        $browser->post('/insights/refresh', []);

        self::assertStringContainsString('There is no tool called', implode("\n", $this->toolReplies(1)));
        $drafts = $this->service($app, \Doctrine\DBAL\Connection::class)->fetchOne('SELECT COUNT(*) FROM ai_drafts');
        self::assertSame(0, is_numeric($drafts) ? (int) $drafts : -1, 'no draft');
        self::assertStringContainsString('Nothing new stood out today.', (string) $browser->get('/insights')->getBody());
    }

    public function testTheJobMakesTheDayOnceForSomeoneSignedInRecently(): void
    {
        [$app] = $this->askApp();
        $this->browserFor($app, 'owner');
        $this->vehicle($app, 'BMW', '320d');
        $this->provider->queue(Script::answer('{"insights": []}'));

        $context = new JobContext(new NullLogger(), static fn (): bool => false);
        self::assertSame(['made' => 1, 'left' => 0, 'failed' => 0], $this->job($app)->run($context)->counts);
        self::assertSame(['made' => 0, 'left' => 0, 'failed' => 0], $this->job($app)->run($context)->counts, 'once a day');
        self::assertCount(1, $this->provider->requests);
    }

    /**
     * @param \Slim\App<\Psr\Container\ContainerInterface> $app
     */
    private function job(\Slim\App $app): AiInsightsJob
    {
        return $this->service($app, AiInsightsJob::class);
    }
}
