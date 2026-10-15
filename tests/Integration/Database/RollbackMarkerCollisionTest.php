<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Logbook\Domain\Access\ShareLevel;
use Logbook\Domain\Expense\ExpenseCategory;
use Logbook\Domain\Expense\ExpenseEntryData;
use Logbook\Domain\Part\ModificationCategory;
use Logbook\Domain\Part\ModificationData;
use Logbook\Repository\VehicleShareRepository;
use Logbook\Service\Attachment\PendingUpload;
use Logbook\Service\Attachment\PendingUploads;
use Logbook\Service\Expense\ExpenseService;
use Logbook\Service\Part\ModificationService;
use Logbook\Support\Storage\FileUpload;
use Logbook\Support\Storage\UploadKind;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\CostFixtures;
use Logbook\Tests\Support\Migrator;
use Slim\Psr7\UploadedFile;

/**
 * The rollback finds each restored expense again by a marker note with a
 * random part, so a note a member typed can never take the owner's files or
 * note (Phase 50.1 rollback, #436).
 */
final class RollbackMarkerCollisionTest extends AppTestCase
{
    use CostFixtures;

    protected function tearDown(): void
    {
        Migrator::run('migrate');
        parent::tearDown();
    }

    public function testAMembersExpenseNoteCannotCaptureTheOwnersRestoredFiles(): void
    {
        $app = $this->createApp(['FEATURES_PARTS' => 'true']);
        $this->pinClock($app, '2026-10-14T10:00:00Z');
        $this->signedIn($app);
        $golf = $this->vehicle($app);
        $member = $this->createMember($app, 'logger');
        $this->service($app, VehicleShareRepository::class)
            ->insert($golf->id, $member->id, ShareLevel::Log, false, false, new DateTimeImmutable('2026-10-14'));
        // The owner's receipted expense, made a modification.
        $expense = $this->service($app, ExpenseService::class)->create(
            $golf,
            new ExpenseEntryData(new DateTimeImmutable('2026-03-01'), ExpenseCategory::Other, '450.000', 'Remap'),
            self::receipt(),
        );
        $modification = $this->service($app, ModificationService::class)->fromExpense(
            $golf,
            $expense,
            new ModificationData(
                'Stage 1 remap',
                ModificationCategory::Other,
                new DateTimeImmutable('2026-03-01'),
                null,
                '450.000',
            ),
            new DateTimeZone('UTC'),
        );
        // The member's own expense, its note the old fixed marker.
        $planted = 'logbook-rollback:modification:' . $modification->id;
        $this->browserFor($app, 'logger')->post('/vehicles/' . $golf->id . '/expenses/new', [
            'spent_on' => '2026-10-01', 'category' => 'other', 'amount' => '1', 'note' => $planted,
        ]);
        $db = $this->connection($app);
        $memberExpense = $db->fetchOne('SELECT id FROM expense_entries WHERE created_by = ?', [$member->id]);
        self::assertNotFalse($memberExpense, 'the member\'s expense was saved');

        Migrator::run('rollback', ['--target' => '20261108100000']);

        self::assertSame($planted, $db->fetchOne('SELECT note FROM expense_entries WHERE id = ?', [$memberExpense]));
        $restored = $db->fetchAssociative('SELECT id, note FROM expense_entries WHERE created_by = ?', [$golf->userId]);
        self::assertIsArray($restored);
        self::assertSame('Stage 1 remap', $restored['note']);
        self::assertEquals(
            $restored['id'],
            $db->fetchOne("SELECT owner_id FROM attachments WHERE owner_type = 'expense'"),
            'the owner\'s receipt is on the owner\'s restored expense',
        );
    }

    private static function receipt(): PendingUploads
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-upload-');
        file_put_contents($path, "%PDF-1.4\n%receipt\n");
        $file = new UploadedFile($path, 'receipt.pdf', 'application/pdf', (int) filesize($path), UPLOAD_ERR_OK);

        return new PendingUploads([new PendingUpload($file, FileUpload::check($file, 1024 * 1024, UploadKind::Document))]);
    }
}
