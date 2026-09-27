<?php

declare(strict_types=1);

namespace Logbook\Middleware;

use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\I18n\LocaleResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Resolves the request locale (the signed-in user's choice, else the
 * browser's Accept-Language, else APP_LOCALE), applies it to the translator
 * and applies the user's display preferences (units, currency, time zone) —
 * or the app defaults when signed out — to the DisplayContext.
 *
 * Exposes the locale as the `locale` request attribute and advertises it via
 * Content-Language.
 */
final readonly class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LocaleResolver $resolver,
        private LocaleAwareInterface $translator,
        private DisplayContext $display,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = RequestContext::user($request);

        $locale = $this->resolver->resolve(
            $request->getHeaderLine('Accept-Language') ?: null,
            $user?->preferences->locale,
        );
        $this->translator->setLocale($locale);
        $this->display->apply(
            $user === null ? $this->display->defaults($locale) : $user->preferences->withLocale($locale),
        );

        $response = $handler->handle($request->withAttribute('locale', $locale));

        $response = $response->withHeader('Content-Language', str_replace('_', '-', $locale));

        // Signed-in pages depend on the account, not on Accept-Language.
        return $user === null ? $response->withAddedHeader('Vary', 'Accept-Language') : $response;
    }
}
