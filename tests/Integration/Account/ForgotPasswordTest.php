<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Account;

use DateInterval;
use DateTimeImmutable;
use Logbook\Domain\Api\ApiScope;
use Logbook\Repository\UserRepository;
use Logbook\Service\Api\ApiKeyService;
use Logbook\Service\Auth\PasswordResets;
use Logbook\Tests\Support\AccountTestCase;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;

/**
 * *Forgotten password* (spec.md §7.9, Phase 33.1): the same answer and the
 * same time whatever was typed, an email only to an active account with a
 * password and a confirmed address, limits per address and per account,
 * and a 60-minute link that only a POST uses.
 */
final class ForgotPasswordTest extends AccountTestCase
{
    private const string NOW = '2026-10-05T09:00:00Z';
    private const string NEW_PASSWORD = 'a brand new passphrase';

    public function testEveryAnswerIsTheSameAndOnlyTheRealAccountIsEmailed(): void
    {
        $app = $this->accountApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->withEmail($app, $this->owner($app), 'pat@example.com');
        $disabled = $this->withEmail($app, $this->createMember($app, 'gone'), 'gone@example.com');
        $now = new DateTimeImmutable(self::NOW);
        $this->service($app, UserRepository::class)->setDisabledAt($disabled->id, $now, $now);
        $this->createMember($app, 'noaddress');
        $sso = $this->service($app, UserRepository::class)->insert(
            'sso-only',
            null,
            'Single Sign',
            $this->owner($app)->preferences,
            new DateTimeImmutable(self::NOW),
            email: 'sso@example.com',
        );
        self::assertFalse($sso->hasPassword());

        $browser = new TestBrowser($app);
        $browser->get('/forgot-password');
        $answers = [];
        $typedIn = [
            'nobody', 'nobody@example.com', 'gone', 'gone@example.com', 'noaddress', 'sso-only', 'sso@example.com', 'owner',
        ];
        foreach ($typedIn as $i => $typed) {
            $answers[$typed] = $browser->from('198.51.100.' . $i)->post('/forgot-password', ['login' => $typed]);
        }

        $reference = self::normalised($answers['owner'], 'owner');
        foreach ($answers as $typed => $answer) {
            self::assertSame($reference, self::normalised($answer, $typed), 'the same answer for ' . $typed);
        }
        self::assertStringContainsString(
            'If that matches an account with an email address, we’ve sent it a link.',
            $reference['body'],
        );
        $answer = $answers['owner'];
        self::assertSame('close', $answer->getHeaderLine('Connection'), 'complete before the email is sent (mod_php)');
        self::assertSame((string) strlen((string) $answer->getBody()), $answer->getHeaderLine('Content-Length'));
        self::assertSame(array_fill(0, 8, 1.5), $this->sleeper->slept, 'every answer padded to the same floor');

        self::assertSame([], $this->mail->sent, 'nothing is sent before the response has gone');
        $this->afterResponse($app);
        self::assertCount(1, $this->mail->sent, 'one email, for the real account only');
        $email = $this->mailTo('pat@example.com')[0];
        self::assertSame('Reset your Logbook password', $email->getSubject());
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('"owner"', $text);
        self::assertStringContainsString('(from 198.51.100.7)', $text, 'who asked');
        self::assertStringContainsString('60 minutes', $text);
        self::assertStringContainsString('If this wasn’t you, ignore this email. Your password hasn’t changed.', $text);
        self::assertStringStartsWith('/invite/', self::linkIn($email));
        self::assertStringNotContainsString('<img', (string) $email->getHtmlBody(), 'no remote images');
    }

    public function testAnAddressSharedByTwoGetsOneEmailEach(): void
    {
        $app = $this->accountApp();
        $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->withEmail($app, $this->owner($app), 'home@example.com');
        $this->withEmail($app, $this->createMember($app), 'home@example.com');

        $this->forgot($app, 'Home@Example.com ');
        $this->afterResponse($app);

        $sent = $this->mailTo('home@example.com');
        self::assertCount(2, $sent);
        $bodies = array_map(static fn ($e): string => (string) $e->getTextBody(), $sent);
        self::assertStringContainsString('"owner"', $bodies[0]);
        self::assertStringContainsString('"partner"', $bodies[1]);
        self::assertNotSame(self::linkIn($sent[0]), self::linkIn($sent[1]), 'a link each');
    }

    public function testRequestsPerAddressAndEmailsPerAccountAreLimited(): void
    {
        $app = $this->accountApp();
        $clock = $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $this->withEmail($app, $this->owner($app), 'pat@example.com');

        $browser = new TestBrowser($app);
        $browser->from('203.0.113.1');
        for ($i = 0; $i < PasswordResets::PER_ADDRESS; $i++) {
            $this->post($browser, 'nobody');
        }
        $over = $this->post($browser, 'owner');
        self::assertStringContainsString('we’ve sent it a link', self::body($over), 'the same answer');
        $this->afterResponse($app);
        self::assertSame([], $this->mail->sent, 'the sixth request from one address sends nothing');

        for ($i = 0; $i < PasswordResets::PER_ACCOUNT + 1; $i++) {
            $this->post((new TestBrowser($app))->from('203.0.113.' . (10 + $i)), 'owner');
        }
        $this->afterResponse($app);
        self::assertCount(PasswordResets::PER_ACCOUNT, $this->mail->sent, 'three emails per account an hour');

        $clock->set($clock->now()->add(new DateInterval('PT61M')));
        $this->post((new TestBrowser($app))->from('203.0.113.50'), 'owner');
        $this->afterResponse($app);
        self::assertCount(PasswordResets::PER_ACCOUNT + 1, $this->mail->sent, 'an hour later, one more');
    }

    public function testTheLinkSetsAPasswordSignsInAndEndsEverythingElse(): void
    {
        $app = $this->accountApp();
        $clock = $this->pinClock($app, self::NOW);
        $this->signedIn($app);
        $owner = $this->withEmail($app, $this->owner($app), 'pat@example.com');
        $elsewhere = $this->browserFor($app, 'owner');
        $token = $this->service($app, ApiKeyService::class)->create($owner, 'Phone', ApiScope::Read)->token;

        $older = $this->linkFor($app, 'owner');
        self::assertSame(200, $elsewhere->get('/garage')->getStatusCode(), 'asking for a link signs nobody out');
        $path = $this->linkFor($app, 'pat@example.com');
        $guest = new TestBrowser($app);
        self::assertSame(404, $guest->get($older)->getStatusCode(), 'a newer link revokes the older');

        self::assertSame(200, $guest->get($path)->getStatusCode());
        self::assertSame(200, $guest->get($path)->getStatusCode(), 'opening it spends nothing (a mail scanner)');
        $this->mail->sent = [];
        $done = $guest->post($path, ['password' => self::NEW_PASSWORD, 'password_confirm' => self::NEW_PASSWORD]);
        self::assertSame(303, $done->getStatusCode());
        self::assertSame(200, $guest->get('/garage')->getStatusCode(), 'signed in');
        self::assertSame(404, (new TestBrowser($app))->get($path)->getStatusCode(), 'used once');
        self::assertStringContainsString('/login', $elsewhere->get('/garage')->getHeaderLine('Location'), 'other sessions end');

        $changed = $this->mailTo('pat@example.com');
        self::assertCount(1, $changed);
        self::assertSame('Your Logbook password was changed', $changed[0]->getSubject());
        self::assertSame(
            200,
            $this->get($app, '/api/v1/me', ['Authorization' => 'Bearer ' . $token])->getStatusCode(),
            'API keys are not passwords',
        );

        $login = new TestBrowser($app);
        $login->get('/login');
        self::assertSame(
            422,
            $login->post('/login', ['username' => 'owner', 'password' => self::PASSWORD])->getStatusCode(),
            'the old password is gone',
        );
        self::assertSame(303, $login->post('/login', ['username' => 'owner', 'password' => self::NEW_PASSWORD])->getStatusCode());

        $late = $this->linkFor($app, 'owner');
        $clock->set($clock->now()->add(new DateInterval('PT60M')));
        self::assertSame(404, (new TestBrowser($app))->get($late)->getStatusCode(), 'sixty minutes, then gone');
    }

    public function testTheSignInPageOffersItOnlyWhenItCanWork(): void
    {
        $on = $this->accountApp();
        $this->signedIn($on);
        $page = self::body((new TestBrowser($on))->get('/login'));
        self::assertStringContainsString('Forgotten your password?', $page);
        self::assertSame(200, (new TestBrowser($on))->get('/forgot-password')->getStatusCode());

        foreach (
            [
                'no email' => ['MAIL_HOST' => ''],
                'password sign-in off' => ['AUTH_LOCAL_LOGIN' => 'false', 'OIDC_ISSUER' => ''],
                'switched off' => ['PASSWORD_RESET_ENABLED' => 'false'],
            ] as $case => $env
        ) {
            $app = $this->accountApp($env);
            $this->resetDatabase($app);
            $this->createOwner($app);
            $browser = new TestBrowser($app);
            $login = self::body($browser->get('/login'));
            self::assertStringNotContainsString('Forgotten your password?', $login, $case);
            self::assertSame(404, $browser->get('/forgot-password')->getStatusCode(), $case);
            if (str_contains($login, 'csrf_value')) {
                self::assertSame(404, $browser->post('/forgot-password', ['login' => 'owner'])->getStatusCode(), $case);
            }
        }
    }

    /**
     * Status, the headers that matter and the body, with what was typed
     * (echoed for *Send it again*) and the per-session token taken out.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function normalised(ResponseInterface $response, string $typed): array
    {
        $body = str_replace('value="' . htmlspecialchars($typed, ENT_QUOTES) . '"', 'value="…"', self::body($response));
        $body = (string) preg_replace('/name="csrf_value" value="[^"]+"/', '', $body);
        $headers = [];
        foreach (['Content-Type', 'Cache-Control', 'Set-Cookie', 'Location', 'Connection'] as $name) {
            $headers[$name] = $response->getHeaderLine($name);
        }

        return ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => $body];
    }

    private function post(TestBrowser $browser, string $typed): ResponseInterface
    {
        $browser->get('/forgot-password');

        return $browser->post('/forgot-password', ['login' => $typed]);
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function forgot(App $app, string $typed): void
    {
        $answer = $this->post((new TestBrowser($app))->from('192.0.2.' . random_int(1, 250)), $typed);
        self::assertSame(200, $answer->getStatusCode());
    }

    /**
     * Ask for a link and return its path from the email.
     *
     * @param App<ContainerInterface> $app
     */
    private function linkFor(App $app, string $typed): string
    {
        $before = count($this->mail->sent);
        $this->forgot($app, $typed);
        $this->afterResponse($app);
        self::assertCount($before + 1, $this->mail->sent);

        return self::linkIn($this->mail->sent[$before]);
    }
}
