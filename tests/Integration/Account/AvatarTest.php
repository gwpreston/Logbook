<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Account;

use Logbook\Domain\User\User;
use Logbook\Repository\UserRepository;
use Logbook\Service\Backup\BackupService;
use Logbook\Service\User\AvatarService;
use Logbook\Service\User\UserAdmin;
use Logbook\Support\Storage\FileStorage;
use Logbook\Tests\Support\AppTestCase;
use Logbook\Tests\Support\ExifJpeg;
use Logbook\Tests\Support\TestBrowser;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Psr7\UploadedFile;

/**
 * Avatars (spec.md §7.9 *Avatars*, Phase 33.1): judged by content, re-encoded
 * square without metadata, served only to signed-in users, deleted with the
 * user and carried by backups.
 */
final class AvatarTest extends AppTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function testAPhotoBecomesASquareWebpWithoutMetadataShownToSignedInUsers(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $photo = ExifJpeg::make(600, 300);
        self::assertTrue(ExifJpeg::hasExif($photo));

        $saved = $browser->post('/settings/avatar', [], ['avatar' => $this->upload($photo, 'me.jpg', 'image/jpeg')]);
        self::assertSame(303, $saved->getStatusCode());
        $owner = $this->user($app, 'owner');
        self::assertNotNull($owner->avatarPath);
        self::assertStringStartsWith('avatars/', $owner->avatarPath);
        self::assertNotNull($owner->avatarUpdatedAt);

        $stored = (string) file_get_contents($this->service($app, FileStorage::class)->absolutePath($owner->avatarPath));
        $size = getimagesizefromstring($stored);
        self::assertIsArray($size);
        self::assertSame([AvatarService::EDGE, AvatarService::EDGE], [$size[0], $size[1]], 'cropped square');
        self::assertContains($size['mime'], ['image/webp', 'image/jpeg']);
        self::assertFalse(ExifJpeg::hasExif($stored), 'no EXIF, so no GPS position');

        $page = self::body($browser->get('/settings'));
        self::assertStringContainsString('/users/' . $owner->id . '/avatar?v=', $page, 'in the sidebar and on Account');
        $this->createMember($app);
        $partner = $this->browserFor($app, 'partner');
        $picture = $partner->get('/users/' . $owner->id . '/avatar?v=1');
        self::assertSame(200, $picture->getStatusCode(), 'any signed-in user (#161)');
        self::assertSame('private, max-age=31536000, immutable', $picture->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $picture->getHeaderLine('X-Content-Type-Options'));
        self::assertSame($stored, (string) $picture->getBody());

        $guest = (new TestBrowser($app))->get('/users/' . $owner->id . '/avatar');
        self::assertSame(303, $guest->getStatusCode(), 'signed-out: to sign-in, not the picture');
        self::assertStringContainsString('/login', $guest->getHeaderLine('Location'));
        self::assertSame(
            404,
            $partner->get('/users/' . $this->user($app, 'partner')->id . '/avatar')->getStatusCode(),
            'none yet',
        );
        self::assertStringContainsString(
            'class="avatar avatar--sm avatar--tone-',
            self::body($partner->get('/settings')),
            'initials instead',
        );

        $old = $owner->avatarPath;
        $browser->post('/settings/avatar', [], ['avatar' => $this->upload(self::png(50, 80), 'new.png')]);
        $replaced = $this->user($app, 'owner');
        self::assertNotSame($old, $replaced->avatarPath);
        self::assertFileDoesNotExist($this->service($app, FileStorage::class)->absolutePath($old), 'the old file goes');

        $browser->post('/settings/avatar/remove');
        self::assertNull($this->user($app, 'owner')->avatarPath);
        self::assertFileDoesNotExist($this->service($app, FileStorage::class)->absolutePath((string) $replaced->avatarPath));
    }

    public function testOnlyRealImagesWithinTheLimitsAreTaken(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $cases = [
            'PHP named .jpg' => $this->upload("<?php echo 'hi';", 'evil.jpg', 'image/jpeg'),
            'SVG' => $this->upload('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>', 'me.svg', 'image/svg+xml'),
            'over 5 MB' => $this->upload(self::png(10, 10) . str_repeat("\0", 6 * 1024 * 1024), 'big.png'),
            'a decompression bomb' => $this->upload(self::hugePng(), 'bomb.png'),
            'nothing chosen' => new UploadedFile(
                (string) tempnam(sys_get_temp_dir(), 'none'),
                '',
                'application/octet-stream',
                0,
                UPLOAD_ERR_NO_FILE,
            ),
        ];
        foreach ($cases as $case => $file) {
            $answer = $browser->post('/settings/avatar', [], ['avatar' => $file]);
            self::assertSame(422, $answer->getStatusCode(), $case);
            self::assertNull($this->user($app, 'owner')->avatarPath, $case);
        }
        $big = $this->upload(self::png(10, 10) . str_repeat("\0", 6 * 1024 * 1024), 'big.png');
        $answer = $browser->post('/settings/avatar', [], ['avatar' => $big]);
        self::assertStringContainsString('The file is too large (maximum 5 MB).', self::body($answer));
    }

    public function testItGoesWithTheUserAndComesBackWithABackup(): void
    {
        $app = $this->createApp();
        $browser = $this->signedIn($app);
        $partner = $this->createMember($app);
        $this->browserFor($app, 'partner')->post('/settings/avatar', [], ['avatar' => $this->upload(self::png(64, 64), 'p.png')]);
        $browser->post('/settings/avatar', [], ['avatar' => $this->upload(self::png(64, 64), 'o.png')]);
        $files = $this->service($app, FileStorage::class);
        $owner = $this->user($app, 'owner');
        $ownerPath = (string) $owner->avatarPath;
        $partnerPath = (string) $this->user($app, 'partner')->avatarPath;

        $backups = $this->service($app, BackupService::class);
        $zip = (string) tempnam(sys_get_temp_dir(), 'logbook-backup-');
        $this->tempFiles[] = $zip;
        $backups->create($zip);
        self::assertTrue(self::zipHas($zip, 'uploads/' . $ownerPath), 'avatars are in the backup');

        $export = (string) tempnam(sys_get_temp_dir(), 'logbook-export-');
        $this->tempFiles[] = $export;
        $backups->createForUser($export, $owner);
        self::assertTrue(self::zipHas($export, 'uploads/' . $ownerPath), 'and in one user’s export');
        self::assertFalse(self::zipHas($export, 'uploads/' . $partnerPath), 'not someone else’s');

        self::assertNull($this->service($app, UserAdmin::class)->delete($owner, $this->user($app, 'partner')));
        self::assertFileDoesNotExist($files->absolutePath($partnerPath), 'deleted with the user');

        unlink($files->absolutePath($ownerPath));
        $backups->restore($zip);
        self::assertFileExists($files->absolutePath($ownerPath), 'restored');
        self::assertSame($ownerPath, $this->user($app, 'owner')->avatarPath);
        self::assertSame($partner->id, $this->user($app, 'partner')->id, 'the backup had them');
    }

    /**
     * @param App<ContainerInterface> $app
     */
    private function user(App $app, string $username): User
    {
        $user = $this->service($app, UserRepository::class)->findByUsername($username);
        self::assertNotNull($user);

        return $user;
    }

    private function upload(string $contents, string $name, string $type = 'image/png'): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'logbook-upload-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, $type, strlen($contents), UPLOAD_ERR_OK);
    }

    private static function zipHas(string $path, string $name): bool
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $found = $zip->locateName($name) !== false;
        $zip->close();

        return $found;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 40, 40));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * A tiny PNG that claims to be 10,000 × 10,000 pixels: refused before
     * anything tries to decode it.
     */
    private static function hugePng(): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data
            . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', 10000, 10000, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress(str_repeat("\0", 64)))
            . $chunk('IEND', '');
    }
}
