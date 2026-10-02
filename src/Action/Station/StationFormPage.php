<?php

declare(strict_types=1);

namespace Logbook\Action\Station;

use Logbook\Domain\Fuel\Fuel;
use Logbook\Domain\Fuel\FuelGrade;
use Logbook\Domain\Station\Station;
use Logbook\Support\Validation\ValidationErrors;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The add/edit station form (spec.md §7.33), as a page and a desktop modal.
 */
final readonly class StationFormPage
{
    public function __construct(private View $view)
    {
    }

    /**
     * @param array<string, string|list<string>> $values
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $values,
        ?Station $station = null,
        ?ValidationErrors $errors = null,
        int $status = 200,
        ?Station $taken = null,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'stations/form.twig', [
            'station' => $station,
            'values' => $values,
            'errors' => $errors?->all() ?? [],
            'grade_groups' => self::gradeGroups(),
            'taken' => $taken,
        ], $status);
    }

    /**
     * Every grade a station may sell, by family; never home charging.
     *
     * @return array<string, list<FuelGrade>> keyed by Fuel value
     */
    public static function gradeGroups(): array
    {
        $groups = [];
        foreach (FuelGrade::cases() as $grade) {
            if ($grade !== FuelGrade::Home) {
                $groups[$grade->family()->value][] = $grade;
            }
        }

        return array_intersect_key($groups, array_flip(array_map(static fn (Fuel $fuel): string => $fuel->value, Fuel::cases())));
    }
}
