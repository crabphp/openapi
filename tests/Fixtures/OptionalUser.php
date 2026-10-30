<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\Contracts\Auth\Identity;

final readonly class OptionalUser implements Identity
{
    public function __construct(
        public int $id,
    ) {}
}
