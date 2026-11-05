<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Plugin;

final readonly class ReadmeSetup implements Plugin
{
    public function register(Container $container): void {}
}
