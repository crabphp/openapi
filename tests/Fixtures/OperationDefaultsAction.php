<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\OpenApi\Attributes\Operation;

#[Operation(description: 'Items.', tags: ['items'], operationId: 'items.show', deprecated: true)]
final class OperationDefaultsAction
{
    #[Operation(summary: 'Show the item.')]
    public function handle(): string
    {
        return 'item';
    }
}
