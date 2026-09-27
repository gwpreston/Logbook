<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Support\I18n\LocaleResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Resolves the request locale, applies it to the translator, exposes it as
 * the `locale` request attribute and advertises it via Content-Language.
 */
final readonly class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LocaleResolver $resolver,
        private LocaleAwareInterface $translator,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = $this->resolver->resolve($request->getHeaderLine('Accept-Language') ?: null);
        $this->translator->setLocale($locale);

        $response = $handler->handle($request->withAttribute('locale', $locale));

        return $response
            ->withHeader('Content-Language', str_replace('_', '-', $locale))
            ->withAddedHeader('Vary', 'Accept-Language');
    }
}
