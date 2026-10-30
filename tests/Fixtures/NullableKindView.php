<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

final readonly class NullableKindView
{
    public function __construct(
        public ?Kind $kind,
    ) {}
}
