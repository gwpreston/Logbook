<?php

declare(strict_types=1);

namespace Logbook\Tests\Unit\Support\View;

use Logbook\Domain\Fuel\EnergyKind;
use Logbook\Kernel;
use Logbook\Support\Config\AppSettings;
use Logbook\Support\Config\Env;
use Logbook\Support\Display\DisplayContext;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Display\DisplayPreferences;
use Logbook\Support\I18n\TranslatorFactory;
use Logbook\Support\Units\ConsumptionUnit;
use Logbook\Support\Units\DistanceUnit;
use Logbook\Support\Units\VolumeUnit;
use Logbook\Support\View\AssetPackage;
use Logbook\Support\View\TwigExtension;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Slim\Interfaces\RouteParserInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * On screen a figure never parts from its unit: the unit filters join them
 * with a no-break space, while DisplayFormatter (which also writes API, MCP
 * and notification text) keeps its plain space.
 */
final class UnitFiguresTest extends TestCase
{
    private const string NBSP = "\u{00A0}";

    private DisplayFormatter $formatter;
    private Environment $twig;

    protected function setUp(): void
    {
        $settings = AppSettings::fromEnv(new Env(['APP_CURRENCY' => 'GBP', 'APP_TIMEZONE' => 'UTC']), '/app');
        $translator = TranslatorFactory::create(Kernel::rootDir() . '/translations', 'en', null, false);
        $context = new DisplayContext($settings);
        $context->apply(new DisplayPreferences(
            'en_GB',
            'Europe/London',
            DistanceUnit::Mile,
            VolumeUnit::UsGallon,
            ConsumptionUnit::MpgUs,
            'GBP',
        ));
        $this->formatter = new DisplayFormatter($context, $translator);

        $this->twig = new Environment(new ArrayLoader());
        $this->twig->addExtension(new TwigExtension(
            self::createStub(RouteParserInterface::class),
            new AssetPackage('', sys_get_temp_dir()),
            $translator,
            $this->formatter,
            $context,
            self::createStub(ClockInterface::class),
            '',
        ));
    }

    public function testDistanceKeepsTheNumberWithItsUnit(): void
    {
        self::assertSame('12,480' . self::NBSP . 'mi', $this->render('{{ km|distance }}', ['km' => 20085.0]));
        self::assertSame('6,000' . self::NBSP . 'mi', $this->render('{{ km|about_distance }}', ['km' => '9650.000']));
    }

    public function testEveryWordOfAUnitStaysTogether(): void
    {
        self::assertSame('10' . self::NBSP . 'US' . self::NBSP . 'gal', $this->render('{{ l|volume(0) }}', ['l' => 37.854]));
        self::assertSame(
            '30.0' . self::NBSP . 'mpg' . self::NBSP . '(US)',
            $this->render('{{ km|economy(l, false) }}', ['km' => 48.28032, 'l' => 3.785411784]),
        );
        self::assertSame(
            '£1.00/US' . self::NBSP . 'gal',
            $this->render("{{ p|unit_price('GBP', false) }}", ['p' => '0.264172']),
        );
        self::assertSame('58' . self::NBSP . 'kWh', $this->render('{{ kwh|quantity(true) }}', ['kwh' => 58]));
        self::assertSame('830' . self::NBSP . 'B', $this->render('{{ bytes|file_size }}', ['bytes' => 830]));
    }

    public function testAFigureATemplateJoinsItselfStaysTogether(): void
    {
        self::assertSame(
            '10,000' . self::NBSP . 'mi',
            $this->render("{{ (n|number ~ ' ' ~ 'units.symbol.mi'|trans)|keep_together }}", ['n' => 10000]),
        );
    }

    public function testAMissingFigureStaysEmpty(): void
    {
        self::assertSame('', $this->render('{{ km|distance }}', ['km' => null]));
    }

    public function testTheFormatterItselfKeepsPlainSpacesForMachineText(): void
    {
        // The API, MCP tools and notifications call the formatter directly.
        self::assertSame('12,480 mi', $this->formatter->distance(20085.0));
        self::assertSame('10 US gal', $this->formatter->volume(37.854, 0));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $template, array $vars): string
    {
        return $this->twig->createTemplate($template)->render($vars);
    }
}
