<?php

declare(strict_types=1);

namespace BillionVerify\Exception;

class InsufficientCreditsException extends BillionVerifyException
{
    public function __construct(string $message = 'Insufficient credits')
    {
        parent::__construct($message, 'INSUFFICIENT_CREDITS', 403);
    }
}
