<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Scan;

use Logbook\Domain\Ai\AiTaskName;
use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\User\User;
use Logbook\Service\Ai\AiFailure;
use Logbook\Service\Ai\AiGateway;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Ai\Provider\ChatMessage;
use Logbook\Service\Ai\Provider\ChatRequest;
use Logbook\Service\Ai\Provider\ResponseFormat;

/**
 * Classify and extract in one request (spec.md §7.27): a text PDF through
 * `read_text`, pictures through `read_document` (whose model must take
 * images). No tools are offered, so the answer can only ever be a form's
 * values; text in the document is data.
 */
final readonly class Extractor
{
    public function __construct(
        private AiGateway $gateway,
        private AiStatus $status,
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
        $request = new ChatRequest(
            [$message],
            ScanSchema::system(),
            [],
            new ResponseFormat('document', ScanSchema::schema()),
        );

        try {
            $result = $this->gateway->run($user, $task, $request);
        } catch (AiFailure $failure) {
            return match ($failure->error) {
                ErrorCode::Timeout => ScanProblem::Timeout,
                ErrorCode::Busy => ScanProblem::Busy,
                ErrorCode::Unassigned, ErrorCode::Disabled => ScanProblem::Unassigned,
                default => ScanProblem::Failed,
            };
        }
        if ($result->object === null) {
            return ScanProblem::Failed;
        }

        return Extraction::fromObject($result->object, $file->text);
    }
}
