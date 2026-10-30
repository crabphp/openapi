<?php

declare(strict_types=1);

namespace Crab\OpenApi;

final class GenerationException extends \RuntimeException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(
        public readonly array $problems,
    ) {
        parent::__construct(implode("\n", $problems));
    }
}
