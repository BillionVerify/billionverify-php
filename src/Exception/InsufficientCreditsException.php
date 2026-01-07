<?php

declare(strict_types=1);

namespace EmailVerify\Exception;

class InsufficientCreditsException extends EmailVerifyException
{
    public function __construct(string $message = 'Insufficient credits')
    {
        parent::__construct($message, 'INSUFFICIENT_CREDITS', 403);
    }
}
