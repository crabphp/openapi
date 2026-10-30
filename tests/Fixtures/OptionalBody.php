<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

final readonly class OptionalBody
{
    public function __construct(
        public ?string $note = null,
    ) {}
}
