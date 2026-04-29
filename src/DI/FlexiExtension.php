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

/**
 * @phpstan-type ConnectionInput array{
 *     company: string,
 *     baseUrl: string|null,
 *     username: string|null,
 *     password: string|null,
 *     timeout: float|null,
 *     guzzle: array<string, mixed>|null
 * }
 * @phpstan-type ExtensionConfig array{
 *     baseUrl: string,
 *     company: string,
 *     username: string,
 *     password: string,
 *     timeout: float,
 *     guzzle: array<string, mixed>,
 *     defaultConnection: string|null,
 *     connections: array<string, string|ConnectionInput>
 * }
 */
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
            'guzzle' => Expect::anyOf(Expect::array(), Expect::null())->default(null),
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
        $config = $this->getExtensionConfig();

        $builder->addDefinition($this->prefix('responseParser'))
            ->setFactory(ResponseParser::class)
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('flexiClientFactory'))
            ->setFactory(FlexiClientFactory::class, [
                'clientFactory' => null,
                'responseParser' => '@' . $this->prefix('responseParser'),
                'baseConfig' => $this->createBaseConfig($config),
                'guzzleConfig' => $config['guzzle'],
                'namedConnections' => $this->normalizeNamedConnections($config),
            ]);

        if ($config['connections'] !== []) {
            $defaultConnection = $this->resolveDefaultConnection($config);
            if ($defaultConnection !== null) {
                $builder->addDefinition($this->prefix('client'))
                    ->setFactory('@' . $this->prefix('flexiClientFactory') . '::createNamed', [
                        $defaultConnection,
                    ]);
            }

            return;
        }

        if ($config['defaultConnection'] !== null) {
            throw new \LogicException('The "defaultConnection" option can be used only together with "connections".');
        }

        FlexiConfig::fromArray([
            'baseUrl' => $config['baseUrl'],
            'company' => $config['company'],
            'username' => $config['username'],
            'password' => $config['password'],
            'timeout' => $config['timeout'],
        ]);

        $builder->addDefinition($this->prefix('config'))
            ->setFactory([FlexiConfig::class, 'fromArray'], [[
                'baseUrl' => $config['baseUrl'],
                'company' => $config['company'],
                'username' => $config['username'],
                'password' => $config['password'],
                'timeout' => $config['timeout'],
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
        $config = $this->getExtensionConfig();
        $clientFactoryServiceName = $this->resolveClientFactoryServiceName($builder);

        $this->getServiceDefinition($builder, $this->prefix('flexiClientFactory'))
            ->setArgument('clientFactory', '@' . $clientFactoryServiceName);

        if ($builder->hasDefinition($this->prefix('guzzleClient'))) {
            $this->getServiceDefinition($builder, $this->prefix('guzzleClient'))
                ->setFactory('@' . $clientFactoryServiceName . '::createClient', [[
                    ...$config['guzzle'],
                ]]);
        }
    }

    /**
     * Vytvoří základní konfiguraci pro vytváření FlexiClient instancí.
     *
     * @param ExtensionConfig $config Výsledná konfigurace rozšíření.
     * @return array{baseUrl: string, username: string, password: string, timeout: float} Základní konfigurace připojení.
     */
    private function createBaseConfig(array $config): array
    {
        $baseConfig = [
            'baseUrl' => $config['baseUrl'],
            'username' => $config['username'],
            'password' => $config['password'],
            'timeout' => $config['timeout'],
        ];

        // Pokud uz je v hlavni konfiguraci uvedena company, zvalidujeme rovnou
        // i s ni - dostaneme tak ostrejsi chybu uz pri kompilaci. Jinak overime
        // jen spolecne hodnoty (bez vazby na konkretni firmu).
        if ($config['company'] !== '') {
            FlexiConfig::fromArray([...$baseConfig, 'company' => $config['company']]);
        } else {
            FlexiConfig::assertConnectionDefaults($baseConfig);
        }

        return $baseConfig;
    }

    /**
     * Normalizuje pojmenovaná připojení do jednotné struktury.
     *
     * @param ExtensionConfig $config Výsledná konfigurace rozšíření.
     * @return array<string, array{
     *     config: array{baseUrl: string, company: string, username: string, password: string, timeout: float},
     *     guzzle: array<string, mixed>
     * }> Normalizovaná pojmenovaná připojení.
     */
    private function normalizeNamedConnections(array $config): array
    {
        $connections = [];

        foreach ($config['connections'] as $name => $connectionDefinition) {
            $connection = is_string($connectionDefinition)
                ? [
                    'company' => $connectionDefinition,
                    'baseUrl' => null,
                    'username' => null,
                    'password' => null,
                    'timeout' => null,
                    'guzzle' => null,
                ]
                : $connectionDefinition;

            $resolvedConnectionConfig = [
                'baseUrl' => $connection['baseUrl'] ?? $config['baseUrl'],
                'company' => $connection['company'] !== '' ? $connection['company'] : $name,
                'username' => $connection['username'] ?? $config['username'],
                'password' => $connection['password'] ?? $config['password'],
                'timeout' => $connection['timeout'] ?? $config['timeout'],
            ];

            FlexiConfig::fromArray($resolvedConnectionConfig);

            $connections[$name] = [
                'config' => $resolvedConnectionConfig,
                'guzzle' => $connection['guzzle'] ?? [],
            ];
        }

        return $connections;
    }

    /**
     * Určí název výchozího pojmenovaného připojení.
     *
     * @param ExtensionConfig $config Výsledná konfigurace rozšíření.
     * @return string|null Název výchozího připojení, nebo null.
     * @throws \LogicException Pokud nastavené `defaultConnection` neexistuje v `connections`.
     */
    private function resolveDefaultConnection(array $config): ?string
    {
        if ($config['connections'] === []) {
            return null;
        }

        if ($config['defaultConnection'] !== null) {
            if (!array_key_exists($config['defaultConnection'], $config['connections'])) {
                throw new \LogicException(sprintf(
                    'Default Flexi connection "%s" was not found in "connections".',
                    $config['defaultConnection'],
                ));
            }

            return $config['defaultConnection'];
        }

        if (count($config['connections']) === 1) {
            return array_key_first($config['connections']);
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

    /**
     * @return ExtensionConfig
     */
    private function getExtensionConfig(): array
    {
        $rawConfig = $this->toArray($this->getConfig(), 'Flexi extension config');

        $defaultConnection = $rawConfig['defaultConnection'] ?? null;
        if ($defaultConnection !== null && !is_string($defaultConnection)) {
            throw new \LogicException('The "defaultConnection" option must be a string or null.');
        }

        return [
            'baseUrl' => $this->requiredString($rawConfig, 'baseUrl'),
            'company' => $this->requiredString($rawConfig, 'company'),
            'username' => $this->requiredString($rawConfig, 'username'),
            'password' => $this->requiredString($rawConfig, 'password'),
            'timeout' => $this->requiredFloat($rawConfig, 'timeout'),
            'guzzle' => $this->requiredMap($rawConfig, 'guzzle'),
            'defaultConnection' => $defaultConnection,
            'connections' => $this->normalizeConnections($rawConfig['connections'] ?? []),
        ];
    }

    /**
     * @return array<string, string|ConnectionInput>
     */
    private function normalizeConnections(mixed $value): array
    {
        $connections = $this->toArray($value, 'The "connections" option');
        $normalized = [];

        foreach ($connections as $name => $connectionDefinition) {
            if (is_string($connectionDefinition)) {
                $normalized[$name] = $connectionDefinition;
                continue;
            }

            $connection = $this->toArray($connectionDefinition, sprintf('Connection "%s"', $name));

            $company = $connection['company'] ?? '';
            if (!is_string($company)) {
                throw new \LogicException(sprintf('Connection "%s" has invalid "company" value.', $name));
            }

            $timeout = $this->nullableFloat($connection['timeout'] ?? null, sprintf('Connection "%s" timeout', $name));

            $normalized[$name] = [
                'company' => $company,
                'baseUrl' => $this->nullableString($connection['baseUrl'] ?? null, sprintf('Connection "%s" baseUrl', $name)),
                'username' => $this->nullableString($connection['username'] ?? null, sprintf('Connection "%s" username', $name)),
                'password' => $this->nullableString($connection['password'] ?? null, sprintf('Connection "%s" password', $name)),
                'timeout' => $timeout,
                'guzzle' => $this->nullableMap($connection['guzzle'] ?? null, sprintf('Connection "%s" guzzle', $name)),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \LogicException(sprintf('The "%s" option must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requiredFloat(array $data, string $key): float
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) && !is_float($value)) {
            throw new \LogicException(sprintf('The "%s" option must be a float.', $key));
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function requiredMap(array $data, string $key): array
    {
        return $this->toArray($data[$key] ?? [], sprintf('The "%s" option', $key));
    }

    private function nullableString(mixed $value, string $context): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \LogicException(sprintf('%s must be a string or null.', $context));
        }

        return $value;
    }

    private function nullableFloat(mixed $value, string $context): ?float
    {
        if ($value === null) {
            return null;
        }

        if (!is_int($value) && !is_float($value)) {
            throw new \LogicException(sprintf('%s must be a float or null.', $context));
        }

        return (float) $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nullableMap(mixed $value, string $context): ?array
    {
        if ($value === null) {
            return null;
        }

        return $this->toArray($value, $context);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value, string $context): array
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            throw new \LogicException(sprintf('%s must be an array or object.', $context));
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }
}
