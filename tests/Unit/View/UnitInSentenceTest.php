<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\View;

use Logbook\Action\ImportApp\ImportAppAction;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * The import pages' sanity line names the units inside a sentence ("With
 * kilometres and UK gallons"): lower case, except an abbreviation, and
 * German keeps its capitalised nouns (Phase 51 design review).
 */
final class UnitInSentenceTest extends TestCase
{
    public function testUnitNamesReadRightInsideASentence(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['units.name.km' => 'Kilometres', 'units.name.gal_uk' => 'UK gallons'], 'en');
        $translator->addResource('array', ['units.name.km' => 'Kilometer'], 'de');

        self::assertSame('kilometres', ImportAppAction::inSentence($translator, 'units.name.km'));
        self::assertSame('UK gallons', ImportAppAction::inSentence($translator, 'units.name.gal_uk'));
        $translator->setLocale('de');
        self::assertSame('Kilometer', ImportAppAction::inSentence($translator, 'units.name.km'));
    }
}
