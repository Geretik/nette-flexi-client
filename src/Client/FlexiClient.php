<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Client;

use Acme\AbraFlexi\Endpoint\EndpointBuilder;
use Acme\AbraFlexi\Exception\ApiErrorException;
use Acme\AbraFlexi\Exception\HttpException;
use Acme\AbraFlexi\Exception\ParseException;
use Acme\AbraFlexi\Http\HttpResponse;
use Acme\AbraFlexi\Http\HttpTransportInterface;
use Acme\AbraFlexi\Payload\PayloadEncoder;
use Acme\AbraFlexi\Response\ResponseParser;
use Acme\AbraFlexi\Sensitive\SensitiveDataMasker;
use Psr\Log\LoggerInterface;

/**
 * Verejne API knihovny - tenky orchestrator nad jednotlivymi vrstvami.
 *
 * Klient sam neresi:
 *  - skladani URL (deleguje na {@see EndpointBuilder}),
 *  - serializaci payloadu (deleguje na {@see PayloadEncoder}),
 *  - HTTP komunikaci (deleguje na {@see HttpTransportInterface}),
 *  - parsovani odpovedi a detekci chyb (deleguje na {@see ResponseParser}),
 *  - maskovani citlivych dat v logu (deleguje na {@see SensitiveDataMasker}).
 *
 * Diky tomu zustava telo metod kratke, snadno citelne a kazda zavislost
 * je samostatne testovatelna.
 */
final readonly class FlexiClient
{
    private SensitiveDataMasker $masker;
    private PayloadEncoder $payloadEncoder;

    /**
     * @param EndpointBuilder $endpointBuilder Builder pro skladani URL endpointu.
     * @param HttpTransportInterface $httpTransport HTTP transportni vrstva.
     * @param ResponseParser $responseParser Parser odpovedi vcetne detekce chyb.
     * @param LoggerInterface|null $logger Volitelny PSR logger pro business chyby.
     * @param PayloadEncoder|null $payloadEncoder Volitelna instance encoderu;
     *                                            pokud chybi, pouzije se vychozi.
     * @param SensitiveDataMasker|null $masker Volitelna instance maskeru;
     *                                         pokud chybi, pouzije se vychozi.
     */
    public function __construct(
        private EndpointBuilder $endpointBuilder,
        private HttpTransportInterface $httpTransport,
        private ResponseParser $responseParser,
        private ?LoggerInterface $logger = null,
        ?PayloadEncoder $payloadEncoder = null,
        ?SensitiveDataMasker $masker = null,
    ) {
        $this->payloadEncoder = $payloadEncoder ?? new PayloadEncoder();
        $this->masker = $masker ?? new SensitiveDataMasker();
    }

    /**
     * Provede GET pozadavek na zadanou agendu Flexi API.
     *
     * Pokud je vyplneno $recordId, nacte konkretni zaznam,
     * jinak nacte seznam zaznamu.
     *
     * @param string $agenda Nazev agendy / endpointu.
     * @param string|null $recordId Volitelne ID konkretniho zaznamu.
     * @param array<string, scalar|null> $query Parametry do URL.
     * @return array<mixed> Naparsovana odpoved z API.
     */
    public function get(string $agenda, ?string $recordId = null, array $query = []): array
    {
        return $this->request('GET', $agenda, $recordId, $query, [], PayloadEncoder::FORMAT_JSON);
    }

    /**
     * Provede POST pozadavek na zadanou agendu Flexi API.
     *
     * @param string $agenda Nazev agendy / endpointu.
     * @param array<mixed>|string $payload Data v tele pozadavku.
     * @param array<string, scalar|null> $query Volitelne parametry do URL.
     * @return array<mixed> Naparsovana odpoved z API.
     */
    public function post(string $agenda, array|string $payload, array $query = []): array
    {
        $encoded = $this->payloadEncoder->encode($agenda, $payload);

        return $this->request('POST', $agenda, null, $query, $this->payloadOptions($encoded), $encoded['format']);
    }

    /**
     * Provede PUT pozadavek na zadanou agendu Flexi API.
     *
     * @param string $agenda Nazev agendy / endpointu.
     * @param string $recordId ID konkretniho zaznamu k uprave.
     * @param array<mixed>|string $payload Data v tele pozadavku.
     * @param array<string, scalar|null> $query Volitelne parametry do URL.
     * @return array<mixed> Naparsovana odpoved z API.
     */
    public function put(string $agenda, string $recordId, array|string $payload, array $query = []): array
    {
        $encoded = $this->payloadEncoder->encode($agenda, $payload);

        return $this->request('PUT', $agenda, $recordId, $query, $this->payloadOptions($encoded), $encoded['format']);
    }

    /**
     * Provede DELETE pozadavek na zadanou agendu Flexi API.
     *
     * @param string $agenda Nazev agendy / endpointu.
     * @param string $recordId ID konkretniho zaznamu ke smazani.
     * @param array<string, scalar|null> $query Volitelne parametry do URL.
     * @return array<mixed> Naparsovana odpoved z API.
     */
    public function delete(string $agenda, string $recordId, array $query = []): array
    {
        return $this->request('DELETE', $agenda, $recordId, $query, [], PayloadEncoder::FORMAT_JSON);
    }

    /**
     * Provede HTTP pozadavek na agendu Flexi API a vrati naparsovanou odpoved.
     *
     * Na hranici transportni vrstvy se HTTP chyba pokusi rozsifrovat na
     * strukturovanou {@see ApiErrorException} - aplikace pak dostane stejnou
     * vyjimku jako pri 200 odpovedi s chybovym payloadem.
     *
     * @param array<string, scalar|null> $query
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    private function request(
        string $method,
        string $agenda,
        ?string $recordId,
        array $query,
        array $options,
        string $format,
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
            $this->logBusinessApiError($method, $agenda, $recordId, $query, $exception);
            throw $exception;
        }
    }

    /**
     * Sestavi Guzzle options pro odeslani zakodovaneho payloadu.
     *
     * @param array{format: string, contentType: string, body: string} $encoded
     * @return array<string, mixed>
     */
    private function payloadOptions(array $encoded): array
    {
        return [
            'headers' => [
                'Accept' => $encoded['contentType'],
                'Content-Type' => $encoded['contentType'],
            ],
            'body' => $encoded['body'],
        ];
    }

    /**
     * Zalogovani business chyby vracene API. Citlive hodnoty se pred logem
     * automaticky maskuji pres {@see SensitiveDataMasker}.
     *
     * @param array<string, scalar|null> $query
     */
    private function logBusinessApiError(
        string $method,
        string $agenda,
        ?string $recordId,
        array $query,
        ApiErrorException $exception,
    ): void {
        $this->logger?->warning('Flexi API business error.', [
            'method' => $method,
            'agenda' => $agenda,
            'recordId' => $recordId,
            'query' => $this->masker->mask($query),
            'errorCode' => $exception->getErrorCode(),
            'details' => $this->masker->mask($exception->getDetails()),
        ]);
    }

    /**
     * Zkusi z tela HTTP chyby vytezit detailnejsi API chybu.
     *
     * Pokud Flexi v ramci 4xx/5xx odpovedi vraci strukturovany payload s polem
     * `errors`, parser pri jeho parsovani primo vyhodi {@see ApiErrorException}.
     * Tu propaguje volajici metoda, takze aplikace dostane bohatsi chybu nez
     * pouhou {@see HttpException}. Jine vysledky parseru zde nezajimaji - jdou
     * stranou a `request()` puvodni `HttpException` rethrowne.
     *
     * @throws ApiErrorException Pokud telo HTTP odpovedi obsahuje API chybu.
     */
    private function rethrowApiErrorFromHttpException(HttpException $exception): void
    {
        $responseBody = $exception->getResponseBody();
        if ($responseBody === null || trim($responseBody) === '') {
            return;
        }

        try {
            $this->responseParser->parse($responseBody);
        } catch (ApiErrorException $apiError) {
            throw $apiError;
        } catch (ParseException) {
            // Telo neni validni JSON/XML - drzime se puvodni HttpException.
        }
    }

    /**
     * Vrati hodnotu hlavicky Content-Type z HTTP odpovedi.
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
}
