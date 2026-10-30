<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests\Fixtures;

use Crab\Validation\FileSize;
use Crab\Validation\Length;
use Psr\Http\Message\UploadedFileInterface;

final readonly class UploadThing
{
    public function __construct(
        #[Length(min: 2)]
        public string $name,
        #[FileSize(min: 1, max: 1024)]
        public UploadedFileInterface $file,
    ) {}
}
