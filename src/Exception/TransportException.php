<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Exception;

use Throwable;

/**
 * Reprezentuje chybu transportní vrstvy (DNS, TLS, timeout, odmítnuté spojení),
 * tedy situaci, kdy se k Abra Flexi API vůbec nepodařilo dostat odpověď s HTTP
 * statusem.
 *
 * Dědí z {@see HttpException} kvůli zpětné kompatibilitě - kód, který chytá
 * `HttpException`, bude i nadále fungovat. Nový kód může cíleně rozlišovat
 * mezi `TransportException` (síť) a `HttpException` (server vrátil ne-2xx).
 */
final class TransportException extends HttpException
{
    /**
     * @param string $message Lidsky čitelná chybová zpráva.
     * @param Throwable|null $previous Původní výjimka transportu (např. Guzzle).
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct(
            message: $message,
            statusCode: 0,
            responseBody: null,
            previous: $previous,
        );
    }
}
