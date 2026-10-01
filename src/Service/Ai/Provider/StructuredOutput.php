<?php

declare(strict_types=1);

namespace Logbook\Service\Ai\Provider;

use Logbook\Domain\Ai\ErrorCode;
use Logbook\Domain\Ai\JsonMode;
use Logbook\Support\Json\SchemaCheck;

/**
 * The object out of a structured answer, whatever the mode (spec.md §7.25
 * *Structured output*): the forced tool call's arguments, or the text as
 * JSON (a Markdown code fence around it is tolerated). Every object
 * passes the same schema check.
 */
final class StructuredOutput
{
    /**
     * @throws ProviderError when there is no object or it does not fit the schema
     */
    public static function extract(ChatResult $result, ResponseFormat $format): ChatResult
    {
        $object = null;
        if ($format->mode === JsonMode::Tool) {
            foreach ($result->toolCalls as $call) {
                if ($call->name === $format->name) {
                    $object = $call->arguments;
                    break;
                }
            }
        } else {
            $object = self::decode($result->text);
        }

        if ($object === null) {
            throw new ProviderError(ErrorCode::BadResponse, sprintf(
                'Expected a JSON object (%s) but the model answered: %s',
                $format->mode->value,
                mb_substr(trim($result->text), 0, 200),
            ));
        }
        $errors = SchemaCheck::errors($object, $format->schema);
        if ($errors !== []) {
            throw new ProviderError(ErrorCode::BadResponse, 'The JSON object does not fit the schema: '
                . implode('; ', array_slice($errors, 0, 5)));
        }

        return $result->withObject($object);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $text): ?array
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m) === 1) {
            $text = $m[1];
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            return null;
        }

        return HttpTransport::object($decoded);
    }
}
