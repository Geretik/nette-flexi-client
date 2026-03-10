<?php

declare(strict_types=1);

namespace Acme\AbraFlexi\Exception;

use Throwable;

final class ParseException extends FlexiException
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
