<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\OpenApi\Attributes\Hidden;

#[Hidden]
final readonly class HiddenAction
{
    public function handle(): string
    {
        return 'hidden';
    }
}
