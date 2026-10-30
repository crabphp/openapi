<?php

declare(strict_types=1);

namespace Crab\OpenApi;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Routing\RouteInfo;
use Crab\Contracts\Routing\Router;
use Crab\OpenApi\Attributes\Hidden;
use Crab\OpenApi\Attributes\Operation;
use Crab\OpenApi\Attributes\Response as ResponseAttribute;
use Crab\OpenApi\Attributes\Security;
use Psr\Http\Message\ResponseInterface;

/**
 * Writes an OpenAPI 3.1 document from the live route table. It infers what
 * the signature proves, uses declarations for what it cannot, and reports the
 * rest as generic rather than guessing.
 */
final class DocumentBuilder
{
    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private SchemaRegistry $schemas;
    private TypeSchema $types;
    private bool $strict = true;
    /**
     * @var list<string>
     */
    private array $warnings = [];
    /**
     * @var list<string>
     */
    private array $fatal = [];
    /**
     * @var array<string, true>
     */
    private array $operationIds = [];

    public function __construct(
        private readonly Router $router,
        private readonly Config $config,
        private readonly bool $customErrorRenderer = false,
    ) {}

    public function build(?bool $strict = null): BuildResult
    {
        $this->strict = $strict ?? (bool) $this->config->get('openapi.strict', true);
        $this->warnings = [];
        $this->fatal = [];
        $this->operationIds = [];
        $this->schemas = new SchemaRegistry();
        $this->types = new TypeSchema($this->schemas, function (string $warning): void {
            $this->failOrWarn($warning);
        });

        $this->schemas->add('Error', $this->errorSchema());
        $securitySchemes = $this->securitySchemes();

        $paths = [];
        $pathMethods = [];
        foreach ($this->router->routes() as $route) {
            if ($route->method === 'WS' || $this->excluded($route)) {
                continue;
            }
            $method = strtolower($route->method);
            if (!in_array($method, self::METHODS, true)) {
                $this->failOrWarn("{$route->method} {$route->pattern} uses a method OpenAPI cannot describe.");
                continue;
            }
            $operation = $this->operation($route);
            if ($operation === null) {
                continue;
            }
            $path = self::path($route->pattern);
            $collision = (string) preg_replace('/\{[^{}]+\}/', '{}', $path);
            if (isset($pathMethods[$collision][$method])) {
                $this->failOrWarn(
                    "{$route->method} {$route->pattern} resolves to the same OpenAPI path as another route.",
                );
            }
            $pathMethods[$collision][$method] = true;
            $paths[$path][$method] = $operation;
        }
        ksort($paths);

        $document = [
            'openapi' => '3.1.0',
            'info' => $this->info(),
            // Public unless an operation requires one of the named schemes.
            'security' => [],
        ];
        $servers = $this->servers();
        if ($servers !== []) {
            $document['servers'] = $servers;
        }
        $document['paths'] = $paths === [] ? new \stdClass() : $paths;

        $components = [];
        $schemas = $this->schemas->schemas();
        if ($schemas !== []) {
            $components['schemas'] = $schemas;
        }
        if ($securitySchemes !== []) {
            $components['securitySchemes'] = $securitySchemes;
        }
        if ($components !== []) {
            $document['components'] = $components;
        }

        if ($this->strict && $this->fatal !== []) {
            throw new GenerationException($this->fatal);
        }
        return new BuildResult($document, array_values(array_unique($this->warnings)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function operation(RouteInfo $route): ?array
    {
        $where = "{$route->method} {$route->pattern}";
        $signature = $this->router->signature($route);
        $input = $this->router->input($route);
        if ($signature === null || $input === null) {
            $this->failOrWarn("{$where} is handled by a PSR-15 handler; its request and response are not described.");
            $security = $this->security($route, []);
            $responses = [
                '200' => ['description' => 'OK', 'x-crab-untyped-body' => true],
            ];
            $this->addErrors($responses, [], $route, $security);
            $operation = [
                'summary' => $route->action,
                'operationId' => $this->operationId($route, null),
                'responses' => $responses,
            ];
            if ($security !== []) {
                $operation['security'] = $security;
            }
            return $operation;
        }

        $methodAttributes = $this->attributes($signature);
        $classAttributes = $signature instanceof \ReflectionMethod
            ? $this->attributes($signature->getDeclaringClass())
            : [];
        if ($this->hidden($signature)) {
            return null;
        }
        $methodOperation = $this->first($methodAttributes, Operation::class);
        $classOperation = $this->first($classAttributes, Operation::class);
        $operation = $methodOperation === null
            ? $classOperation
            : new Operation(
                summary: $methodOperation->summary ?? $classOperation->summary ?? null,
                description: $methodOperation->description ?? $classOperation->description ?? null,
                tags: $methodOperation->tags !== [] ? $methodOperation->tags : $classOperation->tags ?? [],
                operationId: $methodOperation->operationId ?? $classOperation->operationId ?? null,
                deprecated: $methodOperation->deprecated || ($classOperation->deprecated ?? false),
            );
        $securityAttributes = $this->all($methodAttributes, Security::class);
        if ($securityAttributes === []) {
            $securityAttributes = $this->all($classAttributes, Security::class);
        }
        $responses = $this->all($methodAttributes, ResponseAttribute::class);
        if ($responses === []) {
            $responses = $this->all($classAttributes, ResponseAttribute::class);
        }

        $result = [];
        $summary = $operation->summary ?? self::shortAction($route->action);
        if ($summary === '') {
            $summary = strtoupper($route->method) . ' ' . $route->pattern;
        }
        if ($summary !== '') {
            $result['summary'] = $summary;
        }
        if ($operation?->description !== null) {
            $result['description'] = $operation->description;
        }
        $result['operationId'] = $this->operationId($route, $operation);
        $tags = $operation->tags ?? [];
        if ($tags === []) {
            $tags = [self::defaultTag($route->pattern)];
        }
        $result['tags'] = $tags;
        if ($operation?->deprecated === true) {
            $result['deprecated'] = true;
        }

        $parameters = $this->parameters($input);
        if ($parameters !== []) {
            $result['parameters'] = $parameters;
        }
        $body = $this->requestBody($input);
        if ($body !== null) {
            $result['requestBody'] = $body;
        }

        $security = $this->security($route, $securityAttributes);
        if ($security !== []) {
            $result['security'] = $security;
        }

        $result['responses'] = $responses === []
            ? $this->inferredResponses($signature, $where)
            : $this->declaredResponses($responses, $where);
        $this->addErrors($result['responses'], $input, $route, $security);
        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    private function parameters(array $input): array
    {
        $parameters = [];
        foreach (self::object($input['parameters'] ?? []) as $name => $schema) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => $this->schema($schema),
            ];
        }
        $query = $input['query'] ?? null;
        if (is_array($query)) {
            $required = array_values(array_filter((array) ($query['required'] ?? []), is_string(...)));
            foreach (self::object($query['properties'] ?? []) as $name => $schema) {
                $parameter = [
                    'name' => $name,
                    'in' => 'query',
                    'required' => in_array($name, $required, true),
                    'schema' => $this->schema($schema),
                    'style' => 'form',
                    'explode' => true,
                ];
                $parameters[] = $parameter;
            }
        }
        return $parameters;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    private function requestBody(array $input): ?array
    {
        $body = $input['body'] ?? null;
        if (!is_array($body)) {
            return null;
        }
        $schema = $this->schema($body);
        $media = self::containsBinary($body) ? 'multipart/form-data' : 'application/json';
        return [
            'required' => is_array($body['required'] ?? null) && $body['required'] !== [],
            'content' => [$media => ['schema' => $schema]],
        ];
    }

    /**
     * @param list<ResponseAttribute> $responses
     * @return array<string, array<string, mixed>>
     */
    private function declaredResponses(array $responses, string $where): array
    {
        $result = [];
        foreach ($responses as $response) {
            $status = (string) $response->status;
            $written = [
                'description' => $response->description !== ''
                    ? $response->description
                    : self::reason($response->status),
            ];
            if ($response->body !== null && $response->status !== 204) {
                $schema = $this->bodySchema($response->body, $where);
                $written['content'] = [$response->contentType => ['schema' => $schema]];
                if ($schema instanceof \stdClass) {
                    $written['x-crab-untyped-body'] = true;
                }
            } elseif ($response->status >= 400) {
                // A declared error without its own body uses Crab's shared
                // error response, including the text/plain negotiation.
                $written = $this->errorResponse($written['description']);
            }
            $headers = $this->headers($response->headers);
            if ($headers !== []) {
                $written['headers'] = $headers;
            }
            if (isset($result[$status])) {
                $result[$status] = self::mergeResponse($result[$status], $written);
            } else {
                $result[$status] = $written;
            }
        }
        ksort($result);
        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function inferredResponses(\ReflectionFunctionAbstract $signature, string $where): array
    {
        $type = $signature->getReturnType();
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $this->failOrWarn(
                "{$where} has a union or intersection return type; declare its responses with #[Response].",
            );
            return ['200' => ['description' => 'OK', 'x-crab-untyped-body' => true]];
        }
        if ($this->types->isNever($type)) {
            $this->failOrWarn(
                "{$where} returns never, so its response is a runtime outcome; declare it with #[Response].",
            );
            return ['default' => ['description' => 'The action does not return.', 'x-crab-untyped-body' => true]];
        }
        if ($this->types->isVoid($type)) {
            return ['204' => ['description' => 'No Content']];
        }
        if ($this->types->isString($type)) {
            $responses = [
                '200' => [
                    'description' => 'OK',
                    'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
                ],
            ];
            return $this->nullableResponse($responses, $type);
        }
        if ($this->types->hasAny($type, ResponseInterface::class)) {
            $this->failOrWarn("{$where} returns a PSR-7 response; declare its status and body with #[Response].");
            return $this->nullableResponse(['200' => ['description' => 'OK', 'x-crab-untyped-body' => true]], $type);
        }
        if ($this->types->hasAny($type, \Generator::class)) {
            $this->failOrWarn("{$where} returns a stream; its event shape is documented as text.");
            return $this->nullableResponse([
                '200' => [
                    'description' => 'Server-sent events',
                    'content' => ['text/event-stream' => ['schema' => ['type' => 'string']]],
                    'x-crab-stream' => true,
                ],
            ], $type);
        }
        $declaringClass = $signature instanceof \ReflectionMethod ? $signature->getDeclaringClass()->getName() : null;
        $schema = $this->types->schema($type, $where, $declaringClass, nullable: false);
        if ($schema === null) {
            return ['204' => ['description' => 'No Content']];
        }
        $response = [
            'description' => 'OK',
            'content' => ['application/json' => ['schema' => $schema]],
        ];
        if ($schema instanceof \stdClass) {
            $response['x-crab-untyped-body'] = true;
        }
        return $this->nullableResponse(['200' => $response], $type);
    }

    /**
     * An action returning null is answered with 204, even when its type says
     * the success value is something else.
     *
     * @param array<string, array<string, mixed>> $responses
     * @return array<string, array<string, mixed>>
     */
    private function nullableResponse(array $responses, ?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType && $type->allowsNull()) {
            $responses['204'] = ['description' => 'No Content'];
        }
        return $responses;
    }

    /**
     * @param string|array<mixed> $body
     * @return array<string, mixed>|\stdClass
     */
    private function bodySchema(string|array $body, string $where): array|\stdClass
    {
        if (is_string($body)) {
            if (!class_exists($body)) {
                $this->failOrWarn("{$where} declares a response body {$body}, which is not a class.");
                return new \stdClass();
            }
            return $this->types->forClass($body, $where);
        }
        if (array_is_list($body)) {
            if (count($body) === 1 && is_string($body[0]) && class_exists($body[0])) {
                return ['type' => 'array', 'items' => $this->types->forClass($body[0], $where)];
            }
            $this->failOrWarn(
                "{$where} declares an array response body; use one class for a list, or a JSON Schema object.",
            );
            return new \stdClass();
        }
        return $this->schemas->normalize($body);
    }

    /**
     * @param array<int|string, mixed> $headers
     * @return array<string, array<string, mixed>>
     */
    private function headers(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $header) {
            if (is_int($name) && is_string($header)) {
                $result[$header] = ['schema' => ['type' => 'string']];
                continue;
            }
            if (!is_string($name)) {
                continue;
            }
            if (is_string($header)) {
                $result[$name] = ['description' => $header, 'schema' => ['type' => 'string']];
                continue;
            }
            if (!is_array($header)) {
                $result[$name] = ['schema' => ['type' => 'string']];
                continue;
            }
            $result[$name] = isset($header['schema']) ? $header : ['schema' => $this->schemas->normalize($header)];
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $responses
     * @param array<string, mixed> $input
     * @param list<array<string, list<string>>> $security
     */
    private function addErrors(array &$responses, array $input, RouteInfo $route, array $security): void
    {
        $hasInput = self::object($input['parameters'] ?? []) !== [] || isset($input['query']) || isset($input['body']);
        if ($hasInput && !isset($responses['422'])) {
            $responses['422'] = $this->errorResponse('The request is invalid.');
        }
        if (
            is_array($input['user'] ?? null)
            && ($input['user']['required'] ?? false) === true
            && !isset($responses['401'])
        ) {
            $responses['401'] = $this->errorResponse('Authentication is required.');
        }
        if ($security !== [] && !isset($responses['401'])) {
            $responses['401'] = $this->errorResponse('Authentication is required.');
        }

        $middleware = $this->config->get('openapi.middleware', []);
        if (!is_array($middleware)) {
            return;
        }
        foreach ($route->middleware as $class) {
            $effect = $middleware[$class] ?? null;
            if (!is_array($effect) || !isset($effect['responses']) || !is_array($effect['responses'])) {
                continue;
            }
            foreach ($effect['responses'] as $status) {
                if (!is_int($status)) {
                    continue;
                }
                if (!isset($responses[(string) $status])) {
                    $responses[(string) $status] = $this->errorResponse(self::reason($status));
                }
            }
        }
        ksort($responses);
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Error'],
                ],
                'text/plain' => [
                    'schema' => ['type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * @param list<Security> $attributes
     * @return list<array<string, list<string>>>
     */
    private function security(RouteInfo $route, array $attributes): array
    {
        $security = [];
        $configured = $this->securitySchemes();
        if ($attributes !== []) {
            foreach ($attributes as $attribute) {
                $requirement = [];
                foreach ($attribute->schemes as $scheme) {
                    $requirement[$scheme] = [];
                }
                $this->checkSecuritySchemes($requirement, $route, $configured);
                $security[] = $requirement;
            }
            return $security;
        }

        $mapped = $this->config->get('openapi.middleware', []);
        if (!is_array($mapped)) {
            return [];
        }
        foreach ($route->middleware as $class) {
            $effect = $mapped[$class] ?? null;
            if (!is_array($effect) || !isset($effect['security'])) {
                continue;
            }
            $schemes = is_array($effect['security']) ? $effect['security'] : [$effect['security']];
            $requirement = [];
            foreach ($schemes as $scheme) {
                if (!is_string($scheme) || $scheme === '') {
                    continue;
                }
                $requirement[$scheme] = [];
            }
            if ($requirement !== []) {
                $this->checkSecuritySchemes($requirement, $route, $configured);
                $security[] = $requirement;
            }
        }
        return $security;
    }

    /**
     * @param array<string, list<string>> $requirement
     * @param array<string, array<string, mixed>> $configured
     */
    private function checkSecuritySchemes(array $requirement, RouteInfo $route, array $configured): void
    {
        foreach (array_keys($requirement) as $scheme) {
            if (array_key_exists($scheme, $configured)) {
                continue;
            }
            $this->failOrWarn(
                "{$route->method} {$route->pattern} requires security scheme {$scheme}, but openapi.security does not define it.",
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function errorSchema(): array|\stdClass
    {
        $configured = $this->config->get('openapi.error_schema');
        if (is_array($configured)) {
            return $this->schemas->normalize($configured);
        }
        if ($this->customErrorRenderer) {
            $this->failOrWarn(
                'The application binds an ErrorRenderer, but "openapi.error_schema" is not set; error bodies are documented in Crab\'s default shape.',
            );
        }
        return ErrorSchema::make();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function securitySchemes(): array
    {
        $configured = $this->config->get('openapi.security', []);
        if (!is_array($configured)) {
            return [];
        }
        $schemes = [];
        foreach ($configured as $name => $scheme) {
            if (!is_string($name) || !is_array($scheme)) {
                continue;
            }
            $schemes[$name] = $scheme;
        }
        return $schemes;
    }

    /**
     * @return array<string, mixed>
     */
    private function info(): array
    {
        $title = $this->config->get('openapi.title') ?? $this->config->get('app.name') ?? 'API';
        $info = [
            'title' => (string) $title,
            'version' => (string) ($this->config->get('openapi.version') ?? '1.0.0'),
        ];
        $description = $this->config->get('openapi.description');
        if (is_string($description) && $description !== '') {
            $info['description'] = $description;
        }
        $license = $this->config->get('openapi.license');
        if (is_string($license) && $license !== '') {
            $info['license'] = ['name' => $license];
        } elseif (is_array($license) && isset($license['name']) && is_string($license['name'])) {
            $info['license'] = $license;
        }
        return $info;
    }

    /**
     * @return list<array{url: string}>
     */
    private function servers(): array
    {
        $configured = $this->config->get('openapi.servers');
        if (is_array($configured) && $configured !== []) {
            $servers = [];
            foreach ($configured as $server) {
                if (is_string($server)) {
                    $servers[] = ['url' => $server];
                } elseif (is_array($server) && isset($server['url']) && is_string($server['url'])) {
                    $servers[] = $server;
                }
            }
            return $servers;
        }
        $url = $this->config->get('app.url');
        return is_string($url) && $url !== '' ? [['url' => $url]] : [];
    }

    private function excluded(RouteInfo $route): bool
    {
        $excluded = $this->config->get('openapi.exclude', []);
        if (!is_array($excluded)) {
            return false;
        }
        $key = "{$route->method} {$route->pattern}";
        foreach ($excluded as $value) {
            if (!is_string($value)) {
                continue;
            }
            if ($value === $key || $value === $route->name || $value === $route->action) {
                return true;
            }
            if (str_contains($route->action, '::') && str_starts_with($route->action, $value . '::')) {
                return true;
            }
        }
        return false;
    }

    private function hidden(\ReflectionFunctionAbstract $signature): bool
    {
        if ($this->attributes($signature, [Hidden::class]) !== []) {
            return true;
        }
        return (
            $signature instanceof \ReflectionMethod
            && $this->attributes($signature->getDeclaringClass(), [Hidden::class]) !== []
        );
    }

    /**
     * @return list<object>
     */
    private function attributes(\ReflectionFunctionAbstract|\ReflectionClass $reflector, ?array $only = null): array
    {
        $attributes = [];
        foreach ($reflector->getAttributes() as $attribute) {
            if ($only !== null && !in_array($attribute->getName(), $only, true)) {
                continue;
            }
            try {
                $attributes[] = $attribute->newInstance();
            } catch (\Throwable $error) {
                $this->failOrWarn("Could not read attribute {$attribute->getName()}: {$error->getMessage()}");
            }
        }
        return $attributes;
    }

    /**
     * @param list<object> $attributes
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    private function first(array $attributes, string $class): ?object
    {
        foreach ($attributes as $attribute) {
            if ($attribute instanceof $class) {
                return $attribute;
            }
        }
        return null;
    }

    /**
     * @param list<object> $attributes
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private function all(array $attributes, string $class): array
    {
        return array_values(array_filter(
            $attributes,
            static fn(object $attribute): bool => $attribute instanceof $class,
        ));
    }

    private function operationId(RouteInfo $route, ?Operation $operation): string
    {
        $id = $operation->operationId ?? $route->name ?? self::defaultOperationId($route);
        $id = trim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '_', $id), '_.-');
        if ($id === '') {
            $id = strtolower($route->method);
        }
        $base = $id;
        $suffix = 2;
        while (isset($this->operationIds[$id])) {
            $id = $base . '_' . $suffix++;
        }
        if ($id !== $base) {
            $this->failOrWarn("Route {$route->method} {$route->pattern} has a repeated operationId; using {$id}.");
        }
        $this->operationIds[$id] = true;
        return $id;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>|\stdClass
     */
    private function schema(array $schema): array|\stdClass
    {
        return $this->schemas->normalize($schema);
    }

    /**
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     * @return array<string, mixed>
     */
    private static function mergeResponse(array $from, array $to): array
    {
        if (isset($to['content']) && is_array($to['content'])) {
            $from['content'] = [...($from['content'] ?? []), ...$to['content']];
        }
        if (isset($to['headers']) && is_array($to['headers'])) {
            $from['headers'] = [...($from['headers'] ?? []), ...$to['headers']];
        }
        if ($to['description'] !== '') {
            $from['description'] = $to['description'];
        }
        if ($to['x-crab-untyped-body'] ?? false) {
            $from['x-crab-untyped-body'] = true;
        }
        return $from;
    }

    private function failOrWarn(string $problem): void
    {
        $this->warnings[] = $problem;
        $this->fatal[] = $problem;
    }

    private static function path(string $pattern): string
    {
        return (string) preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*):(?:[^{}]++|\{[^{}]*\})+\}/', '{$1}', $pattern);
    }

    private static function defaultTag(string $pattern): string
    {
        $segment = explode('/', trim($pattern, '/'))[0] ?? '';
        return $segment === '' || str_starts_with($segment, '{') ? 'default' : $segment;
    }

    private static function shortAction(string $action): string
    {
        if ($action === 'Closure') {
            return '';
        }
        if (str_contains($action, '::')) {
            [$class, $method] = explode('::', $action, 2);
            return (string) substr($class, (int) strrpos('\\' . $class, '\\')) . '.' . $method;
        }
        return (string) substr($action, (int) strrpos('\\' . $action, '\\'));
    }

    private static function defaultOperationId(RouteInfo $route): string
    {
        if ($route->action !== 'Closure') {
            return self::shortAction($route->action);
        }
        $path = self::path($route->pattern);
        $path = (string) preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', '.$1', $path);
        $path = trim((string) preg_replace('/[^A-Za-z0-9]+/', '.', $path), '.');
        return strtolower($route->method) . ($path === '' ? '.root' : '.' . $path);
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        return is_array($value) ? $value : (array) $value;
    }

    private static function containsBinary(array $schema): bool
    {
        if (($schema['format'] ?? null) === 'binary') {
            return true;
        }
        foreach ($schema as $key => $value) {
            if ($key === 'x-class') {
                continue;
            }
            if (is_array($value) && self::containsBinary($value)) {
                return true;
            }
        }
        return false;
    }

    private static function reason(int $status): string
    {
        return (
            [
                200 => 'OK',
                201 => 'Created',
                202 => 'Accepted',
                204 => 'No Content',
                301 => 'Moved Permanently',
                302 => 'Found',
                303 => 'See Other',
                304 => 'Not Modified',
                307 => 'Temporary Redirect',
                308 => 'Permanent Redirect',
                400 => 'Bad Request',
                401 => 'Unauthorized',
                403 => 'Forbidden',
                404 => 'Not Found',
                405 => 'Method Not Allowed',
                409 => 'Conflict',
                415 => 'Unsupported Media Type',
                422 => 'Unprocessable Content',
                429 => 'Too Many Requests',
                500 => 'Internal Server Error',
                502 => 'Bad Gateway',
                503 => 'Service Unavailable',
                504 => 'Gateway Timeout',
            ][$status] ?? 'Response'
        );
    }
}
