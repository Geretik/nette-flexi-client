<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Config;

use Acme\AbraFlexi\Config\FlexiConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlexiConfig::class)]
final class FlexiConfigTest extends TestCase
{
    public function testConstructAndFromArrayProduceEquivalentConfig(): void
    {
        $direct = new FlexiConfig(
            baseUrl: 'https://demo.flexibee.eu',
            company: 'demo',
            username: 'user',
            password: 'pass',
            timeout: 5.5,
        );

        $fromArray = FlexiConfig::fromArray([
            'baseUrl' => 'https://demo.flexibee.eu',
            'company' => 'demo',
            'username' => 'user',
            'password' => 'pass',
            'timeout' => 5.5,
        ]);

        self::assertSame($direct->baseUrl, $fromArray->baseUrl);
        self::assertSame($direct->company, $fromArray->company);
        self::assertSame($direct->username, $fromArray->username);
        self::assertSame($direct->password, $fromArray->password);
        self::assertSame($direct->timeout, $fromArray->timeout);
    }

    public function testFromArrayUsesDefaultTimeoutWhenMissing(): void
    {
        $config = FlexiConfig::fromArray([
            'baseUrl' => 'https://demo.flexibee.eu',
            'company' => 'demo',
            'username' => 'user',
            'password' => 'pass',
        ]);

        self::assertSame(10.0, $config->timeout);
    }

    public function testFromArrayCastsIntegerTimeoutToFloat(): void
    {
        $config = FlexiConfig::fromArray([
            'baseUrl' => 'https://demo.flexibee.eu',
            'company' => 'demo',
            'username' => 'user',
            'password' => 'pass',
            'timeout' => 30,
        ]);

        self::assertSame(30.0, $config->timeout);
    }

    public function testWithPasswordMaskedReplacesPasswordButKeepsRestIntact(): void
    {
        $config = new FlexiConfig(
            baseUrl: 'https://demo.flexibee.eu',
            company: 'demo',
            username: 'user',
            password: 'secret',
            timeout: 5.0,
        );

        self::assertSame([
            'baseUrl' => 'https://demo.flexibee.eu',
            'company' => 'demo',
            'username' => 'user',
            'password' => '***',
            'timeout' => 5.0,
        ], $config->withPasswordMasked());
    }

    /**
     * @param array{
     *     baseUrl?: string,
     *     company?: string,
     *     username?: string,
     *     password?: string,
     *     timeout?: float
     * } $overrides
     */
    #[DataProvider('invalidConfigs')]
    public function testRejectsInvalidConfiguration(array $overrides, string $expectedMessageFragment): void
    {
        $base = [
            'baseUrl' => 'https://demo.flexibee.eu',
            'company' => 'demo',
            'username' => 'user',
            'password' => 'pass',
            'timeout' => 5.0,
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessageFragment);

        FlexiConfig::fromArray([...$base, ...$overrides]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'empty baseUrl' => [['baseUrl' => ''], 'Invalid baseUrl'];
        yield 'malformed baseUrl' => [['baseUrl' => 'not-a-url'], 'Invalid baseUrl'];
        yield 'unsupported scheme' => [['baseUrl' => 'ftp://demo.flexibee.eu'], 'scheme'];
        yield 'empty company' => [['company' => ''], 'Company'];
        yield 'empty username' => [['username' => ''], 'Username'];
        yield 'empty password' => [['password' => ''], 'Password'];
        yield 'zero timeout' => [['timeout' => 0.0], 'Timeout'];
        yield 'negative timeout' => [['timeout' => -1.0], 'Timeout'];
    }

    public function testAcceptsHttpAndHttpsSchemes(): void
    {
        $http = FlexiConfig::fromArray([
            'baseUrl' => 'http://demo.flexibee.eu:5434',
            'company' => 'demo',
            'username' => 'user',
            'password' => 'pass',
        ]);

        $https = FlexiConfig::fromArray([
            'baseUrl' => 'https://demo.flexibee.eu',
            'company' => 'demo',
            'username' => 'user',
            'password' => 'pass',
        ]);

        self::assertSame('http://demo.flexibee.eu:5434', $http->baseUrl);
        self::assertSame('https://demo.flexibee.eu', $https->baseUrl);
    }

    public function testAssertConnectionDefaultsAcceptsValidBaseConfigWithoutCompany(): void
    {
        FlexiConfig::assertConnectionDefaults([
            'baseUrl' => 'https://demo.flexibee.eu',
            'username' => 'user',
            'password' => 'pass',
            'timeout' => 5.0,
        ]);

        self::addToAssertionCount(1);
    }

    public function testAssertConnectionDefaultsRejectsMissingCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username');

        FlexiConfig::assertConnectionDefaults([
            'baseUrl' => 'https://demo.flexibee.eu',
            'username' => '',
            'password' => 'pass',
        ]);
    }

    public function testAssertConnectionDefaultsRejectsUnsupportedScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scheme');

        FlexiConfig::assertConnectionDefaults([
            'baseUrl' => 'ftp://demo.flexibee.eu',
            'username' => 'user',
            'password' => 'pass',
        ]);
    }
}
