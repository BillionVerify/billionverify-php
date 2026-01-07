<?php

declare(strict_types=1);

namespace EmailVerify\Exception;

class TimeoutException extends EmailVerifyException
{
    public function __construct(string $message = 'Request timed out')
    {
        parent::__construct($message, 'TIMEOUT', 504);
    }
}
