<?php

declare(strict_types=1);

namespace Crab\OpenApi;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Http\ErrorRenderer;
use Crab\Contracts\Http\MiddlewareStack;
use Crab\Contracts\Provider;
use Crab\Contracts\Routing\Router;
use Crab\OpenApi\Commands\Generate;

/**
 * Adds the openapi:generate command and the "openapi" configuration
 * section. It adds no middleware and no request-path work.
 */
final class OpenApiProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults(
            'openapi',
            [
                'title' => null,
                'version' => '1.0.0',
                'description' => null,
                'license' => null,
                'output' => 'openapi.json',
                'strict' => true,
                'exclude' => [],
                'servers' => [],
                'security' => [],
                'middleware' => [],
                'error_schema' => null,
            ],
            [
                'title' => ['type' => ['string', 'null']],
                'version' => ['type' => 'string', 'minLength' => 1],
                'description' => ['type' => ['string', 'null']],
                'license' => ['type' => ['string', 'array', 'object', 'null']],
                'output' => ['type' => 'string', 'minLength' => 1],
                'strict' => ['type' => 'boolean'],
                'exclude' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]],
                'servers' => [
                    'type' => 'array',
                    'items' => [
                        'type' => ['string', 'object'],
                        'properties' => [
                            'url' => ['type' => 'string', 'minLength' => 1],
                            'description' => ['type' => 'string'],
                            'variables' => ['type' => 'object'],
                        ],
                    ],
                ],
                'security' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'object'],
                ],
                'middleware' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'security' => [
                                'type' => ['string', 'array'],
                                'items' => ['type' => 'string', 'minLength' => 1],
                            ],
                            'responses' => [
                                'type' => 'array',
                                'items' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 599],
                            ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'error_schema' => ['type' => ['array', 'object', 'null']],
            ],
        );

        $container->singleton(DocumentBuilder::class, static function (Container $container): DocumentBuilder {
            $middleware = $container->has(MiddlewareStack::class) ? $container->get(MiddlewareStack::class) : null;
            return new DocumentBuilder(
                $container->get(Router::class),
                $container->get(Config::class),
                $container->has(ErrorRenderer::class),
                $middleware instanceof MiddlewareStack ? $middleware->layers() : [],
            );
        });

        if ($container->has(Console::class)) {
            $container->get(Console::class)->add('openapi:generate', Generate::class);
        }
    }
}
