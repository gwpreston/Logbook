<?php

declare(strict_types=1);

namespace Logbook\Action\SalePack;

use Logbook\Service\SalePack\PaperworkSelector;
use Logbook\Service\SalePack\SalePackOptions;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The sale pack's options from the request (spec.md §7.19), for the page
 * and the ZIP alike.
 */
final readonly class SalePackRequest
{
    public function __construct(private PaperworkSelector $paperwork)
    {
    }

    public function options(ServerRequestInterface $request): SalePackOptions
    {
        return SalePackOptions::fromQuery($request->getQueryParams(), $this->paperwork->offered());
    }
}
