<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

class ParentAction
{
    public int $id = 1;

    public function handle(): static
    {
        return $this;
    }
}
