<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Tests\Http;

use Acme\AbraFlexi\Config\FlexiConfig;
use Acme\AbraFlexi\Exception\HttpException;
use Acme\AbraFlexi\Http\GuzzleHttpTransport;
use Acme\AbraFlexi\Http\HttpResponse;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stringable;

#[CoversClass(GuzzleHttpTransport::class)]
final class GuzzleHttpTransportTest extends TestCase
{
    public function testReturnsSuccessfulResponseAndAppliesDefaultOptions(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => ['application/json']], '{"ok":true}'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $transport = new GuzzleHttpTransport(
            client: new Client(['handler' => $handlerStack]),
            config: $this->createConfig(),
        );

        $response = $transport->request('GET', 'https://demo.flexibee.eu/c/demo-company/adresar');

        self::assertInstanceOf(HttpResponse::class, $response);
        self::assertSame(200, $response->statusCode);
        self::assertSame('{"ok":true}', $response->body);
        self::assertCount(1, $history);
        self::assertSame(['demo-user', 'demo-password'], $history[0]['options']['auth']);
        self::assertSame(10.0, $history[0]['options']['timeout']);
        self::assertSame(10.0, $history[0]['options']['connect_timeout']);
        self::assertSame('application/json', $history[0]['request']->getHeaderLine('Accept'));
    }

    public function testThrowsHttpExceptionWhenStatusCodeIsError(): void
    {
        $mock = new MockHandler([
            new Response(404, ['Content-Type' => ['application/json']], '{"error":"not-found"}'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $transport = new GuzzleHttpTransport(
            client: new Client(['handler' => $handlerStack]),
            config: $this->createConfig(),
        );

        try {
            $transport->request('GET', 'https://demo.flexibee.eu/c/demo-company/adresar/1');
            self::fail('Expected HttpException was not thrown.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame('{"error":"not-found"}', $exception->getResponseBody());
            self::assertStringContainsString('HTTP request failed with status 404', $exception->getMessage());
        }
    }

    public function testThrowsHttpExceptionOnTransportFailure(): void
    {
        $request = new Request('GET', 'https://demo.flexibee.eu/c/demo-company/adresar');
        $mock = new MockHandler([
            new ConnectException('Connection timed out', $request),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $transport = new GuzzleHttpTransport(
            client: new Client(['handler' => $handlerStack]),
            config: $this->createConfig(),
        );

        try {
            $transport->request('GET', 'https://demo.flexibee.eu/c/demo-company/adresar');
            self::fail('Expected HttpException was not thrown.');
        } catch (HttpException $exception) {
            self::assertSame(0, $exception->getStatusCode());
            self::assertNull($exception->getResponseBody());
            self::assertStringContainsString('HTTP transport error', $exception->getMessage());
            self::assertInstanceOf(ConnectException::class, $exception->getPrevious());
        }
    }

    public function testLogsRequestAndMasksSensitiveOptions(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => ['application/json']], '{"ok":true}'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $logger = new InMemoryLogger();
        $transport = new GuzzleHttpTransport(
            client: new Client(['handler' => $handlerStack]),
            config: $this->createConfig(),
            logger: $logger,
        );

        $transport->request('POST', 'https://demo.flexibee.eu/c/demo-company/adresar', [
            'headers' => ['Authorization' => 'Bearer secret-token'],
            'password' => 'should-not-appear',
            'body' => '{"foo":"bar","password":"secret-password","nested":{"token":"nested-secret"}}',
        ]);

        self::assertNotEmpty($logger->records);
        $firstRecord = $logger->records[0];
        self::assertSame('debug', $firstRecord['level']);
        self::assertSame('Sending Flexi API request.', $firstRecord['message']);
        self::assertSame('***', $firstRecord['context']['options']['auth'][1]);
        self::assertSame('***', $firstRecord['context']['options']['headers']['Authorization']);
        self::assertSame('***', $firstRecord['context']['options']['password']);
        self::assertSame(
            '{"foo":"bar","password":"***","nested":{"token":"***"}}',
            $firstRecord['context']['options']['body'],
        );
        self::assertStringNotContainsString('demo-password', json_encode($firstRecord['context'], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret-password', json_encode($firstRecord['context'], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('nested-secret', json_encode($firstRecord['context'], JSON_THROW_ON_ERROR));
    }

    public function testMasksSensitiveValuesInLoggedUrlQuery(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => ['application/json']], '{"ok":true}'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $logger = new InMemoryLogger();
        $transport = new GuzzleHttpTransport(
            client: new Client(['handler' => $handlerStack]),
            config: $this->createConfig(),
            logger: $logger,
        );

        $transport->request(
            'GET',
            'https://demo-user:demo-password@demo.flexibee.eu/c/demo-company/adresar?token=secret-token&password=secret-password&detail=full',
        );

        self::assertCount(2, $logger->records);
        self::assertSame(
            'https://demo-user:***@demo.flexibee.eu/c/demo-company/adresar?token=***&password=***&detail=full',
            $logger->records[0]['context']['url'],
        );
        self::assertSame(
            'https://demo-user:***@demo.flexibee.eu/c/demo-company/adresar?token=***&password=***&detail=full',
            $logger->records[1]['context']['url'],
        );
        self::assertStringNotContainsString('secret-token', json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret-password', json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('demo-password', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function testMasksSensitiveValuesInLoggedErrorResponseBody(): void
    {
        $mock = new MockHandler([
            new Response(400, ['Content-Type' => ['application/json'], 'Set-Cookie' => ['session=secret']], '{"error":"bad-request","password":"secret-password","nested":{"token":"nested-secret"}}'),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $logger = new InMemoryLogger();
        $transport = new GuzzleHttpTransport(
            client: new Client(['handler' => $handlerStack]),
            config: $this->createConfig(),
            logger: $logger,
        );

        try {
            $transport->request('POST', 'https://demo-user:demo-password@demo.flexibee.eu/c/demo-company/adresar');
            self::fail('Expected HttpException was not thrown.');
        } catch (HttpException) {
            self::assertCount(3, $logger->records);
            $responseRecord = $logger->records[1];
            self::assertSame('Received Flexi API response.', $responseRecord['message']);
            self::assertSame('***', $responseRecord['context']['headers']['Set-Cookie']);

            $warningRecord = $logger->records[2];
            self::assertSame('warning', $warningRecord['level']);
            self::assertSame('https://demo-user:***@demo.flexibee.eu/c/demo-company/adresar', $warningRecord['context']['url']);
            self::assertSame(
                '{"error":"bad-request","password":"***","nested":{"token":"***"}}',
                $warningRecord['context']['responseBody'],
            );
            self::assertStringNotContainsString('secret-password', json_encode($warningRecord['context'], JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('nested-secret', json_encode($warningRecord['context'], JSON_THROW_ON_ERROR));
        }
    }

    private function createConfig(): FlexiConfig
    {
        return new FlexiConfig(
            baseUrl: 'https://demo.flexibee.eu',
            company: 'demo-company',
            username: 'demo-user',
            password: 'demo-password',
            timeout: 10.0,
        );
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
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
