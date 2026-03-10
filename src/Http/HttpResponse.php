<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Http;

final readonly class HttpResponse
{
    /**
     * @param array<string, array<int, string>> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {
    }
}
