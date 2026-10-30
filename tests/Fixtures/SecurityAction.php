<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\OpenApi\Attributes\Security;

final readonly class SecurityAction
{
    #[Security('bearer', 'apiKey')]
    public function handle(): string
    {
        return 'secured';
    }
}
