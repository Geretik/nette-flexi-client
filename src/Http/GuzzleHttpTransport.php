<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Http;

use Acme\AbraFlexi\Config\FlexiConfig;
use Acme\AbraFlexi\Exception\HttpException;
use DOMDocument;
use DOMElement;
use DOMNode;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use Psr\Log\LoggerInterface;

final readonly class GuzzleHttpTransport implements HttpTransportInterface
{
    public function __construct(
        private ClientInterface $client,
        private FlexiConfig $config,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @throws HttpException
     */
    public function request(string $method, string $url, array $options = []): HttpResponse
    {
        $requestOptions = array_replace($this->defaultOptions(), $options);
        $upperMethod = strtoupper($method);
        $maskedUrl = $this->maskSensitiveUrl($url);

        $this->logger?->debug('Sending Flexi API request.', [
            'method' => $upperMethod,
            'url' => $maskedUrl,
            'options' => $this->maskSensitiveData($requestOptions),
        ]);

        try {
            $response = $this->client->request($method, $url, $requestOptions);
        } catch (GuzzleException $exception) {
            $statusCode = 0;
            $responseBody = null;
            $maskedExceptionMessage = $this->maskSensitiveText($exception->getMessage());

            if ($exception instanceof RequestException && $exception->getResponse() !== null) {
                $statusCode = $exception->getResponse()->getStatusCode();
                $responseBody = (string) $exception->getResponse()->getBody();
            }

            $this->logger?->error('Flexi API transport error.', [
                'method' => $upperMethod,
                'url' => $maskedUrl,
                'statusCode' => $statusCode,
                'responseBody' => $responseBody !== null ? $this->maskSensitivePayload($responseBody) : null,
                'exceptionClass' => $exception::class,
                'exceptionMessage' => $maskedExceptionMessage,
            ]);

            throw new HttpException(
                message: sprintf('HTTP transport error for %s %s: %s', $upperMethod, $maskedUrl, $maskedExceptionMessage),
                statusCode: $statusCode,
                responseBody: $responseBody,
                previous: $exception,
            );
        }

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();
        /** @var array<string, array<int, string>> $headers */
        $headers = $response->getHeaders();

        $this->logger?->debug('Received Flexi API response.', [
            'method' => $upperMethod,
            'url' => $maskedUrl,
            'statusCode' => $statusCode,
            'headers' => $this->maskSensitiveData($headers),
        ]);

        if ($statusCode >= 400) {
            $this->logger?->warning('Flexi API request returned error status.', [
                'method' => $upperMethod,
                'url' => $maskedUrl,
                'statusCode' => $statusCode,
                'responseBody' => $this->maskSensitivePayload($body),
            ]);

            throw new HttpException(
                message: sprintf('HTTP request failed with status %d for %s %s.', $statusCode, $upperMethod, $maskedUrl),
                statusCode: $statusCode,
                responseBody: $body,
            );
        }

        return new HttpResponse(
            statusCode: $statusCode,
            headers: $headers,
            body: $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultOptions(): array
    {
        return [
            'auth' => [$this->config->username, $this->config->password],
            'connect_timeout' => $this->config->timeout,
            'timeout' => $this->config->timeout,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function maskSensitiveData(array $data): array
    {
        $masked = [];
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if ($normalizedKey === 'auth' && is_array($value)) {
                $masked[$key] = [
                    $value[0] ?? '***',
                    '***',
                ];
                continue;
            }

            if ($this->isSensitiveKey($normalizedKey)) {
                $masked[$key] = '***';
                continue;
            }

            if (is_string($value) && $this->isPayloadKey($normalizedKey)) {
                $masked[$key] = $this->maskSensitivePayload($value);
                continue;
            }

            if (is_string($value) && $this->isUrlKey($normalizedKey)) {
                $masked[$key] = $this->maskSensitiveUrl($value);
                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $masked[$key] = $this->maskSensitiveData($value);
                continue;
            }

            $masked[$key] = $value;
        }

        return $masked;
    }

    private function maskSensitivePayload(string $payload): string
    {
        if (trim($payload) === '') {
            return $payload;
        }

        $maskedJson = $this->maskJsonPayload($payload);
        if ($maskedJson !== null) {
            return $maskedJson;
        }

        $maskedXml = $this->maskXmlPayload($payload);
        if ($maskedXml !== null) {
            return $maskedXml;
        }

        return preg_replace_callback(
            '/(?P<prefix>\b(?:password|passwd|authorization|token|api[_-]?key|secret|cookie)\b\s*[:=]\s*)(?P<value>[^&,\s]+)/i',
            static fn(array $matches): string => $matches['prefix'] . '***',
            $payload,
        ) ?? $payload;
    }

    private function maskSensitiveText(string $text): string
    {
        $maskedText = preg_replace_callback(
            '~[a-z][a-z0-9+.-]*://[^\s<>"\')]+~i',
            fn(array $matches): string => $this->maskSensitiveUrl($matches[0]),
            $text,
        ) ?? $text;

        return $this->maskSensitivePayload($maskedText);
    }

    private function maskJsonPayload(string $payload): ?string
    {
        $trimmedPayload = ltrim($payload);
        if (!str_starts_with($trimmedPayload, '{') && !str_starts_with($trimmedPayload, '[')) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $masked = $this->maskSensitiveValue($decoded);

            return json_encode($masked, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return null;
        }
    }

    private function maskXmlPayload(string $payload): ?string
    {
        $trimmedPayload = ltrim($payload);
        if (!str_starts_with($trimmedPayload, '<')) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadXML($payload, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document->documentElement === null) {
            return null;
        }

        $this->maskXmlNode($document->documentElement);

        $masked = $document->saveXML($document->documentElement);

        return $masked !== false ? $masked : null;
    }

    private function maskXmlNode(DOMNode $node): void
    {
        if (!$node instanceof DOMElement) {
            return;
        }

        foreach (iterator_to_array($node->attributes) as $attribute) {
            if ($this->isSensitiveKey(strtolower($attribute->name))) {
                $attribute->value = '***';
            }
        }

        if ($this->isSensitiveKey(strtolower($node->tagName))) {
            while ($node->firstChild !== null) {
                $node->removeChild($node->firstChild);
            }

            $node->appendChild($node->ownerDocument->createTextNode('***'));

            return;
        }

        foreach (iterator_to_array($node->childNodes) as $childNode) {
            $this->maskXmlNode($childNode);
        }
    }

    private function maskSensitiveValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey(strtolower($key))) {
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

    private function maskSensitiveUrl(string $url): string
    {
        $maskedUrl = preg_replace_callback(
            '~^(?P<scheme>[a-z][a-z0-9+.-]*://)(?P<user>[^/@:]+)(?::(?P<password>[^/@]*))?@~i',
            static function (array $matches): string {
                $password = array_key_exists('password', $matches) ? ':***' : '';

                return $matches['scheme'] . $matches['user'] . $password . '@';
            },
            $url,
        ) ?? $url;

        $queryStart = strpos($maskedUrl, '?');
        if ($queryStart === false) {
            return $maskedUrl;
        }

        $fragmentStart = strpos($maskedUrl, '#', $queryStart);
        $query = $fragmentStart === false
            ? substr($maskedUrl, $queryStart + 1)
            : substr($maskedUrl, $queryStart + 1, $fragmentStart - $queryStart - 1);

        if ($query === '') {
            return $maskedUrl;
        }

        $maskedQuery = $this->maskSensitiveQuery($query);
        if ($fragmentStart === false) {
            return substr($maskedUrl, 0, $queryStart + 1) . $maskedQuery;
        }

        return substr($maskedUrl, 0, $queryStart + 1) . $maskedQuery . substr($maskedUrl, $fragmentStart);
    }

    private function maskSensitiveQuery(string $query): string
    {
        $pairs = explode('&', $query);

        foreach ($pairs as $index => $pair) {
            if ($pair === '') {
                continue;
            }

            [$encodedKey] = explode('=', $pair, 2);
            if (!$this->isSensitiveQueryKey(rawurldecode($encodedKey))) {
                continue;
            }

            $pairs[$index] = $encodedKey . '=***';
        }

        return implode('&', $pairs);
    }

    private function isPayloadKey(string $key): bool
    {
        return in_array($key, ['body', 'responsebody'], true);
    }

    private function isUrlKey(string $key): bool
    {
        return in_array($key, ['url', 'uri'], true);
    }

    private function isSensitiveQueryKey(string $key): bool
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
