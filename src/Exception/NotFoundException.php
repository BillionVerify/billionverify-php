<?php

declare(strict_types=1);

namespace EmailVerify\Exception;

class NotFoundException extends EmailVerifyException
{
    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct($message, 'NOT_FOUND', 404);
    }
}
