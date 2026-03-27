<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\DI;

use Acme\AbraFlexi\Client\FlexiClient;
use Acme\AbraFlexi\Client\FlexiClientFactory;
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
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

final class FlexiExtension extends CompilerExtension
{
    /**
     * Vrátí schéma konfigurace pro nastavení rozšíření.
     *
     * @return Schema Schéma konfigurace pro validaci a zpracování nastavení.
     */
    public function getConfigSchema(): Schema
    {
        $connectionSchema = Expect::structure([
            'company' => Expect::string(''),
            'baseUrl' => Expect::string()->nullable()->default(null),
            'username' => Expect::string()->nullable()->default(null),
            'password' => Expect::string()->nullable()->default(null),
            'timeout' => Expect::float()->nullable()->default(null),
            'guzzle' => Expect::array()->nullable()->default(null),
        ]);

        return Expect::structure([
            'baseUrl' => Expect::string(''),
            'company' => Expect::string(''),
            'username' => Expect::string(''),
            'password' => Expect::string(''),
            'timeout' => Expect::float(10.0),
            'guzzle' => Expect::array()->default([]),
            'defaultConnection' => Expect::string()->nullable()->default(null),
            'connections' => Expect::arrayOf(Expect::anyOf(
                Expect::string(),
                $connectionSchema,
            ))->default([]),
        ]);
    }

    /**
     * Načte a zaregistruje služby rozšíření do DI kontejneru.
     *
     * Podle konfigurace buď:
     * - nastaví pojmenovaná připojení přes FlexiClientFactory,
     * - nebo vytvoří jedno základní připojení přímo z hlavní konfigurace.
     *
     * @throws \LogicException Pokud je nastavena volba defaultConnection
     *                         bez definovaných connections.
     */
    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();
        $config = $this->getConfig();

        $builder->addDefinition($this->prefix('responseParser'))
            ->setFactory(ResponseParser::class)
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('flexiClientFactory'))
            ->setFactory(FlexiClientFactory::class, [
                'clientFactory' => null,
                'responseParser' => '@' . $this->prefix('responseParser'),
                'baseConfig' => $this->createBaseConfig($config),
                'guzzleConfig' => $config->guzzle,
                'namedConnections' => $this->normalizeNamedConnections($config),
            ]);

        if ($config->connections !== []) {
            $defaultConnection = $this->resolveDefaultConnection($config);
            if ($defaultConnection !== null) {
                $builder->addDefinition($this->prefix('client'))
                    ->setFactory('@' . $this->prefix('flexiClientFactory') . '::createNamed', [
                        $defaultConnection,
                    ]);
            }

            return;
        }

        if ($config->defaultConnection !== null) {
            throw new \LogicException('The "defaultConnection" option can be used only together with "connections".');
        }

        FlexiConfig::fromArray([
            'baseUrl' => $config->baseUrl,
            'company' => $config->company,
            'username' => $config->username,
            'password' => $config->password,
            'timeout' => $config->timeout,
        ]);

        $builder->addDefinition($this->prefix('config'))
            ->setFactory([FlexiConfig::class, 'fromArray'], [[
                'baseUrl' => $config->baseUrl,
                'company' => $config->company,
                'username' => $config->username,
                'password' => $config->password,
                'timeout' => $config->timeout,
            ]])
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('endpointBuilder'))
            ->setFactory(EndpointBuilder::class, [
                'config' => '@' . $this->prefix('config'),
            ])
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('guzzleClient'))
            ->setType(Client::class)
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('httpTransport'))
            ->setType(HttpTransportInterface::class)
            ->setFactory(GuzzleHttpTransport::class, [
                'client' => '@' . $this->prefix('guzzleClient'),
                'config' => '@' . $this->prefix('config'),
            ])
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('client'))
            ->setFactory(FlexiClient::class, [
                'endpointBuilder' => '@' . $this->prefix('endpointBuilder'),
                'httpTransport' => '@' . $this->prefix('httpTransport'),
                'responseParser' => '@' . $this->prefix('responseParser'),
            ]);
    }

    /**
     * Doplní konfiguraci služeb před finálním sestavením DI kontejneru.
     *
     * Nastaví službu ClientFactory do FlexiClientFactory a případně
     * nakonfiguruje vytvoření Guzzle klienta přes nalezený ClientFactory.
     */
    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();
        $config = $this->getConfig();
        $clientFactoryServiceName = $this->resolveClientFactoryServiceName($builder);

        $this->getServiceDefinition($builder, $this->prefix('flexiClientFactory'))
            ->setArgument('clientFactory', '@' . $clientFactoryServiceName);

        if ($builder->hasDefinition($this->prefix('guzzleClient'))) {
            $this->getServiceDefinition($builder, $this->prefix('guzzleClient'))
                ->setFactory('@' . $clientFactoryServiceName . '::createClient', [[
                    ...$config->guzzle,
                ]]);
        }
    }

    /**
     * Vytvoří základní konfiguraci pro vytváření FlexiClient instancí.
     *
     * @param object $config Výsledná konfigurace rozšíření.
     * @return array{baseUrl: string, username: string, password: string, timeout: float} Základní konfigurace připojení.
     */
    private function createBaseConfig(object $config): array
    {
        $baseConfig = [
            'baseUrl' => $config->baseUrl,
            'username' => $config->username,
            'password' => $config->password,
            'timeout' => $config->timeout,
        ];

        FlexiConfig::fromArray([
            ...$baseConfig,
            'company' => $config->company !== '' ? $config->company : '__base__',
        ]);

        return $baseConfig;
    }

    /**
     * Normalizuje pojmenovaná připojení do jednotné struktury.
     *
     * @param object $config Výsledná konfigurace rozšíření.
     * @return array<string, array{
     *     config: array{baseUrl: string, company: string, username: string, password: string, timeout: float},
     *     guzzle: array<string, mixed>
     * }> Normalizovaná pojmenovaná připojení.
     */
    private function normalizeNamedConnections(object $config): array
    {
        $connections = [];

        foreach ($config->connections as $name => $connectionDefinition) {
            $connection = is_string($connectionDefinition)
                ? (object) [
                    'company' => $connectionDefinition,
                    'baseUrl' => null,
                    'username' => null,
                    'password' => null,
                    'timeout' => null,
                    'guzzle' => null,
                ]
                : $connectionDefinition;

            $resolvedConnectionConfig = [
                'baseUrl' => $connection->baseUrl ?? $config->baseUrl,
                'company' => $connection->company !== '' ? $connection->company : (string) $name,
                'username' => $connection->username ?? $config->username,
                'password' => $connection->password ?? $config->password,
                'timeout' => $connection->timeout ?? $config->timeout,
            ];

            FlexiConfig::fromArray($resolvedConnectionConfig);

            $connections[(string) $name] = [
                'config' => $resolvedConnectionConfig,
                'guzzle' => $connection->guzzle ?? [],
            ];
        }

        return $connections;
    }

    /**
     * Určí název výchozího pojmenovaného připojení.
     *
     * @param object $config Výsledná konfigurace rozšíření.
     * @return string|null Název výchozího připojení, nebo null.
     * @throws \LogicException Pokud nastavené `defaultConnection` neexistuje v `connections`.
     */
    private function resolveDefaultConnection(object $config): ?string
    {
        if ($config->connections === []) {
            return null;
        }

        if ($config->defaultConnection !== null) {
            if (!array_key_exists($config->defaultConnection, $config->connections)) {
                throw new \LogicException(sprintf(
                    'Default Flexi connection "%s" was not found in "connections".',
                    $config->defaultConnection,
                ));
            }

            return $config->defaultConnection;
        }

        if (count($config->connections) === 1) {
            $singleConnectionName = array_key_first($config->connections);

            return is_string($singleConnectionName) ? $singleConnectionName : null;
        }

        return null;
    }

    /**
     * Vrátí název služby typu ClientFactory z DI kontejneru.
     *
     * @param ContainerBuilder $builder Builder DI kontejneru.
     * @return string Název nalezené služby typu ClientFactory.
     * @throws \LogicException Pokud služba neexistuje nebo pokud je nalezeno více služeb tohoto typu.
     */
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

    /**
     * Vrátí definici služby a ověří, že jde o ServiceDefinition.
     *
     * @param ContainerBuilder $builder Builder DI kontejneru.
     * @param string $serviceName Název služby.
     * @return ServiceDefinition Definice služby.
     * @throws \LogicException Pokud definice služby není typu ServiceDefinition.
     */
    private function getServiceDefinition(ContainerBuilder $builder, string $serviceName): ServiceDefinition
    {
        $definition = $builder->getDefinition($serviceName);
        if (!$definition instanceof ServiceDefinition) {
            throw new \LogicException(sprintf(
                'Service "%s" must use %s, %s given.',
                $serviceName,
                ServiceDefinition::class,
                $definition::class,
            ));
        }

        return $definition;
    }
}
