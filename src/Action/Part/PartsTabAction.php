<?php

declare(strict_types=1);

namespace Logbook\Action\Part;

use Logbook\Action\Attachment\AttachmentGuard;
use Logbook\Domain\Attachment\Attachment;
use Logbook\Domain\Part\Part;
use Logbook\Domain\Part\PartStatus;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Part\ModificationService;
use Logbook\Service\Part\PartService;
use Logbook\Service\Part\PartThumbnails;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\Pagination;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/parts — the *Parts* tab (spec.md §7.39 *Pages*, #426):
 * *Modifications* (current first, then reverted), then *Parts* filtered by
 * status (*Fitted* by default, 25 per page), every row with its first photo
 * as a thumbnail.
 */
final readonly class PartsTabAction
{
    /** The part filters, in the order shown; `all` is every status. */
    public const array FILTERS = ['fitted', 'stored', 'gone', 'all'];

    public function __construct(
        private PartService $parts,
        private ModificationService $modifications,
        private AttachmentService $attachments,
        private VehicleService $vehicles,
        private View $view,
        private AttachmentGuard $guard,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $user = RequestContext::requireUser($request);
        $query = $request->getQueryParams();
        $filter = is_string($query['status'] ?? null) && in_array($query['status'], self::FILTERS, true)
            ? $query['status']
            : 'fitted';

        $all = $this->parts->list($vehicle);
        $counts = array_fill_keys(self::FILTERS, 0);
        foreach ($all as $part) {
            $counts[$part->status->value]++;
            $counts['all']++;
        }
        $listed = $filter === 'all'
            ? $all
            : array_values(array_filter($all, static fn (Part $p): bool => $p->status === PartStatus::from($filter)));
        $pagination = Pagination::fromQuery($query, count($listed));
        $modifications = $this->modifications->list($vehicle);
        $index = $this->attachments->index($vehicle);

        return $this->view->render($request, $response, 'parts/index.twig', [
            'vehicle' => $vehicle,
            'parts' => $pagination->slice($listed),
            'modifications' => $modifications,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'counts' => $counts,
            'pagination' => $pagination,
            'thumbnails' => PartThumbnails::from($index, fn (Attachment $a): bool => $this->guard->mayUse($user, $vehicle, $a)),
            'attachment_counts' => $this->attachments->counts($vehicle),
            'currency' => $this->vehicles->currencyFor($user, $vehicle),
        ]);
    }
}
