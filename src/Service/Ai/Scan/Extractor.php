<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\User\User;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiRequestRepository;
use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\AiGateway;
use Logbook\Service\Ai\AiSession;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Ai\Provider\ChatMessage;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\ResponseFormat;
use Logbook\Service\Ocr\OcrProblem;
use Logbook\Service\Ocr\OcrReader;
use Logbook\Service\Ocr\OcrResult;

/**
 * Classify and extract in one request (spec.md §7.27): a text PDF through
 * `read_text`, pictures through `read_document` (whose model must take
 * images), or, with no vision model, OCR's text through a text model
 * (Phase 53.2, extractViaOcr()). With OCR working, a vision read is
 * checked against OCR's text of the same file (Phase 53.3,
 * extractChecked()). No tools are offered, so the answer can only ever be
 * a form's values; text in the document is data.
 */
final readonly class Extractor
{
    public function __construct(
        private AiGateway $gateway,
        private AiStatus $status,
        private AiConnectionRepository $connections,
        private OcrReader $ocr,
        private AiRequestRepository $requests,
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
        $model = $this->status->isOnFor($user) ? $this->status->model($task) : null;
        if ($model === null) {
            // Never a model for a user who switched AI off (#518).
            return ScanProblem::Unassigned;
        }
        if (!$file->isText() && !$model->images) {
            return ScanProblem::NoVision;
        }

        $message = $file->isText()
            ? ChatMessage::user(self::between("The document's text, between the markers:", (string) $file->text))
            : self::pictures($file);

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
        if (!$this->status->isOnFor($user)) {
            return ScanProblem::Unassigned;
        }
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
                        return self::ocrProblem($read);
                    }
                    $text = OcrText::of($read);
                    if ($text->isTooLittle()) {
                        return ScanProblem::TooLittleText;
                    }

                    return $this->fromText($session, $text, $engine);
                },
            );
        } catch (AiFailure $failure) {
            return self::problem($failure);
        }
    }

    /**
     * The vision model reads the pictures while OCR reads the same file
     * alongside, and each value is then looked for in OCR's text (spec.md
     * §7.27 *The cross-check*, #508). With $ocrFirst (an *Internet*
     * connection) OCR finishes before anything is sent, and a registration
     * document goes as its redacted text instead, or, when OCR read too
     * little of it, not at all (#510). OCR failing or
     * reading too little skips the check; the scan carries on.
     */
    public function extractChecked(
        User $user,
        string $path,
        string $mime,
        PreparedFile $file,
        bool $ocrFirst,
    ): Extraction|ScanProblem {
        if ($file->problem !== null) {
            return $file->problem;
        }
        $model = $this->status->isOnFor($user) ? $this->status->model(AiTaskName::ReadDocument) : null;
        if ($model === null) {
            return ScanProblem::Unassigned;
        }
        if (!$model->images) {
            return ScanProblem::NoVision;
        }
        $locale = $user->preferences->locale;
        try {
            return $this->gateway->session(
                $user,
                AiTaskName::ReadDocument,
                $this->ocr->longestSeconds(),
                function (AiSession $session) use ($path, $mime, $file, $locale, $ocrFirst): Extraction|ScanProblem {
                    $engine = $this->ocr->engine()?->name();
                    $job = $this->ocr->begin($path, $mime, $locale);
                    if ($ocrFirst) {
                        $read = $job->result();
                        $text = $read->ok() ? OcrText::of($read) : null;
                        if ($text !== null && $text->looksLikeRegistration()) {
                            // A V5C never goes as a picture here, even when OCR read too little (#510).
                            return $text->isTooLittle() || $engine === null
                                ? ScanProblem::TooLittleText
                                : $this->fromText($session, $text, $engine);
                        }
                    }
                    $result = $session->chat(self::request(self::pictures($file)));
                    if ($result->object === null) {
                        return ScanProblem::Failed;
                    }
                    $read = Extraction::fromObject($result->object)
                        ->withReadBy(new ReadBy($session->model, $session->connection->name));
                    $text = self::usable($job->result());
                    if ($text === null) {
                        return $read;
                    }
                    $read = $read->crossChecked($text);
                    if ($result->requestId !== null && $read->crossCheck !== null) {
                        $this->requests->setScanCounts(
                            $result->requestId,
                            $read->crossCheck['found'],
                            $read->crossCheck['flagged'],
                            $read->crossCheck['unchecked'],
                        );
                    }

                    return $read;
                },
            );
        } catch (AiFailure $failure) {
            return self::problem($failure);
        }
    }

    /**
     * OCR's text through the session's model, as the document's text.
     */
    private function fromText(AiSession $session, OcrText $text, string $engine): Extraction|ScanProblem
    {
        $result = $session->chat(self::request(ChatMessage::user($text->message())));
        if ($result->object === null) {
            return ScanProblem::Failed;
        }

        return Extraction::fromObject($result->object, $text)
            ->withReadBy(new ReadBy($session->model, $session->connection->name, $engine));
    }

    /**
     * OCR's text when it read enough to check against, else null.
     */
    private static function usable(OcrResult $read): ?OcrText
    {
        if (!$read->ok()) {
            return null;
        }
        $text = OcrText::of($read);

        return $text->isTooLittle() ? null : $text;
    }

    private static function pictures(PreparedFile $file): ChatMessage
    {
        return ChatMessage::user(
            count($file->images) > 1 ? 'The document, one image per page.' : 'The document.',
            $file->images,
        );
    }

    private static function ocrProblem(OcrResult $read): ScanProblem
    {
        return ScanProblem::fromOcr($read->problem);
    }

    /**
     * The document's text between markers it can't contain (K120): each
     * marker carries a hash of the text, so a `>>>` in the document doesn't
     * end the block.
     */
    public static function between(string $lead, string $text): string
    {
        $tag = 'document ' . substr(hash('sha256', $text), 0, 12);

        return $lead . "\n<<<" . $tag . "\n" . $text . "\n>>>" . $tag;
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
