<?php

declare(strict_types=1);

namespace BillionVerify\Exception;

class NotFoundException extends BillionVerifyException
{
    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct($message, 'NOT_FOUND', 404);
    }
}
