<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

enum Kind: string
{
    case Simple = 'simple';
    case Special = 'special';
}
