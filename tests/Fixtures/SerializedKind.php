<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

enum SerializedKind: string implements \JsonSerializable
{
    case Simple = 'simple';

    public function jsonSerialize(): array
    {
        return ['kind' => $this->value];
    }
}
