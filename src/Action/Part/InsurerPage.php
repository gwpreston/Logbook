<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use DateTimeImmutable;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Part\InsurerList;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders *For your insurer* (shared by the page and *Mark declared*, which
 * shows a date error beside the field, K16).
 */
final readonly class InsurerPage
{
    public function __construct(
        private InsurerList $list,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Vehicle $vehicle,
        DateTimeImmutable $today,
        array $values,
        ?ValidationErrors $errors = null,
        int $status = 200,
    ): ResponseInterface {
        $rows = $this->list->forVehicle($vehicle);

        return $this->view->render($request, $response, 'parts/insurer.twig', [
            'vehicle' => $vehicle,
            'rows' => $rows,
            'undeclared' => count(array_filter($rows, static fn ($row): bool => !$row->isDeclared())),
            'reverted' => $this->list->revertedCount($vehicle),
            'today' => $today,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
        ], $status);
    }
}
