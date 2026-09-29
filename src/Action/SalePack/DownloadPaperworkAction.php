<?php

declare(strict_types=1);

namespace Logbook\Action\SalePack;

use Logbook\Action\Vehicle\VehicleRoute;
use Logbook\Service\SalePack\PaperworkArchive;
use Logbook\Service\SalePack\PaperworkSelector;
use Logbook\Service\Vehicle\VehicleService;
use Logbook\Support\Http\FileResponder;
use Logbook\Support\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /vehicles/{id}/sale-pack/paperwork.zip — the sale pack's paperwork
 * (spec.md §7.19): the files of the kinds ticked, less those unticked,
 * streamed as one ZIP with `contents.txt`. The vehicle is the signed-in
 * owner's or a 404; every file is resolved through it, so no id in the
 * query can add a file.
 */
final readonly class DownloadPaperworkAction
{
    public function __construct(
        private VehicleService $vehicles,
        private SalePackRequest $options,
        private PaperworkSelector $paperwork,
        private PaperworkArchive $archive,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vehicle = VehicleRoute::vehicle($this->vehicles, $request, $args);
        $user = RequestContext::requireUser($request);
        $selection = $this->paperwork->select($user, $vehicle, $this->options->options($request));
        $body = $this->archive->stream($user, $vehicle, $selection);

        return $response
            ->withBody($body)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', FileResponder::attachment($this->archive->filename($user, $vehicle)))
            ->withHeader('Content-Length', (string) $body->getSize())
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
