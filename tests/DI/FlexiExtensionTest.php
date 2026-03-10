<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\DI;

use Acme\AbraFlexi\Client\FlexiClient;
use Acme\AbraFlexi\DI\FlexiExtension;
use Acme\AbraFlexi\Http\GuzzleHttpTransport;
use Acme\AbraFlexi\Http\HttpTransportInterface;
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

        $transport = $container->getByType(HttpTransportInterface::class);
        self::assertInstanceOf(GuzzleHttpTransport::class, $transport);

        $guzzleClient = $container->getService('abraFlexi.guzzleClient');
        self::assertInstanceOf(Client::class, $guzzleClient);
    }
}
