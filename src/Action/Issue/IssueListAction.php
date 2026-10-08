<?php

declare(strict_types=1);

namespace Logbook\Action\Issue;

use Logbook\Domain\Issue\IssueStatus;
use Logbook\Service\Attachment\AttachmentService;
use Logbook\Service\Issue\IssueService;
use Logbook\Support\Http\RequestContext;
use Logbook\Support\View\Pagination;
use Logbook\Support\View\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/issues — the Issues tab (spec.md §7.37 *Pages*):
 * filtered by status (*Open* by default), safety first, then newest
 * noticed first, 25 per page.
 */
final readonly class IssueListAction
{
    /** The filters, in the order shown; `all` is every status. */
    public const array FILTERS = ['open', 'watching', 'fixed', 'all'];

    public function __construct(
        private IssueService $issues,
        private AttachmentService $attachments,
        private View $view,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = RequestContext::vehicle($request);
        $query = $request->getQueryParams();
        $filter = is_string($query['status'] ?? null) && in_array($query['status'], self::FILTERS, true)
            ? $query['status']
            : 'open';

        $all = $this->issues->list($vehicle);
        $counts = array_fill_keys(self::FILTERS, 0);
        foreach ($all as $issue) {
            $counts[$issue->status()->value]++;
            $counts['all']++;
        }
        $listed = $filter === 'all'
            ? $all
            : array_values(array_filter($all, static fn ($i): bool => $i->status() === IssueStatus::from($filter)));
        $pagination = Pagination::fromQuery($query, count($listed));
        $page = $pagination->slice($listed);

        return $this->view->render($request, $response, 'issues/index.twig', [
            'vehicle' => $vehicle,
            'issues' => $page,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'counts' => $counts,
            'pagination' => $pagination,
            'fixes' => $this->issues->fixLinks($page),
            'attachment_counts' => $this->attachments->counts($vehicle),
        ]);
    }
}
