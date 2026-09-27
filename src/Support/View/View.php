<?php

declare(strict_types=1);

namespace Logbook\Support\View;

use Psr\Http\Message\ResponseInterface;
use Twig\Environment;

/**
 * Renders a Twig template into a PSR-7 response.
 */
final readonly class View
{
    public function __construct(private Environment $twig)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(ResponseInterface $response, string $template, array $context = []): ResponseInterface
    {
        $response->getBody()->write($this->twig->render($template, $context));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param array<string, mixed> $context
     */
    public function fetch(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }
}
