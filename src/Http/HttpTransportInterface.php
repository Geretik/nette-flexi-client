<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Http;

use Acme\AbraFlexi\Exception\HttpException;
use Acme\AbraFlexi\Exception\TransportException;

/**
 * Kontrakt pro HTTP transport pouzivany klientem Abra Flexi API.
 *
 * Vlastni implementaci je mozne zaregistrovat v DI kontejneru pod typem
 * `HttpTransportInterface` - hlavni klient ji pak pouzije misto
 * {@see GuzzleHttpTransport}.
 */
interface HttpTransportInterface
{
    /**
     * Provede HTTP pozadavek a vrati normalizovanou odpoved.
     *
     * @param string $method HTTP metoda (napr. GET, POST, PUT, DELETE).
     * @param string $url Cilova URL adresa pozadavku.
     * @param array<string, mixed> $options Volby specificke pro implementaci
     *                                      (napr. headers, body, query).
     * @throws TransportException Pokud doslo k siti/TLS/timeout chybe a server
     *                            vubec nevratil HTTP odpoved.
     * @throws HttpException Pokud server vratil HTTP odpoved se status kodem >= 400.
     */
    public function request(string $method, string $url, array $options = []): HttpResponse;
}
