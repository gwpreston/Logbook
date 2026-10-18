<?php

declare(strict_types=1);

namespace Logbook\Tests\Integration\Ai\Scan;

use DateTimeImmutable;
use Logbook\Domain\Ai\AdapterType;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\Capability;
use Logbook\Domain\Ai\Location;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Service\Ai\Scan\Router;
use Logbook\Service\Ai\Scan\ScanProblem;
use Logbook\Tests\Support\ScanTestCase;

/**
 * How a photo is read (spec.md §7.27 *Routing*, *Where it goes*; Phase
 * 53.3): the vision model checked by OCR, sent as text on *Send text, not
 * images*, or read through OCR first on the Internet.
 */
final class RouterTest extends ScanTestCase
{
    private const string FAKE = __DIR__ . '/../../../Fixtures/ocr/fake-tesseract';

    public function testSendTextWithOcrWorkingReadsThroughOcr(): void
    {
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE]);
        $this->readDocumentOn(Location::Network, sendText: true);

        $route = $this->router()->picture();

        self::assertTrue($route->ocr);
        self::assertSame(AiTaskName::ReadDocument, $route->task);
        self::assertNull($route->problem);
        self::assertFalse($route->crossCheck);
    }

    public function testSendTextWithoutOcrReadsNothing(): void
    {
        $this->scanApp();
        $this->readDocumentOn(Location::Network, sendText: true);

        $route = $this->router()->picture();

        self::assertSame(ScanProblem::TextOnly, $route->problem);
        self::assertNull($route->task);
        self::assertFalse($this->router()->readsPictures());
    }

    public function testVisionOnTheInternetWithOcrIsCrossCheckedAndReadByOcrFirst(): void
    {
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE]);
        $this->readDocumentOn(Location::Internet);

        $route = $this->router()->picture();

        self::assertFalse($route->ocr);
        self::assertSame(AiTaskName::ReadDocument, $route->task);
        self::assertTrue($route->crossCheck);
        self::assertTrue($route->ocrFirst);
    }

    public function testVisionOnTheNetworkWithOcrIsCrossCheckedButNotReadByOcrFirst(): void
    {
        $this->scanApp(['TESSERACT_BINARY' => self::FAKE]);

        $route = $this->router()->picture();

        self::assertFalse($route->ocr);
        self::assertTrue($route->crossCheck);
        self::assertFalse($route->ocrFirst);
    }

    public function testVisionWithoutOcrIsPlainVision(): void
    {
        $this->scanApp();
        $this->readDocumentOn(Location::Internet);

        $route = $this->router()->picture();

        self::assertFalse($route->ocr);
        self::assertSame(AiTaskName::ReadDocument, $route->task);
        self::assertFalse($route->crossCheck);
        self::assertFalse($route->ocrFirst, 'nothing to read first with');
        self::assertTrue($this->router()->readsPictures());
    }

    private function router(): Router
    {
        return $this->service($this->app, Router::class);
    }

    /**
     * Moves `read_document` to a new vision model on a connection here.
     */
    private function readDocumentOn(Location $location, bool $sendText = false): void
    {
        $id = $this->addConnection(
            $this->app,
            'Vision',
            AdapterType::OpenAiCompatible,
            $location === Location::Internet ? 'https://api.openai.com/v1' : 'http://192.168.1.30/v1',
            $location,
            sendText: $sendText,
        );
        if ($location === Location::Internet) {
            $this->service($this->app, AiConnectionRepository::class)
                ->acknowledge($id, $this->owner->id, 'https://api.openai.com/v1', new DateTimeImmutable(self::NOW));
        }
        $this->assign($this->app, $id, 'gpt-5', AiTaskName::ReadDocument, [Capability::Json, Capability::Images]);
    }
}
