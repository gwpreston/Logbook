<?php

declare(strict_types=1);

namespace Logbook\Action\Api;

use Logbook\Domain\User\User;
use Logbook\Service\Api\ApiReader;
use Logbook\Service\Trip\ClaimFilter;
use Logbook\Service\Trip\TripSettingsStore;
use Logbook\Support\Api\ApiProblem;
use Logbook\Support\Api\ApiResponder;
use Logbook\Support\Date\LocalTime;
use Logbook\Support\Http\RequestContext;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/trips/claim — the key user's mileage claim figures (spec.md
 * §7.20, §7.23): a tax year (`year`, its start year; default the current
 * one) or `from` and `to` (dates, inclusive), and `vehicles[]` (default
 * all). Only their own business trips appear. A parameter that cannot be
 * read is a 400, and a vehicle they cannot see a 404, where the claim page
 * would quietly fall back.
 */
final readonly class TripClaimAction
{
    private const string DATE = 'a date (YYYY-MM-DD).';

    public function __construct(
        private ApiReader $reader,
        private TripSettingsStore $settings,
        private ApiResponder $responder,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::requireUser($request);
        $today = LocalTime::today($this->clock, $user->preferences->timeZone());
        $filter = ClaimFilter::fromQuery(
            $this->query($user, $request->getQueryParams()),
            $this->settings->for($user)->taxYearStart,
            $today,
        );

        return $this->responder->json($this->reader->claim($user, $filter));
    }

    /**
     * The query checked, as ClaimFilter reads it.
     *
     * @param array<array-key, mixed> $query
     * @return array<string, mixed>
     * @throws ApiProblem
     */
    private function query(User $user, array $query): array
    {
        $checked = [];

        $year = $query['year'] ?? null;
        if ($year !== null) {
            if (!is_string($year) || preg_match('/^[12][0-9]{3}$/', $year) !== 1) {
                throw ApiProblem::invalidParameter('year', 'the year a tax year starts in (2026).');
            }
            $checked['year'] = $year;
        }

        $period = $query['period'] ?? null;
        if ($period !== null && $period !== 'custom' && $period !== 'year') {
            throw ApiProblem::invalidParameter('period', '"year" or "custom".');
        }
        $custom = $period === 'custom' || isset($query['from']) || isset($query['to']);
        if ($custom && $period !== 'year') {
            if ($year !== null) {
                throw ApiProblem::invalidParameter('year', 'give either a year or from and to.');
            }
            $from = self::date($query, 'from');
            $to = self::date($query, 'to');
            if ($from > $to) {
                throw ApiProblem::invalidParameter('to', 'on or after "from".');
            }
            $checked += ['period' => 'custom', 'from' => $from, 'to' => $to];
        }

        $vehicles = $query['vehicles'] ?? [];
        $vehicles = is_string($vehicles) ? [$vehicles] : $vehicles;
        if (!is_array($vehicles)) {
            throw ApiProblem::invalidParameter('vehicles', 'vehicle ids (vehicles[]=3).');
        }
        $checked['vehicles'] = [];
        foreach ($vehicles as $id) {
            if (!is_string($id) || preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
                throw ApiProblem::invalidParameter('vehicles', 'vehicle ids (vehicles[]=3).');
            }
            if ($this->reader->visibleVehicle($user, (int) $id) === null) {
                throw ApiProblem::notFound('There is no such vehicle.');
            }
            $checked['vehicles'][] = $id;
        }

        return $checked;
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function date(array $query, string $name): string
    {
        $value = $query[$name] ?? null;
        if (!is_string($value) || LocalTime::parseDate($value) === null) {
            throw ApiProblem::invalidParameter($name, self::DATE);
        }

        return $value;
    }
}
