<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\OpenApi\Attributes\Operation;
use Crab\OpenApi\Attributes\Security;

#[Operation(summary: 'An inherited action.')]
#[Security('bearer')]
final class InheritedAction extends ParentAction
{
    public string $label = 'child';
}
