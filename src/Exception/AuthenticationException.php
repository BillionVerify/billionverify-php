<?php

declare(strict_types=1);

namespace BillionVerify\Exception;

class AuthenticationException extends BillionVerifyException
{
    public function __construct(string $message = 'Invalid or missing API key')
    {
        parent::__construct($message, 'INVALID_API_KEY', 401);
    }
}
