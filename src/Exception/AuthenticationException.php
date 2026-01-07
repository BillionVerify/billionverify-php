<?php

declare(strict_types=1);

namespace EmailVerify\Exception;

class AuthenticationException extends EmailVerifyException
{
    public function __construct(string $message = 'Invalid or missing API key')
    {
        parent::__construct($message, 'INVALID_API_KEY', 401);
    }
}
