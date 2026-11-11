<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\Request;
use Psr\Http\Message\ResponseInterface;

final class BearerAuth implements Middleware
{
    public function handle(Request $request, Handler $next): ResponseInterface
    {
        return $next->handle($request);
    }
}
