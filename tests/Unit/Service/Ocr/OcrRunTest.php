<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ocr;

use Logbook\Service\Ocr\OcrBox;
use Logbook\Service\Ocr\OcrJob;
use Logbook\Service\Ocr\OcrLine;
use Logbook\Service\Ocr\OcrProblem;
use Logbook\Service\Ocr\OcrResult;
use Logbook\Service\Ocr\OcrRun;
use Logbook\Service\Ocr\OcrWord;
use PHPUnit\Framework\TestCase;

/**
 * A page's read started in the background and a file's pages collected
 * (spec.md §7.27 *The cross-check*, Phase 53.3).
 */
final class OcrRunTest extends TestCase
{
    public function testADoneRunIsItsResultAndNeverAbandons(): void
    {
        $result = self::page('one', 1);

        self::assertSame($result, OcrRun::done($result)->result());
    }

    public function testTheCollectorRunsOnceAndItsResultIsKept(): void
    {
        $calls = 0;
        $run = new OcrRun(static function () use (&$calls): OcrResult {
            $calls++;

            return self::page('one', 1);
        });

        self::assertSame($run->result(), $run->result());
        self::assertSame(1, $calls);
    }

    public function testARunNobodyCollectedIsAbandonedWhenDestroyed(): void
    {
        $abandoned = 0;
        $run = new OcrRun(static fn (): OcrResult => self::page('one', 1), static function () use (&$abandoned): void {
            $abandoned++;
        });
        unset($run);

        self::assertSame(1, $abandoned);
    }

    public function testACollectedRunIsNotAbandoned(): void
    {
        $abandoned = 0;
        $run = new OcrRun(static fn (): OcrResult => self::page('one', 1), static function () use (&$abandoned): void {
            $abandoned++;
        });
        $run->result();
        unset($run);

        self::assertSame(0, $abandoned);
    }

    public function testAllPagesOfAJobAreJoined(): void
    {
        $job = new OcrJob(OcrRun::done(self::page('one', 1)), 3, static fn (int $i): OcrResult => self::page("p$i", $i + 1));

        $result = $job->result();

        self::assertTrue($result->ok());
        self::assertSame(['one', 'p1', 'p2'], array_map(static fn (OcrLine $l): string => $l->words[0]->text, $result->lines));
        self::assertSame($result, $job->result(), 'collected once');
    }

    public function testPagesStartedWithTheJobAreCollectedNotReadAgain(): void
    {
        $read = [];
        $started = [
            1 => new OcrRun(static fn (): OcrResult => self::page('started1', 2)),
            2 => OcrRun::done(self::page('started2', 3)),
        ];
        $job = new OcrJob(OcrRun::done(self::page('one', 1)), 3, static function (int $i) use (&$read): OcrResult {
            $read[] = $i;

            return self::page("late$i", $i + 1);
        }, $started);

        $texts = array_map(static fn (OcrLine $l): string => $l->words[0]->text, $job->result()->lines);

        self::assertSame(['one', 'started1', 'started2'], $texts);
        self::assertSame([], $read);
    }

    public function testARunIsSettledOnlyWhenItsResultIsKnownWithoutWaiting(): void
    {
        self::assertTrue(OcrRun::done(OcrResult::failed(OcrProblem::Unavailable))->settled());
        self::assertFalse((new OcrRun(static fn (): OcrResult => self::page('one', 1)))->settled());
    }

    public function testAFailedLaterPageKeepsThePagesAlreadyRead(): void
    {
        $read = [];
        $job = new OcrJob(OcrRun::done(self::page('one', 1)), 3, static function (int $i) use (&$read): OcrResult {
            $read[] = $i;

            return $i === 1 ? OcrResult::failed(OcrProblem::Timeout) : self::page("p$i", $i + 1);
        });

        $result = $job->result();

        self::assertTrue($result->ok());
        self::assertSame(['one'], array_map(static fn (OcrLine $l): string => $l->words[0]->text, $result->lines));
        self::assertSame([1], $read, 'later pages are not tried');
    }

    public function testAFailedFirstPageIsTheResult(): void
    {
        $failed = OcrResult::failed(OcrProblem::Failed);
        $job = new OcrJob(OcrRun::done($failed), 3, static fn (int $i): OcrResult => self::page("p$i", $i + 1));

        self::assertSame($failed, $job->result());
        self::assertSame(OcrProblem::Failed, OcrJob::failed($failed)->result()->problem);
    }

    private static function page(string $text, int $page): OcrResult
    {
        $word = new OcrWord($text, 90.0, new OcrBox(10, 10, 50, 20), $page);

        return OcrResult::read([new OcrLine([$word], 1, $page)], 'eng', 'tesseract 5.3.0');
    }
}
