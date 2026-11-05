<?php

declare(strict_types=1);

namespace Crab\OpenApi;

/**
 * Collects reusable schemas and rewrites the validation schema's x-class
 * markers as OpenAPI component references. Components are named after the
 * fully qualified class, with namespace separators written as dots.
 */
final class SchemaRegistry
{
    /**
     * @var array<string, array<string, mixed>|\stdClass>
     */
    private array $schemas = [];

    /**
     * Classes whose components are being built, so a recursive class ends in
     * a reference to the component already being written.
     *
     * @var array<class-string, string>
     */
    private array $building = [];

    /**
     * Classes used as both request and response schemas. They receive
     * separate component names so one route's direction cannot change the
     * validation or representation rules another route sees.
     *
     * @var array<class-string, true>
     */
    private array $splitClasses = [];

    /**
     * @param list<class-string> $classes
     */
    public function splitClasses(array $classes): void
    {
        $this->splitClasses = [];
        foreach ($classes as $class) {
            if (!is_string($class) || $class === '') {
                continue;
            }
            $this->splitClasses[$class] = true;
        }
    }

    public function add(string $name, array|\stdClass $schema): void
    {
        $this->schemas[$name] ??= $schema;
    }

    /**
     * Registers a schema provided inline by the route description and returns
     * its reference.
     *
     * @param class-string $class
     * @param array<string, mixed> $schema
     * @return array{'$ref': string}
     */
    public function fromInlineClass(string $class, array $schema, string $role = 'input'): array
    {
        $name = $this->componentName($class, $role);
        if (isset($this->schemas[$name])) {
            return ['$ref' => '#/components/schemas/' . $name];
        }
        unset($schema['x-class']);
        $this->schemas[$name] = ['type' => 'object'];
        $this->schemas[$name] = $this->normalize($schema, $role);
        return ['$ref' => '#/components/schemas/' . $name];
    }

    /**
     * Registers a class through a callback that builds its schema, and
     * returns a reference to it. A recursive reference finds the placeholder.
     *
     * @param class-string $class
     * @param \Closure(): (array<string, mixed>|\stdClass) $schema
     * @return array{'$ref': string}
     */
    public function fromClass(string $class, \Closure $schema, string $role = 'output'): array
    {
        $name = $this->componentName($class, $role);
        if (isset($this->schemas[$name])) {
            return ['$ref' => '#/components/schemas/' . $name];
        }
        $this->schemas[$name] = ['type' => 'object'];
        $this->building[$class] = $name;
        try {
            $this->schemas[$name] = $schema();
        } finally {
            unset($this->building[$class]);
        }
        return ['$ref' => '#/components/schemas/' . $name];
    }

    public function isBuilding(string $class): bool
    {
        return isset($this->building[$class]);
    }

    /**
     * Converts the parts of a validation schema that are not OpenAPI words
     * into extensions, and replaces every nested x-class object with a ref.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>|\stdClass
     */
    public function normalize(array $schema, string $role = 'input'): array|\stdClass
    {
        $class = $schema['x-class'] ?? null;
        if (is_string($class) && class_exists($class)) {
            unset($schema['x-class']);
            $nullable = in_array('null', (array) ($schema['type'] ?? []), true);
            if ($nullable) {
                $types = array_values(array_diff((array) $schema['type'], ['null']));
                $schema['type'] = count($types) === 1 ? $types[0] : $types;
            }
            // A field's default, rules and nullability belong to that use of
            // the class. Keeping them in the component makes the first field
            // change the contract of every later field using the same class.
            $shape = array_intersect_key($schema, array_flip([
                'type',
                'properties',
                'required',
                'additionalProperties',
            ]));
            if (is_subclass_of($class, \BackedEnum::class)) {
                $values = array_map(static fn(\BackedEnum $case): int|string => $case->value, $class::cases());
                $shape = ['type' => is_int($values[0] ?? '') ? 'integer' : 'string', 'enum' => $values];
                if (($schema['enum'] ?? null) === $values) {
                    unset($schema['enum']);
                }
            }
            $local = array_diff_key($schema, array_flip(['type', 'properties', 'required', 'additionalProperties']));
            $reference = $this->fromInlineClass($class, $shape, $role);
            if ($nullable) {
                $reference = ['oneOf' => [$reference, ['type' => 'null']]];
            }
            return $this->normalize([...$reference, ...$local], $role);
        }

        if (isset($schema['x-rules'])) {
            $schema['x-crab-rules'] = $schema['x-rules'];
            unset($schema['x-rules']);
        }
        if (isset($schema['minBytes'])) {
            $schema['x-crab-min-bytes'] = $schema['minBytes'];
            unset($schema['minBytes']);
        }
        if (isset($schema['maxBytes'])) {
            $schema['x-crab-max-bytes'] = $schema['maxBytes'];
            unset($schema['maxBytes']);
        }
        if (isset($schema['mediaTypes'])) {
            $schema['x-crab-media-types'] = $schema['mediaTypes'];
            unset($schema['mediaTypes']);
            $types = $schema['x-crab-media-types'];
            if (is_array($types) && count($types) === 1 && is_string(array_values($types)[0])) {
                $schema['contentMediaType'] = array_values($types)[0];
            }
        }

        foreach (['properties', 'patternProperties'] as $key) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) {
                continue;
            }
            foreach ($schema[$key] as $name => $property) {
                if (!is_array($property)) {
                    continue;
                }
                $schema[$key][$name] = $this->normalize($property, $role);
            }
            if ($schema[$key] === []) {
                $schema[$key] = new \stdClass();
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->normalize($schema['items'], $role);
        }
        foreach (['additionalProperties', 'contains', 'not'] as $key) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) {
                continue;
            }
            $schema[$key] = $this->normalize($schema[$key], $role);
        }
        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $key) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) {
                continue;
            }
            foreach ($schema[$key] as $index => $part) {
                if (!is_array($part)) {
                    continue;
                }
                $schema[$key][$index] = $this->normalize($part, $role);
            }
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && self::allowsNull($schema)) {
            if (!in_array(null, $schema['enum'], true)) {
                $schema['enum'][] = null;
            }
        }
        return $schema === [] ? new \stdClass() : $schema;
    }

    private function componentName(string $class, string $role): string
    {
        $name = self::name($class);
        if (!isset($this->splitClasses[$class])) {
            return $name;
        }
        return $name . ($role === 'output' ? '.Output' : '.Input');
    }

    private static function allowsNull(array $schema): bool
    {
        return (
            in_array('null', (array) ($schema['type'] ?? []), true)
            || isset($schema['oneOf'])
            && is_array($schema['oneOf'])
            && self::hasNullPart($schema['oneOf'])
        );
    }

    /**
     * @param array<mixed> $parts
     */
    private static function hasNullPart(array $parts): bool
    {
        foreach ($parts as $part) {
            if (is_array($part) && in_array('null', (array) ($part['type'] ?? []), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param class-string $class
     */
    public static function name(string $class): string
    {
        return str_replace('\\', '.', $class);
    }

    /**
     * @return array<string, array<string, mixed>|\stdClass>
     */
    public function schemas(): array
    {
        $schemas = $this->schemas;
        ksort($schemas);
        return $schemas;
    }
}
