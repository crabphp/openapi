<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

#[\Attribute(\Attribute::TARGET_FUNCTION)]
final class UnrelatedMetadata
{
    public function __construct()
    {
        throw new \LogicException('Generating documentation must not construct unrelated attributes.');
    }
}
