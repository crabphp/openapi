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
    private const NO_CONTENT_STATUSES = [204, 304, 205];

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
    /**
     * @var list<string>
     */
    private array $globalMiddleware;
    /**
     * @var array<string, array{security: list<string>, responses: list<int>}>
     */
    private array $middlewareEffects = [];
    private bool $securityValidated = false;
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $validatedSecuritySchemes = [];

    public function __construct(
        private readonly Router $router,
        private readonly Config $config,
        private readonly bool $customErrorRenderer = false,
        array $globalMiddleware = [],
    ) {
        $this->globalMiddleware = array_values(array_filter($globalMiddleware, is_string(...)));
    }

    public function build(?bool $strict = null): BuildResult
    {
        $this->strict = $strict ?? (bool) $this->config->get('openapi.strict', true);
        $this->warnings = [];
        $this->fatal = [];
        $this->operationIds = [];
        $this->middlewareEffects = [];
        $this->securityValidated = false;
        $this->validatedSecuritySchemes = [];
        $this->schemas = new SchemaRegistry();
        $this->types = new TypeSchema($this->schemas, function (string $warning): void {
            $this->failOrWarn($warning);
        });

        $this->schemas->splitClasses($this->schemaSplitClasses($this->router->routes()));

        $this->schemas->add('Error', $this->errorSchema());
        $securitySchemes = $this->securitySchemes();
        $this->middlewareEffects = $this->configuredMiddleware();

        $paths = [];
        $pathMethods = [];
        $pathPatterns = [];
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
            if (
                isset($pathMethods[$collision][$method])
                || isset($pathPatterns[$collision]) && $pathPatterns[$collision] !== $path
            ) {
                $this->failOrWarn(
                    "{$route->method} {$route->pattern} resolves to the same OpenAPI path as another route.",
                );
            }
            $pathMethods[$collision][$method] = true;
            $pathPatterns[$collision] ??= $path;
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

        $actionClass = $this->actionClass($route, $signature);
        if ($this->hidden($signature, $actionClass)) {
            return null;
        }
        $methodAttributes = $this->attributes($signature);
        $classAttributes = $actionClass === null ? [] : $this->attributes($actionClass);
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

        $parameters = $this->parameters($input, $where);
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
            ? $this->inferredResponses($signature, $where, $actionClass)
            : $this->declaredResponses($responses, $where);
        $this->addErrors($result['responses'], $input, $route, $security);
        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    private function parameters(array $input, string $where): array
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
                $types = (array) ($schema['type'] ?? []);
                $object = in_array('object', $types, true);
                $list = in_array('array', $types, true);
                $supported = self::queryScalar($schema);
                if ($list && !$object) {
                    $supported = self::queryScalar($schema['items'] ?? []);
                } elseif ($object && !$list) {
                    $supported = isset($schema['properties']);
                    foreach (self::object($schema['properties'] ?? []) as $property) {
                        $supported = $supported && self::queryScalar($property);
                    }
                }
                if (!$supported) {
                    $this->failOrWarn(
                        "{$where} query field {$name} has a shape without a standard OpenAPI serialization; use scalar fields, lists of scalars or objects with scalar properties.",
                    );
                }
                $parameter = [
                    'name' => $list && !$object ? $name . '[]' : $name,
                    'in' => 'query',
                    'required' => in_array($name, $required, true),
                    'schema' => $this->schema($schema),
                    'style' => $object ? 'deepObject' : 'form',
                    'explode' => true,
                ];
                $parameters[] = $parameter;
            }
        }
        return $parameters;
    }

    private static function queryScalar(array $schema): bool
    {
        $types = (array) ($schema['type'] ?? []);
        return (
            $types !== []
            && array_diff($types, ['string', 'integer', 'number', 'boolean', 'null']) === []
            && ($schema['format'] ?? null) !== 'binary'
        );
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
            if ($response->body !== null && self::hasNoContent($response->status)) {
                $this->failOrWarn(
                    "{$where} declares a body for HTTP {$response->status}, which must not have response content.",
                );
            } elseif ($response->body !== null) {
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
            $headers = $this->headers($response->headers, $where);
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
    private function inferredResponses(
        \ReflectionFunctionAbstract $signature,
        string $where,
        ?\ReflectionClass $actionClass,
    ): array {
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
        $declaringClass = $this->returnTypeClass($signature, $actionClass);
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
            if ($body === []) {
                return new \stdClass();
            }
            if (count($body) === 1 && is_string($body[0]) && class_exists($body[0])) {
                return ['type' => 'array', 'items' => $this->types->forClass($body[0], $where)];
            }
            $this->failOrWarn(
                "{$where} declares an array response body; use one class for a list, or a JSON Schema object.",
            );
            return new \stdClass();
        }
        return $this->schemas->normalize($body, 'output');
    }

    /**
     * @param array<int|string, mixed> $headers
     * @return array<string, array<string, mixed>>
     */
    private function headers(array $headers, string $where): array
    {
        $result = [];
        foreach ($headers as $name => $header) {
            if (is_int($name) && is_string($header)) {
                if (trim($header) === '') {
                    $this->failOrWarn("{$where} declares an empty response header name.");
                    continue;
                }
                $result[$header] = ['schema' => ['type' => 'string']];
                continue;
            }
            if (!is_string($name)) {
                $this->failOrWarn("{$where} declares a response header with a non-string name.");
                continue;
            }
            if (trim($name) === '') {
                $this->failOrWarn("{$where} declares an empty response header name.");
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
            $result[$name] = isset($header['schema'])
                ? $header
                : ['schema' => $this->schemas->normalize($header, 'output')];
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

        foreach ($this->allMiddleware($route) as $class) {
            $effect = $this->middlewareEffects[$class] ?? null;
            if ($effect === null) {
                continue;
            }
            foreach ($effect['responses'] as $status) {
                if ($status < 100 || $status > 599) {
                    continue;
                }
                if (!isset($responses[(string) $status])) {
                    if ($status >= 400) {
                        $responses[(string) $status] = $this->errorResponse(self::reason($status));
                    } else {
                        $responses[(string) $status] = ['description' => self::reason($status)];
                        if (!self::hasNoContent($status)) {
                            $this->failOrWarn(
                                "{$route->method} {$route->pattern} maps middleware response {$status} without a body schema; declare it with #[Response].",
                            );
                            $responses[(string) $status]['x-crab-untyped-body'] = true;
                        }
                    }
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

        $requirement = [];
        foreach ($this->allMiddleware($route) as $class) {
            $effect = $this->middlewareEffects[$class] ?? null;
            if ($effect === null) {
                continue;
            }
            foreach ($effect['security'] as $scheme) {
                $requirement[$scheme] = [];
            }
        }
        if ($requirement !== []) {
            $this->checkSecuritySchemes($requirement, $route, $configured);
            // Middleware is stacked, so every mapped scheme is required. A
            // repeated #[Security] attribute remains an explicit alternative.
            $security[] = $requirement;
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
        if ($configured !== null) {
            if (!is_array($configured) || array_is_list($configured)) {
                $this->failOrWarn('openapi.error_schema must be a JSON Schema object.');
            } else {
                return $this->schemas->normalize($configured, 'output');
            }
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
        if ($this->securityValidated) {
            return $this->validatedSecuritySchemes;
        }
        $this->securityValidated = true;
        $configured = $this->config->get('openapi.security', []);
        if (!is_array($configured) || $configured !== [] && array_is_list($configured)) {
            $this->failOrWarn('openapi.security must be a map of scheme names to OpenAPI security scheme objects.');
            return [];
        }
        $schemes = [];
        foreach ($configured as $name => $scheme) {
            if (!is_string($name) || trim($name) === '' || preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
                $this->failOrWarn('openapi.security scheme names must be non-empty strings.');
                continue;
            }
            if (!is_array($scheme) || array_is_list($scheme)) {
                $this->failOrWarn("openapi.security.{$name} must be an object.");
                continue;
            }
            if (!$this->validSecurityScheme($name, $scheme)) {
                continue;
            }
            if ($scheme['type'] === 'oauth2') {
                foreach ($scheme['flows'] as $flowName => $flow) {
                    $scheme['flows'][$flowName]['scopes'] = $flow['scopes'] === [] ? new \stdClass() : $flow['scopes'];
                }
            }
            $schemes[$name] = $scheme;
        }
        return $this->validatedSecuritySchemes = $schemes;
    }

    /**
     * @return array<string, array{security: list<string>, responses: list<int>}>
     */
    private function configuredMiddleware(): array
    {
        $configured = $this->config->get('openapi.middleware', []);
        if (!is_array($configured) || $configured !== [] && array_is_list($configured)) {
            $this->failOrWarn('openapi.middleware must be a map of middleware classes to effects.');
            return [];
        }
        $effects = [];
        foreach ($configured as $class => $effect) {
            if (!is_string($class) || trim($class) === '') {
                $this->failOrWarn('openapi.middleware keys must be non-empty middleware class names.');
                continue;
            }
            if (!is_array($effect) || array_is_list($effect)) {
                $this->failOrWarn("openapi.middleware.{$class} must be an object.");
                continue;
            }
            $security = $effect['security'] ?? [];
            if (is_string($security)) {
                $security = [$security];
            }
            if (!is_array($security) || !array_is_list($security)) {
                $this->failOrWarn("openapi.middleware.{$class}.security must be a name or list of names.");
                $security = [];
            }
            $securityNames = [];
            foreach ($security as $scheme) {
                if (!is_string($scheme) || trim($scheme) === '' || preg_match('/\s/', $scheme) === 1) {
                    $this->failOrWarn("openapi.middleware.{$class}.security must contain only non-empty scheme names.");
                    continue;
                }
                $securityNames[] = $scheme;
            }
            $responses = $effect['responses'] ?? [];
            if (!is_array($responses) || !array_is_list($responses)) {
                $this->failOrWarn("openapi.middleware.{$class}.responses must be a list of HTTP statuses.");
                $responses = [];
            }
            $statuses = [];
            foreach ($responses as $status) {
                if (!is_int($status) || $status < 100 || $status > 599) {
                    $this->failOrWarn(
                        "openapi.middleware.{$class}.responses must contain integers between 100 and 599.",
                    );
                    continue;
                }
                $statuses[] = $status;
            }
            $unknown = array_diff(array_keys($effect), ['security', 'responses']);
            foreach ($unknown as $key) {
                $this->failOrWarn("openapi.middleware.{$class}.{$key} is not a supported middleware effect.");
            }
            $effects[$class] = [
                'security' => array_values(array_unique($securityNames)),
                'responses' => array_values(array_unique($statuses)),
            ];
        }
        return $effects;
    }

    /**
     * Global middleware is outside RouteInfo by design, so add it to every
     * operation before applying the route and group middleware.
     *
     * @return list<string>
     */
    private function allMiddleware(RouteInfo $route): array
    {
        return array_values(array_unique([...$this->globalMiddleware, ...$route->middleware]));
    }

    /**
     * @param array<string, mixed> $scheme
     */
    private function validSecurityScheme(string $name, array $scheme): bool
    {
        $type = $scheme['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['apiKey', 'http', 'mutualTLS', 'oauth2', 'openIdConnect'], true)) {
            $this->failOrWarn(
                "openapi.security.{$name}.type must be apiKey, http, mutualTLS, oauth2 or openIdConnect.",
            );
            return false;
        }
        if (array_key_exists('description', $scheme) && !is_string($scheme['description'])) {
            $this->failOrWarn("openapi.security.{$name}.description must be a string.");
            return false;
        }
        $allowed = [
            'apiKey' => ['type', 'description', 'name', 'in'],
            'http' => ['type', 'description', 'scheme', 'bearerFormat'],
            'mutualTLS' => ['type', 'description'],
            'oauth2' => ['type', 'description', 'flows'],
            'openIdConnect' => ['type', 'description', 'openIdConnectUrl'],
        ][$type];
        foreach (array_keys($scheme) as $field) {
            if (is_string($field) && (in_array($field, $allowed, true) || str_starts_with($field, 'x-'))) {
                continue;
            }
            $this->failOrWarn("openapi.security.{$name}.{$field} is not valid for a {$type} security scheme.");
            return false;
        }
        $valid = match ($type) {
            'apiKey' => is_string($scheme['name'] ?? null)
                && trim($scheme['name']) !== ''
                && preg_match('/\s/', $scheme['name']) !== 1
                && is_string($scheme['in'] ?? null)
                && in_array($scheme['in'], ['query', 'header', 'cookie'], true),
            'http' => is_string($scheme['scheme'] ?? null)
                && trim($scheme['scheme']) !== ''
                && preg_match('/\s/', $scheme['scheme']) !== 1
                && (!array_key_exists('bearerFormat', $scheme) || is_string($scheme['bearerFormat'])),
            'mutualTLS' => true,
            'oauth2' => $this->validOAuthFlows($name, $scheme['flows'] ?? null),
            'openIdConnect' => is_string($scheme['openIdConnectUrl'] ?? null)
                && trim($scheme['openIdConnectUrl']) !== '',
        };
        if (!$valid) {
            $this->failOrWarn("openapi.security.{$name} is missing fields required by its {$type} type.");
        }
        return $valid;
    }

    private function validOAuthFlows(string $name, mixed $flows): bool
    {
        if (!is_array($flows) || array_is_list($flows) || $flows === []) {
            return false;
        }
        $valid = true;
        foreach ($flows as $flowName => $flow) {
            if (
                !is_string($flowName)
                || !in_array(
                    $flowName,
                    [
                        'implicit',
                        'password',
                        'clientCredentials',
                        'authorizationCode',
                    ],
                    true,
                )
            ) {
                $valid = false;
                continue;
            }
            if (!is_array($flow) || array_is_list($flow)) {
                $valid = false;
                continue;
            }
            $allowed = match ($flowName) {
                'implicit' => ['authorizationUrl', 'refreshUrl', 'scopes'],
                'password', 'clientCredentials' => ['tokenUrl', 'refreshUrl', 'scopes'],
                'authorizationCode' => ['authorizationUrl', 'tokenUrl', 'refreshUrl', 'scopes'],
            };
            foreach (array_keys($flow) as $field) {
                if (is_string($field) && (in_array($field, $allowed, true) || str_starts_with($field, 'x-'))) {
                    continue;
                }
                $valid = false;
            }
            $urlField = in_array($flowName, ['implicit', 'authorizationCode'], true) ? 'authorizationUrl' : 'tokenUrl';
            if (
                $flowName === 'authorizationCode'
                && (!is_string($flow['tokenUrl'] ?? null) || trim($flow['tokenUrl']) === '')
            ) {
                $valid = false;
            }
            if (!is_string($flow[$urlField] ?? null) || trim($flow[$urlField]) === '') {
                $valid = false;
            }
            if (
                array_key_exists('refreshUrl', $flow)
                && (!is_string($flow['refreshUrl']) || trim($flow['refreshUrl']) === '')
            ) {
                $valid = false;
            }
            $scopes = $flow['scopes'] ?? null;
            if (!$this->validScopes($scopes)) {
                $valid = false;
            }
        }
        if (!$valid) {
            $this->failOrWarn("openapi.security.{$name}.flows contains an invalid OAuth flow.");
        }
        return $valid;
    }

    private function validScopes(mixed $scopes): bool
    {
        if (!is_array($scopes) || $scopes !== [] && array_is_list($scopes)) {
            return false;
        }
        foreach ($scopes as $scope => $description) {
            if (!is_string($scope) || trim($scope) === '' || !is_string($description)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function info(): array
    {
        $title = $this->config->get('openapi.title');
        if ($title === null) {
            $title = $this->config->get('app.name') ?? 'API';
        }
        if (!is_string($title) || trim($title) === '') {
            $this->failOrWarn('openapi.title must be a non-empty string.');
            $title = 'API';
        }
        $version = $this->config->get('openapi.version', '1.0.0');
        if (!is_string($version) || trim($version) === '') {
            $this->failOrWarn('openapi.version must be a non-empty string.');
            $version = '1.0.0';
        }
        $info = [
            'title' => $title,
            'version' => $version,
        ];
        $description = $this->config->get('openapi.description');
        if ($description !== null && !is_string($description)) {
            $this->failOrWarn('openapi.description must be a string or null.');
        } elseif (is_string($description) && $description !== '') {
            $info['description'] = $description;
        }
        $license = $this->config->get('openapi.license');
        if (is_string($license) && trim($license) !== '') {
            $info['license'] = ['name' => $license];
        } elseif ($license !== null) {
            if (
                !is_array($license)
                || array_is_list($license)
                || !is_string($license['name'] ?? null)
                || trim($license['name']) === ''
            ) {
                $this->failOrWarn('openapi.license must be a non-empty name or a license object with a name.');
            } else {
                $invalid = false;
                foreach (['url', 'identifier'] as $field) {
                    if (!array_key_exists($field, $license)) {
                        continue;
                    }
                    if (!is_string($license[$field]) || trim($license[$field]) === '') {
                        $invalid = true;
                    }
                }
                if (isset($license['url'], $license['identifier'])) {
                    $this->failOrWarn('openapi.license takes either url or identifier, not both.');
                } elseif ($invalid) {
                    $this->failOrWarn('openapi.license.url and openapi.license.identifier must be strings.');
                } else {
                    $info['license'] = $license;
                }
            }
        }
        return $info;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function servers(): array
    {
        $configured = $this->config->get('openapi.servers');
        if ($configured !== null && !is_array($configured)) {
            $this->failOrWarn('openapi.servers must be a list of URLs or server objects.');
            return [];
        }
        if (is_array($configured) && $configured !== []) {
            $servers = [];
            if (!array_is_list($configured)) {
                $this->failOrWarn('openapi.servers must be a list of URLs or server objects.');
                return [];
            }
            foreach ($configured as $index => $server) {
                if (is_string($server) && trim($server) !== '') {
                    $servers[] = ['url' => $server];
                    continue;
                }
                if (
                    !is_array($server)
                    || array_is_list($server)
                    || !is_string($server['url'] ?? null)
                    || trim($server['url']) === ''
                ) {
                    $this->failOrWarn("openapi.servers.{$index} must have a non-empty string url.");
                    continue;
                }
                $invalid = false;
                foreach (array_keys($server) as $key) {
                    if (is_string($key) && in_array($key, ['url', 'description', 'variables'], true)) {
                        continue;
                    }
                    $invalid = true;
                }
                if (array_key_exists('description', $server) && !is_string($server['description'])) {
                    $invalid = true;
                }
                if (
                    array_key_exists('variables', $server)
                    && (
                        !is_array($server['variables'])
                        || $server['variables'] !== []
                        && array_is_list($server['variables'])
                    )
                ) {
                    $invalid = true;
                }
                if (
                    is_array($server['variables'] ?? null)
                    && ($server['variables'] === [] || !array_is_list($server['variables']))
                ) {
                    foreach ($server['variables'] as $variableName => $variable) {
                        if (
                            !is_string($variableName)
                            || trim($variableName) === ''
                            || !is_array($variable)
                            || array_is_list($variable)
                            || !is_string($variable['default'] ?? null)
                        ) {
                            $invalid = true;
                            continue;
                        }
                        if (array_key_exists('description', $variable) && !is_string($variable['description'])) {
                            $invalid = true;
                        }
                        foreach (array_keys($variable) as $variableField) {
                            if (
                                is_string($variableField)
                                && in_array($variableField, ['enum', 'default', 'description'], true)
                            ) {
                                continue;
                            }
                            $invalid = true;
                        }
                        if (
                            array_key_exists('enum', $variable)
                            && (
                                !is_array($variable['enum'])
                                || !array_is_list($variable['enum'])
                                || $variable['enum'] === []
                                || array_filter($variable['enum'], static fn(mixed $value): bool => !is_string($value))
                                !== []
                                || !in_array($variable['default'], $variable['enum'], true)
                            )
                        ) {
                            $invalid = true;
                        }
                    }
                }
                if ($invalid) {
                    $this->failOrWarn("openapi.servers.{$index} contains invalid server fields.");
                } else {
                    if (($server['variables'] ?? null) === []) {
                        $server['variables'] = new \stdClass();
                    }
                    $servers[] = $server;
                }
            }
            return $servers;
        }
        $url = $this->config->get('app.url');
        if ($url === null) {
            return [];
        }
        if (!is_string($url) || trim($url) === '') {
            $this->failOrWarn('app.url must be a non-empty string when used as the OpenAPI server URL.');
            return [];
        }
        return [['url' => $url]];
    }

    private function excluded(RouteInfo $route): bool
    {
        $excluded = $this->config->get('openapi.exclude', []);
        if (!is_array($excluded)) {
            $this->failOrWarn('openapi.exclude must be a list of route keys, names or action classes.');
            return false;
        }
        $key = "{$route->method} {$route->pattern}";
        foreach ($excluded as $value) {
            if (!is_string($value)) {
                $this->failOrWarn('openapi.exclude must contain only strings.');
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

    private function actionClass(RouteInfo $route, \ReflectionFunctionAbstract $signature): ?\ReflectionClass
    {
        if (!$signature instanceof \ReflectionMethod) {
            return null;
        }
        $class = explode('::', $route->action, 2)[0];
        return class_exists($class) ? new \ReflectionClass($class) : $signature->getDeclaringClass();
    }

    private function returnTypeClass(\ReflectionFunctionAbstract $signature, ?\ReflectionClass $actionClass): ?string
    {
        $type = $signature->getReturnType();
        if ($type instanceof \ReflectionNamedType && $type->getName() === 'static' && $actionClass !== null) {
            return $actionClass->getName();
        }
        return $signature instanceof \ReflectionMethod ? $signature->getDeclaringClass()->getName() : null;
    }

    private function hidden(\ReflectionFunctionAbstract $signature, ?\ReflectionClass $actionClass): bool
    {
        if ($this->attributes($signature, [Hidden::class]) !== []) {
            return true;
        }
        return $actionClass !== null && $this->attributes($actionClass, [Hidden::class]) !== [];
    }

    /**
     * @return list<object>
     */
    private function attributes(
        \ReflectionFunctionAbstract|\ReflectionClass $reflector,
        array $only = [Hidden::class, Operation::class, ResponseAttribute::class, Security::class],
    ): array {
        $attributes = [];
        foreach ($reflector->getAttributes() as $attribute) {
            if (!in_array($attribute->getName(), $only, true)) {
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
     * Finds classes whose request and response representations both need a
     * component. This pass runs before operations are written, making the
     * request/response component names independent of route order.
     *
     * @param list<RouteInfo> $routes
     * @return list<class-string>
     */
    private function schemaSplitClasses(array $routes): array
    {
        $inputs = [];
        $outputs = [];
        foreach ($routes as $route) {
            if ($route->method === 'WS' || $this->excluded($route)) {
                continue;
            }
            $signature = $this->router->signature($route);
            if ($signature === null) {
                continue;
            }
            $actionClass = $this->actionClass($route, $signature);
            if ($this->hidden($signature, $actionClass)) {
                continue;
            }
            $input = $this->router->input($route);
            if (is_array($input)) {
                $this->collectSchemaClasses($input, $inputs);
            }
            $declaring = $this->returnTypeClass($signature, $actionClass);
            $this->collectOutputType($signature->getReturnType(), $declaring, $outputs, []);
            $this->collectResponseAttributeClasses($signature, $outputs);
            if ($actionClass !== null) {
                $this->collectResponseAttributeClasses($actionClass, $outputs);
            }
        }
        return array_values(array_intersect(array_keys($inputs), array_keys($outputs)));
    }

    /**
     * @param mixed $schema
     * @param array<class-string, true> $classes
     */
    private function collectSchemaClasses(mixed $schema, array &$classes): void
    {
        if (!is_array($schema)) {
            return;
        }
        if (is_string($schema['x-class'] ?? null) && class_exists($schema['x-class'])) {
            $classes[$schema['x-class']] = true;
        }
        foreach ($schema as $part) {
            $this->collectSchemaClasses($part, $classes);
        }
    }

    /**
     * @param array<class-string, true> $classes
     */
    private function collectResponseAttributeClasses(
        \ReflectionFunctionAbstract|\ReflectionClass $reflector,
        array &$classes,
    ): void {
        foreach ($reflector->getAttributes(ResponseAttribute::class) as $attribute) {
            try {
                $response = $attribute->newInstance();
            } catch (\Throwable) {
                continue;
            }
            if (self::hasNoContent($response->status)) {
                continue;
            }
            $this->collectResponseBodyClasses($response->body, $classes);
        }
    }

    /**
     * @param string|array<mixed>|null $body
     * @param array<class-string, true> $classes
     */
    private function collectResponseBodyClasses(string|array|null $body, array &$classes): void
    {
        if (is_string($body) && class_exists($body)) {
            $this->collectOutputClass($body, $classes, []);
            return;
        }
        if (is_array($body)) {
            $this->collectSchemaClasses($body, $classes);
            foreach ($body as $item) {
                if (!is_string($item) || !class_exists($item)) {
                    continue;
                }
                $this->collectOutputClass($item, $classes, []);
            }
        }
    }

    /**
     * @param array<class-string, true> $classes
     * @param array<class-string, true> $seen
     */
    private function collectOutputType(
        ?\ReflectionType $type,
        ?string $declaringClass,
        array &$classes,
        array $seen,
    ): void {
        if ($type === null) {
            return;
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $part) {
                $this->collectOutputType($part, $declaringClass, $classes, $seen);
            }
            return;
        }
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return;
        }
        $name = self::resolveTypeName($type->getName(), $declaringClass);
        if (class_exists($name) || enum_exists($name)) {
            $this->collectOutputClass($name, $classes, $seen);
        }
    }

    /**
     * @param array<class-string, true> $classes
     * @param array<class-string, true> $seen
     */
    private function collectOutputClass(string $class, array &$classes, array $seen): void
    {
        if (isset($seen[$class])) {
            return;
        }
        $seen[$class] = true;
        if (
            enum_exists($class)
            || !class_exists($class)
            || is_a($class, \DateTimeInterface::class, true)
            || is_a($class, \JsonSerializable::class, true)
            || is_a($class, ResponseInterface::class, true)
            || is_a($class, \Generator::class, true)
        ) {
            return;
        }
        $classes[$class] = true;
        foreach (new \ReflectionClass($class)->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $this->collectOutputType($property->getType(), $property->getDeclaringClass()->getName(), $classes, $seen);
        }
    }

    private static function resolveTypeName(string $name, ?string $declaringClass): string
    {
        if ($declaringClass !== null && in_array($name, ['self', 'static'], true)) {
            return $declaringClass;
        }
        if ($declaringClass !== null && $name === 'parent') {
            $parent = new \ReflectionClass($declaringClass)->getParentClass();
            return $parent === false ? $name : $parent->getName();
        }
        return $name;
    }

    /**
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     * @return array<string, mixed>
     */
    private static function mergeResponse(array $from, array $to): array
    {
        if (isset($to['content']) && is_array($to['content'])) {
            foreach ($to['content'] as $media => $content) {
                $earlier = $from['content'][$media]['schema'] ?? null;
                if ($earlier !== null && $earlier !== $content['schema']) {
                    $content['schema'] = ['anyOf' => [$earlier, $content['schema']]];
                }
                $from['content'][$media] = $content;
            }
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
                205 => 'Reset Content',
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

    private static function hasNoContent(int $status): bool
    {
        return $status < 200 || in_array($status, self::NO_CONTENT_STATUSES, true);
    }
}
