<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Query;

use Acme\AbraFlexi\Query\FlexiRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlexiRef::class)]
final class FlexiRefTest extends TestCase
{
    public function testCodeReturnsCorrectPrefix(): void
    {
        self::assertSame('code:FAK-2024-001', FlexiRef::code('FAK-2024-001'));
    }

    public function testCodePreservesSpecialCharacters(): void
    {
        self::assertSame('code:A/B C', FlexiRef::code('A/B C'));
    }

    public function testExtReturnsNamespaceAndIdSeparatedByColon(): void
    {
        self::assertSame('ext:myapp:42', FlexiRef::ext('myapp', '42'));
    }

    public function testExtAllowsNumericId(): void
    {
        self::assertSame('ext:erp:1000', FlexiRef::ext('erp', '1000'));
    }

    public function testIdReturnsCorrectPrefix(): void
    {
        self::assertSame('id:123', FlexiRef::id('123'));
    }

    public function testIdWithStringValue(): void
    {
        self::assertSame('id:abc', FlexiRef::id('abc'));
    }
}
