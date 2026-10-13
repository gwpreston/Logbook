<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use DateTimeImmutable;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Repository\UserRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * What a share allows on the pages (spec.md §7.21, §7.9, Phase 46): the
 * summary under the sharing forms, every state for Alpine and the saved one
 * without JS; *What each level allows*; *What you can do*; the flash when a
 * save widens access; the *Admin* line and *Make admin*'s confirmation.
 */
final class ShareSummaryPagesTest extends AppTestCase
{
    use CostFixtures;

    public function testTheOwnersFormSaysWhatTheShareAllows(): void
    {
        $app = $this->createApp();
        $owner = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $member = $this->createMember($app);
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, true, new DateTimeImmutable('2026-09-01T00:00:00Z'));

        $page = self::body($owner->get('/vehicles/' . $golf->id . '/sharing'));

        // The saved share's summary is the one shown without JS.
        $shown = '/<div x-bind:hidden="state !== \'%s\'">\s*<p class="share-summary__heading">%s can<\/p>/';
        self::assertMatchesRegularExpression(sprintf($shown, 'log-0-1', 'Sam Partner'), $page);
        self::assertStringContainsString('see its documents, including policy numbers and the registration', $page);
        self::assertStringContainsString('edit or delete the entries they added', $page);
        self::assertStringContainsString('see the amounts they type themselves, but no totals or reports', $page);
        self::assertStringContainsString('Sam Partner can’t', $page);
        self::assertStringContainsString('share, transfer, archive or delete it', $page);
        // The add form's default (Log, nothing ticked) is shown too, for "They".
        self::assertMatchesRegularExpression(sprintf($shown, 'log-0-0', 'They'), $page);
        // Every other state is there, hidden, for Alpine.
        self::assertSame(2 * 12, substr_count($page, 'x-bind:hidden="state !== '));
        self::assertSame(2 * 11, substr_count($page, ' hidden x-bind:hidden="state !== '));
        // Quick picks are hidden until Alpine shows them.
        self::assertStringContainsString('x-on:click="pick(\'log\', false, true)"', $page);
        self::assertStringContainsString('Co-owner', $page);
        // Without JS, every level's summary.
        self::assertStringContainsString('What each level allows', $page);
    }

    public function testASharedUserReadsWhatTheyCanDo(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $member = $this->createMember($app);
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));

        $page = self::body($this->browserFor($app, 'partner')->get('/vehicles/' . $golf->id . '/sharing'));

        self::assertStringContainsString('What you can do', $page);
        self::assertStringContainsString('You can</p>', $page);
        self::assertStringContainsString('You can’t</p>', $page);
        self::assertStringContainsString('edit or delete the entries you added', $page);
        self::assertStringContainsString('see the amounts you type yourself, but no totals or reports', $page);
        self::assertStringNotContainsString('What each level allows', $page, 'that is the owner\'s');
        self::assertStringNotContainsString('x-bind:hidden="state', $page);
    }

    public function testASaveThatWidensAccessSaysWhat(): void
    {
        $app = $this->createApp();
        $owner = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $member = $this->createMember($app);
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-09-01T00:00:00Z'));
        $save = '/vehicles/' . $golf->id . '/sharing/' . $member->id . '/save';
        $owner->get('/vehicles/' . $golf->id . '/sharing');

        $wider = self::body($owner->follow($owner->post($save, ['level' => 'log', 'can_see_costs' => '1'])));
        self::assertStringContainsString('Sam Partner can now: see costs, valuations and reports.', $wider);

        $withReminders = ['level' => 'log', 'can_see_costs' => '1', 'notify' => '1'];
        $reminders = self::body($owner->follow($owner->post($save, $withReminders)));
        self::assertStringContainsString('Sharing saved.', $reminders);
        self::assertStringNotContainsString('can now', $reminders, 'reminders are not access');

        $manage = self::body($owner->follow($owner->post($save, ['level' => 'manage'])));
        self::assertStringContainsString(
            'Sam Partner can now: edit the vehicle, and edit or delete any entry; '
            . 'export, import and prepare the sale pack; see an incident’s fault, claim numbers and payouts.',
            $manage,
        );

        $narrower = self::body($owner->follow($owner->post($save, ['level' => 'view'])));
        self::assertStringContainsString('Sharing saved.', $narrower);
        self::assertStringNotContainsString('can now', $narrower);
    }

    public function testMakeAdminAsksFirstAndTheAdminTickSaysWhatItGrants(): void
    {
        $app = $this->createApp();
        $owner = $this->signedIn($app);
        $member = $this->createMember($app);

        $users = self::body($owner->get('/settings/users'));
        self::assertStringContainsString(
            'Admins can manage users and modules, change server settings, and download or restore backups '
            . 'containing every vehicle.',
            $users,
        );
        $confirm = '/settings/users/' . $member->id . '/admin';
        self::assertStringContainsString('href="' . $confirm . '"', $users, 'Make admin is a link to the confirmation');

        $page = self::body($owner->get($confirm));
        self::assertStringContainsString('Make Sam Partner an admin?', $page);
        self::assertStringContainsString('backups containing every vehicle on this install', $page);
        $accounts = $this->service($app, UserRepository::class);
        self::assertFalse($accounts->findByUsername('partner')?->isAdmin, 'not before confirming');

        $done = $owner->post($confirm);
        self::assertSame(303, $done->getStatusCode());
        self::assertStringContainsString('Sam Partner is now an admin.', self::body($owner->follow($done)));
        $promoted = $accounts->find($member->id);
        self::assertNotNull($promoted);
        self::assertTrue($promoted->isAdmin);
    }
}
