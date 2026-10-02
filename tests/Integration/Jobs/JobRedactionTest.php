<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Jobs;

use Logbook\Domain\Job\JobTrigger;
use Logbook\Repository\JobRunRepository;
use Logbook\Service\Jobs\Job;
use Logbook\Service\Jobs\JobContext;
use Logbook\Service\Jobs\JobResult;
use Logbook\Service\Jobs\JobRunner;
use Logbook\Tests\Support\AiTestCase;

/**
 * No secret in a job's stored or printed output (spec.md §5 *Jobs*,
 * *Redaction*; acceptance criterion 4): an SMTP password from the
 * environment, a stored AI connection key and a Logbook API key.
 */
final class JobRedactionTest extends AiTestCase
{
    public function testSecretsAreMaskedInStoredAndPrintedLines(): void
    {
        $app = $this->aiApp(['MAIL_PASSWORD' => 'smtp-hunter2-pass', 'APP_URL' => 'https://garage.example']);
        $this->resetDatabase($app);
        $this->cloud($app, 'sk-proj-abcdefghijklmnop');
        $apiKey = 'lbk_Zx9aQ2wErTy-UiOp_1234567890abcdef';
        $job = new class ($apiKey) implements Job {
            public function __construct(private string $apiKey)
            {
            }

            public function name(): string
            {
                return 'reminders';
            }

            public function interval(): int
            {
                return 0;
            }

            public function run(JobContext $context): JobResult
            {
                $context->logger->info('SMTP login with smtp-hunter2-pass failed');
                $context->logger->warning('Provider said: key sk-proj-abcdefghijklmnop is invalid');
                $context->logger->info('Called with {key}', ['key' => $this->apiKey]);

                return JobResult::ok('Done with ' . $this->apiKey);
            }
        };

        $printed = [];
        $sink = static function (string $line) use (&$printed): void {
            $printed[] = $line;
        };
        $run = $this->service($app, JobRunner::class)->run($job, JobTrigger::Manual, null, $sink);
        $stored = $this->service($app, JobRunRepository::class)->find($run->id);
        self::assertNotNull($stored);

        $texts = ['stored' => $stored->output . "\n" . $stored->summary, 'printed' => implode("\n", $printed)];
        foreach ($texts as $where => $text) {
            foreach (['smtp-hunter2-pass', 'sk-proj-abcdefghijklmnop', $apiKey, 'lbk_'] as $secret) {
                self::assertStringNotContainsString($secret, $text, $where . ': ' . $secret);
            }
            self::assertStringContainsString('SMTP login with •••• failed', $text, $where);
            self::assertStringContainsString('key •••• is invalid', $text, $where);
            self::assertStringContainsString('Called with ••••', $text, $where);
        }
        self::assertSame('Done with ••••', $stored->summary);
    }
}
