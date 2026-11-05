<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests;

use Crab\Config\Config;
use Crab\Http\Request;
use Crab\OpenApi\Attributes\Hidden;
use Crab\OpenApi\Attributes\Operation;
use Crab\OpenApi\Attributes\Response;
use Crab\OpenApi\Attributes\Security;
use Crab\OpenApi\DocumentBuilder;
use Crab\OpenApi\GenerationException;
use Crab\OpenApi\Tests\Fixtures\ApiKeyAuth;
use Crab\OpenApi\Tests\Fixtures\BearerAuth;
use Crab\OpenApi\Tests\Fixtures\CreateThingAction;
use Crab\OpenApi\Tests\Fixtures\HiddenAction;
use Crab\OpenApi\Tests\Fixtures\InheritedView;
use Crab\OpenApi\Tests\Fixtures\Kind;
use Crab\OpenApi\Tests\Fixtures\ListThingsAction;
use Crab\OpenApi\Tests\Fixtures\NullableInput;
use Crab\OpenApi\Tests\Fixtures\NullableKindView;
use Crab\OpenApi\Tests\Fixtures\NumberView;
use Crab\OpenApi\Tests\Fixtures\OperationDefaultsAction;
use Crab\OpenApi\Tests\Fixtures\OptionalBody;
use Crab\OpenApi\Tests\Fixtures\OptionalUser;
use Crab\OpenApi\Tests\Fixtures\RecursiveNode;
use Crab\OpenApi\Tests\Fixtures\SecurityAction;
use Crab\OpenApi\Tests\Fixtures\SerializedKind;
use Crab\OpenApi\Tests\Fixtures\SharedThing;
use Crab\OpenApi\Tests\Fixtures\Thing;
use Crab\OpenApi\Tests\Fixtures\UninitializedView;
use Crab\OpenApi\Tests\Fixtures\UnrelatedMetadata;
use Crab\OpenApi\Tests\Fixtures\UploadThing;
use Crab\Routing\FromQuery;
use Crab\Routing\Router;
use Crab\Validation\Data;
use Crab\Validation\Fields;
use Crab\Validation\ListOf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DocumentBuilderTest extends TestCase
{
    private const THING = '#/components/schemas/Crab.OpenApi.Tests.Fixtures.Thing';
    private const CHILD = '#/components/schemas/Crab.OpenApi.Tests.Fixtures.Child';
    private const NODE = '#/components/schemas/Crab.OpenApi.Tests.Fixtures.RecursiveNode';

    public function testInputsAndTypedResponsesAreInferredFromTheRoute(): void
    {
        $router = new Router();
        $router->get('/things/{id:\d+}', static function (int $id, #[FromQuery] int $page = 1): Thing {
            throw new \LogicException('Generation does not call actions.');
        });

        $document = $this->document($router);
        $operation = $document['paths']['/things/{id}']['get'];

        self::assertSame([], $document['security']);
        self::assertSame('get.things.id', $operation['operationId']);
        self::assertSame(['things'], $operation['tags']);
        self::assertSame(
            [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                [
                    'name' => 'page',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'integer', 'default' => 1],
                    'style' => 'form',
                    'explode' => true,
                ],
            ],
            $operation['parameters'],
        );
        self::assertSame(
            ['$ref' => self::THING],
            $operation['responses']['200']['content']['application/json']['schema'],
        );
        self::assertSame('The request is invalid.', $operation['responses']['422']['description']);
        self::assertSame(
            ['$ref' => '#/components/schemas/Error'],
            $operation['responses']['422']['content']['application/json']['schema'],
        );
        self::assertSame(['type' => 'string'], $operation['responses']['422']['content']['text/plain']['schema']);

        $thing = $document['components']['schemas']['Crab.OpenApi.Tests.Fixtures.Thing'];
        self::assertSame('object', $thing['type']);
        self::assertSame(['id', 'name', 'kind', 'createdAt', 'child'], $thing['required']);
        self::assertSame(
            [
                'type' => 'string',
                'enum' => ['simple', 'special'],
            ],
            $thing['properties']['kind'],
        );
        self::assertSame(
            [
                'type' => 'string',
                'format' => 'date-time',
            ],
            $thing['properties']['createdAt'],
        );
        self::assertSame(
            [
                'oneOf' => [['$ref' => self::CHILD], ['type' => 'null']],
            ],
            $thing['properties']['child'],
        );

        $child = $document['components']['schemas']['Crab.OpenApi.Tests.Fixtures.Child'];
        self::assertSame(['integer', 'null'], $child['properties']['rank']['type']);
    }

    public function testVoidTextAndNullableValuesDescribeEveryStatusTheActionCanReturn(): void
    {
        $router = new Router();
        $router->get('/none', static function (): void {});
        $router->get('/text', static fn(): string => 'text');
        $router->get('/maybe-text', static fn(): ?string => null);
        $router->get('/maybe-thing', static fn(): ?Thing => null);

        $paths = $this->document($router)['paths'];

        self::assertSame(['204' => ['description' => 'No Content']], $paths['/none']['get']['responses']);
        self::assertSame(
            ['type' => 'string'],
            $paths['/text']['get']['responses']['200']['content']['text/plain']['schema'],
        );
        self::assertArrayHasKey('200', $paths['/maybe-text']['get']['responses']);
        self::assertSame('No Content', $paths['/maybe-text']['get']['responses']['204']['description']);
        self::assertSame(
            ['$ref' => self::THING],
            $paths['/maybe-thing']['get']['responses']['200']['content']['application/json']['schema'],
        );
        self::assertSame('No Content', $paths['/maybe-thing']['get']['responses']['204']['description']);
    }

    public function testQueryListsUseNamesThatTheFrameworkReadsAsLists(): void
    {
        $router = new Router();
        $router->get('/lists', static fn(#[FromQuery, ListOf('int')] array $ids): string => implode(',', $ids));
        $parameter = $this->document($router)['paths']['/lists']['get']['parameters'][0];
        $query = implode('&', array_map(
            static fn(int $id): string => rawurlencode($parameter['name']) . '=' . $id,
            [1, 2],
        ));

        $response = $router->handle(new Request('GET', '/lists?' . $query));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1,2', (string) $response->getBody());
        self::assertSame('ids[]', $parameter['name']);
        self::assertSame('form', $parameter['style']);
        self::assertTrue($parameter['explode']);
    }

    public function testQueryObjectsUseDeepObjectSerialization(): void
    {
        $router = new Router();
        $router->get(
            '/filters',
            static fn(
                #[
                    FromQuery,
                    Fields([
                        'filter' => ['name' => 'string'],
                    ]),
                ]
                Data $query,
            ): string => $query->filter->name,
        );
        $parameter = $this->document($router)['paths']['/filters']['get']['parameters'][0];
        $name = $parameter['style'] === 'deepObject' ? $parameter['name'] . '[name]' : 'name';

        $response = $router->handle(new Request('GET', '/filters?' . rawurlencode($name) . '=crab'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('crab', (string) $response->getBody());
        self::assertSame('deepObject', $parameter['style']);
    }

    public function testNestedQueryCollectionsAreReportedAsUnsupported(): void
    {
        $router = new Router();
        $router->get(
            '/nested',
            static fn(
                #[
                    FromQuery,
                    Fields([
                        'filter' => ['ids' => [['int']]],
                    ]),
                ]
                Data $query,
            ): string => 'ok',
        );

        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage('query field filter');
        $this->builder($router)->build();
    }

    public function testDeclaredResponsesDescribeStatusHeadersAndLists(): void
    {
        $router = new Router();
        $router->post('/things', CreateThingAction::class);
        $router->get('/things', ListThingsAction::class);

        $paths = $this->document($router)['paths'];
        $created = $paths['/things']['post'];
        $listed = $paths['/things']['get'];

        self::assertSame('CreateThingAction', $created['summary']);
        self::assertSame(['things'], $created['tags']);
        self::assertSame('Thing created', $created['responses']['201']['description']);
        self::assertSame(
            ['$ref' => self::THING],
            $created['responses']['201']['content']['application/json']['schema'],
        );
        self::assertSame(['schema' => ['type' => 'string']], $created['responses']['201']['headers']['Location']);
        self::assertSame(
            ['$ref' => '#/components/schemas/Crab.OpenApi.Tests.Fixtures.CreateThing'],
            $created['requestBody']['content']['application/json']['schema'],
        );
        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => self::THING]],
            $listed['responses']['200']['content']['application/json']['schema'],
        );
    }

    public function testRepeatedResponseDeclarationsKeepEveryBodySchema(): void
    {
        $router = new Router();
        $router->get(
            '/variants',
            #[Response(body: ['type' => 'integer']), Response(body: ['type' => 'string'])] static fn(): int|string => 1,
        );

        $schema = $this->document(
            $router,
        )['paths']['/variants']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertSame(['anyOf' => [['type' => 'integer'], ['type' => 'string']]], $schema);
    }

    public function testAnExplicitEmptyResponseSchemaAllowsAnyJsonValue(): void
    {
        $router = new Router();
        $router->get('/anything', #[Response(body: [])] static fn(): mixed => null);

        $schema = $this->document(
            $router,
        )['paths']['/anything']['get']['responses']['200']['content']['application/json']['schema'];

        self::assertInstanceOf(\stdClass::class, $schema);
    }

    public function testNeverNeedsAnExplicitRuntimeResponse(): void
    {
        $router = new Router();
        $router->get('/never', static function (): never {
            throw new \LogicException();
        });

        try {
            $this->builder($router)->build();
            self::fail('Strict generation documented a response for a never-returning action.');
        } catch (GenerationException $error) {
            self::assertStringContainsString('returns never', $error->getMessage());
        }

        $response = $this->builder($router)->build(false)->document['paths']['/never']['get']['responses']['default'];
        self::assertTrue($response['x-crab-untyped-body']);
    }

    public function testAFileInputSelectsMultipartAndKeepsCrabSpecificRulesAsExtensions(): void
    {
        $router = new Router();
        $router->post('/uploads', static function (UploadThing $upload): void {
            throw new \LogicException();
        });

        $body = $this->document($router)['paths']['/uploads']['post']['requestBody'];
        $schema = $this->document($router)['components']['schemas']['Crab.OpenApi.Tests.Fixtures.UploadThing'];

        self::assertSame(['multipart/form-data'], array_keys($body['content']));
        self::assertSame(
            ['$ref' => '#/components/schemas/Crab.OpenApi.Tests.Fixtures.UploadThing'],
            $body['content']['multipart/form-data']['schema'],
        );
        self::assertSame(
            [
                'type' => 'string',
                'format' => 'binary',
                'x-crab-min-bytes' => 1,
                'x-crab-max-bytes' => 1024,
            ],
            $schema['properties']['file'],
        );
        self::assertSame(1, $schema['properties']['file']['x-crab-min-bytes']);
        self::assertSame(1024, $schema['properties']['file']['x-crab-max-bytes']);
    }

    public function testOptionalUsersDoNotAdvertiseAuthenticationFailures(): void
    {
        $router = new Router();
        $router->get('/optional-user', static fn(?OptionalUser $user): string => 'ok');
        $router->get('/required-user', static fn(OptionalUser $user): string => 'ok');

        $paths = $this->document($router)['paths'];

        self::assertArrayNotHasKey('401', $paths['/optional-user']['get']['responses']);
        self::assertArrayHasKey('401', $paths['/required-user']['get']['responses']);
    }

    public function testARequestBodyIsOptionalWhenItsInputHasNoRequiredFields(): void
    {
        $router = new Router();
        $router->post('/optional-body', static fn(OptionalBody $body): string => 'ok');

        $requestBody = $this->document($router)['paths']['/optional-body']['post']['requestBody'];

        self::assertFalse($requestBody['required']);
    }

    public function testMiddlewareEffectsAreKeptForUntypedHandlers(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Not called.');
            }
        };
        $router = new Router();
        $router->get('/handler', $handler)->middleware(new BearerAuth());

        $operation = $this->builder($router, [
            'strict' => false,
            'security' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
            'middleware' => [BearerAuth::class => ['security' => 'bearer', 'responses' => [429]]],
        ])->build(false)->document['paths']['/handler']['get'];

        self::assertSame([['bearer' => []]], $operation['security']);
        self::assertArrayHasKey('401', $operation['responses']);
        self::assertArrayHasKey('429', $operation['responses']);
    }

    public function testNullableBackedEnumPropertiesAcceptNull(): void
    {
        $router = new Router();
        $router->get('/nullable-enum', static fn(): NullableKindView => throw new \LogicException());

        $schema = $this->document($router)['components']['schemas']['Crab.OpenApi.Tests.Fixtures.NullableKindView'];

        self::assertSame(
            [
                'oneOf' => [
                    ['type' => 'string', 'enum' => ['simple', 'special']],
                    ['type' => 'null'],
                ],
            ],
            $schema['properties']['kind'],
        );
    }

    public function testNullableBackedEnumInputsKeepNullOutsideTheSharedComponent(): void
    {
        $router = new Router();
        $router->post('/nullable-input', static fn(NullableInput $input): string => 'ok');

        $document = $this->document($router);
        $schema = $document['components']['schemas']['Crab.OpenApi.Tests.Fixtures.Kind'];

        self::assertSame('string', $schema['type']);
        self::assertSame(['simple', 'special'], $schema['enum']);
        self::assertSame(
            [
                'oneOf' => [
                    ['$ref' => '#/components/schemas/Crab.OpenApi.Tests.Fixtures.Kind'],
                    ['type' => 'null'],
                ],
                'default' => null,
            ],
            $document['components']['schemas']['Crab.OpenApi.Tests.Fixtures.NullableInput']['properties']['kind'],
        );
    }

    public function testSharedInputClassesKeepEachFieldsNullabilityAndDefault(): void
    {
        $documents = [];
        foreach ([false, true] as $reverse) {
            $router = new Router();
            $routes = [
                '/simple' => static fn(#[FromQuery] Kind $kind = Kind::Simple): string => 'ok',
                '/special' => static fn(#[FromQuery] Kind $kind = Kind::Special): string => 'ok',
            ];
            foreach ($reverse ? array_reverse($routes) : $routes as $path => $action) {
                $router->get($path, $action);
            }
            $router->post(
                '/objects',
                static fn(
                    #[Fields([
                        'nullable' => '?' . SharedThing::class,
                        'required' => SharedThing::class,
                    ])]
                    Data $input,
                ): string => 'ok',
            );
            $documents[] = $this->document($router);
        }

        self::assertSame($documents[0], $documents[1]);
        $document = $documents[0];
        $enum = ['$ref' => '#/components/schemas/Crab.OpenApi.Tests.Fixtures.Kind'];
        self::assertSame(
            $enum + ['default' => 'simple'],
            $document['paths']['/simple']['get']['parameters'][0]['schema'],
        );
        self::assertSame(
            $enum + ['default' => 'special'],
            $document['paths']['/special']['get']['parameters'][0]['schema'],
        );
        $properties =
            $document['paths']['/objects']['post']['requestBody']['content']['application/json']['schema']['properties'];
        $object = ['$ref' => '#/components/schemas/Crab.OpenApi.Tests.Fixtures.SharedThing'];
        self::assertSame($object, $properties['required']);
        self::assertSame(['oneOf' => [$object, ['type' => 'null']]], $properties['nullable']);
        self::assertSame(
            'object',
            $document['components']['schemas']['Crab.OpenApi.Tests.Fixtures.SharedThing']['type'],
        );
    }

    public function testEmptySchemaObjectsRemainObjectsWhenEncoded(): void
    {
        $router = new Router();
        $router->get('/anything', #[Response(body: [
            'type' => 'object',
            'properties' => [
                'anything' => [],
                'empty' => ['type' => 'object', 'properties' => []],
            ],
        ])]
        static fn(): array => []);

        $document = json_decode(json_encode($this->document($router), JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
        $schema = $document->paths->{'/anything'}->get->responses->{'200'}->content->{'application/json'}->schema;

        self::assertInstanceOf(\stdClass::class, $schema->properties->anything);
        self::assertInstanceOf(\stdClass::class, $schema->properties->empty->properties);
    }

    public function testTrueAndFalseReturnTypesAreInferredAsBooleans(): void
    {
        $router = new Router();
        $router->get('/true', static fn(): true => true);
        $router->get('/false', static fn(): false => false);

        $paths = $this->document($router)['paths'];

        self::assertSame(
            ['type' => 'boolean'],
            $paths['/true']['get']['responses']['200']['content']['application/json']['schema'],
        );
        self::assertSame(
            ['type' => 'boolean'],
            $paths['/false']['get']['responses']['200']['content']['application/json']['schema'],
        );
    }

    public function testADeclaredErrorWithoutABodyUsesCrabErrorResponses(): void
    {
        $router = new Router();
        $router->get('/missing', #[Response(404, description: 'No such thing.')] static fn(): string => 'found');

        $response = $this->document($router)['paths']['/missing']['get']['responses']['404'];

        self::assertSame('No such thing.', $response['description']);
        self::assertSame(['$ref' => '#/components/schemas/Error'], $response['content']['application/json']['schema']);
        self::assertSame(['type' => 'string'], $response['content']['text/plain']['schema']);
    }

    public function testStrictModeFailsWhenAnArrayShapeIsNotInTheType(): void
    {
        $router = new Router();
        $router->get('/values', static fn(): array => ['unknown' => true]);
        $builder = $this->builder($router, ['strict' => true]);

        try {
            $builder->build();
            self::fail('Strict generation accepted an array whose shape is unknown.');
        } catch (GenerationException $error) {
            self::assertStringContainsString('returns array', $error->getMessage());
        }

        $result = $builder->build(false);
        $response = $result->document['paths']['/values']['get']['responses']['200'];
        self::assertTrue($response['x-crab-untyped-body']);
        self::assertNotSame([], $result->warnings);
    }

    public function testSecuritySchemesAndMiddlewareEffectsAreMappedExplicitly(): void
    {
        $router = new Router();
        $router->get('/attribute', SecurityAction::class);
        $router->get('/mapped', static fn(): string => 'mapped')->middleware(new BearerAuth());
        $route = $router->routes()[1];

        $config = [
            'strict' => true,
            'security' => [
                'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
                'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key'],
            ],
            'middleware' => [
                BearerAuth::class => ['security' => 'bearer', 'responses' => [401, 429]],
            ],
        ];
        $document = $this->document($router, $config);

        self::assertSame([['bearer' => [], 'apiKey' => []]], $document['paths']['/attribute']['get']['security']);
        self::assertSame([['bearer' => []]], $document['paths']['/mapped']['get']['security']);
        self::assertSame($config['security'], $document['components']['securitySchemes']);
        self::assertArrayHasKey('401', $document['paths']['/mapped']['get']['responses']);
        self::assertSame('Too Many Requests', $document['paths']['/mapped']['get']['responses']['429']['description']);
        self::assertSame([BearerAuth::class], $route->middleware);
    }

    public function testStackedMappedMiddlewareRequiresAllSecuritySchemes(): void
    {
        $router = new Router();
        $router->get('/secured', static fn(): string => 'ok')->middleware(new BearerAuth(), new ApiKeyAuth());

        $operation = $this->document($router, [
            'security' => [
                'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
                'apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Key'],
            ],
            'middleware' => [
                BearerAuth::class => ['security' => 'bearer'],
                ApiKeyAuth::class => ['security' => 'apiKey'],
            ],
        ])['paths']['/secured']['get'];

        self::assertSame([['bearer' => [], 'apiKey' => []]], $operation['security']);
    }

    public function testGlobalMappedMiddlewareEffectsApplyToEveryOperation(): void
    {
        $router = new Router();
        $router->get('/global', static fn(): string => 'ok');

        $operation = $this->builder(
            $router,
            [
                'security' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
                'middleware' => [BearerAuth::class => ['security' => 'bearer', 'responses' => [429]]],
            ],
            [BearerAuth::class],
        )->build(false)->document['paths']['/global']['get'];

        self::assertSame([['bearer' => []]], $operation['security']);
        self::assertArrayHasKey('401', $operation['responses']);
        self::assertArrayHasKey('429', $operation['responses']);
    }

    public function testRequestAndResponseComponentsDoNotDependOnRouteOrder(): void
    {
        $makeRouter = static function (bool $responseFirst): Router {
            $router = new Router();
            if ($responseFirst) {
                $router->get('/output', static fn(): SharedThing => throw new \LogicException());
                $router->post('/input', static fn(SharedThing $thing): string => 'ok');
            } else {
                $router->post('/input', static fn(SharedThing $thing): string => 'ok');
                $router->get('/output', static fn(): SharedThing => throw new \LogicException());
            }
            return $router;
        };

        $first = $this->document($makeRouter(true));
        $second = $this->document($makeRouter(false));
        $inputRef = ['$ref' => '#/components/schemas/Crab.OpenApi.Tests.Fixtures.SharedThing.Input'];
        $outputRef = ['$ref' => '#/components/schemas/Crab.OpenApi.Tests.Fixtures.SharedThing.Output'];

        self::assertSame(
            $inputRef,
            $first['paths']['/input']['post']['requestBody']['content']['application/json']['schema'],
        );
        self::assertSame(
            $outputRef,
            $first['paths']['/output']['get']['responses']['200']['content']['application/json']['schema'],
        );
        self::assertSame(
            $inputRef,
            $second['paths']['/input']['post']['requestBody']['content']['application/json']['schema'],
        );
        self::assertSame(
            $outputRef,
            $second['paths']['/output']['get']['responses']['200']['content']['application/json']['schema'],
        );
        self::assertSame(
            2,
            $first['components']['schemas']['Crab.OpenApi.Tests.Fixtures.SharedThing.Input']['properties']['name']['minLength'],
        );
        self::assertArrayNotHasKey(
            'minLength',
            $first['components']['schemas']['Crab.OpenApi.Tests.Fixtures.SharedThing.Output']['properties']['name'],
        );
    }

    public function testUninitializedPublicPropertiesAreNotMarkedRequired(): void
    {
        $router = new Router();
        $router->get('/view', static fn(): UninitializedView => throw new \LogicException());

        $schema = $this->document($router)['components']['schemas']['Crab.OpenApi.Tests.Fixtures.UninitializedView'];

        self::assertSame(['note'], $schema['required']);
    }

    public function testOverlappingPropertyTypesAcceptAnInteger(): void
    {
        $router = new Router();
        $router->get('/number', static fn(): NumberView => new NumberView(1));

        $schema = $this->document($router)['components']['schemas']['Crab.OpenApi.Tests.Fixtures.NumberView'];

        self::assertSame(['anyOf' => [['type' => 'integer'], ['type' => 'number']]], $schema['properties']['value']);
    }

    public function testInheritedPropertyTypesUseTheirDeclaringClass(): void
    {
        $router = new Router();
        $router->get('/inherited', static fn(): InheritedView => new InheritedView());

        $schemas = $this->document($router)['components']['schemas'];
        $parent = ['$ref' => '#/components/schemas/Crab.OpenApi.Tests.Fixtures.ParentView'];

        self::assertSame(
            ['oneOf' => [$parent, ['type' => 'null']]],
            $schemas['Crab.OpenApi.Tests.Fixtures.InheritedView']['properties']['next'],
        );
        self::assertSame(['next'], array_keys($schemas['Crab.OpenApi.Tests.Fixtures.ParentView']['properties']));
    }

    public function testAnEnumWithCustomJsonSerializationNeedsAnExplicitSchema(): void
    {
        $router = new Router();
        $router->get('/serialized-enum', static fn(): SerializedKind => SerializedKind::Simple);

        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage('JsonSerializable shape cannot be inferred');
        $this->builder($router)->build();
    }

    public function testBodiesOnNoContentStatusesFailStrictGeneration(): void
    {
        $router = new Router();
        $router->get('/no-content', #[Response(204, body: Thing::class)] static fn(): string => 'ok');
        $router->get('/not-modified', #[Response(304, body: Thing::class)] static fn(): string => 'ok');
        $router->get('/early-hints', #[Response(103, body: Thing::class)] static fn(): string => 'ok');

        $builder = $this->builder($router);
        try {
            $builder->build();
            self::fail('Strict generation accepted a body on a no-content response.');
        } catch (GenerationException $error) {
            self::assertStringContainsString('must not have response content', $error->getMessage());
        }

        $document = $builder->build(false)->document;
        self::assertArrayNotHasKey('content', $document['paths']['/no-content']['get']['responses']['204']);
        self::assertArrayNotHasKey('content', $document['paths']['/not-modified']['get']['responses']['304']);
        self::assertArrayNotHasKey('content', $document['paths']['/early-hints']['get']['responses']['103']);
    }

    public function testMiddlewareResponsesDoNotInventBodiesForBodylessStatuses(): void
    {
        $router = new Router();
        $router->get('/mapped', static fn(): string => 'ok')->middleware(new BearerAuth());

        $responses = $this->document($router, [
            'middleware' => [BearerAuth::class => ['responses' => [204, 205, 304]]],
        ])['paths']['/mapped']['get']['responses'];

        foreach ([204, 205, 304] as $status) {
            self::assertArrayNotHasKey('content', $responses[$status]);
        }
    }

    public function testMappedSuccessResponsesNeedAnExplicitBodyDeclaration(): void
    {
        $router = new Router();
        $router->get('/mapped', static fn(): string => 'ok')->middleware(new BearerAuth());

        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage('middleware response 202 without a body schema');
        $this->document($router, ['middleware' => [BearerAuth::class => ['responses' => [202]]]]);
    }

    public function testUndefinedSecuritySchemesFailStrictGeneration(): void
    {
        $router = new Router();
        $router->get('/secured', #[Security('missing')] static fn(): string => 'ok');

        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage('openapi.security does not define it');
        $this->builder($router)->build();
    }

    public function testMalformedSecurityServersAndMiddlewareConfigurationFailsStrictGeneration(): void
    {
        $router = new Router();
        $router->get('/configured', static fn(): string => 'ok');

        $builder = $this->builder($router, [
            'security' => ['broken' => ['type' => 'unknown']],
            'servers' => [['description' => 'missing url']],
            'middleware' => [BearerAuth::class => ['responses' => [700], 'unexpected' => true]],
        ]);

        try {
            $builder->build();
            self::fail('Strict generation accepted malformed OpenAPI configuration.');
        } catch (GenerationException $error) {
            self::assertStringContainsString('openapi.security.broken.type', $error->getMessage());
            self::assertStringContainsString('openapi.servers.0', $error->getMessage());
            self::assertStringContainsString('openapi.middleware', $error->getMessage());
        }
    }

    public function testRecursiveClassesBecomeReferencesAndAreRegisteredOnce(): void
    {
        $router = new Router();
        $router->get('/nodes', static fn(): RecursiveNode => new RecursiveNode('root'));
        $router->get('/again', static fn(): RecursiveNode => new RecursiveNode('again'));

        $schemas = $this->document($router)['components']['schemas'];

        self::assertArrayHasKey('Crab.OpenApi.Tests.Fixtures.RecursiveNode', $schemas);
        self::assertSame(
            ['oneOf' => [['$ref' => self::NODE], ['type' => 'null']]],
            $schemas['Crab.OpenApi.Tests.Fixtures.RecursiveNode']['properties']['next'],
        );
    }

    public function testHiddenExcludedAndWebSocketRoutesAreAbsent(): void
    {
        $router = new Router();
        $router->get('/visible', static fn(): string => 'visible');
        $router->get('/class-hidden', HiddenAction::class);
        $router->get('/closure-hidden', #[Hidden] static fn(): string => 'hidden');
        $router->get('/excluded', static fn(): string => 'excluded');
        $router->websocket('/live', static function (\Crab\Contracts\WebSocket\Socket $socket): void {});

        $document = $this->document($router, ['exclude' => ['GET /excluded']]);

        self::assertSame(['/visible'], array_keys($document['paths']));
    }

    public function testUnrelatedAttributesAreNeverConstructed(): void
    {
        $router = new Router();
        $router->get('/metadata', #[UnrelatedMetadata, \DocumentationOnlyAttribute] static fn(): string => 'ok');

        $result = $this->builder($router)->build();

        self::assertSame([], $result->warnings);
        self::assertArrayHasKey('/metadata', $result->document['paths']);
    }

    public function testHiddenRoutesDoNotValidateResponseAttributes(): void
    {
        $router = new Router();
        $router->get('/hidden', #[Hidden, Response(99)] static fn(): string => 'hidden');

        $result = $this->builder($router)->build();

        self::assertSame([], $result->warnings);
        self::assertInstanceOf(\stdClass::class, $result->document['paths']);
    }

    public function testOutputIsDeterministicAcrossBuilds(): void
    {
        $router = new Router();
        $router->get('/things', static fn(): Thing => throw new \LogicException());
        $router->get('/things/{id}', static fn(int $id): Thing => throw new \LogicException());
        $builder = $this->builder($router);

        $encode = static fn(array $document): string => json_encode($document, JSON_THROW_ON_ERROR);

        self::assertSame($encode($builder->build()->document), $encode($builder->build()->document));
    }

    public function testInfoAndClosureSummariesHaveUsefulDefaults(): void
    {
        $router = new Router();
        $router->get('/', static fn(): string => 'home');

        $document = $this->document($router, [
            'description' => 'A test API.',
            'license' => ['name' => 'MIT', 'identifier' => 'MIT'],
            'servers' => ['https://api.example.test'],
        ]);

        self::assertSame('GET /', $document['paths']['/']['get']['summary']);
        self::assertSame('A test API.', $document['info']['description']);
        self::assertSame(['name' => 'MIT', 'identifier' => 'MIT'], $document['info']['license']);
        self::assertSame([['url' => 'https://api.example.test']], $document['servers']);
    }

    public function testEmptyOAuthScopesAndServerVariablesEncodeAsObjects(): void
    {
        $document = $this->document(new Router(), [
            'security' => [
                'oauth' => [
                    'type' => 'oauth2',
                    'flows' => ['clientCredentials' => ['tokenUrl' => '/token', 'scopes' => []]],
                ],
            ],
            'servers' => [['url' => 'https://api.example.test', 'variables' => []]],
        ]);
        $decoded = json_decode(json_encode($document, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);

        self::assertInstanceOf(
            \stdClass::class,
            $decoded->components->securitySchemes->oauth->flows->clientCredentials->scopes,
        );
        self::assertInstanceOf(\stdClass::class, $decoded->servers[0]->variables);
    }

    public function testALicenseCannotHaveBothAnIdentifierAndAUrl(): void
    {
        $this->expectException(GenerationException::class);
        $this->expectExceptionMessage('openapi.license');
        $this->document(new Router(), ['license' => [
            'name' => 'MIT',
            'identifier' => 'MIT',
            'url' => 'https://opensource.org/license/mit/',
        ]]);
    }

    public function testMethodOperationDetailsExtendClassOperationDefaults(): void
    {
        $router = new Router();
        $router->get('/operation-defaults', [OperationDefaultsAction::class, 'handle']);

        $operation = $this->document($router)['paths']['/operation-defaults']['get'];

        self::assertSame('Show the item.', $operation['summary']);
        self::assertSame('Items.', $operation['description']);
        self::assertSame(['items'], $operation['tags']);
        self::assertSame('items.show', $operation['operationId']);
        self::assertTrue($operation['deprecated']);
    }

    public function testUnknownPsrHandlersAreHonestAndHaveNoInventedInputOrBody(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Not called.');
            }
        };
        $router = new Router();
        $router->get('/handler', $handler);
        $builder = $this->builder($router);

        $this->expectException(GenerationException::class);
        $builder->build();
    }

    public function testNonStrictPsrHandlerOutputIsMarkedGeneric(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Not called.');
            }
        };
        $router = new Router();
        $router->get('/handler', $handler);

        $operation = $this->builder($router)->build(false)->document['paths']['/handler']['get'];

        self::assertTrue($operation['responses']['200']['x-crab-untyped-body']);
        self::assertArrayNotHasKey('parameters', $operation);
        self::assertArrayNotHasKey('requestBody', $operation);
    }

    #[DataProvider('collisionProvider')]
    public function testAmbiguousOperationsFailStrictGeneration(callable $declare): void
    {
        $router = new Router();
        $declare($router);

        $this->expectException(GenerationException::class);
        $this->builder($router)->build();
    }

    public static function collisionProvider(): array
    {
        return [
            'two route patterns become one OpenAPI path' => [static function (Router $router): void {
                $router->get('/things/{id:\d+}', static fn(int $id): string => 'one');
                $router->get('/things/{slug}', static fn(string $slug): string => 'two');
            }],
            'equivalent paths have different parameter names across methods' =>
                [static function (Router $router): void {
                    $router->get('/things/{id}', static fn(int $id): string => 'one');
                    $router->put('/things/{slug}', static fn(string $slug): string => 'two');
                }],
            'operation ids repeat' => [static function (Router $router): void {
                $router->get('/one', #[Operation(operationId: 'same')] static fn(): string => 'one');
                $router->get('/two', #[Operation(operationId: 'same')] static fn(): string => 'two');
            }],
            'an HTTP method has no OpenAPI operation' => [static function (Router $router): void {
                $router->add('PURGE', '/cache', static function (): void {});
            }],
        ];
    }

    private function document(Router $router, array $openapi = []): array
    {
        return $this->builder($router, $openapi)->build()->document;
    }

    private function builder(Router $router, array $openapi = [], array $globalMiddleware = []): DocumentBuilder
    {
        return new DocumentBuilder(
            $router,
            new Config([
                'app' => ['name' => 'Test API'],
                'openapi' => ['strict' => true, ...$openapi],
            ]),
            globalMiddleware: $globalMiddleware,
        );
    }
}
