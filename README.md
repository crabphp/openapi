# Crab OpenAPI

Generate an OpenAPI 3.1 document from a [Crab](../framework) application's route table, validation schemas and action signatures.

The package runs at build time. It adds a console command and configuration defaults; it adds no middleware and no work to a request.

## Installation

```sh
composer require crabphp/openapi
```

Register the plugin in `app.php`:

```php
use Crab\OpenApi\OpenApiPlugin;

return App::configure(__DIR__)
    ->withPlugins([
        OpenApiPlugin::class,
    ])
    ->withSetup(Setup::class)
    ->withRouting(routes: 'routes/web.php')
    ->create();
```

Create `config/openapi.php`:

```php
return [
    'title' => 'Shop API',
    'version' => '1.0.0',
    'output' => 'openapi.json',
    'strict' => true,
];
```

Generate and check the document:

```sh
php cua openapi:generate
php cua openapi:generate --check
php cua openapi:generate --stdout
```

`--check` regenerates in memory and exits non-zero when the file differs. It starts no server and opens no database or network connection.

## What is inferred

Inputs come from the same schemas the router uses to validate a request:

- path parameters, including their types and rules;
- flattened query fields, defaults and required fields;
- body classes, nested objects, enums, dates, lists and files;
- `multipart/form-data` when a body contains a file.

Responses are inferred only where Crab's runtime makes the result unambiguous:

| Return type | Documented response |
| --- | --- |
| `void`, `null` | `204` |
| `string` | `200 text/plain` |
| typed JSON scalar/class | `200 application/json` |
| nullable typed value | both the typed `200` and `204` |

A DTO's public properties become its JSON Schema. Property unions accept any of their member types, including overlapping types such as `int|float`. Inherited properties resolve `self` and `parent` in the class that declared them. A backed enum becomes an enum and a date becomes an ISO `date-time`. Recursive classes become component `$ref`s.

The generator records a warning and, in strict mode, stops when the signature cannot prove the response: a PSR-7 `ResponseInterface`, a bare `array`, `mixed`, `never`, a stream's event shape, `JsonSerializable` (including an enum that implements it), or a union or intersection return type. Declare those responses explicitly.

## Declaring responses

Attributes can go on an action method, action class or closure:

```php
use Crab\OpenApi\Attributes\Operation;
use Crab\OpenApi\Attributes\Response;

#[Operation(tags: ['orders'])]
final readonly class PlaceOrder
{
    #[Response(201, body: CreatedOrder::class, headers: ['Location'])]
    public function handle(CreateOrder $input): \Psr\Http\Message\ResponseInterface
    {
        // ...
    }
}
```

The generator constructs only this package's attributes. Other action metadata is ignored, and a `#[Hidden]` action is excluded before its response declarations are read.

`body` accepts:

- one class name for a JSON object;
- `[OrderView::class]` for a JSON list of that class;
- an inline JSON Schema array;
- `null` for no body.

A declared error status without a body uses the shared Crab `Error` component:

```php
#[Response(404, description: 'No such order.')]
```

Other attributes are:

```php
use Crab\OpenApi\Attributes\Hidden;
use Crab\OpenApi\Attributes\Operation;
use Crab\OpenApi\Attributes\Security;

#[Security('bearer')]              // one alternative requiring bearer
#[Security('oauth2', 'apiKey')]    // another alternative requiring both
#[Operation(operationId: 'orders.show', summary: 'Show an order', deprecated: true)]
#[Hidden]
```

For streams and downloads, set `contentType` and describe the text or binary schema:

```php
#[Response(
    body: ['type' => 'string'],
    contentType: 'text/event-stream',
    description: 'Stock updates as server-sent events.',
)]
```

## Configuration

The plugin declares and checks its settings when the document builder is first used, including from the console. Unknown settings and values of the wrong type are refused even with `--no-strict`.

| Setting | Default | |
| --- | --- | --- |
| `openapi.title` | `app.name`, then `API` | Document title |
| `openapi.version` | `1.0.0` | API version |
| `openapi.description` | `null` | Document description |
| `openapi.license` | `null` | A license name or OpenAPI license object |
| `openapi.output` | `openapi.json` | File, relative to `app.path` unless absolute |
| `openapi.strict` | `true` | Fail on response details PHP cannot prove |
| `openapi.servers` | `app.url` | Server URLs or server objects |
| `openapi.exclude` | `[]` | Route keys (`GET /path`), names or action classes |
| `openapi.security` | `[]` | OpenAPI security scheme objects, by name |
| `openapi.middleware` | `[]` | Documented middleware effects, by class |
| `openapi.error_schema` | Crab's error object | Schema for an application's custom `ErrorRenderer` |

Middleware effects stay explicit because a class name does not say what it does:

```php
use App\Http\AdminsOnly;
use Crab\Auth\TokenAuth;
use Crab\Http\RateLimit;

return [
    'security' => [
        'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
    ],
    'middleware' => [
        TokenAuth::class => ['security' => 'bearer', 'responses' => [401]],
        RateLimit::class => ['responses' => [429]],
        AdminsOnly::class => ['responses' => [403]],
    ],
];
```

Every security name used by middleware or `#[Security]` must have a matching entry in `openapi.security`; strict generation reports a missing scheme.

Path, query and body input automatically add `422`. A required identity parameter or mapped security scheme adds `401`. Error responses document both `application/json` and Crab's plain-text negotiation.

## Components and determinism

A class named `App\Http\Order` becomes the component `App.Http.Order`. Components are sorted and repeated classes are registered once. Output has no timestamps, uses stable route order and is safe to commit. `x-crab-rules`, `x-crab-min-bytes`, `x-crab-max-bytes` and `x-crab-media-types` retain validation details with no OpenAPI keyword.

Input fields keep their own nullability, defaults and rules beside the component reference. A nullable use of a class or enum does not make every other use nullable.

When one class is used for both request validation and a response view, it gets separate `.Input` and `.Output` components so validation constraints never depend on route order. Mapped middleware on the application's global stack is applied to every operation; middleware stacked on one route requires all of its mapped security schemes.

## Limits

- Response bodies are not inspected at runtime. An attribute states what the action promises.
- Docblock types are not parsed into schemas; use DTOs or `#[Response]`.
- Promoted or defaulted public DTO properties are required. A public property without a default may be uninitialized, and is therefore optional in the response schema because PHP's JSON serialization omits it.
- OpenAPI 3.1 is the output format. A 3.0 or YAML renderer is not included.
- A custom `ErrorRenderer` needs `openapi.error_schema`, or strict generation reports that the error body is unknown.

## Tests

```sh
composer test
composer format:check
composer lint
```

The `sample/` application in this workspace exercises the package against a real shop API.
