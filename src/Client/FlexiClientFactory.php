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
     * @param array{baseUrl: string, username: string, password: string, timeout: float} $baseConfig
     * @param array<string, mixed> $guzzleConfig
     * @param array<string, array{
     *     config: array{baseUrl: string, company: string, username: string, password: string, timeout: float},
     *     guzzle: array<string, mixed>
     * }> $namedConnections
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
     * @param array{
     *     baseUrl?: string,
     *     username?: string,
     *     password?: string,
     *     timeout?: float|int
     * } $overrides
     * @param array<string, mixed> $guzzle
     */
    public function create(string $company, array $overrides = [], array $guzzle = []): FlexiClient
    {
        /** @var array{baseUrl: string, company: string, username: string, password: string, timeout: float|int} $config */
        $config = array_replace($this->baseConfig, $overrides, [
            'company' => $company,
        ]);

        return $this->createFromArray($config, $guzzle);
    }

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

    public function hasNamed(string $name): bool
    {
        return isset($this->namedConnections[$name]);
    }

    /**
     * @return list<string>
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
        $resolvedConfig = FlexiConfig::fromArray($config);
        $guzzleClient = $this->clientFactory->createClient(array_replace_recursive($this->guzzleConfig, $guzzle));
        $endpointBuilder = new EndpointBuilder($resolvedConfig);
        $httpTransport = new GuzzleHttpTransport($guzzleClient, $resolvedConfig, $this->logger);

        return new FlexiClient($endpointBuilder, $httpTransport, $this->responseParser, $this->logger);
    }

    /**
     * @param array<string, mixed> $baseConfig
     */
    private function assertBaseConfig(array $baseConfig): void
    {
        foreach (['baseUrl', 'username', 'password', 'timeout'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $baseConfig)) {
                throw new InvalidArgumentException(sprintf('Missing base Flexi configuration key "%s".', $requiredKey));
            }
        }

        FlexiConfig::fromArray([
            ...$baseConfig,
            'company' => '__base__',
        ]);
    }
}
