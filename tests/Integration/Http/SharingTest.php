<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Http;

use Logbook\Domain\Access\ShareLevel;
use Logbook\Repository\FuelEntryRepository;
use Logbook\Repository\VehicleRepository;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;

/**
 * Sharing a vehicle (spec.md §7.21): the owner adds, changes and removes
 * shares; a Log user adds entries and changes only their own; a shared user
 * sets their reminders and leaves; transfer hands everything over.
 */
final class SharingTest extends AppTestCase
{
    use CostFixtures;

    private const array FILL = [
        'filled_at' => '2026-09-20T09:15',
        'odometer' => '10500',
        'fuel' => 'petrol',
        'volume' => '40',
        'price' => '',
        'total' => '61.23',
        'station' => '',
        'notes' => '',
    ];

    public function testTheOwnerSharesAndTheSharedUserSeesTheVehicle(): void
    {
        $app = $this->createApp();
        $owner = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->createMember($app);
        $partner = $this->browserFor($app, 'partner');
        self::assertSame(404, $partner->get('/vehicles/' . $golf->id)->getStatusCode(), 'not before it is shared');

        $sharing = '/vehicles/' . $golf->id . '/sharing';
        $owner->get($sharing);
        $refused = $owner->post($sharing, ['username' => 'nobody', 'level' => 'log']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('There is no user with that username.', self::body($refused));
        $self = $owner->post($sharing, ['username' => 'owner', 'level' => 'log']);
        self::assertSame(422, $self->getStatusCode(), 'not the owner');

        $added = $owner->post($sharing, ['username' => 'Partner', 'level' => 'log', 'notify' => '1']);
        self::assertSame(303, $added->getStatusCode());
        $page = self::body($owner->follow($added));
        self::assertStringContainsString('Shared with partner.', $page);
        self::assertStringContainsString('Sam Partner', $page);
        self::assertSame(422, $owner->post($sharing, ['username' => 'partner', 'level' => 'view'])->getStatusCode(), 'only once');

        self::assertSame(200, $partner->get('/vehicles/' . $golf->id)->getStatusCode());
        $garage = self::body($partner->get('/garage'));
        self::assertStringContainsString('Shared with you', $garage);
        self::assertStringContainsString('Golf', $garage);
        $mine = self::body($partner->get($sharing));
        self::assertStringContainsString('You have Log access', $mine);
        self::assertStringNotContainsString('Share with someone', $mine, 'only the owner adds people');
        self::assertSame(403, $partner->post($sharing, ['username' => 'owner', 'level' => 'view'])->getStatusCode());
        self::assertSame(403, $partner->get('/vehicles/' . $golf->id . '/transfer')->getStatusCode());

        $share = $this->service($app, VehicleShareRepository::class)->listForVehicle($golf->id)[0];
        self::assertSame(ShareLevel::Log, $share->level);
        self::assertFalse($share->canSeeCosts, 'off unless ticked');
        self::assertTrue($share->notify);

        $owner->post($sharing . '/' . $share->userId . '/save', ['level' => 'manage']);
        $share = $this->service($app, VehicleShareRepository::class)->listForVehicle($golf->id)[0];
        self::assertSame(ShareLevel::Manage, $share->level);
        self::assertTrue($share->canSeeCosts, 'Manage always sees costs');
        self::assertFalse($share->notify, 'the form says what it wants');

        $owner->post($sharing . '/' . $share->userId . '/remove');
        self::assertSame(404, $partner->get('/vehicles/' . $golf->id)->getStatusCode(), 'removed means gone');
    }

    public function testALogUserChangesOnlyTheirOwnEntries(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $theirs = $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10000', '44.5', '71.37');
        $member = $this->createMember($app);
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new \DateTimeImmutable('2026-09-01T00:00:00Z'));
        $partner = $this->browserFor($app, 'partner');
        $base = '/vehicles/' . $golf->id . '/fuel';

        $partner->get($base . '/new');
        $saved = $partner->post($base . '/new', self::FILL);
        self::assertSame(303, $saved->getStatusCode());
        $entries = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id);
        $mine = array_values(array_filter($entries, static fn ($e): bool => $e->id !== $theirs->id))[0];
        self::assertSame($member->id, $mine->createdBy, 'the fill-up names who added it');
        self::assertSame($this->owner($app)->id, $theirs->createdBy);

        $list = self::body($partner->get($base));
        self::assertStringContainsString('61.23', $list, 'they see the amount they typed');
        self::assertStringNotContainsString('71.37', $list, 'and no other');
        self::assertStringContainsString('/fuel/' . $mine->id . '/edit', $list);
        self::assertStringNotContainsString('/fuel/' . $theirs->id . '/edit', $list, 'no edit link on the owner\'s');
        self::assertStringContainsString('Added by Pat Owner', $list);

        self::assertSame(200, $partner->get($base . '/' . $mine->id . '/edit')->getStatusCode());
        self::assertSame(403, $partner->get($base . '/' . $theirs->id . '/edit')->getStatusCode());
        self::assertSame(403, $partner->post($base . '/' . $theirs->id . '/delete')->getStatusCode());
        self::assertSame(303, $partner->post($base . '/' . $mine->id . '/delete')->getStatusCode());
        self::assertCount(1, $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id));
    }

    public function testASharedUserChoosesRemindersAndLeaves(): void
    {
        $app = $this->createApp();
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $member = $this->createMember($app);
        $shares = $this->service($app, VehicleShareRepository::class);
        $shares->insert($golf->id, $member->id, ShareLevel::View, false, false, new \DateTimeImmutable('2026-09-01T00:00:00Z'));
        $partner = $this->browserFor($app, 'partner');
        $sharing = '/vehicles/' . $golf->id . '/sharing';

        $partner->get($sharing);
        $partner->post($sharing . '/me/notify', ['notify' => '1']);
        self::assertTrue($shares->find($golf->id, $member->id)?->notify);

        $left = $partner->post($sharing . '/me/leave');
        self::assertSame(303, $left->getStatusCode());
        self::assertNull($shares->find($golf->id, $member->id));
        self::assertSame(404, $partner->get('/vehicles/' . $golf->id)->getStatusCode());
    }

    public function testTransferHandsEverythingOverAndKeepsManage(): void
    {
        $app = $this->createApp();
        $owner = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $this->fillUp($app, $golf, '2026-09-10T08:00:00Z', '10000', '44.5', '71.37');
        $this->maintenance($app, $golf, '2026-09-12', 'Oil change', '120.00', '10100');
        $member = $this->createMember($app);
        $ownerId = $this->owner($app)->id;

        $transfer = '/vehicles/' . $golf->id . '/transfer';
        $owner->get($transfer);
        self::assertSame(422, $owner->post($transfer, ['username' => 'nobody', 'keep_access' => '1'])->getStatusCode());
        $done = $owner->post($transfer, ['username' => 'partner', 'keep_access' => '1']);
        self::assertSame(303, $done->getStatusCode());

        $moved = $this->service($app, VehicleRepository::class)->findById($golf->id);
        self::assertSame($member->id, $moved?->userId, 'the new owner');
        $kept = $this->service($app, VehicleShareRepository::class)->find($golf->id, $ownerId);
        self::assertSame(ShareLevel::Manage, $kept?->level, 'the old owner keeps Manage');
        $fills = $this->service($app, FuelEntryRepository::class)->listForVehicle($golf->id);
        self::assertCount(1, $fills, 'every entry stays');
        self::assertSame($ownerId, $fills[0]->createdBy, 'and still names who added it');
        self::assertSame(403, $owner->get($transfer)->getStatusCode(), 'only the owner transfers');
        self::assertSame(200, $owner->get('/vehicles/' . $golf->id . '/edit')->getStatusCode(), 'Manage edits');

        $partner = $this->browserFor($app, 'partner');
        self::assertStringContainsString('Golf', self::body($partner->get('/garage')));
        $partner->get('/vehicles/' . $golf->id . '/transfer');
        $partner->post('/vehicles/' . $golf->id . '/transfer', ['username' => 'owner']);
        self::assertNull(
            $this->service($app, VehicleShareRepository::class)->find($golf->id, $ownerId),
            'back to the old owner: their share goes, they own it again',
        );
        self::assertSame($ownerId, $this->service($app, VehicleRepository::class)->findById($golf->id)?->userId);
    }

    public function testAViewShareCannotAddOrActOnTheOwnersReminders(): void
    {
        $app = $this->createApp();
        $this->pinClock($app, '2026-09-30T12:00:00Z');
        $owner = $this->signedIn($app);
        $golf = $this->vehicle($app);
        $member = $this->createMember($app);
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::View, false, false, new \DateTimeImmutable('2026-09-01T00:00:00Z'));
        $owner->get('/reminders/new');
        $reminder = ['vehicle_id' => (string) $golf->id, 'title' => 'Wash', 'due_on' => '2026-10-02', 'lead_time_days' => '7'];
        self::assertSame(303, $owner->post('/reminders/new', $reminder)->getStatusCode());
        $partner = $this->browserFor($app, 'partner');

        $list = self::body($partner->get('/reminders'));
        self::assertStringContainsString('Wash', $list, 'they see the reminder');
        self::assertStringNotContainsString('/done', $list, 'but no Done or Dismiss');
        self::assertStringNotContainsString('/edit', $list, 'and no Edit');

        $partner->get('/reminders/new');
        $refused = $partner->post('/reminders/new', ['title' => 'Mine'] + $reminder);
        self::assertSame(422, $refused->getStatusCode(), 'adding a reminder to the owner\'s vehicle needs Manage');
        self::assertStringNotContainsString('Mine', self::body($partner->get('/reminders')));
    }
}
