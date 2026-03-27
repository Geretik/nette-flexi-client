<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Exception;

use Throwable;

/**
 * Reprezentuje chybu pri parsovani odpovedi z Abra Flexi API.
 */
final class ParseException extends FlexiException
{
    /**
     * @param string $message Lidsky citelna chybova zprava.
     * @param Throwable|null $previous Predchozi vyjimka, pokud je k dispozici.
     */
    public function __construct(
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
