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

    public function add(string $name, array|\stdClass $schema): void
    {
        $this->schemas[$name] ??= $schema;
    }

    /**
     * Registers a schema provided inline by the route description and returns
     * its reference. The first occurrence decides the component.
     *
     * @param class-string $class
     * @param array<string, mixed> $schema
     * @return array{'$ref': string}
     */
    public function fromInlineClass(string $class, array $schema): array
    {
        $name = self::name($class);
        if (isset($this->schemas[$name])) {
            return ['$ref' => '#/components/schemas/' . $name];
        }
        unset($schema['x-class']);
        $this->schemas[$name] = ['type' => 'object'];
        $this->schemas[$name] = $this->normalize($schema);
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
    public function fromClass(string $class, \Closure $schema): array
    {
        $name = self::name($class);
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
    public function normalize(array $schema): array|\stdClass
    {
        $class = $schema['x-class'] ?? null;
        if (is_string($class) && class_exists($class)) {
            if (isset($this->schemas[self::name($class)])) {
                return ['$ref' => '#/components/schemas/' . self::name($class)];
            }
            unset($schema['x-class']);
            return $this->fromInlineClass($class, $schema);
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
                $schema[$key][$name] = $this->normalize($property);
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->normalize($schema['items']);
        }
        foreach (['additionalProperties', 'contains', 'not'] as $key) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) {
                continue;
            }
            $schema[$key] = $this->normalize($schema[$key]);
        }
        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $key) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) {
                continue;
            }
            foreach ($schema[$key] as $index => $part) {
                if (!is_array($part)) {
                    continue;
                }
                $schema[$key][$index] = $this->normalize($part);
            }
        }
        return $schema;
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
