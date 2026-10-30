<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\Validation\Length;

final readonly class CreateThing
{
    public function __construct(
        #[Length(min: 2, max: 40)]
        public string $name,
        public ?int $count = null,
    ) {}
}
