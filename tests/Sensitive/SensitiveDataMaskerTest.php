<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Sensitive;

use Acme\AbraFlexi\Sensitive\SensitiveDataMasker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SensitiveDataMasker::class)]
final class SensitiveDataMaskerTest extends TestCase
{
    #[DataProvider('sensitiveKeys')]
    public function testRecognizesSensitiveKeysCaseInsensitive(string $key): void
    {
        self::assertTrue((new SensitiveDataMasker())->isSensitiveKey($key));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveKeys(): iterable
    {
        yield 'exact password' => ['password'];
        yield 'uppercase' => ['PASSWORD'];
        yield 'mixed case authorization' => ['Authorization'];
        yield 'set-cookie header' => ['Set-Cookie'];
        yield 'access_token substring' => ['access_token'];
        yield 'client_secret substring' => ['client_secret'];
        yield 'api-key dash variant' => ['api-key'];
        yield 'apikey no separator' => ['apikey'];
        yield 'cookie substring' => ['x-cookie-id'];
    }

    #[DataProvider('safeKeys')]
    public function testDoesNotMarkUnrelatedKeysAsSensitive(string $key): void
    {
        self::assertFalse((new SensitiveDataMasker())->isSensitiveKey($key));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeKeys(): iterable
    {
        yield 'username' => ['username'];
        yield 'email' => ['email'];
        yield 'limit' => ['limit'];
        yield 'detail' => ['detail'];
        yield 'kod' => ['kod'];
        yield 'empty string' => [''];
    }

    public function testIsSensitivePathDetectsSensitiveSegment(): void
    {
        $masker = new SensitiveDataMasker();

        self::assertTrue($masker->isSensitivePath('auth[password]'));
        self::assertTrue($masker->isSensitivePath('data.user.token'));
        self::assertTrue($masker->isSensitivePath('headers[Authorization]'));
        self::assertFalse($masker->isSensitivePath('data.user.name'));
        self::assertFalse($masker->isSensitivePath('limit'));
    }

    public function testMaskReturnsScalarUnchangedForSafeKey(): void
    {
        $masker = new SensitiveDataMasker();

        self::assertSame('plain', $masker->mask('plain', 'username'));
        self::assertSame(42, $masker->mask(42, 'limit'));
        self::assertNull($masker->mask(null, 'detail'));
    }

    public function testMaskReplacesScalarValueForSensitiveKey(): void
    {
        $masker = new SensitiveDataMasker();

        self::assertSame(SensitiveDataMasker::MASK, $masker->mask('hunter2', 'password'));
        self::assertSame(SensitiveDataMasker::MASK, $masker->mask('Bearer abc', 'Authorization'));
    }

    public function testMaskRecursivelyTraversesNestedArraysAndPreservesStructure(): void
    {
        $masker = new SensitiveDataMasker();

        $masked = $masker->mask([
            'username' => 'demo',
            'password' => 'hunter2',
            'nested' => [
                'token' => 'secret-token',
                'data' => [
                    'limit' => 10,
                    'api_key' => 'should-be-hidden',
                ],
            ],
            'list' => ['a', 'b'],
        ]);

        self::assertSame([
            'username' => 'demo',
            'password' => SensitiveDataMasker::MASK,
            'nested' => [
                'token' => SensitiveDataMasker::MASK,
                'data' => [
                    'limit' => 10,
                    'api_key' => SensitiveDataMasker::MASK,
                ],
            ],
            'list' => ['a', 'b'],
        ], $masked);
    }

    public function testMaskTreatsSensitiveArrayValueAsOpaqueWhenKeyIsSensitive(): void
    {
        $masker = new SensitiveDataMasker();

        // Cely podstrom citliveho klice je nahrazen jednou maskou - chranime
        // se tim pred uniknutim zanorenych dat (napr. struktura tokenu).
        self::assertSame(
            SensitiveDataMasker::MASK,
            $masker->mask(['type' => 'Bearer', 'value' => 'abc'], 'token'),
        );
    }
}
