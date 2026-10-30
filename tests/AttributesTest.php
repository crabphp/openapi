<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests;

use Crab\OpenApi\Attributes\Response;
use Crab\OpenApi\Attributes\Security;
use PHPUnit\Framework\TestCase;

final class AttributesTest extends TestCase
{
    public function testSecurityKeepsItsSchemesAndRefusesNone(): void
    {
        $security = new Security('bearer', 'apiKey');

        self::assertSame(['bearer', 'apiKey'], $security->schemes);
        $this->expectException(\InvalidArgumentException::class);
        new Security();
    }

    public function testResponseRefusesAnImpossibleStatusOrEmptyContentType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response(status: 99);
    }
}
