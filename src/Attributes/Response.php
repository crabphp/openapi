<?php

declare(strict_types=1);

namespace Crab\OpenApi\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS
| \Attribute::TARGET_METHOD
| \Attribute::TARGET_FUNCTION
| \Attribute::IS_REPEATABLE)]
final readonly class Response
{
    /**
     * A response the action returns. Body is a class, a list containing one
     * class for an array of them, an inline JSON Schema array, or null for no
     * body. Headers may be names or names mapped to schemas.
     *
     * @param class-string|list<class-string>|array<string, mixed>|null $body
     * @param array<int|string, mixed> $headers
     */
    public function __construct(
        public int $status = 200,
        public string|array|null $body = null,
        public string $description = '',
        public string $contentType = 'application/json',
        public array $headers = [],
    ) {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('An HTTP response status must be between 100 and 599.');
        }
        if (
            trim($contentType) === ''
            || $contentType !== trim($contentType)
            || preg_match('/[\x00-\x1F\x7F]/', $contentType) === 1
            || !preg_match('~^[^/\s]+/[^;\s]+(?:\s*;.*)?$~', $contentType)
        ) {
            throw new \InvalidArgumentException(
                'A response content type may not be empty and must be a valid media type.',
            );
        }
    }
}
