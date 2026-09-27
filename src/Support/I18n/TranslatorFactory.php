<?php

declare(strict_types=1);

namespace Logbook\Support\I18n;

use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator;

final class TranslatorFactory
{
    public static function create(
        string $translationsDir,
        string $defaultLocale,
        ?string $cacheDir,
        bool $debug,
    ): Translator {
        $translator = new Translator($defaultLocale, null, $cacheDir, $debug);
        $translator->setFallbackLocales([AvailableLocales::FALLBACK]);
        $translator->addLoader('php', new PhpFileLoader());

        foreach (TranslationFiles::in($translationsDir) as $file) {
            $translator->addResource('php', $file['path'], $file['locale'], $file['domain']);
        }

        return $translator;
    }
}
