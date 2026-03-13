<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\DI;

use Acme\AbraFlexi\Client\FlexiClient;
use Acme\AbraFlexi\DI\FlexiExtension;
use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Http\GuzzleHttpTransport;
use Contributte\Guzzlette\DI\GuzzleExtension;
use GuzzleHttp\Client;
use Nette\DI\Compiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlexiExtension::class)]
final class FlexiExtensionTest extends TestCase
{
    public function testExtensionCompilesContainerAndRegistersClientServices(): void
    {
        $className = 'FlexiTestContainer_' . str_replace('.', '_', uniqid('', true));

        $compiler = new Compiler();
        $compiler->setClassName($className);
        $compiler->addExtension('httpClient', new GuzzleExtension());
        $compiler->addExtension('abraFlexi', new FlexiExtension());
        $compiler->addConfig([
            'httpClient' => [
                'client' => [
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                ],
            ],
            'abraFlexi' => [
                'baseUrl' => 'https://demo.flexibee.eu',
                'company' => 'demo-company',
                'username' => 'demo-user',
                'password' => 'demo-password',
                'timeout' => 15.0,
                'guzzle' => [
                    'headers' => [
                        'User-Agent' => 'nette-flexi-client-test',
                    ],
                ],
            ],
        ]);

        eval($compiler->compile());

        /** @var object $container */
        $container = new $className();

        $client = $container->getByType(FlexiClient::class);
        self::assertInstanceOf(FlexiClient::class, $client);

        $transport = $container->getService('abraFlexi.httpTransport');
        self::assertInstanceOf(GuzzleHttpTransport::class, $transport);

        $guzzleClient = $container->getService('abraFlexi.guzzleClient');
        self::assertInstanceOf(Client::class, $guzzleClient);
    }

    public function testMultipleExtensionInstancesCompileAndKeepServicesIsolated(): void
    {
        $className = 'FlexiMultiContainer_' . str_replace('.', '_', uniqid('', true));

        $compiler = new Compiler();
        $compiler->setClassName($className);
        $compiler->addExtension('httpClient', new GuzzleExtension());
        $compiler->addExtension('abraA', new FlexiExtension());
        $compiler->addExtension('abraB', new FlexiExtension());
        $compiler->addConfig([
            'abraA' => [
                'baseUrl' => 'https://a.example',
                'company' => 'company-a',
                'username' => 'user-a',
                'password' => 'password-a',
                'timeout' => 10.0,
            ],
            'abraB' => [
                'baseUrl' => 'https://b.example',
                'company' => 'company-b',
                'username' => 'user-b',
                'password' => 'password-b',
                'timeout' => 15.0,
            ],
        ]);

        eval($compiler->compile());

        /** @var object $container */
        $container = new $className();

        $builderA = $container->getService('abraA.endpointBuilder');
        self::assertInstanceOf(EndpointBuilder::class, $builderA);
        self::assertSame('https://a.example/c/company-a/adresar.json', $builderA->agenda('adresar', null, [], 'json'));

        $builderB = $container->getService('abraB.endpointBuilder');
        self::assertInstanceOf(EndpointBuilder::class, $builderB);
        self::assertSame('https://b.example/c/company-b/adresar.json', $builderB->agenda('adresar', null, [], 'json'));

        $clientA = $container->getService('abraA.client');
        self::assertInstanceOf(FlexiClient::class, $clientA);

        $clientB = $container->getService('abraB.client');
        self::assertInstanceOf(FlexiClient::class, $clientB);
    }
}
