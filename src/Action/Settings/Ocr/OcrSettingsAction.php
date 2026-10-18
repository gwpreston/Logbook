<?php

declare(strict_types=1);

namespace Logbook\Action\Settings\Ocr;

use Logbook\Service\Ocr\OcrReader;
use Logbook\Service\Ocr\OcrSampleTest;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Http\LongRequest;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/ocr — *Reading text from images* (spec.md §7.27,
 * Phase 53.1), admins only: the engine `OCR_ENGINE` picks, its version and
 * languages, and *Test it*, which posts here and shows what the sample
 * receipt read as. Linked from Settings → AI, and from Settings'
 * Installation group when AI is off: OCR isn't an AI feature.
 */
final readonly class OcrSettingsAction
{
    public function __construct(
        private OcrReader $reader,
        private OcrSampleTest $sample,
        private AppSettings $settings,
        private View $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $test = null;
        if ($request->getMethod() === 'POST') {
            // PaddleOCR on a CPU can take a while: as long as a page may, plus room.
            LongRequest::allow($this->settings->ocr->timeout + 30);
            $test = $this->sample->run(RequestContext::requireUser($request)->id, RequestContext::locale($request));
        }

        return $this->view->render($request, $response, 'settings/ocr.twig', [
            'choice' => $this->settings->ocr->engine->value,
            'status' => $this->reader->status(),
            'available' => $this->reader->isWorking(),
            // Found but not answering (K73): a broken install, not a missing one.
            'found' => $this->reader->isAvailable(),
            'timeout' => $this->settings->ocr->timeout,
            'test' => $test,
            'ai_enabled' => $this->settings->ai->enabled,
        ]);
    }
}
