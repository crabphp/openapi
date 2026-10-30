<?php

declare(strict_types=1);

namespace Crab\OpenApi;

/**
 * A generated document with the places the generator could not describe
 * fully. A warning never makes the document false; it marks a generic part
 * that an application may choose to declare more precisely.
 */
final readonly class BuildResult
{
    /**
     * @param array<string, mixed> $document
     * @param list<string> $warnings
     */
    public function __construct(
        public array $document,
        public array $warnings = [],
    ) {}
}
