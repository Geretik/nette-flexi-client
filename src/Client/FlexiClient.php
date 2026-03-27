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
     * Provede GET požadavek na zadanou agendu Flexi API.
     *
     * Pokud je vyplněno $recordId, načte konkrétní záznam.
     * Pokud je $recordId null, načte seznam záznamů.
     *
     * @param string $agenda Název agendy / endpointu pro získání dat.
     * @param string|null $recordId Volitelné ID konkrétního záznamu, jinak se načítá celý seznam.
     * @param array<string, scalar|null> $query Parametry do URL, např. filtrování, stránkování nebo upřesnění odpovědi.
     * @return array<mixed> Naparsovaná odpověď z API.
     */
    public function get(string $agenda, ?string $recordId = null, array $query = []): array
    {
        return $this->request('GET', $agenda, $recordId, $query, [], self::FORMAT_JSON);
    }

    /**
     * Provede POST požadavek na zadanou agendu Flexi API.
     *
     * @param string $agenda Název agendy / endpointu.
     * @param array<mixed>|string $payload Data v těle požadavku.
     * @param array<string, scalar|null> $query Volitelné parametry do URL.
     * @return array<mixed> Naparsovaná odpověď z API.
     */
    public function post(string $agenda, array|string $payload, array $query = []): array
    {
        $preparedPayload = $this->createPayloadOptions($agenda, $payload);

        return $this->request('POST', $agenda, null, $query, $preparedPayload['options'], $preparedPayload['format']);
    }

    /**
     * Provede PUT požadavek na zadanou agendu Flexi API.
     *
     * @param string $agenda Název agendy / endpointu.
     * @param string $recordId ID konkrétního záznamu, který se má upravit.
     * @param array<mixed>|string $payload Data v těle požadavku.
     * @param array<string, scalar|null> $query Volitelné parametry do URL.
     * @return array<mixed> Naparsovaná odpověď z API.
     */
    public function put(string $agenda, string $recordId, array|string $payload, array $query = []): array
    {
        $preparedPayload = $this->createPayloadOptions($agenda, $payload);

        return $this->request('PUT', $agenda, $recordId, $query, $preparedPayload['options'], $preparedPayload['format']);
    }

    /**
     * Provede DELETE požadavek na zadanou agendu Flexi API.
     *
     * @param string $agenda Název agendy / endpointu.
     * @param string $recordId ID konkrétního záznamu, který se má smazat.
     * @param array<string, scalar|null> $query Volitelné parametry do URL.
     * @return array<mixed> Naparsovaná odpověď z API.
     */
    public function delete(string $agenda, string $recordId, array $query = []): array
    {
        return $this->request('DELETE', $agenda, $recordId, $query, [], self::FORMAT_JSON);
    }

    /**
     * Provede HTTP požadavek na zadanou agendu Flexi API a vrátí naparsovanou odpověď.
     *
     * @param string $method HTTP metoda požadavku, např. GET, POST, PUT nebo DELETE.
     * @param string $agenda Název agendy / endpointu.
     * @param string|null $recordId Volitelné ID konkrétního záznamu, jinak se pracuje nad celou agendou.
     * @param array<string, scalar|null> $query Volitelné parametry do URL.
     * @param array<string, mixed> $options Volitelné HTTP options, např. headers nebo body požadavku.
     * @param string $format Formát endpointu / odpovědi, typicky json nebo xml.
     * @return array<mixed> Naparsovaná odpověď z API.
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
     * Připraví payload a HTTP options pro odeslání požadavku do Flexi API.
     *
     * @param string $agenda Název agendy / endpointu.
     * @param array<mixed>|string $payload Data odesílaná v těle požadavku.
     *                                     String se odešle přímo,
     *                                     pole se normalizuje a zakóduje do JSON.
     * @return array{format: string, options: array<string, mixed>}
     *         Připravený formát payloadu a HTTP options pro request.
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
     * Doplní payload do JSON struktury očekávané Flexi API.
     *
     * @param string $agenda Název agendy / endpointu.
     * @param array<mixed> $payload Data pro odeslání.
     * @return array<mixed> Payload obalený do správné struktury s root uzlem 'winstrom'.
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

    /**
     * Rozpozná, zda je payload ve formátu XML nebo JSON.
     *
     * @param string $payload Obsah request body jako text.
     * @return string Formát payloadu, typicky 'xml' nebo 'json'.
     */
    private function detectPayloadFormat(string $payload): string
    {
        $trimmedPayload = ltrim($payload);

        if (str_starts_with($trimmedPayload, '<')) {
            return self::FORMAT_XML;
        }

        return self::FORMAT_JSON;
    }

    /**
     * Vrátí Content-Type hlavičku podle zvoleného formátu.
     *
     * @param string $format Formát payloadu, typicky 'xml' nebo 'json'.
     * @return string Odpovídající MIME typ pro HTTP hlavičku.
     */
    private function contentTypeForFormat(string $format): string
    {
        return match ($format) {
            self::FORMAT_XML => 'application/xml',
            default => 'application/json',
        };
    }

    /**
     * Zkusí z těla HTTP chyby vytěžit detailnější API chybu.
     *
     * @param HttpException $exception HTTP výjimka s odpovědí serveru.
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

    /**
     * Vrátí hodnotu hlavičky Content-Type z HTTP odpovědi.
     *
     * @param HttpResponse $response HTTP odpověď.
     * @return string|null Hodnota Content-Type, nebo null pokud hlavička neexistuje.
     */
    private function extractContentType(HttpResponse $response): ?string
    {
        foreach ($response->headers as $name => $values) {
            if (strtolower($name) === 'content-type') {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    /**
     * Zamaskuje citlivé hodnoty v datech, zejména před logováním.
     *
     * @param mixed $value Hodnota ke kontrole.
     * @param string|null $key Volitelný název klíče pro určení citlivosti.
     * @return mixed Původní nebo zamaskovaná hodnota.
     */
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

    /**
     * Zjistí, zda klíč nebo některá jeho část označuje citlivý údaj.
     *
     * @param string $key Název klíče ke kontrole.
     * @return bool True, pokud je klíč citlivý, jinak false.
     */
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

    /**
     * Zjistí, zda název klíče odpovídá citlivému údaji.
     *
     * @param string $key Název klíče ke kontrole.
     * @return bool True, pokud je klíč citlivý, jinak false.
     */
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
