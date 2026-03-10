<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Endpoint;

use Acme\AbraFlexi\Config\FlexiConfig;
use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EndpointBuilder::class)]
final class EndpointBuilderTest extends TestCase
{
    public function testBuildsCompanyRootEndpoint(): void
    {
        $builder = $this->createBuilder('https://demo.flexibee.eu/');

        $url = $builder->forCompany();

        self::assertSame('https://demo.flexibee.eu/c/demo-company', $url);
    }

    public function testBuildsAgendaEndpointWithEncodedSegmentsAndQuery(): void
    {
        $builder = $this->createBuilder('https://demo.flexibee.eu/');

        $url = $builder->agenda('adresar/firma', 'ACME 42', [
            'limit' => 10,
            'where' => 'kod eq "A B"',
        ], 'json');

        self::assertSame(
            'https://demo.flexibee.eu/c/demo-company/adresar/firma/ACME%2042.json?limit=10&where=kod%20eq%20%22A%20B%22',
            $url,
        );
    }

    public function testThrowsOnUnsupportedFormat(): void
    {
        $builder = $this->createBuilder('https://demo.flexibee.eu/');

        $this->expectException(InvalidArgumentException::class);
        $builder->agenda('adresar', null, [], 'html');
    }

    public function testThrowsOnEmptyAgenda(): void
    {
        $builder = $this->createBuilder('https://demo.flexibee.eu/');

        $this->expectException(InvalidArgumentException::class);
        $builder->agenda('  ');
    }

    public function testThrowsOnEmptyCompanySegment(): void
    {
        $builder = $this->createBuilder('https://demo.flexibee.eu/');

        $this->expectException(InvalidArgumentException::class);
        $builder->forCompany(['adresar', '']);
    }

    private function createBuilder(string $baseUrl): EndpointBuilder
    {
        return new EndpointBuilder(new FlexiConfig(
            baseUrl: $baseUrl,
            company: 'demo-company',
            username: 'test-user',
            password: 'test-password',
            timeout: 10.0,
        ));
    }
}
