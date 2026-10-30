<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

final readonly class RecursiveNode
{
    public function __construct(
        public string $name,
        public ?self $next = null,
    ) {}
}
