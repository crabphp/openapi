<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\Validation\Length;

final readonly class SharedThing
{
    public function __construct(
        #[Length(min: 2)]
        public string $name,
    ) {}
}
