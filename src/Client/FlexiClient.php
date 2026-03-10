<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Client;

use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Exception\ApiErrorException;
use Acme\AbraFlexi\Exception\HttpException;
use Acme\AbraFlexi\Exception\ParseException;
use Acme\AbraFlexi\Http\HttpResponse;
use Acme\AbraFlexi\Http\HttpTransportInterface;
use Acme\AbraFlexi\Response\ResponseParser;
use JsonException;
use Psr\Log\LoggerInterface;

final readonly class FlexiClient
{
    private const FORMAT_JSON = 'json';
    private const FORMAT_XML = 'xml';

    public function __construct(
        private EndpointBuilder $endpointBuilder,
        private HttpTransportInterface $httpTransport,
        private ResponseParser $responseParser,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<mixed>
     */
    public function get(string $agenda, ?string $recordId = null, array $query = []): array
    {
        return $this->request('GET', $agenda, $recordId, $query, [], self::FORMAT_JSON);
    }

    /**
     * @param array<mixed>|string $payload
     * @param array<string, scalar|null> $query
     * @return array<mixed>
     */
    public function post(string $agenda, array|string $payload, array $query = []): array
    {
        $preparedPayload = $this->createPayloadOptions($agenda, $payload);

        return $this->request('POST', $agenda, null, $query, $preparedPayload['options'], $preparedPayload['format']);
    }

    /**
     * @param array<mixed>|string $payload
     * @param array<string, scalar|null> $query
     * @return array<mixed>
     */
    public function put(string $agenda, string $recordId, array|string $payload, array $query = []): array
    {
        $preparedPayload = $this->createPayloadOptions($agenda, $payload);

        return $this->request('PUT', $agenda, $recordId, $query, $preparedPayload['options'], $preparedPayload['format']);
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<mixed>
     */
    public function delete(string $agenda, string $recordId, array $query = []): array
    {
        return $this->request('DELETE', $agenda, $recordId, $query, [], self::FORMAT_JSON);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    private function request(
        string $method,
        string $agenda,
        ?string $recordId,
        array $query,
        array $options = [],
        string $format = self::FORMAT_JSON,
    ): array {
        $url = $this->endpointBuilder->agenda($agenda, $recordId, $query, $format);
        try {
            $response = $this->httpTransport->request($method, $url, $options);
        } catch (HttpException $exception) {
            $this->rethrowApiErrorFromHttpException($exception);
            throw $exception;
        }

        try {
            return $this->responseParser->parse($response->body, $this->extractContentType($response));
        } catch (ApiErrorException $exception) {
            $this->logger?->warning('Flexi API business error.', [
                'method' => $method,
                'agenda' => $agenda,
                'recordId' => $recordId,
                'query' => $this->maskSensitiveValue($query),
                'errorCode' => $exception->getErrorCode(),
                'details' => $this->maskSensitiveValue($exception->getDetails()),
            ]);

            throw $exception;
        }
    }

    /**
     * @param array<mixed>|string $payload
     * @return array{format: string, options: array<string, mixed>}
     */
    private function createPayloadOptions(string $agenda, array|string $payload): array
    {
        if (is_string($payload)) {
            $format = $this->detectPayloadFormat($payload);
            $contentType = $this->contentTypeForFormat($format);

            return [
                'format' => $format,
                'options' => [
                    'headers' => [
                        'Accept' => $contentType,
                        'Content-Type' => $contentType,
                    ],
                    'body' => $payload,
                ],
            ];
        }

        try {
            $encodedPayload = json_encode($this->normalizeJsonPayload($agenda, $payload), JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParseException('Request payload could not be encoded to JSON.', $exception);
        }

        return [
            'format' => self::FORMAT_JSON,
            'options' => [
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => $encodedPayload,
            ],
        ];
    }

    /**
     * @param array<mixed> $payload
     * @return array<mixed>
     */
    private function normalizeJsonPayload(string $agenda, array $payload): array
    {
        if (isset($payload['winstrom']) && is_array($payload['winstrom'])) {
            return $payload;
        }

        $segments = explode('/', trim($agenda, '/'));
        $rootNode = end($segments);
        if (!is_string($rootNode) || $rootNode === '') {
            throw new ParseException('Agenda must not be empty when preparing request payload.');
        }

        return [
            'winstrom' => [
                $rootNode => $payload,
            ],
        ];
    }

    private function detectPayloadFormat(string $payload): string
    {
        $trimmedPayload = ltrim($payload);

        if (str_starts_with($trimmedPayload, '<')) {
            return self::FORMAT_XML;
        }

        return self::FORMAT_JSON;
    }

    private function contentTypeForFormat(string $format): string
    {
        return match ($format) {
            self::FORMAT_XML => 'application/xml',
            default => 'application/json',
        };
    }

    /**
     * Flexi often returns structured API validation errors in 4xx bodies.
     */
    private function rethrowApiErrorFromHttpException(HttpException $exception): void
    {
        $responseBody = $exception->getResponseBody();
        if ($responseBody === null || trim($responseBody) === '') {
            return;
        }

        try {
            $this->responseParser->parse($responseBody);
        } catch (ParseException) {
            return;
        }
    }

    private function extractContentType(HttpResponse $response): ?string
    {
        foreach ($response->headers as $name => $values) {
            if (strtolower($name) === 'content-type') {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    private function maskSensitiveValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveContextKey($key)) {
            return '***';
        }

        if (!is_array($value)) {
            return $value;
        }

        $masked = [];
        foreach ($value as $nestedKey => $nestedValue) {
            $masked[$nestedKey] = $this->maskSensitiveValue(
                $nestedValue,
                is_string($nestedKey) ? $nestedKey : null,
            );
        }

        return $masked;
    }

    private function isSensitiveContextKey(string $key): bool
    {
        $segments = preg_split('/[\[\].]+/', strtolower($key), -1, PREG_SPLIT_NO_EMPTY);
        if ($segments === false || $segments === []) {
            return $this->isSensitiveKey(strtolower($key));
        }

        foreach ($segments as $segment) {
            if ($this->isSensitiveKey($segment)) {
                return true;
            }
        }

        return false;
    }

    private function isSensitiveKey(string $key): bool
    {
        return in_array($key, [
            'password',
            'passwd',
            'authorization',
            'proxy-authorization',
            'token',
            'api_key',
            'apikey',
            'api-key',
            'secret',
            'cookie',
            'set-cookie',
        ], true)
            || str_contains($key, 'token')
            || str_contains($key, 'secret')
            || str_contains($key, 'authorization')
            || str_contains($key, 'cookie');
    }
}
