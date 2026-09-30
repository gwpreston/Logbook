<?php

declare(strict_types=1);

namespace Logbook\Support\Http;

use Logbook\Support\View\View;
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorRendererInterface;
use Throwable;

/**
 * Friendly, translated HTML error pages.
 *
 * Production shows a generic message only. With APP_DEBUG the exception class,
 * message and trace are shown — always HTML-escaped (Twig autoescape, or
 * htmlspecialchars in the fallback) so an attacker-controlled message or URL
 * can never inject markup.
 */
final readonly class HtmlErrorRenderer implements ErrorRendererInterface
{
    private const array KNOWN_STATUSES = [400, 403, 404, 405, 413, 500, 503];

    public function __construct(private View $view)
    {
    }

    public function __invoke(Throwable $exception, bool $displayErrorDetails): string
    {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        $key = match (true) {
            $exception instanceof CsrfFailedException => 'csrf',
            $exception instanceof AccessDeniedException => 'access_denied',
            in_array($status, self::KNOWN_STATUSES, true) => (string) $status,
            default => $status < 500 ? '4xx' : '5xx',
        };

        $details = $displayErrorDetails ? self::details($exception) : [];

        try {
            return $this->view->fetch('error.twig', [
                'status' => $status,
                'key' => $key,
                'details' => $details,
            ]);
        } catch (Throwable) {
            return self::fallback($status, $details);
        }
    }

    /**
     * @return list<array{class: string, message: string, location: string, trace: string}>
     */
    private static function details(Throwable $exception): array
    {
        $chain = [];
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            $chain[] = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'location' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ];
        }

        return $chain;
    }

    /**
     * Last resort if the template layer itself is broken.
     *
     * @param list<array{class: string, message: string, location: string, trace: string}> $details
     */
    private static function fallback(int $status, array $details): string
    {
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><title>Error ' . $status . '</title>'
            . '<h1>Error ' . $status . '</h1>';

        foreach ($details as $detail) {
            $html .= '<h2>' . self::e($detail['class']) . '</h2><p>' . self::e($detail['message']) . '</p>'
                . '<p>' . self::e($detail['location']) . '</p><pre>' . self::e($detail['trace']) . '</pre>';
        }

        return $html . '</html>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
