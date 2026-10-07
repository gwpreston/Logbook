<?php

declare(strict_types=1);

namespace Logbook\Tests\Support;

use DI\Container;
use Logbook\Kernel;
use Logbook\Support\Clock\Sleeper;
use Logbook\Support\Http\AfterResponse;
use Psr\Container\ContainerInterface;
use Slim\App;
use Symfony\Component\Mime\Email;

/**
 * Account email (Phase 33.1, spec.md §7.9): email set up and recorded, a
 * recording sleeper for the forgotten-password floor, the work queued for
 * after the response run on demand, and a clean slate for the rate limits.
 */
abstract class AccountTestCase extends ReminderTestCase
{
    protected const array MAIL = [
        'APP_URL' => 'https://garage.example',
        'TEST_MAIL_HOST' => 'smtp.test',
        'TEST_MAIL_FROM' => 'Logbook <logbook@garage.example>',
        'TEST_MAIL_TO' => '',
    ];

    protected RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        parent::setUp();
        self::clearRateLimits();
    }

    protected function tearDown(): void
    {
        self::clearRateLimits();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $env
     * @return App<ContainerInterface>
     */
    protected function accountApp(array $env = []): App
    {
        $app = $this->createRecordingApp($env + self::MAIL);
        $this->sleeper = new RecordingSleeper();
        $container = $app->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(Sleeper::class, $this->sleeper);

        return $app;
    }

    /**
     * Run what the front controller runs once the response has gone.
     *
     * @param App<ContainerInterface> $app
     */
    protected function afterResponse(App $app): void
    {
        $this->service($app, AfterResponse::class)->run();
    }

    /**
     * @return list<Email>
     */
    protected function mailTo(string $address): array
    {
        return array_values(array_filter(
            $this->mail->sent,
            static fn (Email $email): bool => $email->getTo()[0]->getAddress() === $address,
        ));
    }

    /**
     * The path of the one link in an email (its text part).
     */
    protected static function linkIn(Email $email): string
    {
        $text = (string) $email->getTextBody();
        self::assertSame(1, preg_match('~https://garage\.example(/[^\s]+)~', $text, $m), 'a link in: ' . $text);

        return $m[1] ?? '';
    }

    private static function clearRateLimits(): void
    {
        foreach (glob(Kernel::rootDir() . '/var/cache/rate-limit/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }
}
