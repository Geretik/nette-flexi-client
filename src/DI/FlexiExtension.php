<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\DI;

use Acme\AbraFlexi\Client\FlexiClient;
use Acme\AbraFlexi\Config\FlexiConfig;
use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Http\GuzzleHttpTransport;
use Acme\AbraFlexi\Http\HttpTransportInterface;
use Acme\AbraFlexi\Response\ResponseParser;
use Contributte\Guzzlette\ClientFactory;
use Contributte\Guzzlette\DI\GuzzleExtension;
use GuzzleHttp\Client;
use Nette\DI\ContainerBuilder;
use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

final class FlexiExtension extends CompilerExtension
{
    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'baseUrl' => Expect::string()->required(),
            'company' => Expect::string()->required(),
            'username' => Expect::string()->required(),
            'password' => Expect::string()->required(),
            'timeout' => Expect::float(10.0),
            'guzzle' => Expect::array()->default([]),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();
        $config = $this->getConfig();

        $builder->addDefinition($this->prefix('config'))
            ->setFactory([FlexiConfig::class, 'fromArray'], [[
                'baseUrl' => $config->baseUrl,
                'company' => $config->company,
                'username' => $config->username,
                'password' => $config->password,
                'timeout' => $config->timeout,
            ]]);

        $builder->addDefinition($this->prefix('endpointBuilder'))
            ->setFactory(EndpointBuilder::class);

        $builder->addDefinition($this->prefix('responseParser'))
            ->setFactory(ResponseParser::class);

        $builder->addDefinition($this->prefix('guzzleClient'))
            ->setType(Client::class)
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('httpTransport'))
            ->setType(HttpTransportInterface::class)
            ->setFactory(GuzzleHttpTransport::class, [
                'client' => '@' . $this->prefix('guzzleClient'),
                'config' => '@' . $this->prefix('config'),
            ]);

        $builder->addDefinition($this->prefix('client'))
            ->setFactory(FlexiClient::class);
    }

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();
        $config = $this->getConfig();
        $clientFactoryServiceName = $this->resolveClientFactoryServiceName($builder);

        $builder->getDefinition($this->prefix('guzzleClient'))
            ->setFactory('@' . $clientFactoryServiceName . '::createClient', [[
                ...$config->guzzle,
            ]]);
    }

    private function resolveClientFactoryServiceName(ContainerBuilder $builder): string
    {
        $serviceNames = array_keys($builder->findByType(ClientFactory::class));

        if ($serviceNames === []) {
            throw new \LogicException(sprintf(
                'Service of type %s was not found. Register %s before %s.',
                ClientFactory::class,
                GuzzleExtension::class,
                self::class,
            ));
        }

        if (count($serviceNames) > 1) {
            throw new \LogicException(sprintf(
                'Multiple services of type %s were found (%s). Register only one %s.',
                ClientFactory::class,
                implode(', ', $serviceNames),
                GuzzleExtension::class,
            ));
        }

        return $serviceNames[0];
    }
}
