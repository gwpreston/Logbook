<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\AiConnection;
use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\Location;
use Logbook\Domain\User\User;
use Logbook\Repository\AiConnectionRepository;
use Logbook\Repository\AiRequestRepository;
use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\AiGateway;
use Logbook\Service\Ai\AiSession;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Ai\ConnectionLocator;
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
 * a form's values; text in the document is data. A *Scan the V5C* scan
 * ($registration, Phase 53.8, #511) gets the wide redaction on every route
 * and never goes to an *Internet* connection as a picture.
 */
final readonly class Extractor
{
    public function __construct(
        private AiGateway $gateway,
        private AiStatus $status,
        private AiConnectionRepository $connections,
        private OcrReader $ocr,
        private AiRequestRepository $requests,
        private ConnectionLocator $locator,
    ) {
    }

    public function task(PreparedFile $file): AiTaskName
    {
        return $file->isText() ? AiTaskName::ReadText : AiTaskName::ReadDocument;
    }

    public function extract(User $user, PreparedFile $file, bool $registration = false): Extraction|ScanProblem
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
        $connection = $this->connections->find($model->connectionId);
        if (!$file->isText() && $registration && ($connection === null || $this->onInternet($connection))) {
            // No OCR to read it on this server: a V5C scanned for its vehicle never goes as a picture (#511).
            return ScanProblem::RegistrationTextOnly;
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

        return Extraction::fromObject($result->object, $file->text, $registration)
            ->withReadBy(new ReadBy($model->name, $connection->name ?? ''));
    }

    /**
     * OCR reads the file, then $task's text model reads OCR's text (spec.md
     * §7.27 *Reading through OCR*). The user's lock is held once for both,
     * for OCR's longest time plus the model's timeout.
     */
    public function extractViaOcr(
        User $user,
        string $path,
        string $mime,
        AiTaskName $task,
        bool $registration = false,
    ): Extraction|ScanProblem {
        if (!$this->status->isOnFor($user)) {
            return ScanProblem::Unassigned;
        }
        $locale = $user->preferences->locale;
        try {
            return $this->gateway->session(
                $user,
                $task,
                $this->ocr->longestSeconds(),
                function (AiSession $session) use ($path, $mime, $locale, $registration): Extraction|ScanProblem {
                    $engine = $this->ocr->engine()?->name();
                    $read = $this->ocr->readHeld($path, $mime, $locale);
                    if (!$read->ok() || $engine === null) {
                        return self::ocrProblem($read);
                    }
                    $text = OcrText::of($read, $registration);
                    if ($text->isTooLittle()) {
                        return ScanProblem::TooLittleText;
                    }

                    return $this->fromText($session, $text, $engine, $registration);
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
     * reading too little skips the check; the scan carries on. The
     * connection counts as *Internet* when its host resolves there now,
     * whatever was stored (#530, K112). A *Scan the V5C* scan
     * ($registration) on an *Internet* connection always goes as text, or
     * not at all (#511).
     */
    public function extractChecked(
        User $user,
        string $path,
        string $mime,
        PreparedFile $file,
        bool $ocrFirst,
        bool $registration = false,
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
                function (
                    AiSession $session,
                ) use (
                    $path,
                    $mime,
                    $file,
                    $locale,
                    $ocrFirst,
                    $registration,
                ): Extraction|ScanProblem {
                    $engine = $this->ocr->engine()?->name();
                    $job = $this->ocr->begin($path, $mime, $locale);
                    if ($ocrFirst || $this->onInternet($session->connection)) {
                        $read = $job->result();
                        $text = $read->ok() ? OcrText::of($read, $registration) : null;
                        if ($registration && ($text === null || $text->isTooLittle() || $engine === null)) {
                            return ScanProblem::RegistrationTextOnly;
                        }
                        if ($text !== null && $text->mentionsRegistration()) {
                            // A V5C never goes as a picture here, even when OCR read too little (#510).
                            return $text->isTooLittle() || $engine === null
                                ? ScanProblem::TooLittleText
                                : $this->fromText($session, $text, $engine, $registration);
                        }
                    }
                    $result = $session->chat(self::request(self::pictures($file)));
                    if ($result->object === null) {
                        return ScanProblem::Failed;
                    }
                    $read = Extraction::fromObject($result->object, null, $registration)
                        ->withReadBy(new ReadBy($session->model, $session->connection->name));
                    $text = self::usable($job->result(), $registration);
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
    private function fromText(AiSession $session, OcrText $text, string $engine, bool $registration): Extraction|ScanProblem
    {
        $result = $session->chat(self::request(ChatMessage::user($text->message())));
        if ($result->object === null) {
            return ScanProblem::Failed;
        }

        return Extraction::fromObject($result->object, $text, $registration)
            ->withReadBy(new ReadBy($session->model, $session->connection->name, $engine));
    }

    /**
     * OCR's text when it read enough to check against, else null.
     */
    private static function usable(OcrResult $read, bool $registration): ?OcrText
    {
        if (!$read->ok()) {
            return null;
        }
        $text = OcrText::of($read, $registration);

        return $text->isTooLittle() ? null : $text;
    }

    /**
     * Whether $connection is on the *Internet*: as stored, or as its host
     * resolves now (#530, K112), since the gateway corrects a stale
     * location only when it sends.
     */
    private function onInternet(AiConnection $connection): bool
    {
        return $connection->location === Location::Internet
            || $this->locator->locate($connection->baseUrl)->location === Location::Internet;
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
