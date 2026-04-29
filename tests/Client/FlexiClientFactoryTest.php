<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Client;

use Acme\AbraFlexi\Client\FlexiClient;
use Acme\AbraFlexi\Client\FlexiClientFactory;
use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Response\ResponseParser;
use Contributte\Guzzlette\ClientFactory;
use Contributte\Guzzlette\SnapshotStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlexiClientFactory::class)]
final class FlexiClientFactoryTest extends TestCase
{
    public function testCreatesNamedAndRuntimeClients(): void
    {
        $factory = new FlexiClientFactory(
            clientFactory: new ClientFactory(new SnapshotStack()),
            responseParser: new ResponseParser(),
            baseConfig: [
                'baseUrl' => 'https://default.example',
                'username' => 'default-user',
                'password' => 'default-password',
                'timeout' => 10.0,
            ],
            namedConnections: [
                'warehouse' => [
                    'config' => [
                        'baseUrl' => 'https://warehouse.example',
                        'company' => 'warehouse-company',
                        'username' => 'warehouse-user',
                        'password' => 'warehouse-password',
                        'timeout' => 20.0,
                    ],
                    'guzzle' => [
                        'headers' => [
                            'X-Connection' => 'warehouse',
                        ],
                    ],
                ],
            ],
        );

        self::assertSame(['warehouse'], $factory->names());
        self::assertTrue($factory->hasNamed('warehouse'));
        self::assertFalse($factory->hasNamed('missing'));

        $namedClient = $factory->createNamed('warehouse');
        self::assertSame(
            'https://warehouse.example/c/warehouse-company/adresar.json',
            $this->extractEndpointBuilder($namedClient)->agenda('adresar', null, [], 'json'),
        );

        $runtimeClient = $factory->create('runtime-company');
        self::assertSame(
            'https://default.example/c/runtime-company/adresar.json',
            $this->extractEndpointBuilder($runtimeClient)->agenda('adresar', null, [], 'json'),
        );
    }

    public function testThrowsWhenNamedConnectionDoesNotExist(): void
    {
        $factory = new FlexiClientFactory(
            clientFactory: new ClientFactory(new SnapshotStack()),
            responseParser: new ResponseParser(),
            baseConfig: [
                'baseUrl' => 'https://default.example',
                'username' => 'default-user',
                'password' => 'default-password',
                'timeout' => 10.0,
            ],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Flexi connection "missing"');

        $factory->createNamed('missing');
    }

    private function extractEndpointBuilder(FlexiClient $client): EndpointBuilder
    {
        $property = new \ReflectionProperty($client, 'endpointBuilder');

        /** @var EndpointBuilder $endpointBuilder */
        $endpointBuilder = $property->getValue($client);

        return $endpointBuilder;
    }
}
