<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Service\Ocr;

use Logbook\Service\Ocr\OcrLine;
use Logbook\Service\Ocr\OcrProblem;
use Logbook\Service\Ocr\PageLayout;
use Logbook\Service\Ocr\PaddleOcrEngine;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * PaddleOCR over HTTP (spec.md §7.27, Phase 53.1; #489, #493): the request
 * PaddleX serving takes, its lines read into OcrLines, and every failure a
 * clean result.
 */
final class PaddleOcrEngineTest extends TestCase
{
    /** An answer captured from PaddleX 3.7.2 basic serving (PP-OCRv6), trimmed. */
    private const string ANSWER = '{"logId":"f705a993","result":{"ocrResults":[{"prunedResult":'
        . '{"rec_texts":["Total 42,50 EUR","Diesel 31.250 L",""],'
        . '"rec_scores":[0.9991989135742188,0.9921611547470093,0.1],"rec_boxes":[[0,15,327,56],[0,80,318,121],[5,5,6,6]]}}],'
        . '"dataInfo":{"width":600,"height":140,"type":"image"}},"errorCode":0,"errorMsg":"Success"}';

    /** @var list<array{string, string, array<mixed>}> */
    private array $requests = [];

    /**
     * @param MockResponse|\Closure(): MockResponse $response
     */
    private function engine(MockResponse|\Closure $response, string $url = 'http://paddleocr:8080'): PaddleOcrEngine
    {
        $settings = AppSettings::fromEnv(new Env(['OCR_URL' => $url, 'OCR_TIMEOUT' => '7']), '/app');
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = [$method, $url, $options];

            return $response instanceof \Closure ? $response() : $response;
        });

        return new PaddleOcrEngine($settings, $client);
    }

    public function testAPageIsSentAsBase64AndItsLinesComeBack(): void
    {
        $result = $this->engine(new MockResponse(self::ANSWER))->read('png bytes', ['deu', 'eng'], PageLayout::Receipt, 2);

        self::assertTrue($result->ok());
        $texts = array_map(static fn (OcrLine $l): string => $l->text, $result->lines);
        self::assertSame(['Total 42,50 EUR', 'Diesel 31.250 L'], $texts);
        self::assertSame("Total 42,50 EUR\nDiesel 31.250 L", $result->layoutText);
        self::assertSame('PaddleOCR', $result->engineVersion);
        self::assertSame(2, $result->lines[0]->page);
        self::assertEqualsWithDelta(99.92, $result->words[0]->confidence, 0.01);

        [$method, $url, $options] = $this->requests[0];
        self::assertSame('POST', $method);
        self::assertSame('http://paddleocr:8080/ocr', $url);
        self::assertIsString($options['body']);
        $body = json_decode($options['body'], true);
        $sent = ['file' => base64_encode('png bytes'), 'fileType' => 1, 'visualize' => false, 'useDocUnwarping' => false];
        self::assertSame($sent, $body);
        self::assertEquals(7, $options['timeout']);
        self::assertSame(0, $options['max_redirects']);
    }

    public function testWordsGetBoxesFromTheirPlaceInTheLine(): void
    {
        $lines = PaddleOcrEngine::lines(json_decode(self::ANSWER, true));

        self::assertNotNull($lines);
        $words = $lines[0]->words;
        self::assertSame(['Total', '42,50', 'EUR'], array_map(static fn ($w): string => $w->text, $words));
        self::assertSame(0, $words[0]->box->left);
        self::assertSame(109, $words[0]->box->width);
        self::assertSame(131, $words[1]->box->left);
        self::assertSame(15, $words[2]->box->top);
        self::assertSame(41, $words[2]->box->height);
    }

    public function testAnAnswerOfTheWrongShapeIsNotRead(): void
    {
        self::assertNull(PaddleOcrEngine::lines([]));
        self::assertNull(PaddleOcrEngine::lines(['result' => ['ocrResults' => [['prunedResult' => ['rec_texts' => 'x']]]]]));
        $badBox = ['prunedResult' => ['rec_texts' => ['a'], 'rec_boxes' => [[1, 2]]]];
        self::assertSame([], PaddleOcrEngine::lines(['result' => ['ocrResults' => [$badBox]]]));
    }

    public function testErrorsAndTimeoutsAreCleanResults(): void
    {
        $cases = [
            'error status' => [
                new MockResponse('{"errorCode":422,"errorMsg":"Invalid input file"}', ['http_code' => 422]),
                OcrProblem::Failed,
            ],
            'error code' => [new MockResponse('{"errorCode":500,"errorMsg":"x"}'), OcrProblem::Failed],
            'not json' => [new MockResponse('<html>'), OcrProblem::Failed],
            'wrong shape' => [new MockResponse('{"errorCode":0,"result":{}}'), OcrProblem::Failed],
            'unreachable' => [new MockResponse('', ['error' => 'Could not resolve host']), OcrProblem::Failed],
            'timeout' => [static fn (): MockResponse => throw new TimeoutException('Idle timeout reached'), OcrProblem::Timeout],
        ];
        foreach ($cases as $name => [$response, $problem]) {
            $result = $this->engine($response)->read('png', ['eng'], PageLayout::Page);
            self::assertSame($problem, $result->problem, $name);
        }
    }

    public function testWithNoAddressItIsUnavailable(): void
    {
        $engine = $this->engine(new MockResponse(self::ANSWER), '');

        self::assertFalse($engine->isAvailable());
        self::assertFalse($engine->status()->available);
        self::assertSame(OcrProblem::Unavailable, $engine->read('png', ['eng'], PageLayout::Page)->problem);
        self::assertSame([], $this->requests);
    }

    public function testStatusNamesTheServiceWithoutItsPassword(): void
    {
        $status = $this->engine(new MockResponse(self::ANSWER))->status();
        $secret = $this->engine(new MockResponse(self::ANSWER), 'http://user:s3cret@paddle_ocr:8080')->status();

        self::assertTrue($status->available);
        self::assertSame('paddleocr', $status->engine);
        self::assertSame('http://paddleocr:8080', $status->location);
        self::assertSame('http://paddle_ocr:8080', $secret->location);
    }
}
