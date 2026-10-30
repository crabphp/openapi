<?php

declare(strict_types=1);

namespace Crab\OpenApi\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
final readonly class Operation
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public ?string $summary = null,
        public ?string $description = null,
        public array $tags = [],
        public ?string $operationId = null,
        public bool $deprecated = false,
    ) {}
}
