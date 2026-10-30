<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

final readonly class Child
{
    public function __construct(
        public string $value,
        public ?int $rank = null,
    ) {}
}
