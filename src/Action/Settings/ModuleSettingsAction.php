<?php

declare(strict_types=1);

namespace Logbook\Action\Settings;

use Logbook\Domain\Feature\Feature;
use Logbook\Service\Ai\AiStatus;
use Logbook\Service\Feature\FeatureToggles;
use Logbook\Service\Ocr\OcrReader;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET|POST /settings/modules — switch modules on and off (spec.md §7.10).
 * A plain form of checkboxes: a module left unticked is switched off. The
 * AI modules are listed only while AI is set up (spec.md §7.25), and
 * reading files while a model or OCR can read one (Phase 53.4, #517); they
 * keep their state while they are not.
 */
final readonly class ModuleSettingsAction
{
    public function __construct(
        private FeatureToggles $features,
        private AiStatus $ai,
        private OcrReader $ocr,
        private View $view,
        private Redirector $redirect,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $shown = $this->shown();
        if ($request->getMethod() !== 'POST') {
            return $this->view->render($request, $response, 'settings/modules.twig', [
                'modules' => $shown,
                'enabled' => $this->features->own(),
                'install_off' => array_map(
                    static fn (Feature $feature): string => $feature->value,
                    $this->features->offSinceInstall(),
                ),
            ]);
        }

        $input = RequestContext::form($request);
        $current = $this->features->own();
        $enabled = array_values(array_filter(
            Feature::cases(),
            static fn (Feature $feature): bool => in_array($feature, $shown, true)
                ? ($input[$feature->value] ?? '') === '1'
                // A module not on the page keeps its state.
                : $current[$feature->value],
        ));
        $this->features->save($enabled);
        RequestContext::session($request)->flash('success', 'modules.saved');

        return $this->redirect->toRoute('settings.modules');
    }

    /**
     * The modules on the page: the AI ones only while AI is set up, and
     * reading files while a model or OCR can read one.
     *
     * @return list<Feature>
     */
    private function shown(): array
    {
        $aiSetUp = $this->ai->isSetUp();

        return array_values(array_filter(
            Feature::cases(),
            fn (Feature $feature): bool => match (true) {
                $feature === Feature::Scan => $aiSetUp || $this->ocr->isWorking(),
                $feature->isAi() => $aiSetUp,
                default => true,
            },
        ));
    }
}
