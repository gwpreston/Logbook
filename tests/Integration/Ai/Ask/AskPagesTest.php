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
 * Ask on the Insights page and the thread pages (spec.md §7.26 *Where*,
 * *Works without JS*, *Answer page*, *Conversations*, *Ask and the Insights
 * page*; Phase 38), and the Ask page's old addresses.
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

        self::assertSame(404, $browser->get('/insights/questions/1')->getStatusCode());
        self::assertStringNotContainsString('data-ask-entry', (string) $browser->get('/')->getBody());
        self::assertStringNotContainsString('#ask', (string) $browser->get('/manifest.webmanifest')->getBody());
        $insights = (string) $browser->get('/insights')->getBody();
        self::assertStringNotContainsString('data-insights-ask', $insights);
        self::assertStringNotContainsString('id="your-questions"', $insights);
        self::assertSame([], $this->provider->requests);
    }

    public function testTheFormAnswersWithoutJavaScript(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $bmw = $this->vehicle($app, 'BMW', '320d', fuel: FuelType::Diesel);
        $this->fillUp($app, $bmw, '2025-03-01T09:00:00Z', '10000', '50', '70.25');

        $home = (string) $browser->get('/')->getBody();
        // Phase 38: no Ask in the sidebar; the top-bar button and the dashboard link open the box on Insights (#273).
        $sidebar = substr($home, (int) strpos($home, '<aside class="sidebar">'));
        $sidebar = substr($sidebar, 0, (int) strpos($sidebar, '</aside>'));
        self::assertStringNotContainsString('data-ask-entry', $sidebar);
        self::assertStringNotContainsString('forum', $sidebar);
        self::assertSame(2, substr_count($home, 'href="/insights#ask" data-ask-entry'), 'top bar and dashboard');
        self::assertStringContainsString('"url": "/insights#ask"', (string) $browser->get('/manifest.webmanifest')->getBody());

        $this->provider->queue(
            Script::tools(['costs', ['period' => 'last_year', 'category' => 'fuel']]),
            Script::answer('You spent £70.25 on fuel in 2025, about £75 a month.'),
        );
        $posted = $browser->post('/insights/questions', ['question' => 'How much did I spend on fuel in 2025?']);
        self::assertSame(303, $posted->getStatusCode());
        self::assertMatchesRegularExpression('#^/insights/questions/\d+\#answer-\d+$#', $posted->getHeaderLine('Location'));

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
        self::assertStringContainsString('Answered by Ollama on the desktop on your network. Nothing leaves it.', $answer);
        $list = (string) $browser->get('/insights')->getBody();
        self::assertStringContainsString('How much did I spend on fuel in 2025?', $list, 'listed under Your questions');
    }

    public function testTheInsightsPageHasTheAskBoxAndAThreadOpensOnItsOwnPage(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->vehicle($app, 'BMW', '320d');

        $insights = (string) $browser->get('/insights')->getBody();
        self::assertStringContainsString('<h2 class="ask-card__title" id="ask-card-title">Ask Logbook</h2>', $insights);
        self::assertStringContainsString('AI answers using only your logged data', $insights);
        self::assertStringContainsString('id="ask"', $insights, 'the top bar\'s #ask lands here');
        self::assertStringContainsString('action="/insights/questions" data-ask-form', $insights);
        self::assertStringContainsString('placeholder="e.g. Why has my fuel spend gone up?"', $insights);
        $suggestions = ['Which vehicle costs me most per mile?', 'Summarise my last 12 months', 'How could I cut my fuel costs?'];
        foreach ($suggestions as $q) {
            self::assertStringContainsString('href="/insights?q=' . urlencode($q) . '#ask"', $insights, $q);
        }
        $filled = (string) $browser->get('/insights?q=Summarise+my+last+12+months')->getBody();
        self::assertStringContainsString('>Summarise my last 12 months</textarea>', $filled);
        self::assertStringContainsString('Nothing asked yet.', $insights);
        self::assertStringContainsString('action="/insights/questions/retention"', $insights, 'retention with no threads (#275)');

        $this->provider->queue(Script::tools(['find_vehicles', ['query' => 'BMW']]), Script::answer('One BMW.'));
        $posted = $browser->post('/insights/questions', ['question' => 'Which BMWs?']);
        self::assertMatchesRegularExpression('#^/insights/questions/\d+\#answer-\d+$#', $posted->getHeaderLine('Location'));

        $ask = (string) $browser->follow($posted)->getBody();
        self::assertStringContainsString('<h1 class="ask-card__title" id="ask-card-title">Ask Logbook</h1>', $ask);
        self::assertStringContainsString('<title>Which BMWs? · Insights', $ask);
        self::assertStringContainsString('class="back-link" href="/insights#your-questions"', $ask);
        $thread = self::threadIn($posted->getHeaderLine('Location'));
        self::assertStringContainsString('name="thread" value="' . $thread . '"', $ask);
        self::assertStringContainsString('data-ask-enter', $ask);
        self::assertStringContainsString('data-busy-label="Thinking…"', $ask);
        self::assertStringContainsString('<a class="nav-link" href="/insights" aria-current="page">', $ask, 'under Insights');
    }

    public function testInTheBackgroundTheReplyIsJsonAndProgressCanBePolled(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->vehicle($app, 'BMW', '320d');
        $token = str_repeat('ab', 16);
        $this->provider->queue(Script::tools(['find_vehicles', ['query' => 'BMW']]), Script::answer('One BMW.'));

        $before = self::decoded($browser->get('/insights/questions/progress/' . $token));
        self::assertSame(['done' => false, 'url' => null, 'line' => 'Thinking…', 'lines' => []], $before);

        $fields = ['question' => 'Which BMWs?', 'progress' => $token];
        $posted = $browser->post('/insights/questions', $fields, headers: ['X-Ask' => '1']);
        self::assertSame(200, $posted->getStatusCode());
        $body = self::decoded($posted);
        self::assertIsString($body['url'] ?? null);
        self::assertMatchesRegularExpression('#^/insights/questions/\d+\#answer-\d+$#', $body['url']);

        $after = self::decoded($browser->get('/insights/questions/progress/' . $token));
        $thread = '/insights/questions/' . self::threadIn($body['url']);
        self::assertSame($thread, $after['url'] ?? null, 'the poll finds the answer too');
        self::assertSame(true, $after['done'] ?? null);
        self::assertSame(['Finding your vehicles…'], $after['lines'] ?? null);

        $this->createMember($app, 'partner');
        $other = $this->browserFor($app, 'partner');
        $theirs = self::decoded($other->get('/insights/questions/progress/' . $token));
        $nothing = ['done' => false, 'url' => null, 'line' => 'Thinking…', 'lines' => []];
        self::assertSame($nothing, $theirs, 'another user sees nothing of it');
    }

    public function testASecondQuestionWhileOneRunsIsRefused(): void
    {
        [$app, $owner] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $now = new DateTimeImmutable(self::NOW);
        $this->service($app, AiBusyRepository::class)->acquire($owner->id, $now, $now->modify('+10 minutes'));

        $posted = $browser->post('/insights/questions', ['question' => 'Anything?']);
        self::assertSame(422, $posted->getStatusCode());
        self::assertStringContainsString('Still working on your last question.', (string) $posted->getBody());
        self::assertStringContainsString('>Anything?</textarea>', (string) $posted->getBody(), 'the question is kept');

        $json = $browser->post('/insights/questions', ['question' => 'Anything?'], headers: ['X-Ask' => '1']);
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
        $refused = $browser->post('/insights/questions', $fields, withCsrf: false, headers: ['X-Ask' => '1']);
        self::assertSame(422, $refused->getStatusCode());

        $busy->release($owner->id);
        $this->provider->queue(Script::answer('Hello.'));
        $again = $browser->post('/insights/questions', $fields, withCsrf: false, headers: ['X-Ask' => '1']);
        self::assertSame(200, $again->getStatusCode(), 'the same token works for the retry');
    }

    public function testWorksBehindASubpath(): void
    {
        [$app] = $this->askApp(['APP_BASE_PATH' => '/logbook']);
        $browser = $this->browserFor($app, 'owner');
        $bmw = $this->vehicle($app, 'BMW', '320d');
        $this->expense($app, $bmw, '2026-09-01', '12.00');

        $page = (string) $browser->get('/logbook/insights')->getBody();
        self::assertStringContainsString('action="/logbook/insights/questions"', $page);
        self::assertStringContainsString('href="/logbook/insights#ask" data-ask-entry', $page);
        self::assertMatchesRegularExpression('#data-progress-url="/logbook/insights/questions/progress/[0-9a-f]{32}"#', $page);

        $this->provider->queue(Script::tools(['costs', ['period' => 'this_year']]), Script::answer('£12.00 this year.'));
        $posted = $browser->post('/logbook/insights/questions', ['question' => 'Costs this year?']);
        self::assertStringStartsWith('/logbook/insights/questions/', $posted->getHeaderLine('Location'));
        $answer = (string) $browser->follow($posted)->getBody();
        self::assertStringContainsString('href="/logbook/reports?range=ytd&amp;include_archived=1"', $answer);

        $this->provider->queue(Script::answer('Again.'));
        $json = self::decoded($browser->post('/logbook/insights/questions', ['question' => 'Again?'], headers: ['X-Ask' => '1']));
        self::assertStringStartsWith('/logbook/insights/questions/', is_string($json['url'] ?? null) ? $json['url'] : '');

        $thread = self::threadIn($posted->getHeaderLine('Location'));
        $moved = $browser->get('/logbook/ask/threads/' . $thread);
        self::assertSame(301, $moved->getStatusCode());
        self::assertSame('/logbook/insights/questions/' . $thread, $moved->getHeaderLine('Location'));
    }

    public function testAnEmptyQuestionIsRejected(): void
    {
        [$app] = $this->askApp();
        $posted = $this->browserFor($app, 'owner')->post('/insights/questions', ['question' => '   ']);

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

        $first = $browser->post('/insights/questions', ['question' => 'Fuel spend this year?']);
        $m = [1 => self::threadIn($first->getHeaderLine('Location'))];
        $second = $browser->post('/insights/questions', ['question' => 'And last year?', 'thread' => $m[1]]);
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

        $first = $partner->post('/insights/questions', ['question' => 'Which Golf?']);
        $m = [1 => self::threadIn($first->getHeaderLine('Location'))];
        $this->service($app, SharingService::class)->remove($golf, $this->userIdOf($app, 'partner'));
        $partner->post('/insights/questions', ['question' => 'And now?', 'thread' => $m[1]]);

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
        $first = $browser->post('/insights/questions', ['question' => 'First?']);
        $browser->post('/insights/questions', ['question' => 'Second?']);
        $m = [1 => self::threadIn($first->getHeaderLine('Location'))];

        $this->createMember($app, 'partner');
        $other = $this->browserFor($app, 'partner');
        self::assertSame(404, $other->get('/insights/questions/' . $m[1])->getStatusCode());
        self::assertSame(404, $other->post('/insights/questions/' . $m[1] . '/delete')->getStatusCode());
        self::assertStringNotContainsString('First?', (string) $other->get('/insights')->getBody());

        self::assertSame(400, $browser->post('/insights/questions/' . $m[1] . '/delete', withCsrf: false)->getStatusCode());
        self::assertSame(400, $browser->post('/insights/questions/delete', withCsrf: false)->getStatusCode());
        self::assertSame(2, $this->rows($app, 'ai_threads'), 'nothing goes without the token');
        $deleted = $browser->post('/insights/questions/' . $m[1] . '/delete');
        self::assertSame(303, $deleted->getStatusCode());
        self::assertSame('/insights#your-questions', $deleted->getHeaderLine('Location'));
        self::assertSame(404, $browser->get('/insights/questions/' . $m[1])->getStatusCode());
        self::assertSame(1, $this->rows($app, 'ai_threads'));
        $browser->post('/insights/questions/delete');
        self::assertSame(0, $this->rows($app, 'ai_threads'));
        self::assertSame(0, $this->rows($app, 'ai_messages'));
    }

    public function testFeedbackMarksTheAnswerAndCountsOutliveTheThread(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->provider->queue(Script::answer('Hello.'));
        $posted = $browser->post('/insights/questions', ['question' => 'Hi?']);
        $m = [1 => self::answerIn($posted->getHeaderLine('Location'))];

        $browser->post('/insights/questions/messages/' . $m[1] . '/feedback', ['mark' => 'helpful']);
        $browser->post('/insights/questions/messages/' . $m[1] . '/feedback', ['mark' => 'not_right']);
        $stored = $this->connection($app)->fetchOne('SELECT feedback FROM ai_messages WHERE id = ?', [$m[1]]);
        self::assertSame('not_right', $stored);
        self::assertSame(
            ['helpful' => 0, 'not_right' => 1],
            $this->feedbackCounts($app),
        );

        $this->createMember($app, 'partner');
        $partner = $this->browserFor($app, 'partner');
        $theirs = $partner->post('/insights/questions/messages/' . $m[1] . '/feedback', ['mark' => 'helpful']);
        self::assertSame(404, $theirs->getStatusCode());

        $browser->post('/insights/questions/delete');
        self::assertSame(['helpful' => 0, 'not_right' => 1], $this->feedbackCounts($app));
    }

    public function testThreadsGoAfterTheUsersRetention(): void
    {
        [$app, $owner] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->provider->queue(Script::answer('Hello.'));
        $browser->post('/insights/questions', ['question' => 'Hi?']);
        $browser->post('/insights/questions/retention', ['days' => '7']);
        self::assertSame(7, $this->service($app, AiPreferences::class)->retentionDays($owner->id));

        $clock = $this->pinClock($app, '2026-10-21T12:00:00Z');
        $this->service($app, AiHousekeeping::class)->run();
        self::assertSame(1, $this->rows($app, 'ai_threads'), 'six days on: kept');

        $clock->set(new DateTimeImmutable('2026-10-22T12:00:01Z'));
        $this->service($app, AiHousekeeping::class)->run();
        self::assertSame(0, $this->rows($app, 'ai_threads'));
        self::assertSame(0, $this->rows($app, 'ai_progress'), 'progress goes after an hour');
    }

    public function testThePagesAnswer404AndInsightsHidesAskWhenAskIsOffForTheUser(): void
    {
        [$app, $owner] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->provider->queue(Script::answer('Hello.'));
        $thread = self::threadIn($browser->post('/insights/questions', ['question' => 'Hi?'])->getHeaderLine('Location'));
        $this->service($app, AiPreferences::class)->set($owner->id, false);
        self::assertSame(404, $browser->get('/insights/questions/' . $thread)->getStatusCode());
        self::assertSame(404, $browser->post('/insights/questions', ['question' => 'Hi?'])->getStatusCode());
        self::assertStringNotContainsString('data-ask-entry', (string) $browser->get('/')->getBody());
        self::assertInsightsWithoutAsk((string) $browser->get('/insights')->getBody());
        self::assertSame(1, count($this->provider->requests), 'only the first question was asked');

        foreach ([['FEATURES_AI_ASK' => 'false'], ['AI_ENABLED' => 'false']] as $env) {
            [$app] = $this->askApp($env);
            $off = $this->browserFor($app, 'owner');
            self::assertSame(404, $off->get('/insights/questions/' . $thread)->getStatusCode(), (string) json_encode($env));
            self::assertInsightsWithoutAsk((string) $off->get('/insights')->getBody());
        }
    }

    public function testAskWithoutAModelIsHiddenToo(): void
    {
        $app = $this->aiApp();
        $this->pinClock($app, self::NOW);
        $this->resetDatabase($app);
        $this->createOwner($app);
        $this->network($app, 'Ollama on the desktop', 'http://192.168.1.20:11434/v1');

        self::assertInsightsWithoutAsk((string) $this->signedIn($app)->get('/insights')->getBody());
    }

    public function testYourQuestionsShowsTheLatestFiveNewestFirstAndTheRestUnderShowAll(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $clock = $this->pinClock($app, self::NOW);
        for ($i = 1; $i <= 7; $i++) {
            $this->provider->queue(Script::answer('Answer ' . $i . '.'));
            $browser->post('/insights/questions', ['question' => 'Question number ' . $i . '?']);
            $clock->set($clock->now()->modify('+1 minute'));
        }
        $this->createMember($app, 'partner');
        $this->provider->queue(Script::answer('Theirs.'));
        $this->browserFor($app, 'partner')->post('/insights/questions', ['question' => 'Partner question?']);

        $page = (string) $browser->get('/insights')->getBody();
        $section = substr($page, (int) strpos($page, 'id="your-questions"'));
        $section = substr($section, 0, (int) strpos($section, '</section>'));
        $shown = substr($section, 0, (int) strpos($section, '<details class="disclosure ask-threads-more">'));
        $more = substr($section, (int) strpos($section, '<details class="disclosure ask-threads-more">'));
        self::assertSame(5, substr_count($shown, 'class="ask-thread-row"'));
        self::assertLessThan(strpos($shown, 'Question number 6?'), strpos($shown, 'Question number 7?'), 'newest first');
        self::assertStringNotContainsString('Question number 2?', $shown);
        self::assertStringContainsString('Show all (7)', $more);
        self::assertSame(2, substr_count($more, 'class="ask-thread-row"'));
        self::assertStringContainsString('Question number 1?', $more);
        self::assertStringNotContainsString('Partner question?', $page, 'only one\'s own');
        self::assertStringContainsString('Delete all 7 conversations?', $section);

        // Order on the page (#272): the box, Your questions, then the insights.
        self::assertLessThan(strpos($page, 'id="your-questions"'), strpos($page, 'id="ask"'));
        self::assertLessThan(strpos($page, 'id="insights-heading"'), strpos($page, 'id="your-questions"'));
    }

    public function testANewQuestionWithoutJavaScriptThatFailsComesBackToInsights(): void
    {
        [$app] = $this->askApp();
        $posted = $this->browserFor($app, 'owner')->post('/insights/questions', ['question' => str_repeat('x', 1001)]);

        self::assertSame(422, $posted->getStatusCode());
        $page = (string) $posted->getBody();
        self::assertStringContainsString('Keep it under 1000 characters.', $page);
        self::assertStringContainsString('data-insights-ask', $page, 'the Insights page, with the box');
        self::assertStringContainsString('id="your-questions"', $page);
    }

    public function testAFollowUpThatFailsStaysOnItsThreadPage(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->provider->queue(Script::answer('Hello.'));
        $thread = self::threadIn($browser->post('/insights/questions', ['question' => 'Hi?'])->getHeaderLine('Location'));

        $posted = $browser->post('/insights/questions', ['question' => ' ', 'thread' => $thread]);
        self::assertSame(422, $posted->getStatusCode());
        self::assertStringContainsString('Type a question.', (string) $posted->getBody());
        self::assertStringContainsString('name="thread" value="' . $thread . '"', (string) $posted->getBody());
    }

    public function testTheAskPagesOldAddressesRedirect(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->provider->queue(Script::answer('Hello.'));
        $thread = self::threadIn($browser->post('/insights/questions', ['question' => 'Hi?'])->getHeaderLine('Location'));

        $ask = $browser->get('/ask');
        self::assertSame(301, $ask->getStatusCode());
        self::assertSame('/insights#ask', $ask->getHeaderLine('Location'));
        $q = $browser->get('/ask?q=' . rawurlencode('Summarise my last 12 months'));
        self::assertSame(301, $q->getStatusCode());
        self::assertSame('/insights?q=Summarise+my+last+12+months#ask', $q->getHeaderLine('Location'));
        self::assertStringContainsString('>Summarise my last 12 months</textarea>', (string) $browser->follow($q)->getBody());
        $old = $browser->get('/ask/threads/' . $thread);
        self::assertSame(301, $old->getStatusCode());
        self::assertSame('/insights/questions/' . $thread, $old->getHeaderLine('Location'));
        self::assertSame(200, $browser->follow($old)->getStatusCode());
        $poll = $browser->get('/ask/progress/' . str_repeat('ab', 16));
        self::assertSame(404, $poll->getStatusCode(), 'the old poll path is gone');
        self::assertSame(404, $browser->post('/ask/threads/delete')->getStatusCode(), 'and the old buttons');
        self::assertSame(1, $this->rows($app, 'ai_threads'));
    }

    public function testAStalePostKeepsTheQuestionAndAsksNothing(): void
    {
        [$app] = $this->askApp();
        $browser = $this->browserFor($app, 'owner');
        $this->provider->queue(Script::answer('Hello.'));
        $thread = self::threadIn($browser->post('/insights/questions', ['question' => 'Hi?'])->getHeaderLine('Location'));
        $asked = count($this->provider->requests);

        $new = $browser->post('/ask', ['question' => 'What did the MOT cost?', 'progress' => str_repeat('cd', 16)]);
        self::assertSame(303, $new->getStatusCode());
        self::assertSame('/insights#ask', $new->getHeaderLine('Location'));
        $insights = (string) $browser->follow($new)->getBody();
        self::assertStringContainsString('>What did the MOT cost?</textarea>', $insights, 'in the box');
        self::assertStringNotContainsString('What did the MOT cost?', (string) $browser->get('/insights')->getBody(), 'once');

        $followUp = $browser->post('/ask', ['question' => 'And the tyres?', 'thread' => $thread]);
        self::assertSame('/insights/questions/' . $thread . '#ask-question', $followUp->getHeaderLine('Location'));
        self::assertStringContainsString('>And the tyres?</textarea>', (string) $browser->follow($followUp)->getBody());

        self::assertSame($asked, count($this->provider->requests), 'nothing asked');
        self::assertSame(1, $this->rows($app, 'ai_threads'));
        self::assertSame(2, $this->rows($app, 'ai_messages'));
        self::assertSame(400, $browser->post('/ask', ['question' => 'x'], withCsrf: false)->getStatusCode(), 'still a form post');
    }

    public function testTheOldAddressesLandOnInsightsWithAiOff(): void
    {
        [$app] = $this->askApp(['AI_ENABLED' => 'false']);
        $browser = $this->browserFor($app, 'owner');

        self::assertSame('/insights#ask', $browser->get('/ask')->getHeaderLine('Location'));
        self::assertSame('/insights#ask', $browser->get('/ask/threads/4')->getHeaderLine('Location'), 'no thread pages to go to');
        $posted = $browser->post('/ask', ['question' => 'Anything?', 'thread' => '4']);
        self::assertSame('/insights#ask', $posted->getHeaderLine('Location'));
        self::assertInsightsWithoutAsk((string) $browser->follow($posted)->getBody());
        self::assertSame([], $this->provider->requests);
    }

    private static function assertInsightsWithoutAsk(string $insights): void
    {
        self::assertStringContainsString('<h1 class="page-header__title">Insights</h1>', $insights);
        self::assertStringNotContainsString('data-insights-ask', $insights);
        self::assertStringNotContainsString('id="your-questions"', $insights);
        self::assertStringNotContainsString('/insights/questions', $insights);
        self::assertStringNotContainsString('data-ask-entry', $insights);
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
