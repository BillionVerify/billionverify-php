<?php

declare(strict_types=1);

namespace BillionVerify\Exception;

class ValidationException extends BillionVerifyException
{
    public function __construct(string $message, ?string $details = null)
    {
        parent::__construct($message, 'INVALID_REQUEST', 400, $details);
    }
}
