<?php

declare(strict_types=1);

namespace Crab\OpenApi\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION)]
final readonly class Hidden {}
