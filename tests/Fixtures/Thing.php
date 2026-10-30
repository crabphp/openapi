<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

final readonly class Thing
{
    public function __construct(
        public int $id,
        public string $name,
        public Kind $kind,
        public \DateTimeImmutable $createdAt,
        public ?Child $child,
    ) {}
}
