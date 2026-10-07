<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Notification\Personal;

use Logbook\Domain\Notification\ChannelRecord;
use Logbook\Service\Notification\Outbound\OutboundHttp;
use Logbook\Service\Notification\Personal\ChannelForm;
use Logbook\Service\Notification\Personal\ChannelSettings;
use Logbook\Service\Notification\Personal\GotifySender;
use Logbook\Service\Notification\Personal\NtfySender;
use Logbook\Service\Notification\Personal\PersonalKinds;
use Logbook\Service\Notification\Personal\PersonalSender;
use Logbook\Service\Notification\Personal\WebhookSender;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each personal kind's form, read from its definition alone (spec.md
 * §7.11 *Personal channels*): http(s) URLs with a host, no credentials and
 * no fragment; tokens without spaces and never an `env:` reference.
 */
final class ChannelFormTest extends TestCase
{
    public function testAValidNtfyChannelKeepsItsTopicAndToken(): void
    {
        $form = ChannelForm::parse(self::ntfy(), ['ntfy-url' => ' https://ntfy.sh/my-garage ', 'ntfy-token' => 'tk_abc']);

        self::assertTrue($form->isValid());
        self::assertSame(['url' => 'https://ntfy.sh/my-garage'], $form->settings());
        self::assertSame(['token' => 'tk_abc'], $form->secrets);
        self::assertSame(['ntfy-url' => 'https://ntfy.sh/my-garage'], $form->inputValues(), 'never the token');
        self::assertSame('https://ntfy.sh/', self::ntfy()->destination(new ChannelSettings($form->settings())));
    }

    public function testTheNtfyTokenIsOptionalAndTheTopicIsChecked(): void
    {
        self::assertTrue(ChannelForm::parse(self::ntfy(), ['ntfy-url' => 'http://ntfy.lan/garage'])->isValid());

        $errors = ChannelForm::parse(self::ntfy(), ['ntfy-url' => 'https://ntfy.sh/'])->errors;
        self::assertSame('notifications.ntfy.url_invalid', $errors['ntfy-url']['key'] ?? null, 'a server without a topic');
        self::assertSame('notifications.error.required', ChannelForm::parse(self::ntfy(), [])->errors['ntfy-url']['key'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badUrls(): iterable
    {
        yield 'no scheme' => ['ntfy.sh/garage', 'notifications.error.url'];
        yield 'ftp' => ['ftp://ntfy.sh/garage', 'notifications.error.url'];
        yield 'javascript' => ['javascript:alert(1)', 'notifications.error.url'];
        yield 'no host' => ['https:///garage', 'notifications.error.url'];
        yield 'a space' => ['https://ntfy.sh/my garage', 'notifications.error.url'];
        yield 'a line break' => ["https://ntfy.sh/garage\nX-Evil: 1", 'notifications.error.url'];
        yield 'credentials' => ['https://user:pass@ntfy.sh/garage', 'notifications.error.url_credentials'];
        yield 'a user only' => ['https://user@ntfy.sh/garage', 'notifications.error.url_credentials'];
        yield 'a fragment' => ['https://ntfy.sh/garage#top', 'notifications.error.url_fragment'];
    }

    #[DataProvider('badUrls')]
    public function testEveryUrlFieldRefusesWhatIsNotAPlainHttpAddress(string $url, string $error): void
    {
        foreach ([self::ntfy(), self::gotify(), self::webhook()] as $sender) {
            $name = $sender->definition()->inputName('url');
            $form = ChannelForm::parse($sender, [$name => $url]);
            self::assertSame($error, $form->errors[$name]['key'] ?? null, $sender->definition()->key . ': ' . $url);
        }
    }

    public function testAUrlHasALengthLimit(): void
    {
        $form = ChannelForm::parse(self::webhook(), ['personal-webhook-url' => 'https://example.com/' . str_repeat('a', 500)]);

        self::assertSame('notifications.error.too_long', $form->errors['personal-webhook-url']['key'] ?? null);
        self::assertSame(['max' => 500], $form->errors['personal-webhook-url']['params']);
    }

    public function testGotifyNeedsItsTokenAndAPriorityInRange(): void
    {
        $form = ChannelForm::parse(self::gotify(), ['gotify-url' => 'https://gotify.example', 'gotify-token' => 'AppTok']);
        self::assertTrue($form->isValid());
        self::assertSame(['url' => 'https://gotify.example', 'priority' => 5], $form->settings(), 'empty: the default');
        $settings = new ChannelSettings($form->settings());
        self::assertSame('https://gotify.example/message', self::gotify()->destination($settings));

        foreach (['-1', '11', 'high', '5.5'] as $priority) {
            $input = ['gotify-url' => 'https://gotify.example', 'gotify-priority' => $priority];
            $errors = ChannelForm::parse(self::gotify(), $input)->errors;
            self::assertSame('notifications.error.integer', $errors['gotify-priority']['key'] ?? null, $priority);
            self::assertSame(['min' => 0, 'max' => 10], $errors['gotify-priority']['params']);
        }
        foreach (['0', '10'] as $priority) {
            $input = ['gotify-url' => 'https://gotify.example', 'gotify-priority' => $priority];
            $form = ChannelForm::parse(self::gotify(), $input);
            self::assertArrayNotHasKey('gotify-priority', $form->errors, 'the edges are allowed: ' . $priority);
            self::assertSame((int) $priority, $form->settings()['priority']);
        }
    }

    public function testASecretIsNeverAnEnvReferenceAndHasNoSpaces(): void
    {
        foreach (['env:SESSION_SECRET', ' env:DB_PASSWORD '] as $typed) {
            $form = ChannelForm::parse(self::gotify(), ['gotify-url' => 'https://gotify.example', 'gotify-token' => $typed]);
            self::assertSame('notifications.error.env_reference', $form->errors['gotify-token']['key'] ?? null, $typed);
            self::assertSame([], $form->secrets, 'not kept to be sent or stored');
        }

        $spaced = ChannelForm::parse(self::ntfy(), ['ntfy-url' => 'https://ntfy.sh/g', 'ntfy-token' => 'two words']);
        self::assertSame('notifications.error.secret_spaces', $spaced->errors['ntfy-token']['key'] ?? null);

        $long = ChannelForm::parse(self::ntfy(), ['ntfy-url' => 'https://ntfy.sh/g', 'ntfy-token' => str_repeat('x', 201)]);
        self::assertSame('notifications.error.too_long', $long->errors['ntfy-token']['key'] ?? null);
    }

    public function testRemoveIsReadPerSecretField(): void
    {
        $form = ChannelForm::parse(self::ntfy(), ['ntfy-url' => 'https://ntfy.sh/g', 'ntfy-token-remove' => '1']);

        self::assertSame(['token'], $form->remove);
        self::assertSame([], $form->secrets);
    }

    public function testASavedChannelsValuesNeverIncludeItsSecrets(): void
    {
        $settings = ['url' => 'https://gotify.example', 'priority' => 7, '_secrets' => ['token']];
        $record = new ChannelRecord(1, 1, 'gotify', true, $settings);

        self::assertSame(
            ['gotify-url' => 'https://gotify.example', 'gotify-priority' => '7'],
            ChannelForm::saved(self::gotify()->definition(), $record),
        );
        self::assertSame(['gotify-url' => '', 'gotify-priority' => '5'], ChannelForm::saved(self::gotify()->definition(), null));
        self::assertSame(['token'], $record->secretFields());
        self::assertSame(['url' => 'https://gotify.example', 'priority' => 7], $record->values());
    }

    public function testKindsAreUniqueAndNeverTheServersChannels(): void
    {
        $kinds = new PersonalKinds([self::ntfy(), self::gotify(), self::webhook()]);
        self::assertSame(['ntfy', 'gotify', 'personal-webhook'], $kinds->keys());
        self::assertNull($kinds->get('webhook'), 'the server\'s webhook is not a personal kind');

        $this->expectException(InvalidArgumentException::class);
        new PersonalKinds([self::ntfy(), self::ntfy()]);
    }

    private static function ntfy(): PersonalSender
    {
        return new NtfySender(self::http());
    }

    private static function gotify(): PersonalSender
    {
        return new GotifySender(self::http());
    }

    private static function webhook(): PersonalSender
    {
        return new WebhookSender(self::http());
    }

    private static function http(): OutboundHttp
    {
        return (new \ReflectionClass(OutboundHttp::class))->newInstanceWithoutConstructor();
    }
}
