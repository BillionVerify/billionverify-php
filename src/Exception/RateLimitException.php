<?php

declare(strict_types=1);

namespace EmailVerify\Exception;

class RateLimitException extends EmailVerifyException
{
    private int $retryAfter;

    public function __construct(string $message = 'Rate limit exceeded', int $retryAfter = 0)
    {
        parent::__construct($message, 'RATE_LIMIT_EXCEEDED', 429);
        $this->retryAfter = $retryAfter;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
