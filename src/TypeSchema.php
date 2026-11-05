<?php

declare(strict_types=1);

namespace Crab\OpenApi;

use Psr\Http\Message\ResponseInterface;

/**
 * Reads a return type as the data Crab writes. It follows the same rules as
 * Http\Response: a backed enum is its value, a date is ISO text, an object is
 * its public properties, an array has no inferred item type, and a response
 * or a JsonSerializable object needs a declaration to be described exactly.
 */
final class TypeSchema
{
    /**
     * @param \Closure(string): void $warn
     */
    public function __construct(
        private readonly SchemaRegistry $registry,
        private readonly \Closure $warn,
    ) {}

    /**
     * @return array<string, mixed>|\stdClass|null Null for no response body.
     * @param bool $nullable Whether null is part of this schema or a separate response.
     */
    public function schema(
        ?\ReflectionType $type,
        string $where,
        ?string $declaringClass = null,
        bool $nullable = true,
    ): array|\stdClass|null {
        if ($type === null) {
            ($this->warn)("{$where} has no return type; its response body is undescribed.");
            return new \stdClass();
        }
        if ($type instanceof \ReflectionUnionType) {
            $parts = [];
            $hasNull = false;
            foreach ($type->getTypes() as $part) {
                if (self::voidName($part)) {
                    $hasNull = true;
                    continue;
                }
                $schema = $this->schema($part, $where, $declaringClass, $nullable);
                if ($schema !== null) {
                    $parts[] = $schema;
                }
            }
            if ($parts === []) {
                return null;
            }
            if ($hasNull && $nullable) {
                $parts[] = ['type' => 'null'];
            }
            // PHP unions allow a value matching more than one member. JSON
            // Schema's oneOf would reject an integer in int|float, for example.
            return count($parts) === 1 ? $parts[0] : ['anyOf' => $parts];
        }
        if (!$type instanceof \ReflectionNamedType) {
            ($this->warn)("{$where} has a return type that cannot be described; its body is generic.");
            return new \stdClass();
        }

        $name = self::resolveName($type->getName(), $declaringClass);
        if ($type->isBuiltin()) {
            $schema = match ($name) {
                'void', 'null' => null,
                'never' => $this->unknown('never', $where),
                'string' => ['type' => 'string'],
                'int' => ['type' => 'integer'],
                'float' => ['type' => 'number'],
                'bool' => ['type' => 'boolean'],
                'true', 'false' => ['type' => 'boolean'],
                'array' => $this->unknown('array', $where),
                'iterable' => $this->unknown('iterable', $where),
                'mixed', 'object' => $this->unknown($name, $where),
                default => $this->unknown($name, $where),
            };
            return $schema === null ? null : $this->nullable($schema, $type, $nullable);
        }

        return $this->nullable($this->forClass($name, $where), $type, $nullable);
    }

    /**
     * The schema of a class named in a response declaration.
     *
     * @param class-string $class
     * @return array<string, mixed>|\stdClass
     */
    public function forClass(string $class, string $where): array|\stdClass
    {
        if (is_a($class, \DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }
        if (is_a($class, \JsonSerializable::class, true)) {
            ($this->warn)(
                "{$where} returns {$class}, whose JsonSerializable shape cannot be inferred; its body is generic.",
            );
            return new \stdClass();
        }
        if (enum_exists($class) && is_subclass_of($class, \BackedEnum::class)) {
            $values = array_map(static fn(\BackedEnum $case): int|string => $case->value, $class::cases());
            return [
                'type' => is_int($values[0] ?? '') ? 'integer' : 'string',
                'enum' => $values,
            ];
        }
        if (is_a($class, ResponseInterface::class, true)) {
            ($this->warn)("{$where} returns a PSR-7 response; declare its status and body with #[Response].");
            return new \stdClass();
        }
        if (is_a($class, \Generator::class, true)) {
            ($this->warn)("{$where} returns a stream; declare its event or chunk shape with #[Response].");
            return new \stdClass();
        }
        if (!class_exists($class)) {
            ($this->warn)("{$where} returns {$class}, which is not a class whose schema can be read.");
            return new \stdClass();
        }
        return $this->registry->fromClass($class, fn(): array|\stdClass => $this->object($class, $where));
    }

    public function isVoid(?\ReflectionType $type): bool
    {
        if ($type === null) {
            return false;
        }
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() && in_array($type->getName(), ['void', 'null'], true);
        }
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $part) {
                if (!self::voidName($part)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    public function isString(?\ReflectionType $type): bool
    {
        return $type instanceof \ReflectionNamedType && $type->isBuiltin() && $type->getName() === 'string';
    }

    public function isNever(?\ReflectionType $type): bool
    {
        return $type instanceof \ReflectionNamedType && $type->isBuiltin() && $type->getName() === 'never';
    }

    /**
     * Whether any part of the return type is, or extends, one of these.
     *
     * @param class-string ...$classes
     */
    public function hasAny(?\ReflectionType $type, string ...$classes): bool
    {
        if ($type === null) {
            return false;
        }
        $types = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $part) {
            if (!$part instanceof \ReflectionNamedType || $part->isBuiltin()) {
                continue;
            }
            foreach ($classes as $class) {
                if (is_a($part->getName(), $class, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    private function object(string $class, string $where): array|\stdClass
    {
        $reflection = new \ReflectionClass($class);
        $properties = [];
        $required = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $properties[$property->getName()] = $this->schema(
                $property->getType(),
                "{$where} property {$class}::\${$property->getName()}",
                $property->getDeclaringClass()->getName(),
            ) ?? new \stdClass();
            // Json_encode() omits a public property that has never been
            // initialized. Promoted properties are initialized by PHP before
            // the constructor body runs; a declared property without a
            // default may still be left unset by its constructor.
            if ($property->isPromoted() || $property->hasDefaultValue()) {
                $required[] = $property->getName();
            }
        }
        if ($properties === []) {
            ($this->warn)("{$where} returns {$class}, which has no public properties; its body is generic.");
            return new \stdClass();
        }
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    private function unknown(string $type, string $where): array|\stdClass
    {
        ($this->warn)("{$where} returns {$type}; its shape is not in the type, so it is documented as any value.");
        return new \stdClass();
    }

    private static function voidName(\ReflectionType $type): bool
    {
        return $type instanceof \ReflectionNamedType
        && $type->isBuiltin()
        && in_array($type->getName(), ['void', 'null'], true);
    }

    private static function resolveName(string $name, ?string $declaringClass): string
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
     * A nullable named type accepts null as well as its declared type.
     *
     * @param array<string, mixed>|\stdClass $schema
     * @return array<string, mixed>|\stdClass
     */
    private function nullable(array|\stdClass $schema, \ReflectionNamedType $type, bool $nullable): array|\stdClass
    {
        if (!$nullable || !$type->allowsNull() || $type->getName() === 'mixed' || $schema instanceof \stdClass) {
            return $schema;
        }
        if (isset($schema['enum'])) {
            return ['oneOf' => [$schema, ['type' => 'null']]];
        }
        if (isset($schema['type'])) {
            $schema['type'] = array_values(array_unique([...((array) $schema['type']), 'null'], SORT_REGULAR));
            return $schema;
        }
        return ['oneOf' => [$schema, ['type' => 'null']]];
    }
}
