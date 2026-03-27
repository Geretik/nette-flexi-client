<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Exception;

use Throwable;

/**
 * Reprezentuje obchodni/API chybu vracenou systemem Abra Flexi.
 */
final class ApiErrorException extends FlexiException
{
    /**
     * @param string $message Lidsky citelna chybova zprava z API.
     * @param string|null $errorCode Volitelny kod chyby specificky pro API.
     * @param array<string, mixed> $details
     * @param Throwable|null $previous Predchozi vyjimka, pokud je k dispozici.
     */
    public function __construct(
        string $message,
        private readonly ?string $errorCode = null,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Vraci kod chyby specificky pro API, pokud jej server poskytl.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Vraci strukturovane detaily pripojene k API chybe.
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
