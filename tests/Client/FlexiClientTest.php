<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Client;

use Acme\AbraFlexi\Client\FlexiClient;
use Acme\AbraFlexi\Config\FlexiConfig;
use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Exception\ApiErrorException;
use Acme\AbraFlexi\Exception\HttpException;
use Acme\AbraFlexi\Exception\ParseException;
use Acme\AbraFlexi\Http\HttpResponse;
use Acme\AbraFlexi\Http\HttpTransportInterface;
use Acme\AbraFlexi\Response\ResponseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stringable;

#[CoversClass(FlexiClient::class)]
final class FlexiClientTest extends TestCase
{
    public function testGetBuildsEndpointAndParsesJsonResponse(): void
    {
        $transport = new InMemoryTransport(
            new HttpResponse(200, ['Content-Type' => ['application/json']], '{"winstrom":{"@version":"1.0","adresar":[{"kod":"ABC"}]}}'),
        );
        $client = $this->createClient($transport);

        $result = $client->get('adresar', null, ['limit' => 5]);

        self::assertSame(['@version' => '1.0', 'adresar' => [['kod' => 'ABC']]], $result);
        self::assertSame('GET', $transport->lastMethod);
        self::assertSame('https://demo.flexibee.eu/c/demo-company/adresar.json?limit=5', $transport->lastUrl);
        self::assertSame([], $transport->lastOptions);
    }

    public function testPostEncodesArrayPayloadToJson(): void
    {
        $transport = new InMemoryTransport(
            new HttpResponse(200, ['Content-Type' => ['application/json']], '{"winstrom":{"success":"true","results":[{"id":"123"}]}}'),
        );
        $client = $this->createClient($transport);

        $result = $client->post('adresar', ['kod' => 'ABC']);

        self::assertSame(['success' => 'true', 'results' => [['id' => '123']]], $result);
        self::assertSame('POST', $transport->lastMethod);
        self::assertSame('https://demo.flexibee.eu/c/demo-company/adresar.json', $transport->lastUrl);
        $headers = $transport->recordedHeaders();
        self::assertSame('application/json', $headers['Accept'] ?? null);
        self::assertSame('application/json', $headers['Content-Type'] ?? null);
        self::assertSame('{"winstrom":{"adresar":{"kod":"ABC"}}}', $transport->recordedBody());
    }

    public function testPutUsesRecordIdInEndpoint(): void
    {
        $transport = new InMemoryTransport(
            new HttpResponse(200, ['Content-Type' => ['application/json']], '{"winstrom":{"success":"true"}}'),
        );
        $client = $this->createClient($transport);

        $result = $client->put('adresar', '42', ['nazev' => 'Updated']);

        self::assertSame(['success' => 'true'], $result);
        self::assertSame('PUT', $transport->lastMethod);
        self::assertSame('https://demo.flexibee.eu/c/demo-company/adresar/42.json', $transport->lastUrl);
        self::assertSame('{"winstrom":{"adresar":{"nazev":"Updated"}}}', $transport->recordedBody());
    }

    public function testDeleteReturnsEmptyArrayForEmptyBody(): void
    {
        $transport = new InMemoryTransport(
            new HttpResponse(204, ['Content-Type' => ['application/json']], ''),
        );
        $client = $this->createClient($transport);

        $result = $client->delete('adresar', '42');

        self::assertSame([], $result);
        self::assertSame('DELETE', $transport->lastMethod);
        self::assertSame('https://demo.flexibee.eu/c/demo-company/adresar/42.json', $transport->lastUrl);
    }

    public function testPostUsesXmlEndpointForRawXmlPayload(): void
    {
        $transport = new InMemoryTransport(
            new HttpResponse(200, ['Content-Type' => ['application/xml']], '<winstrom><success>true</success></winstrom>'),
        );
        $client = $this->createClient($transport);

        $result = $client->post('adresar', '<winstrom><adresar><kod>XML-1</kod></adresar></winstrom>');

        self::assertSame(['success' => 'true'], $result);
        self::assertSame('https://demo.flexibee.eu/c/demo-company/adresar.xml', $transport->lastUrl);
        $headers = $transport->recordedHeaders();
        self::assertSame('application/xml', $headers['Accept'] ?? null);
        self::assertSame('application/xml', $headers['Content-Type'] ?? null);
    }

    public function testPostThrowsParseExceptionForNonEncodablePayload(): void
    {
        $transport = new InMemoryTransport(
            new HttpResponse(200, ['Content-Type' => ['application/json']], '{"ok":true}'),
        );
        $client = $this->createClient($transport);

        $this->expectException(ParseException::class);
        $client->post('adresar', ['bad' => INF]);
    }

    public function testConvertsStructuredHttpErrorResponseToApiErrorException(): void
    {
        $client = $this->createClient(new FailingTransport(new HttpException(
            'Request failed',
            400,
            '{"winstrom":{"success":"false","results":[{"errors":[{"message":"Pole je neplatne.","code":"INVALID"}]}]}}',
        )));

        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('Pole je neplatne.');

        $client->post('adresar', ['kod' => 'BAD']);
    }

    public function testLogsBusinessApiErrorReturnedInSuccessfulResponse(): void
    {
        $logger = new InMemoryLogger();
        $client = $this->createClient(
            new InMemoryTransport(
                new HttpResponse(
                    200,
                    ['Content-Type' => ['application/json']],
                    '{"winstrom":{"error":"Pole je neplatne.","code":"INVALID","password":"secret-password"}}',
                ),
            ),
            $logger,
        );

        $this->expectException(ApiErrorException::class);
        $this->expectExceptionMessage('Pole je neplatne.');

        try {
            $client->post('adresar', ['kod' => 'BAD'], ['token' => 'secret-token', 'detail' => 'full']);
        } finally {
            self::assertCount(1, $logger->records);
            self::assertSame('warning', $logger->records[0]['level']);
            self::assertSame('Flexi API business error.', $logger->records[0]['message']);
            self::assertSame('POST', $logger->records[0]['context']['method']);
            self::assertSame('adresar', $logger->records[0]['context']['agenda']);
            self::assertSame(['token' => '***', 'detail' => 'full'], $logger->records[0]['context']['query']);
            self::assertSame('INVALID', $logger->records[0]['context']['errorCode']);
            $details = $logger->records[0]['context']['details'] ?? null;
            self::assertIsArray($details);
            self::assertSame('***', $details['password'] ?? null);
            self::assertStringNotContainsString(
                'secret-password',
                json_encode($logger->records[0]['context'], JSON_THROW_ON_ERROR),
            );
            self::assertStringNotContainsString(
                'secret-token',
                json_encode($logger->records[0]['context'], JSON_THROW_ON_ERROR),
            );
        }
    }

    private function createClient(HttpTransportInterface $transport, ?LoggerInterface $logger = null): FlexiClient
    {
        $config = new FlexiConfig(
            baseUrl: 'https://demo.flexibee.eu',
            company: 'demo-company',
            username: 'demo-user',
            password: 'demo-password',
            timeout: 10.0,
        );

        return new FlexiClient(
            endpointBuilder: new EndpointBuilder($config),
            httpTransport: $transport,
            responseParser: new ResponseParser(),
            logger: $logger,
        );
    }
}

final class InMemoryTransport implements HttpTransportInterface
{
    public string $lastMethod = '';
    public string $lastUrl = '';

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public function __construct(
        private readonly HttpResponse $response,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $this->lastMethod = $method;
        $this->lastUrl = $url;
        $this->lastOptions = $options;

        return $this->response;
    }

    /**
     * @return array<string, string>
     */
    public function recordedHeaders(): array
    {
        $headers = $this->lastOptions['headers'] ?? null;
        if (!is_array($headers)) {
            throw new \RuntimeException('Recorded request does not contain headers.');
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $normalized[$name] = $value;
            }
        }

        return $normalized;
    }

    public function recordedBody(): string
    {
        $body = $this->lastOptions['body'] ?? null;
        if (!is_string($body)) {
            throw new \RuntimeException('Recorded request does not contain a string body.');
        }

        return $body;
    }
}

final class FailingTransport implements HttpTransportInterface
{
    public function __construct(
        private readonly HttpException $exception,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        throw $this->exception;
    }
}

final class InMemoryLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(Stringable|string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(Stringable|string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $this->normalizeLevel($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    private function normalizeLevel(mixed $level): string
    {
        if (is_string($level)) {
            return $level;
        }

        if (is_int($level) || is_float($level) || is_bool($level)) {
            return (string) $level;
        }

        if ($level instanceof Stringable) {
            return (string) $level;
        }

        return get_debug_type($level);
    }
}
