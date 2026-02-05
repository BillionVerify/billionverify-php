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
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/') . '/';
        $this->timeout = $timeout;
        $this->retries = $retries;

        $this->httpClient = new HttpClient([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => [
                'EV-API-KEY' => $this->apiKey,
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

            case 402:
                throw new InsufficientCreditsException($message);

            case 403:
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
     * Make a multipart/form-data request for file uploads.
     *
     * @throws EmailVerifyException
     */
    private function multipartRequest(string $path, array $multipart, int $attempt = 1): array
    {
        try {
            $response = $this->httpClient->request('POST', $path, [
                'multipart' => $multipart,
                'headers' => [
                    'EV-API-KEY' => $this->apiKey,
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);

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

            return $this->handleErrorResponse($statusCode, $message, $code, $details, 'POST', $path, null, $attempt, $response);
        }
    }

    /**
     * Verify a single email address.
     *
     * @param string $email The email address to verify
     * @param bool $checkSmtp Whether to perform SMTP verification
     * @return array Verification result
     * @throws EmailVerifyException
     */
    public function verify(string $email, bool $checkSmtp = true): array
    {
        $payload = [
            'email' => $email,
            'check_smtp' => $checkSmtp,
        ];

        return $this->request('POST', 'verify/single', $payload);
    }

    /**
     * Verify multiple email addresses in a single synchronous request.
     *
     * @param array $emails Array of email addresses (max 50)
     * @param bool $checkSmtp Whether to perform SMTP verification
     * @return array Batch verification results
     * @throws EmailVerifyException
     */
    public function verifyBatch(array $emails, bool $checkSmtp = true): array
    {
        if (count($emails) > 50) {
            throw new ValidationException('Maximum 50 emails per batch request. For larger lists, use uploadFile().');
        }

        $payload = [
            'emails' => $emails,
            'check_smtp' => $checkSmtp,
        ];

        return $this->request('POST', 'verify/bulk', $payload);
    }

    /**
     * Upload a file for asynchronous batch verification.
     *
     * Supported formats: CSV (.csv), Excel (.xlsx, .xls), Text (.txt)
     * Limits: Max 20MB file size, 100,000 emails per file.
     *
     * @param string $filePath Path to the file containing email addresses
     * @param bool $checkSmtp Whether to perform SMTP verification
     * @param string|null $emailColumn Column name containing email addresses (auto-detected if null)
     * @param bool $preserveOriginal Keep original columns in result file
     * @return array Upload response with task_id and status
     * @throws EmailVerifyException
     */
    public function uploadFile(
        string $filePath,
        bool $checkSmtp = true,
        ?string $emailColumn = null,
        bool $preserveOriginal = true
    ): array {
        if (!file_exists($filePath)) {
            throw new ValidationException("File not found: {$filePath}");
        }

        $multipart = [
            [
                'name' => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => basename($filePath),
            ],
            [
                'name' => 'check_smtp',
                'contents' => $checkSmtp ? 'true' : 'false',
            ],
            [
                'name' => 'preserve_original',
                'contents' => $preserveOriginal ? 'true' : 'false',
            ],
        ];

        if ($emailColumn !== null) {
            $multipart[] = [
                'name' => 'email_column',
                'contents' => $emailColumn,
            ];
        }

        return $this->multipartRequest('verify/file', $multipart);
    }

    /**
     * Get the status of a file verification job.
     *
     * @param string $jobId The job ID returned from file upload
     * @param int $timeout Long-polling timeout in seconds (0-300). If set, waits until job completes or timeout.
     * @return array Job status
     * @throws EmailVerifyException
     */
    public function getFileJobStatus(string $jobId, int $timeout = 0): array
    {
        $path = "verify/file/{$jobId}";
        if ($timeout > 0) {
            $timeout = min($timeout, 300);
            $path .= "?timeout={$timeout}";
        }

        return $this->request('GET', $path);
    }

    /**
     * Download verification results for a completed file job.
     *
     * Without filters: Returns redirect to the full result file.
     * With filters: Returns CSV containing only matching emails.
     * Multiple filters can be combined (OR logic).
     *
     * @param string $jobId The job ID
     * @param bool|null $valid Include valid emails
     * @param bool|null $invalid Include invalid emails
     * @param bool|null $catchall Include catch-all emails
     * @param bool|null $role Include role emails
     * @param bool|null $unknown Include unknown emails
     * @param bool|null $disposable Include disposable emails
     * @param bool|null $risky Include risky emails
     * @return string CSV content or redirect URL
     * @throws EmailVerifyException
     */
    public function getFileJobResults(
        string $jobId,
        ?bool $valid = null,
        ?bool $invalid = null,
        ?bool $catchall = null,
        ?bool $role = null,
        ?bool $unknown = null,
        ?bool $disposable = null,
        ?bool $risky = null
    ): string {
        $filters = [];
        if ($valid === true) $filters['valid'] = 'true';
        if ($invalid === true) $filters['invalid'] = 'true';
        if ($catchall === true) $filters['catchall'] = 'true';
        if ($role === true) $filters['role'] = 'true';
        if ($unknown === true) $filters['unknown'] = 'true';
        if ($disposable === true) $filters['disposable'] = 'true';
        if ($risky === true) $filters['risky'] = 'true';

        $path = "verify/file/{$jobId}/results";
        if (!empty($filters)) {
            $path .= '?' . http_build_query($filters);
        }

        try {
            $response = $this->httpClient->request('GET', $path, [
                'allow_redirects' => false,
            ]);

            $statusCode = $response->getStatusCode();

            // Handle redirect (307)
            if ($statusCode === 307) {
                $location = $response->getHeaderLine('Location');
                if ($location) {
                    // Follow redirect to get the file
                    $redirectResponse = $this->httpClient->request('GET', $location);
                    return $redirectResponse->getBody()->getContents();
                }
            }

            return $response->getBody()->getContents();
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

            match ($statusCode) {
                401 => throw new AuthenticationException($message),
                404 => throw new NotFoundException($message),
                default => throw new EmailVerifyException($message, $error['code'] ?? 'UNKNOWN_ERROR', $statusCode),
            };
        }
    }

    /**
     * Wait for file job completion.
     *
     * @param string $jobId The job ID returned from file upload
     * @param int $pollInterval Time between polls in seconds (default: 5)
     * @param int $maxWait Maximum wait time in seconds (default: 600)
     * @return array Final job status
     * @throws EmailVerifyException
     */
    public function waitForFileJobCompletion(string $jobId, int $pollInterval = 5, int $maxWait = 600): array
    {
        $startTime = time();

        while (time() - $startTime < $maxWait) {
            $response = $this->getFileJobStatus($jobId);
            $status = $response['data']['status'] ?? $response['status'] ?? null;

            if (in_array($status, ['completed', 'failed'], true)) {
                return $response;
            }

            sleep($pollInterval);
        }

        throw new TimeoutException("File job {$jobId} did not complete within {$maxWait} seconds");
    }

    /**
     * Get current credit balance.
     *
     * @return array Credits information
     * @throws EmailVerifyException
     */
    public function getCredits(): array
    {
        return $this->request('GET', 'credits');
    }

    /**
     * Create a new webhook.
     *
     * The webhook secret is returned in the response. Store it securely for signature verification.
     *
     * @param string $url The HTTPS webhook URL
     * @param array $events List of events to subscribe to ('file.completed', 'file.failed')
     * @return array Webhook configuration including the secret
     * @throws EmailVerifyException
     */
    public function createWebhook(string $url, array $events): array
    {
        $payload = [
            'url' => $url,
            'events' => $events,
        ];

        return $this->request('POST', 'webhooks', $payload);
    }

    /**
     * List all webhooks.
     *
     * @return array List of webhooks
     * @throws EmailVerifyException
     */
    public function listWebhooks(): array
    {
        return $this->request('GET', 'webhooks');
    }

    /**
     * Delete a webhook.
     *
     * @param string $webhookId The webhook ID to delete
     * @throws EmailVerifyException
     */
    public function deleteWebhook(string $webhookId): void
    {
        $this->request('DELETE', "webhooks/{$webhookId}");
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

    /**
     * Health check endpoint. No authentication required.
     *
     * @param string|null $baseUrl Optional base URL override (defaults to the client's base URL)
     * @return array Health status with 'status' and 'time' fields
     */
    public static function healthCheck(?string $baseUrl = null): array
    {
        $url = rtrim($baseUrl ?? 'https://api.emailverify.ai', '/') . '/health';

        $client = new HttpClient([
            'timeout' => 10,
            'headers' => [
                'User-Agent' => self::USER_AGENT,
            ],
        ]);

        try {
            $response = $client->request('GET', $url);
            $contents = $response->getBody()->getContents();
            return json_decode($contents, true);
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
