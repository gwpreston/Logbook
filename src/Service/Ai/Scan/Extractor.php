<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\User\User;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\AiGateway;
use Logbook\Service\Ai\AiSession;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Ai\Provider\ChatMessage;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\ResponseFormat;
use Logbook\Service\Ocr\OcrProblem;
use Logbook\Service\Ocr\OcrReader;

/**
 * Classify and extract in one request (spec.md §7.27): a text PDF through
 * `read_text`, pictures through `read_document` (whose model must take
 * images), or, with no vision model, OCR's text through a text model
 * (Phase 53.2, extractViaOcr()). No tools are offered, so the answer can
 * only ever be a form's values; text in the document is data.
 */
final readonly class Extractor
{
    public function __construct(
        private AiGateway $gateway,
        private AiStatus $status,
        private AiConnectionRepository $connections,
        private OcrReader $ocr,
    ) {
    }

    public function task(PreparedFile $file): AiTaskName
    {
        return $file->isText() ? AiTaskName::ReadText : AiTaskName::ReadDocument;
    }

    public function extract(User $user, PreparedFile $file): Extraction|ScanProblem
    {
        if ($file->problem !== null) {
            return $file->problem;
        }
        $task = $this->task($file);
        $model = $this->status->model($task);
        if ($model === null) {
            return ScanProblem::Unassigned;
        }
        if (!$file->isText() && !$model->images) {
            return ScanProblem::NoVision;
        }

        $message = $file->isText()
            ? ChatMessage::user("The document's text, between the markers:\n<<<\n" . $file->text . "\n>>>")
            : ChatMessage::user(
                count($file->images) > 1 ? 'The document, one image per page.' : 'The document.',
                $file->images,
            );

        try {
            $result = $this->gateway->run($user, $task, self::request($message));
        } catch (AiFailure $failure) {
            return self::problem($failure);
        }
        if ($result->object === null) {
            return ScanProblem::Failed;
        }
        $connection = $this->connections->find($model->connectionId);

        return Extraction::fromObject($result->object, $file->text)
            ->withReadBy(new ReadBy($model->name, $connection->name ?? ''));
    }

    /**
     * OCR reads the file, then $task's text model reads OCR's text (spec.md
     * §7.27 *Reading through OCR*). The user's lock is held once for both,
     * for OCR's longest time plus the model's timeout.
     */
    public function extractViaOcr(User $user, string $path, string $mime, AiTaskName $task): Extraction|ScanProblem
    {
        $locale = $user->preferences->locale;
        try {
            return $this->gateway->session(
                $user,
                $task,
                $this->ocr->longestSeconds(),
                function (AiSession $session) use ($path, $mime, $locale): Extraction|ScanProblem {
                    $engine = $this->ocr->engine()?->name();
                    $read = $this->ocr->readHeld($path, $mime, $locale);
                    if (!$read->ok() || $engine === null) {
                        return match ($read->problem) {
                            OcrProblem::Timeout => ScanProblem::Timeout,
                            OcrProblem::Busy => ScanProblem::Busy,
                            OcrProblem::TooLarge, OcrProblem::Unreadable => ScanProblem::Unreadable,
                            OcrProblem::Unavailable => ScanProblem::NoVision,
                            default => ScanProblem::Failed,
                        };
                    }
                    $text = OcrText::of($read);
                    if ($text->isTooLittle()) {
                        return ScanProblem::TooLittleText;
                    }
                    $result = $session->chat(self::request(ChatMessage::user($text->message())));
                    if ($result->object === null) {
                        return ScanProblem::Failed;
                    }

                    return Extraction::fromObject($result->object, $text)
                        ->withReadBy(new ReadBy($session->model, $session->connection->name, $engine));
                },
            );
        } catch (AiFailure $failure) {
            return self::problem($failure);
        }
    }

    private static function request(ChatMessage $message): ChatRequest
    {
        return new ChatRequest(
            [$message],
            ScanSchema::system(),
            [],
            new ResponseFormat('document', ScanSchema::schema()),
        );
    }

    private static function problem(AiFailure $failure): ScanProblem
    {
        return match ($failure->error) {
            ErrorCode::Timeout => ScanProblem::Timeout,
            ErrorCode::Busy => ScanProblem::Busy,
            ErrorCode::Unassigned, ErrorCode::Disabled => ScanProblem::Unassigned,
            default => ScanProblem::Failed,
        };
    }
}
