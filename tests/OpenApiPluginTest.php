<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests;

use Crab\Config\Config;
use Crab\Console\Console;
use Crab\Container\Container;
use Crab\Contracts\Config\Config as ConfigContract;
use Crab\Contracts\Console\Console as ConsoleContract;
use Crab\Contracts\Http\MiddlewareStack as MiddlewareStackContract;
use Crab\Contracts\Routing\Router as RouterContract;
use Crab\Http\MiddlewareStack;
use Crab\OpenApi\DocumentBuilder;
use Crab\OpenApi\OpenApiPlugin;
use Crab\OpenApi\Tests\Fixtures\BearerAuth;
use Crab\Routing\Router;
use PHPUnit\Framework\TestCase;

final class OpenApiPluginTest extends TestCase
{
    public function testRegistersDefaultsTheBuilderAndTheCommand(): void
    {
        $config = new Config();
        $container = new Container();
        $container->instance(ConfigContract::class, $config);
        $container->instance(RouterContract::class, new Router());
        $container->instance(ConsoleContract::class, new Console($container));

        new OpenApiPlugin()->register($container);

        self::assertSame('openapi.json', $config->get('openapi.output'));
        self::assertTrue($config->get('openapi.strict'));
        self::assertSame([], $config->problems('openapi'));
        self::assertInstanceOf(DocumentBuilder::class, $container->get(DocumentBuilder::class));
        self::assertTrue($container->get(ConsoleContract::class)->has('openapi:generate'));
    }

    public function testInvalidSecurityAndMiddlewareSettingsAreReported(): void
    {
        $config = new Config([
            'openapi' => [
                'security' => 'bearer',
                'middleware' => [
                    BearerAuth::class => ['responses' => [700], 'unknown' => true],
                ],
            ],
        ]);
        $container = new Container();
        $container->instance(ConfigContract::class, $config);
        $container->instance(RouterContract::class, new Router());

        new OpenApiPlugin()->register($container);
        $problems = $config->problems('openapi');

        self::assertNotEmpty($problems);
        self::assertStringContainsString('openapi.security', implode("\n", $problems));
        self::assertStringContainsString('openapi.middleware.', implode("\n", $problems));
    }

    public function testBuilderReceivesGlobalMiddlewareFromTheApplicationStack(): void
    {
        $router = new Router();
        $router->get('/global', static fn(): string => 'ok');
        $config = new Config([
            'openapi' => [
                'security' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                'middleware' => [BearerAuth::class => ['security' => 'bearer']],
            ],
        ]);
        $container = new Container();
        $container->instance(ConfigContract::class, $config);
        $container->instance(RouterContract::class, $router);
        $stack = new MiddlewareStack($router);
        $stack->add(new BearerAuth());
        $container->instance(MiddlewareStackContract::class, $stack);

        new OpenApiPlugin()->register($container);

        $operation = $container->get(DocumentBuilder::class)->build(false)->document['paths']['/global']['get'];
        self::assertSame([['bearer' => []]], $operation['security']);
    }
}
