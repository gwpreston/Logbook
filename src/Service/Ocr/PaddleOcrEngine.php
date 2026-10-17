<?php

declare(strict_types=1);

namespace Logbook\Service\Ocr;

use JsonException;
use Logbook\Support\Config\AppSettings;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PaddleOCR reached over HTTP (`OCR_URL`, spec.md §9, §7.27; decided
 * 2026-10-17, #489, #493): PaddleX basic serving's general OCR pipeline,
 * `POST {OCR_URL}/ocr` with the page as base64. It answers with text lines
 * (regions) and their boxes and scores, not words, so each line's words get
 * boxes estimated from their place in the line. Its languages are chosen
 * where the service runs, not per request. Redirects are never followed.
 */
final class PaddleOcrEngine implements OcrEngine
{
    /** The most of an answer that is read; a page's lines are far smaller. */
    private const int MAX_BODY = 4 * 1024 * 1024;

    public function __construct(
        private readonly AppSettings $settings,
        private readonly HttpClientInterface $http,
    ) {
    }

    public function name(): string
    {
        return 'paddleocr';
    }

    public function isAvailable(): bool
    {
        return $this->settings->ocr->paddleOcrUrl !== '';
    }

    public function status(): OcrEngineStatus
    {
        $set = $this->settings->ocr->paddleOcrUrl !== '';

        // Shown on the admin page: never with a password in it.
        return new OcrEngineStatus($this->name(), $set, $set ? 'PaddleOCR' : '', [], $this->settings->ocr->paddleOcrAddress());
    }

    public function read(string $png, array $languages, PageLayout $layout, int $page = 1): OcrResult
    {
        if (!$this->isAvailable()) {
            return OcrResult::failed(OcrProblem::Unavailable);
        }
        $timeout = $this->settings->ocr->timeout;
        try {
            $response = $this->http->request('POST', $this->settings->ocr->paddleOcrUrl . '/ocr', [
                'json' => [
                    'file' => base64_encode($png),
                    'fileType' => 1,
                    'visualize' => false,
                    // Logbook has already turned the page upright and level.
                    'useDocUnwarping' => false,
                ],
                'timeout' => $timeout,
                'max_duration' => $timeout,
                'max_redirects' => 0,
            ]);
            if ($response->getStatusCode() !== 200) {
                return OcrResult::failed(OcrProblem::Failed, '', 'PaddleOCR');
            }
            $body = substr($response->getContent(false), 0, self::MAX_BODY);
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (TimeoutExceptionInterface) {
            return OcrResult::failed(OcrProblem::Timeout, '', 'PaddleOCR');
        } catch (ExceptionInterface | JsonException) {
            return OcrResult::failed(OcrProblem::Failed, '', 'PaddleOCR');
        }

        $lines = is_array($data) && ($data['errorCode'] ?? null) === 0 ? self::lines($data, $page) : null;

        return $lines === null
            ? OcrResult::failed(OcrProblem::Failed, '', 'PaddleOCR')
            : OcrResult::read($lines, '', 'PaddleOCR');
    }

    /**
     * The answer's text regions as lines, or null when it isn't the shape
     * PaddleX serving gives.
     *
     * @param array<mixed> $data
     * @return list<OcrLine>|null
     */
    public static function lines(array $data, int $page = 1): ?array
    {
        $result = $data['result'] ?? null;
        $result = is_array($result) && is_array($result['ocrResults'] ?? null) ? ($result['ocrResults'][0] ?? null) : null;
        $result = is_array($result) ? ($result['prunedResult'] ?? null) : null;
        if (!is_array($result) || !is_array($result['rec_texts'] ?? null)) {
            return null;
        }
        $texts = $result['rec_texts'];
        $scores = is_array($result['rec_scores'] ?? null) ? $result['rec_scores'] : [];
        $boxes = is_array($result['rec_boxes'] ?? null) ? $result['rec_boxes'] : [];

        $lines = [];
        foreach ($texts as $i => $text) {
            $box = $boxes[$i] ?? null;
            if (!is_string($text) || trim($text) === '' || !is_array($box) || count($box) !== 4) {
                continue;
            }
            [$x1, $y1, $x2, $y2] = array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) round((float) $v) : 0,
                array_values($box),
            );
            $score = $scores[$i] ?? 0;
            $confidence = max(0.0, min(100.0, (is_numeric($score) ? (float) $score : 0.0) * 100));
            $words = self::words(trim($text), $confidence, new OcrBox($x1, $y1, max(1, $x2 - $x1), max(1, $y2 - $y1)), $page);
            if ($words !== []) {
                // Each region is its own block: the service doesn't group them.
                $lines[] = new OcrLine($words, $i + 1, $page);
            }
        }

        return $lines;
    }

    /**
     * A region's words, each with a box estimated by its characters' share
     * of the region's width, and the region's score.
     *
     * @return list<OcrWord>
     */
    private static function words(string $text, float $confidence, OcrBox $box, int $page): array
    {
        $length = max(1, mb_strlen($text));
        $words = [];
        $offset = 0;
        foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $chars = mb_strlen($part);
            if (trim($part) !== '') {
                $left = $box->left + (int) round($box->width * $offset / $length);
                $width = max(1, (int) round($box->width * $chars / $length));
                $words[] = new OcrWord($part, $confidence, new OcrBox($left, $box->top, $width, $box->height), $page);
            }
            $offset += $chars;
        }

        return $words;
    }
}
