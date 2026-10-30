<?php

declare(strict_types=1);

namespace Crab\OpenApi;

/**
 * The shape Http\ErrorResponse writes: an "error" object with a status and a
 * message, plus each group of field problems the response carries.
 */
final class ErrorSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function make(): array
    {
        $problems = [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
            ],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'error' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'integer'],
                        'message' => ['type' => 'string'],
                        'fields' => $problems,
                        'query' => $problems,
                        'path' => $problems,
                    ],
                    'required' => ['status', 'message'],
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['error'],
            'additionalProperties' => false,
        ];
    }
}
