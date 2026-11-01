<?php

declare(strict_types=1);

namespace Crab\OpenApi\Tests;

use Crab\OpenApi\Attributes\Operation;
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

    public function testResponseRefusesAnImpossibleStatusOrInvalidContentType(): void
    {
        $caught = false;
        try {
            new Response(status: 99);
            self::fail('An impossible status was accepted.');
        } catch (\InvalidArgumentException) {
            $caught = true;
        }
        self::assertTrue($caught);

        $this->expectException(\InvalidArgumentException::class);
        new Response(contentType: 'plain text');
    }

    public function testOperationRefusesMalformedTags(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Operation(tags: ['things', 123]);
    }
}
