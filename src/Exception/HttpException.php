<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Exception;

use Throwable;

/**
 * Reprezentuje HTTP chybu pri komunikaci s Abra Flexi API.
 */
final class HttpException extends FlexiException
{
    /**
     * @param string $message Lidsky citelna chybova zprava.
     * @param int $statusCode HTTP stavovy kod odpovedi.
     * @param string|null $responseBody Volitelne telo HTTP odpovedi.
     * @param Throwable|null $previous Predchozi vyjimka, pokud je k dispozici.
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly ?string $responseBody = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Vraci HTTP stavovy kod odpovedi.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Vraci telo HTTP odpovedi, pokud je k dispozici.
     */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
