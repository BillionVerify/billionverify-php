<?php

declare(strict_types=1);

namespace BillionVerify\Exception;

class TimeoutException extends BillionVerifyException
{
    public function __construct(string $message = 'Request timed out')
    {
        parent::__construct($message, 'TIMEOUT', 504);
    }
}
