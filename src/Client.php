<?php

declare(strict_types=1);

namespace EmailVerify;

use EmailVerify\Exception\AuthenticationException;
use EmailVerify\Exception\EmailVerifyException;
use EmailVerify\Exception\InsufficientCreditsException;
use EmailVerify\Exception\NotFoundException;
use EmailVerify\Exception\RateLimitException;
use EmailVerify\Exception\TimeoutException;
use EmailVerify\Exception\ValidationException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

class Client
{
    private const DEFAULT_BASE_URL = 'https://api.emailverify.ai/v1';
    private const DEFAULT_TIMEOUT = 30;
    private const DEFAULT_RETRIES = 3;
    private const USER_AGENT = 'emailverify-php/1.0.0';

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $retries;
    private HttpClient $httpClient;

    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $retries = self::DEFAULT_RETRIES
    ) {
        if (empty($apiKey)) {
            throw new AuthenticationException('API key is required');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = $timeout;
        $this->retries = $retries;

        $this->httpClient = new HttpClient([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => [
                'EMAILVERIFY-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ],
        ]);
    }

    /**
     * @throws EmailVerifyException
     */
    private function request(string $method, string $path, ?array $body = null, int $attempt = 1): ?array
    {
        try {
            $options = [];
            if ($body !== null) {
                $options['json'] = $body;
            }

            $response = $this->httpClient->request($method, $path, $options);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 204) {
                return null;
            }

            $contents = $response->getBody()->getContents();
            return json_decode($contents, true);
        } catch (ConnectException $e) {
            throw new TimeoutException('Connection timed out: ' . $e->getMessage());
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if ($response === null) {
                throw new EmailVerifyException('Network error: ' . $e->getMessage(), 'NETWORK_ERROR', 0);
            }

            $statusCode = $response->getStatusCode();
            $contents = $response->getBody()->getContents();
            $data = json_decode($contents, true) ?? [];
            $error = $data['error'] ?? [];
            $message = $error['message'] ?? $response->getReasonPhrase();
            $code = $error['code'] ?? 'UNKNOWN_ERROR';
            $details = $error['details'] ?? null;

            return $this->handleErrorResponse($statusCode, $message, $code, $details, $method, $path, $body, $attempt, $response);
        }
    }

    /**
     * @throws EmailVerifyException
     */
    private function handleErrorResponse(
        int $statusCode,
        string $message,
        string $code,
        ?string $details,
        string $method,
        string $path,
        ?array $body,
        int $attempt,
        $response
    ): ?array {
        switch ($statusCode) {
            case 401:
                throw new AuthenticationException($message);

            case 403:
                if ($code === 'INSUFFICIENT_CREDITS') {
                    throw new InsufficientCreditsException($message);
                }
                throw new EmailVerifyException($message, $code, 403);

            case 404:
                throw new NotFoundException($message);

            case 429:
                $retryAfter = (int) ($response->getHeader('Retry-After')[0] ?? 0);
                if ($attempt < $this->retries) {
                    $waitTime = $retryAfter > 0 ? $retryAfter : pow(2, $attempt);
                    sleep((int) $waitTime);
                    return $this->request($method, $path, $body, $attempt + 1);
                }
                throw new RateLimitException($message, $retryAfter);

            case 400:
                throw new ValidationException($message, $details);

            case 500:
            case 502:
            case 503:
                if ($attempt < $this->retries) {
                    sleep((int) pow(2, $attempt));
                    return $this->request($method, $path, $body, $attempt + 1);
                }
                throw new EmailVerifyException($message, $code, $statusCode);

            default:
                throw new EmailVerifyException($message, $code, $statusCode, $details);
        }
    }

    /**
     * Verify a single email address.
     *
     * @param string $email The email address to verify
     * @param bool $smtpCheck Whether to perform SMTP verification
     * @param int|null $timeout Verification timeout in milliseconds
     * @return array Verification result
     * @throws EmailVerifyException
     */
    public function verify(string $email, bool $smtpCheck = true, ?int $timeout = null): array
    {
        $payload = [
            'email' => $email,
            'smtp_check' => $smtpCheck,
        ];

        if ($timeout !== null) {
            $payload['timeout'] = $timeout;
        }

        return $this->request('POST', '/verify', $payload);
    }

    /**
     * Submit a bulk verification job.
     *
     * @param array $emails Array of email addresses (max 10,000)
     * @param bool $smtpCheck Whether to perform SMTP verification
     * @param string|null $webhookUrl URL to receive completion notification
     * @return array Bulk job response
     * @throws EmailVerifyException
     */
    public function verifyBulk(array $emails, bool $smtpCheck = true, ?string $webhookUrl = null): array
    {
        if (count($emails) > 10000) {
            throw new ValidationException('Maximum 10,000 emails per bulk job');
        }

        $payload = [
            'emails' => $emails,
            'smtp_check' => $smtpCheck,
        ];

        if ($webhookUrl !== null) {
            $payload['webhook_url'] = $webhookUrl;
        }

        return $this->request('POST', '/verify/bulk', $payload);
    }

    /**
     * Get the status of a bulk verification job.
     *
     * @param string $jobId The bulk job ID
     * @return array Job status
     * @throws EmailVerifyException
     */
    public function getBulkJobStatus(string $jobId): array
    {
        return $this->request('GET', "/verify/bulk/{$jobId}");
    }

    /**
     * Get the results of a completed bulk verification job.
     *
     * @param string $jobId The bulk job ID
     * @param int $limit Number of results per page (default: 100, max: 1000)
     * @param int $offset Starting position (default: 0)
     * @param string|null $status Filter by status ('valid', 'invalid', 'unknown')
     * @return array Bulk results
     * @throws EmailVerifyException
     */
    public function getBulkJobResults(string $jobId, int $limit = 100, int $offset = 0, ?string $status = null): array
    {
        $query = http_build_query(array_filter([
            'limit' => $limit,
            'offset' => $offset,
            'status' => $status,
        ]));

        $path = "/verify/bulk/{$jobId}/results";
        if ($query) {
            $path .= "?{$query}";
        }

        return $this->request('GET', $path);
    }

    /**
     * Wait for bulk job completion.
     *
     * @param string $jobId The bulk job ID
     * @param int $pollInterval Time between polls in seconds (default: 5)
     * @param int $maxWait Maximum wait time in seconds (default: 600)
     * @return array Final job status
     * @throws EmailVerifyException
     */
    public function waitForBulkJobCompletion(string $jobId, int $pollInterval = 5, int $maxWait = 600): array
    {
        $startTime = time();

        while (time() - $startTime < $maxWait) {
            $status = $this->getBulkJobStatus($jobId);

            if (in_array($status['status'], ['completed', 'failed'], true)) {
                return $status;
            }

            sleep($pollInterval);
        }

        throw new TimeoutException("Bulk job {$jobId} did not complete within {$maxWait} seconds");
    }

    /**
     * Get current credit balance.
     *
     * @return array Credits information
     * @throws EmailVerifyException
     */
    public function getCredits(): array
    {
        return $this->request('GET', '/credits');
    }

    /**
     * Create a new webhook.
     *
     * @param string $url The webhook URL
     * @param array $events List of events to subscribe to
     * @param string|null $secret Optional webhook secret
     * @return array Webhook configuration
     * @throws EmailVerifyException
     */
    public function createWebhook(string $url, array $events, ?string $secret = null): array
    {
        $payload = [
            'url' => $url,
            'events' => $events,
        ];

        if ($secret !== null) {
            $payload['secret'] = $secret;
        }

        return $this->request('POST', '/webhooks', $payload);
    }

    /**
     * List all webhooks.
     *
     * @return array List of webhooks
     * @throws EmailVerifyException
     */
    public function listWebhooks(): array
    {
        return $this->request('GET', '/webhooks');
    }

    /**
     * Delete a webhook.
     *
     * @param string $webhookId The webhook ID to delete
     * @throws EmailVerifyException
     */
    public function deleteWebhook(string $webhookId): void
    {
        $this->request('DELETE', "/webhooks/{$webhookId}");
    }

    /**
     * Verify a webhook signature.
     *
     * @param string $payload The raw request body
     * @param string $signature The signature from the request header
     * @param string $secret Your webhook secret
     * @return bool True if signature is valid
     */
    public static function verifyWebhookSignature(string $payload, string $signature, string $secret): bool
    {
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }
}
