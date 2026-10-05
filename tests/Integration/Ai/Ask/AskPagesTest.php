<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Ask;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Vehicle\FuelType;
use Logbook\Repository\AiBusyRepository;
use Logbook\Service\Ai\AiHousekeeping;
use Logbook\Service\Ai\AiPreferences;
use Logbook\Service\Sharing\SharingService;
use Logbook\Tests\Support\AskTestCase;
use Logbook\Tests\Support\ScriptedProvider as Script;

/**
 * The Ask pages (spec.md §7.26 *Where*, *Works without JS*, *Answer page*,
 * *Conversations*).
 */
final class AskPagesTest extends AskTestCase
{
    public function testNothingShowsUntilAskIsSetUp(): void
    {
        $app = $this->aiApp();
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $browser = $this->signedIn($app);
        $this->vehicle($app);

        self::assertSame(404, $browser->get('/ask')->getStatusCode());
        self::assertStringNotContainsString('data-ask-entry', (string) $browser->get('/')->getBody());
        self::assertStringNotContainsString('/ask', (string) $browser->get('/manifest.webmanifest')->getBody());
        self::assertSame([], $this->provider->requests);
    }

    public function testTheFormAnswersWithoutJavaScript(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $bmw = $this->vehicle($app, 'BMW', '320d', fuel: FuelType::Diesel);
        $this->fillUp($app, $bmw, '2025-03-01T09:00:00Z', '10000', '50', '70.25');

        $home = (string) $browser->get('/')->getBody();
        self::assertStringContainsString('data-ask-entry', $home);
        // Settings below Ask in the sidebar (spec.md §8 *Sidebar order*, Phase 33.2).
        $sidebar = substr($home, (int) strpos($home, '<aside class="sidebar">'));
        self::assertLessThan(strpos($sidebar, 'href="/settings"'), strpos($sidebar, 'data-ask-entry'));
        self::assertStringContainsString('"url": "/ask"', (string) $browser->get('/manifest.webmanifest')->getBody());
        $page = (string) $browser->get('/ask')->getBody();
        self::assertStringContainsString('Answered by Ollama on the desktop on your network. Nothing leaves it.', $page);

        $this->provider->queue(
            Script::tools(['costs', ['period' => 'last_year', 'category' => 'fuel']]),
            Script::answer('You spent £70.25 on fuel in 2025, about £75 a month.'),
        );
        $posted = $browser->post('/ask', ['question' => 'How much did I spend on fuel in 2025?']);
        self::assertSame(303, $posted->getStatusCode());
        self::assertMatchesRegularExpression('#^/ask/threads/\d+\#answer-\d+$#', $posted->getHeaderLine('Location'));

        $answer = (string) $browser->follow($posted)->getBody();
        self::assertStringContainsString('How much did I spend on fuel in 2025?', $answer);
        self::assertStringContainsString('You spent £70.25 on fuel in 2025', $answer);
        self::assertStringContainsString(
            'href="/reports?range=custom&amp;from=2025-01-01&amp;to=2025-12-31&amp;include_archived=1&amp;group=fuel"',
            $answer,
        );
        self::assertStringContainsString('Costs · All vehicles · 1 Jan 2025 – 31 Dec 2025 · Fuel · by category', $answer);
        self::assertStringContainsString('<mark class="ask-unverified"', $answer, '£75 was not from Logbook');
        self::assertStringContainsString('data-ask-grounding', $answer);
        self::assertStringContainsString('Answered by llama3.2:3b on Ollama on the desktop', $answer);
        $list = (string) $browser->get('/ask')->getBody();
        self::assertStringContainsString('How much did I spend on fuel in 2025?', $list, 'listed');
    }

    public function testTheInsightsPageHasTheAskBoxAndAskKeepsItsPlace(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->vehicle($app, 'BMW', '320d');

        $insights = (string) $browser->get('/insights')->getBody();
        self::assertStringContainsString('<h2 class="ask-card__title" id="ask-card-title">Ask Logbook</h2>', $insights);
        self::assertStringContainsString('AI answers using only your logged data', $insights);
        self::assertStringContainsString('action="/ask" data-ask-form', $insights, 'opens the thread on Ask (#193)');
        self::assertStringContainsString('placeholder="e.g. Why has my fuel spend gone up?"', $insights);
        $suggestions = ['Which vehicle costs me most per mile?', 'Summarise my last 12 months', 'How could I cut my fuel costs?'];
        foreach ($suggestions as $q) {
            self::assertStringContainsString('href="/ask?q=' . urlencode($q) . '"', $insights, $q);
        }
        $sidebar = substr($insights, (int) strpos($insights, '<aside class="sidebar">'));
        self::assertStringContainsString('data-ask-entry', $sidebar, 'Ask keeps its own entry (#192)');
        self::assertLessThan(strpos($sidebar, 'data-ask-entry'), strpos($sidebar, 'href="/insights"'), 'Insights, then Ask');

        $this->provider->queue(Script::tools(['find_vehicles', ['query' => 'BMW']]), Script::answer('One BMW.'));
        $posted = $browser->post('/ask', ['question' => 'Which BMWs?']);
        self::assertMatchesRegularExpression('#^/ask/threads/\d+\#answer-\d+$#', $posted->getHeaderLine('Location'));

        $ask = (string) $browser->get('/ask')->getBody();
        self::assertStringContainsString('<h1 class="ask-card__title" id="ask-card-title">Ask Logbook</h1>', $ask);
        self::assertStringContainsString('data-ask-enter', $ask);
        self::assertStringContainsString('data-busy-label="Thinking…"', $ask);
    }

    public function testInTheBackgroundTheReplyIsJsonAndProgressCanBePolled(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->vehicle($app, 'BMW', '320d');
        $token = str_repeat('ab', 16);
        $this->provider->queue(Script::tools(['find_vehicles', ['query' => 'BMW']]), Script::answer('One BMW.'));

        $before = self::decoded($browser->get('/ask/progress/' . $token));
        self::assertSame(['done' => false, 'url' => null, 'line' => 'Thinking…', 'lines' => []], $before);

        $posted = $browser->post('/ask', ['question' => 'Which BMWs?', 'progress' => $token], headers: ['X-Ask' => '1']);
        self::assertSame(200, $posted->getStatusCode());
        $body = self::decoded($posted);
        self::assertIsString($body['url'] ?? null);
        self::assertMatchesRegularExpression('#^/ask/threads/\d+\#answer-\d+$#', $body['url']);

        $after = self::decoded($browser->get('/ask/progress/' . $token));
        self::assertSame('/ask/threads/' . self::threadIn($body['url']), $after['url'] ?? null, 'the poll finds the answer too');
        self::assertSame(true, $after['done'] ?? null);
        self::assertSame(['Finding your vehicles…'], $after['lines'] ?? null);

        $this->createMember($app, 'partner');
        $other = $this->browserFor($app, 'partner');
        $theirs = self::decoded($other->get('/ask/progress/' . $token));
        $nothing = ['done' => false, 'url' => null, 'line' => 'Thinking…', 'lines' => []];
        self::assertSame($nothing, $theirs, 'another user sees nothing of it');
    }

    public function testASecondQuestionWhileOneRunsIsRefused(): void
    {
        [$app, $owner] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $now = new DateTimeImmutable(self::NOW);
        $this->service($app, AiBusyRepository::class)->acquire($owner->id, $now, $now->modify('+10 minutes'));

        $posted = $browser->post('/ask', ['question' => 'Anything?']);
        self::assertSame(422, $posted->getStatusCode());
        self::assertStringContainsString('Still working on your last question.', (string) $posted->getBody());
        self::assertStringContainsString('>Anything?</textarea>', (string) $posted->getBody(), 'the question is kept');

        $json = $browser->post('/ask', ['question' => 'Anything?'], headers: ['X-Ask' => '1']);
        self::assertSame(['error' => 'Still working on your last question.'], self::decoded($json));
        self::assertSame([], $this->provider->requests);
        self::assertSame(0, $this->rows($app, 'ai_threads'));
    }

    public function testARefusedBackgroundPostLeavesTheFormsTokenUsable(): void
    {
        [$app, $owner] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $fields = ['question' => 'Anything?'] + $browser->csrfFields();
        $now = new DateTimeImmutable(self::NOW);
        $busy = $this->service($app, AiBusyRepository::class);
        $busy->acquire($owner->id, $now, $now->modify('+10 minutes'));
        self::assertSame(422, $browser->post('/ask', $fields, withCsrf: false, headers: ['X-Ask' => '1'])->getStatusCode());

        $busy->release($owner->id);
        $this->provider->queue(Script::answer('Hello.'));
        $again = $browser->post('/ask', $fields, withCsrf: false, headers: ['X-Ask' => '1']);
        self::assertSame(200, $again->getStatusCode(), 'the same token works for the retry');
    }

    public function testWorksBehindASubpath(): void
    {
        [$app] = $this->askApp(['APP_BASE_PATH' => '/logbook']);
        $browser = $this->browserFor($app, 'owner');
        $bmw = $this->vehicle($app, 'BMW', '320d');
        $this->expense($app, $bmw, '2026-09-01', '12.00');

        $page = (string) $browser->get('/ask')->getBody();
        self::assertStringContainsString('action="/logbook/ask"', $page);
        self::assertMatchesRegularExpression('#data-progress-url="/logbook/ask/progress/[0-9a-f]{32}"#', $page);

        $this->provider->queue(Script::tools(['costs', ['period' => 'this_year']]), Script::answer('£12.00 this year.'));
        $posted = $browser->post('/logbook/ask', ['question' => 'Costs this year?']);
        self::assertStringStartsWith('/logbook/ask/threads/', $posted->getHeaderLine('Location'));
        $answer = (string) $browser->follow($posted)->getBody();
        self::assertStringContainsString('href="/logbook/reports?range=ytd&amp;include_archived=1"', $answer);

        $this->provider->queue(Script::answer('Again.'));
        $json = self::decoded($browser->post('/logbook/ask', ['question' => 'Again?'], headers: ['X-Ask' => '1']));
        self::assertStringStartsWith('/logbook/ask/threads/', is_string($json['url'] ?? null) ? $json['url'] : '');
    }

    public function testAnEmptyQuestionIsRejected(): void
    {
        [$app] = $this->askApp();
        $posted = $this->browserFor($app, 'owner')->post('/ask', ['question' => '   ']);

        self::assertSame(422, $posted->getStatusCode());
        self::assertStringContainsString('Type a question.', (string) $posted->getBody());
    }

    public function testAFollowUpCarriesTheEarlierTurnAndItsResults(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $bmw = $this->vehicle($app, 'BMW', '320d', fuel: FuelType::Diesel);
        $this->fillUp($app, $bmw, '2025-03-01T09:00:00Z', '10000', '50', '70.25');
        $this->provider->queue(
            Script::tools(['costs', ['period' => 'this_year', 'category' => 'fuel']]),
            Script::answer('Nothing on fuel this year.'),
            Script::tools(['costs', ['period' => 'last_year', 'category' => 'fuel']]),
            Script::answer('£70.25 last year.'),
        );

        $first = $browser->post('/ask', ['question' => 'Fuel spend this year?']);
        $m = [1 => self::threadIn($first->getHeaderLine('Location'))];
        $second = $browser->post('/ask', ['question' => 'And last year?', 'thread' => $m[1]]);
        self::assertSame(303, $second->getStatusCode());

        $sent = $this->sent(2);
        self::assertSame(['system', 'user', 'assistant', 'user'], array_column($sent, 'role'));
        self::assertSame('Fuel spend this year?', $sent[1]['content']);
        self::assertSame('Nothing on fuel this year.', $sent[2]['content']);
        self::assertStringContainsString('Results of tools called earlier', $sent[0]['content']);

        $page = (string) $browser->follow($second)->getBody();
        self::assertStringContainsString('Fuel spend this year?', $page);
        self::assertStringContainsString('£70.25 last year.', $page);
    }

    public function testAFollowUpDropsWhatCameFromAVehicleNoLongerShared(): void
    {
        [$app, $owner] = $this->askApp();
        $golf = $this->vehicle($app, 'Volkswagen', 'Golf');
        $this->createMember($app, 'partner');
        self::assertNull($this->service($app, SharingService::class)->add($golf, 'partner', ShareLevel::Manage, true, false));
        $partner = $this->browserFor($app, 'partner');
        $this->provider->queue(
            Script::tools(['find_vehicles', ['query' => 'Golf']]),
            Script::answer('You can see the Golf.'),
            Script::answer('I have no vehicle for you.'),
        );

        $first = $partner->post('/ask', ['question' => 'Which Golf?']);
        $m = [1 => self::threadIn($first->getHeaderLine('Location'))];
        $this->service($app, SharingService::class)->remove($golf, $this->userIdOf($app, 'partner'));
        $partner->post('/ask', ['question' => 'And now?', 'thread' => $m[1]]);

        $sent = $this->sent(2);
        self::assertSame(['system', 'user'], array_column($sent, 'role'));
        self::assertStringNotContainsString('Golf', (string) json_encode($sent));
        unset($owner);
    }

    public function testThreadsAreTheirOwnersAndCanBeDeleted(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->provider->queue(Script::answer('Hello.'), Script::answer('Again.'));
        $first = $browser->post('/ask', ['question' => 'First?']);
        $browser->post('/ask', ['question' => 'Second?']);
        $m = [1 => self::threadIn($first->getHeaderLine('Location'))];

        $this->createMember($app, 'partner');
        $other = $this->browserFor($app, 'partner');
        self::assertSame(404, $other->get('/ask/threads/' . $m[1])->getStatusCode());
        self::assertSame(404, $other->post('/ask/threads/' . $m[1] . '/delete')->getStatusCode());
        self::assertStringNotContainsString('First?', (string) $other->get('/ask')->getBody());

        self::assertSame(303, $browser->post('/ask/threads/' . $m[1] . '/delete')->getStatusCode());
        self::assertSame(404, $browser->get('/ask/threads/' . $m[1])->getStatusCode());
        self::assertSame(1, $this->rows($app, 'ai_threads'));
        $browser->post('/ask/threads/delete');
        self::assertSame(0, $this->rows($app, 'ai_threads'));
        self::assertSame(0, $this->rows($app, 'ai_messages'));
    }

    public function testFeedbackMarksTheAnswerAndCountsOutliveTheThread(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->provider->queue(Script::answer('Hello.'));
        $posted = $browser->post('/ask', ['question' => 'Hi?']);
        $m = [1 => self::answerIn($posted->getHeaderLine('Location'))];

        $browser->post('/ask/messages/' . $m[1] . '/feedback', ['mark' => 'helpful']);
        $browser->post('/ask/messages/' . $m[1] . '/feedback', ['mark' => 'not_right']);
        $stored = $this->connection($app)->fetchOne('SELECT feedback FROM ai_messages WHERE id = ?', [$m[1]]);
        self::assertSame('not_right', $stored);
        self::assertSame(
            ['helpful' => 0, 'not_right' => 1],
            $this->feedbackCounts($app),
        );

        $this->createMember($app, 'partner');
        $partner = $this->browserFor($app, 'partner');
        self::assertSame(404, $partner->post('/ask/messages/' . $m[1] . '/feedback', ['mark' => 'helpful'])->getStatusCode());

        $browser->post('/ask/threads/delete');
        self::assertSame(['helpful' => 0, 'not_right' => 1], $this->feedbackCounts($app));
    }

    public function testThreadsGoAfterTheUsersRetention(): void
    {
        [$app, $owner] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->provider->queue(Script::answer('Hello.'));
        $browser->post('/ask', ['question' => 'Hi?']);
        $browser->post('/ask/retention', ['days' => '7']);
        self::assertSame(7, $this->service($app, AiPreferences::class)->retentionDays($owner->id));

        $clock = $this->pinClock($app, '2026-10-21T12:00:00Z');
        $this->service($app, AiHousekeeping::class)->run();
        self::assertSame(1, $this->rows($app, 'ai_threads'), 'six days on: kept');

        $clock->set(new DateTimeImmutable('2026-10-22T12:00:01Z'));
        $this->service($app, AiHousekeeping::class)->run();
        self::assertSame(0, $this->rows($app, 'ai_threads'));
        self::assertSame(0, $this->rows($app, 'ai_progress'), 'progress goes after an hour');
    }

    public function testThePagesAnswer404WhenAskIsOffForTheUser(): void
    {
        [$app, $owner] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->service($app, AiPreferences::class)->set($owner->id, false);
        self::assertSame(404, $browser->get('/ask')->getStatusCode());
        self::assertSame(404, $browser->post('/ask', ['question' => 'Hi?'])->getStatusCode());
        self::assertStringNotContainsString('data-ask-entry', (string) $browser->get('/')->getBody());

        [$app] = $this->askApp(['FEATURES_AI_ASK' => 'false']);
        self::assertSame(404, $this->browserFor($app, 'owner')->get('/ask')->getStatusCode());

        [$app] = $this->askApp(['AI_ENABLED' => 'false']);
        self::assertSame(404, $this->browserFor($app, 'owner')->get('/ask')->getStatusCode());
        self::assertSame([], $this->provider->requests);
    }

    /**
     * @param \Slim\App<\Psr\Container\ContainerInterface> $app
     * @return array{helpful: int, not_right: int}
     */
    private function feedbackCounts(\Slim\App $app): array
    {
        $counts = ['helpful' => 0, 'not_right' => 0];
        foreach ($this->connection($app)->fetchAllAssociative('SELECT mark, total FROM ai_feedback') as $row) {
            $mark = $row['mark'] === 'helpful' ? 'helpful' : 'not_right';
            $counts[$mark] = is_numeric($row['total']) ? (int) $row['total'] : 0;
        }

        return $counts;
    }

    /**
     * @param \Slim\App<\Psr\Container\ContainerInterface> $app
     */
    private function userIdOf(\Slim\App $app, string $username): int
    {
        $id = $this->connection($app)->fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
        self::assertTrue(is_numeric($id));

        return (int) $id;
    }
}
