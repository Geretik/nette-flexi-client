<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Client;

use Acme\AbraFlexi\Config\FlexiConfig;
use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Http\GuzzleHttpTransport;
use Acme\AbraFlexi\Response\ResponseParser;
use Contributte\Guzzlette\ClientFactory;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final readonly class FlexiClientFactory
{
    /**
     * Vytvoří factory pro generování FlexiClient instancí.
     *
     * @param ClientFactory $clientFactory Factory pro vytvoření HTTP klienta.
     * @param ResponseParser $responseParser Parser odpovědí z API.
     * @param array{baseUrl: string, username: string, password: string, timeout: float} $baseConfig Základní konfigurace připojení.
     * @param array<string, mixed> $guzzleConfig Volitelná konfigurace Guzzle klienta.
     * @param array<string, array{
     *     config: array{baseUrl: string, company: string, username: string, password: string, timeout: float},
     *     guzzle: array<string, mixed>
     * }> $namedConnections Volitelná pojmenovaná připojení s vlastní konfigurací.
     * @param LoggerInterface|null $logger Volitelný logger.
     */
    public function __construct(
        private ClientFactory $clientFactory,
        private ResponseParser $responseParser,
        private array $baseConfig,
        private array $guzzleConfig = [],
        private array $namedConnections = [],
        private ?LoggerInterface $logger = null,
    ) {
        $this->assertBaseConfig($this->baseConfig);
    }

    /**
     * Vytvoří FlexiClient pro zadanou firmu.
     *
     * @param string $company Firma, pro kterou se má klient vytvořit.
     * @param array{
     *     baseUrl?: string,
     *     username?: string,
     *     password?: string,
     *     timeout?: float|int
     * } $overrides Volitelné přepsání základní konfigurace.
     * @param array<string, mixed> $guzzle Volitelná konfigurace Guzzle klienta.
     * @return FlexiClient Nakonfigurovaný klient.
     */
    public function create(string $company, array $overrides = [], array $guzzle = []): FlexiClient
    {
        /** @var array{baseUrl: string, company: string, username: string, password: string, timeout: float|int} $config */
        $config = array_replace($this->baseConfig, $overrides, [
            'company' => $company,
        ]);

        return $this->createFromArray($config, $guzzle);
    }

    /**
     * Vytvoří FlexiClient podle názvu pojmenovaného připojení.
     *
     * @param string $name Název pojmenovaného připojení.
     * @return FlexiClient Nakonfigurovaný klient.
     * @throws InvalidArgumentException Pokud připojení s daným názvem neexistuje.
     */
    public function createNamed(string $name): FlexiClient
    {
        $connection = $this->namedConnections[$name] ?? null;
        if ($connection === null) {
            $availableConnections = $this->names();

            throw new InvalidArgumentException(sprintf(
                'Unknown Flexi connection "%s". Available connections: %s',
                $name,
                $availableConnections === [] ? '(none)' : implode(', ', $availableConnections),
            ));
        }

        return $this->createFromArray($connection['config'], $connection['guzzle']);
    }

    /**
     * Zjistí, zda existuje pojmenované připojení se zadaným názvem.
     *
     * @param string $name Název pojmenovaného připojení.
     * @return bool True pokud připojení existuje, jinak false.
     */
    public function hasNamed(string $name): bool
    {
        return isset($this->namedConnections[$name]);
    }

    /**
     * Vrátí seznam názvů všech dostupných pojmenovaných připojení.
     *
     * @return list<string> Seznam názvů pojmenovaných připojení.
     */
    public function names(): array
    {
        /** @var list<string> $names */
        $names = array_keys($this->namedConnections);

        return $names;
    }

    /**
     * @param array{
     *     baseUrl: string,
     *     company: string,
     *     username: string,
     *     password: string,
     *     timeout?: float|int
     * } $config
     * @param array<string, mixed> $guzzle
     */
    public function createFromArray(array $config, array $guzzle = []): FlexiClient
    {
        // Převede konfigurační pole na validní objekt FlexiConfig.
        $resolvedConfig = FlexiConfig::fromArray($config);

        // Vytvoří HTTP klienta a spojí globální Guzzle konfiguraci
        // s konfigurací předanou pro toto konkrétní vytvoření klienta.
        $guzzleClient = $this->clientFactory->createClient(array_replace_recursive($this->guzzleConfig, $guzzle));

        // Připraví builder endpointů pro danou konfiguraci.
        $endpointBuilder = new EndpointBuilder($resolvedConfig);

        // Připraví HTTP transport nad Guzzle klientem.
        $httpTransport = new GuzzleHttpTransport($guzzleClient, $resolvedConfig, $this->logger);

        // Vrátí finální instanci FlexiClient.
        return new FlexiClient($endpointBuilder, $httpTransport, $this->responseParser, $this->logger);
    }

    /**
     * Ověří, že základní konfigurace obsahuje všechny povinné klíče
     * a že ji lze převést na validní instanci FlexiConfig.
     *
     * @param array<string, mixed> $baseConfig Základní konfigurace připojení.
     * @throws InvalidArgumentException Pokud některý povinný konfigurační klíč chybí
     *                                  nebo pokud je konfigurace neplatná.
     */
    private function assertBaseConfig(array $baseConfig): void
    {
        foreach (['baseUrl', 'username', 'password', 'timeout'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $baseConfig)) {
                throw new InvalidArgumentException(sprintf('Missing base Flexi configuration key "%s".', $requiredKey));
            }
        }

        /** @var array{baseUrl: string, username: string, password: string, timeout?: float|int} $baseConfig */
        FlexiConfig::assertConnectionDefaults($baseConfig);
    }
}
