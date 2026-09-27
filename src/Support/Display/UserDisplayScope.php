<?php

declare(strict_types=1);

namespace Logbook\Support\Display;

use Logbook\Domain\User\User;
use Logbook\Support\I18n\LocaleResolver;
use Symfony\Component\Translation\Translator;

/**
 * Runs code in one owner's language, units and time zone outside their own
 * request (the scheduled task, the calendar feed), then restores whatever
 * was in force. The same resolution as LocaleMiddleware.
 */
final readonly class UserDisplayScope
{
    public function __construct(
        private Translator $translator,
        private DisplayContext $display,
        private LocaleResolver $locales,
    ) {
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(User $user, callable $work): mixed
    {
        $previousLocale = $this->translator->getLocale();
        $previousPreferences = $this->display->preferences();

        $locale = $this->locales->resolve(null, $user->preferences->locale);
        $this->translator->setLocale($locale);
        $this->display->apply($user->preferences->withLocale($locale));

        try {
            return $work();
        } finally {
            $this->translator->setLocale($previousLocale);
            $this->display->apply($previousPreferences);
        }
    }
}
