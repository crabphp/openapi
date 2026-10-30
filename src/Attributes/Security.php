<?php

declare(strict_types=1);

namespace Crab\OpenApi\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS
| \Attribute::TARGET_METHOD
| \Attribute::TARGET_FUNCTION
| \Attribute::IS_REPEATABLE)]
final readonly class Security
{
    /**
     * @var list<string>
     */
    public array $schemes;

    /**
     * @param string ...$schemes Required together for this alternative.
     */
    public function __construct(string ...$schemes)
    {
        if ($schemes === []) {
            throw new \InvalidArgumentException('Security names at least one scheme.');
        }
        $this->schemes = array_values($schemes);
    }
}
