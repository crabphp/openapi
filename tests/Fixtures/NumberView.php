<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

final readonly class NumberView
{
    public function __construct(
        public int|float $value,
    ) {}
}
