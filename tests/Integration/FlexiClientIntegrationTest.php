<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Integration;

use Acme\AbraFlexi\Client\FlexiClient;
use Acme\AbraFlexi\Config\FlexiConfig;
use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Exception\ApiErrorException;
use Acme\AbraFlexi\Exception\HttpException;
use Acme\AbraFlexi\Exception\TransportException;
use Acme\AbraFlexi\Http\GuzzleHttpTransport;
use Acme\AbraFlexi\Response\ResponseParser;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class FlexiClientIntegrationTest extends TestCase
{
    public function testCanCreateReadUpdateAndDeleteAdresarRecord(): void
    {
        if (!$this->shouldRunIntegrationTests()) {
            self::markTestSkipped('Integration test is disabled. Set ABRA_FLEXI_RUN_INTEGRATION=1 to enable it.');
        }

        $client = $this->createClient();
        $code = strtoupper('CX' . date('mdHis') . bin2hex(random_bytes(2)));
        $createdName = 'Codex Integration Test';
        $updatedName = 'Codex Integration Test Updated';
        $recordId = null;

        try {
            $created = $this->withRetry(static fn() => $client->post('adresar', [
                'kod' => $code,
                'nazev' => $createdName,
            ]));

            $recordId = $this->extractCreatedRecordId($created);

            $loaded = $this->extractRecord($this->withRetry(static fn() => $client->get('adresar', $recordId)));
            $this->assertRecordId($loaded, $recordId);
            self::assertSame($code, $loaded['kod'] ?? null);
            self::assertSame($createdName, $loaded['nazev'] ?? null);

            $this->withRetry(static fn() => $client->put('adresar', $recordId, [
                'kod' => $code,
                'nazev' => $updatedName,
            ]));

            $reloaded = $this->extractRecord($this->withRetry(static fn() => $client->get('adresar', $recordId)));
            $this->assertRecordId($reloaded, $recordId);
            self::assertSame($code, $reloaded['kod'] ?? null);
            self::assertSame($updatedName, $reloaded['nazev'] ?? null);

            $this->withRetry(static fn() => $client->delete('adresar', $recordId));
            try {
                $this->withRetry(static fn() => $client->get('adresar', $recordId));
                self::fail('Deleted record should not be readable anymore.');
            } catch (ApiErrorException | HttpException $exception) {
                self::assertFalse(
                    $this->isTransientTransportFailure($exception),
                    'Deletion verification failed due to a transient transport failure.',
                );
                self::assertFalse(
                    $this->isParallelRequestLimit($exception),
                    'Deletion verification failed due to repeated rate limiting from the demo API.',
                );
            }

            $recordId = null;
        } catch (ApiErrorException | HttpException $exception) {
            if ($this->isTransientTransportFailure($exception)) {
                self::markTestSkipped(sprintf(
                    'Integration endpoint is temporarily unavailable: %s',
                    $exception->getMessage(),
                ));
            }

            throw $exception;
        } finally {
            if (is_string($recordId) && $recordId !== '') {
                try {
                    $this->withRetry(static fn() => $client->delete('adresar', $recordId));
                } catch (\Throwable) {
                }
            }
        }
    }

    private function createClient(): FlexiClient
    {
        $config = new FlexiConfig(
            baseUrl: $this->readEnv('ABRA_FLEXI_BASE_URL', 'https://demo.flexibee.eu:5434'),
            company: $this->readEnv('ABRA_FLEXI_COMPANY', 'demo'),
            username: $this->readEnv('ABRA_FLEXI_USERNAME', 'winstrom'),
            password: $this->readEnv('ABRA_FLEXI_PASSWORD', 'winstrom'),
            timeout: (float) $this->readEnv('ABRA_FLEXI_TIMEOUT', '10'),
        );

        return new FlexiClient(
            endpointBuilder: new EndpointBuilder($config),
            httpTransport: new GuzzleHttpTransport(
                client: new Client(),
                config: $config,
            ),
            responseParser: new ResponseParser(),
        );
    }

    private function shouldRunIntegrationTests(): bool
    {
        return filter_var(
            $this->readEnv('ABRA_FLEXI_RUN_INTEGRATION', '0'),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;
    }

    private function readEnv(string $name, string $default): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withRetry(callable $operation): mixed
    {
        $maxAttempts = 6;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $operation();
            } catch (ApiErrorException | HttpException $exception) {
                if (
                    !$this->isParallelRequestLimit($exception)
                    && !$this->isTransientTransportFailure($exception)
                    || $attempt === $maxAttempts
                ) {
                    throw $exception;
                }

                usleep($attempt * 500_000);
            }
        }

        self::fail('Retry loop did not return a result.');
    }

    private function isParallelRequestLimit(ApiErrorException | HttpException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'too many parallel requests')) {
            return true;
        }

        if ($exception instanceof HttpException && $exception->getStatusCode() === 429) {
            return true;
        }

        return false;
    }

    private function isTransientTransportFailure(ApiErrorException | HttpException $exception): bool
    {
        if ($exception instanceof TransportException) {
            return true;
        }

        if (!$exception instanceof HttpException || $exception->getStatusCode() !== 0) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'connection refused')
            || str_contains($message, 'timed out')
            || str_contains($message, 'could not resolve host')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'temporary failure');
    }

    /**
     * @param array<mixed> $response
     * @return array<string, mixed>
     */
    private function extractRecord(array $response): array
    {
        $record = $response['adresar'] ?? null;
        if (!is_array($record)) {
            self::fail('Response does not contain an adresar record.');
        }

        if (array_is_list($record)) {
            $record = $record[0] ?? null;
        }

        if (!is_array($record)) {
            self::fail('Adresar record has unexpected shape.');
        }

        $normalized = [];
        foreach ($record as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $createdResponse
     */
    private function extractCreatedRecordId(array $createdResponse): string
    {
        $results = $createdResponse['results'] ?? null;
        if (!is_array($results) || $results === []) {
            self::fail('Create response does not contain results.');
        }

        $firstResult = $results[0] ?? null;
        if (!is_array($firstResult)) {
            self::fail('Create response result has unexpected shape.');
        }

        $recordId = $firstResult['id'] ?? null;
        if (!is_string($recordId) || $recordId === '') {
            self::fail('Create response does not contain a valid record ID.');
        }

        return $recordId;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function assertRecordId(array $record, string $expectedId): void
    {
        $id = $record['id'] ?? null;
        if (!is_string($id) && !is_int($id)) {
            self::fail('Record ID has unexpected type.');
        }

        self::assertSame($expectedId, (string) $id);
    }
}
