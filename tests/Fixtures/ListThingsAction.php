<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\OpenApi\Attributes\Response;
use Psr\Http\Message\ResponseInterface;

final readonly class ListThingsAction
{
    #[Response(200, body: [Thing::class])]
    public function handle(): ResponseInterface
    {
        throw new \LogicException('Not called while the document is generated.');
    }
}
