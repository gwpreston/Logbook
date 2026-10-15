<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use DateTimeImmutable;
use Logbook\Domain\Part\Modification;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Vehicle\Vehicle;
use Logbook\Service\Part\ModificationNotFound;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartChangeRefused;
use Logbook\Service\Part\PartNotFound;
use Logbook\Service\Part\PartService;
use Logbook\Support\Display\DisplayFormatter;
use Logbook\Support\Http\Redirector;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\Validation\ValidationErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Shared by the parts and modifications actions (spec.md §7.39).
 */
final class PartRoute
{
    /**
     * @param array<string, string> $args
     */
    public static function part(PartService $parts, Vehicle $vehicle, ServerRequestInterface $request, array $args): Part
    {
        try {
            return $parts->get($vehicle, (int) ($args['part'] ?? 0));
        } catch (PartNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * @param array<string, string> $args
     */
    public static function modification(
        ModificationService $modifications,
        Vehicle $vehicle,
        ServerRequestInterface $request,
        array $args,
    ): Modification {
        try {
            return $modifications->get($vehicle, (int) ($args['modification'] ?? 0));
        } catch (ModificationNotFound) {
            throw new HttpNotFoundException($request);
        }
    }

    /**
     * An archived vehicle keeps its parts and modifications read-only
     * (spec.md §7.39 *Pages*): a change is refused, back to the tab.
     */
    public static function refuseArchived(
        ServerRequestInterface $request,
        Vehicle $vehicle,
        Redirector $redirect,
    ): ?ResponseInterface {
        if (!$vehicle->isArchived()) {
            return null;
        }
        RequestContext::session($request)->flash('error', 'part.error.archived');

        return $redirect->toRoute('parts.index', ['id' => (string) $vehicle->id]);
    }

    /**
     * A refusal as a form error: its dates formatted for the reader.
     */
    public static function refusal(
        PartChangeRefused $refused,
        DisplayFormatter $formatter,
        string $field = 'form',
    ): ValidationErrors {
        $errors = new ValidationErrors();
        $errors->add($field, $refused->key, self::params($refused, $formatter));

        return $errors;
    }

    /**
     * A refusal's parameters with its dates formatted, for a form error or a flash.
     *
     * @return array<string, int|string>
     */
    public static function params(PartChangeRefused $refused, DisplayFormatter $formatter): array
    {
        $params = [];
        foreach ($refused->params as $key => $value) {
            $params[$key] = $value instanceof DateTimeImmutable ? $formatter->date($value) : $value;
        }

        return $params;
    }
}
