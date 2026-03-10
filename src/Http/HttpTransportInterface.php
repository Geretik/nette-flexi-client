<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Http;

use Acme\AbraFlexi\Exception\HttpException;

interface HttpTransportInterface
{
    /**
     * @param array<string, mixed> $options
     * @throws HttpException
     */
    public function request(string $method, string $url, array $options = []): HttpResponse;
}
