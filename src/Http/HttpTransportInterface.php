<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Http;

use Acme\AbraFlexi\Exception\HttpException;

/**
 * Kontrakt pro HTTP transport pouzivany klientem Abra Flexi API.
 */
interface HttpTransportInterface
{
    /**
     * Provede HTTP pozadavek a vrati normalizovanou odpoved.
     *
     * @param string $method HTTP metoda (napr. GET, POST, PUT, DELETE).
     * @param string $url Cilova URL adresa pozadavku.
     * @param array<string, mixed> $options
     * @throws HttpException
     */
    public function request(string $method, string $url, array $options = []): HttpResponse;
}
