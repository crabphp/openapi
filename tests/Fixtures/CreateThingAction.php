<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\Http\Response as HttpResponse;
use Crab\OpenApi\Attributes\Operation;
use Crab\OpenApi\Attributes\Response;

#[Operation(tags: ['things'])]
final readonly class CreateThingAction
{
    #[Response(201, body: Thing::class, description: 'Thing created', headers: ['Location'])]
    public function handle(CreateThing $thing): HttpResponse
    {
        return HttpResponse::created();
    }
}
