<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Http;

/**
 * Hodnotovy objekt reprezentujici HTTP odpoved z API.
 */
final readonly class HttpResponse
{
    /**
     * @param int $statusCode HTTP stavovy kod odpovedi.
     * @param array<string, array<int, string>> $headers
     * @param string $body Telo HTTP odpovedi.
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {
    }
}
