<?php

declare(strict_types=1);

namespace BillionVerify\Exception;

use Exception;

class BillionVerifyException extends Exception
{
    protected string $errorCode;
    protected int $statusCode;
    protected ?string $details;

    public function __construct(
        string $message,
        string $code = 'UNKNOWN_ERROR',
        int $statusCode = 0,
        ?string $details = null
    ) {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->statusCode = $statusCode;
        $this->details = $details;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }
}
