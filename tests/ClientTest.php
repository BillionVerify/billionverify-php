<?php

declare(strict_types=1);

namespace BillionVerify\Tests;

use BillionVerify\Client;
use BillionVerify\Exception\AuthenticationException;
use BillionVerify\Exception\InsufficientCreditsException;
use BillionVerify\Exception\NotFoundException;
use BillionVerify\Exception\RateLimitException;
use BillionVerify\Exception\ValidationException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ClientTest extends TestCase
{
    private function createClientWithMockHandler(array $responses): Client
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);
        $httpClient = new HttpClient(['handler' => $handlerStack]);

        $client = new Client('test-api-key');

        // Use reflection to replace the HTTP client
        $reflection = new ReflectionClass($client);
        $property = $reflection->getProperty('httpClient');
        $property->setAccessible(true);
        $property->setValue($client, $httpClient);

        return $client;
    }

    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(AuthenticationException::class);
        new Client('');
    }

    public function testConstructorWithDefaultOptions(): void
    {
        $client = new Client('test-key');
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testConstructorWithCustomOptions(): void
    {
        $client = new Client(
            'test-key',
            'https://custom.api.com/v1',
            60,
            5
        );
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testVerifySuccess(): void
    {
        $responseData = [
            'email' => 'test@example.com',
            'status' => 'valid',
            'result' => [
                'deliverable' => true,
                'valid_format' => true,
                'valid_domain' => true,
                'valid_mx' => true,
                'disposable' => false,
                'role' => false,
                'catchall' => false,
                'free' => false,
                'smtp_valid' => true,
            ],
            'score' => 0.95,
            'reason' => null,
            'credits_used' => 1,
        ];

        $client = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $client->verify('test@example.com');

        $this->assertEquals('test@example.com', $result['email']);
        $this->assertEquals('valid', $result['status']);
        $this->assertEquals(0.95, $result['score']);
        $this->assertTrue($result['result']['deliverable']);
    }

    public function testVerifyWithOptions(): void
    {
        $responseData = [
            'email' => 'test@example.com',
            'status' => 'valid',
            'result' => [],
            'score' => 0.95,
            'credits_used' => 1,
        ];

        $client = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $client->verify('test@example.com', false);

        $this->assertIsArray($result);
    }

    public function testVerifyAuthenticationError(): void
    {
        $client = $this->createClientWithMockHandler([
            new Response(401, [], json_encode([
                'error' => [
                    'code' => 'INVALID_API_KEY',
                    'message' => 'Invalid API key',
                ],
            ])),
        ]);

        $this->expectException(AuthenticationException::class);
        $client->verify('test@example.com');
    }

    public function testVerifyValidationError(): void
    {
        $client = $this->createClientWithMockHandler([
            new Response(400, [], json_encode([
                'error' => [
                    'code' => 'INVALID_EMAIL',
                    'message' => 'Invalid email format',
                ],
            ])),
        ]);

        $this->expectException(ValidationException::class);
        $client->verify('invalid');
    }

    public function testVerifyInsufficientCredits(): void
    {
        $client = $this->createClientWithMockHandler([
            new Response(402, [], json_encode([
                'error' => [
                    'code' => 'INSUFFICIENT_CREDITS',
                    'message' => 'Not enough credits',
                ],
            ])),
        ]);

        $this->expectException(InsufficientCreditsException::class);
        $client->verify('test@example.com');
    }

    public function testVerifyNotFound(): void
    {
        $client = $this->createClientWithMockHandler([
            new Response(404, [], json_encode([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Resource not found',
                ],
            ])),
        ]);

        $this->expectException(NotFoundException::class);
        $client->verify('test@example.com');
    }

    public function testVerifyBatchSuccess(): void
    {
        $responseData = [
            'results' => [
                ['email' => 'user1@example.com', 'status' => 'valid', 'score' => 0.95, 'is_deliverable' => true, 'credits_used' => 1],
                ['email' => 'user2@example.com', 'status' => 'invalid', 'score' => 0.0, 'is_deliverable' => false, 'credits_used' => 0],
                ['email' => 'user3@example.com', 'status' => 'valid', 'score' => 0.90, 'is_deliverable' => true, 'credits_used' => 1],
            ],
            'total_emails' => 3,
            'valid_emails' => 2,
            'invalid_emails' => 1,
            'credits_used' => 2,
            'process_time' => 1500,
        ];

        $client = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $client->verifyBatch([
            'user1@example.com',
            'user2@example.com',
            'user3@example.com',
        ]);

        $this->assertEquals(3, $result['total_emails']);
        $this->assertEquals(2, $result['valid_emails']);
        $this->assertCount(3, $result['results']);
    }

    public function testVerifyBatchTooManyEmails(): void
    {
        $client = new Client('test-key');
        $emails = array_fill(0, 51, 'test@example.com');

        $this->expectException(ValidationException::class);
        $client->verifyBatch($emails);
    }

    public function testGetFileJobStatus(): void
    {
        $responseData = [
            'job_id' => 'job_123',
            'status' => 'processing',
            'file_name' => 'emails.csv',
            'total_emails' => 100,
            'processed_emails' => 50,
            'valid_emails' => 40,
            'invalid_emails' => 5,
            'unknown_emails' => 5,
            'credits_used' => 50,
            'created_at' => '2025-01-15T10:30:00Z',
            'progress_percent' => 50,
        ];

        $client = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $client->getFileJobStatus('job_123');

        $this->assertEquals('job_123', $result['job_id']);
        $this->assertEquals(50, $result['progress_percent']);
    }

    public function testGetFileJobResults(): void
    {
        // Mock a redirect response (307) followed by CSV content
        $csvContent = "email,status,score\ntest@example.com,valid,0.95";

        $client = $this->createClientWithMockHandler([
            new Response(200, ['Content-Type' => 'text/csv'], $csvContent),
        ]);

        $result = $client->getFileJobResults('job_123', true);

        $this->assertStringContainsString('test@example.com', $result);
        $this->assertStringContainsString('valid', $result);
    }

    public function testGetCredits(): void
    {
        $responseData = [
            'available' => 9500,
            'used' => 500,
            'total' => 10000,
            'plan' => 'Professional',
            'resets_at' => '2025-02-01T00:00:00Z',
            'rate_limit' => [
                'requests_per_hour' => 10000,
                'remaining' => 9850,
            ],
        ];

        $client = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $client->getCredits();

        $this->assertEquals(9500, $result['available']);
        $this->assertEquals('Professional', $result['plan']);
        $this->assertEquals(9850, $result['rate_limit']['remaining']);
    }

    public function testCreateWebhook(): void
    {
        $responseData = [
            'id' => 'webhook_123',
            'url' => 'https://example.com/webhook',
            'events' => ['file.completed'],
            'secret' => 'generated-secret',
            'is_active' => true,
            'created_at' => '2025-01-15T10:30:00Z',
            'updated_at' => '2025-01-15T10:30:00Z',
        ];

        $client = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $client->createWebhook(
            'https://example.com/webhook',
            ['file.completed']
        );

        $this->assertEquals('webhook_123', $result['id']);
        $this->assertEquals('https://example.com/webhook', $result['url']);
        $this->assertEquals('generated-secret', $result['secret']);
    }

    public function testListWebhooks(): void
    {
        $responseData = [
            [
                'id' => 'webhook_123',
                'url' => 'https://example.com/webhook',
                'events' => ['file.completed'],
                'is_active' => true,
                'created_at' => '2025-01-15T10:30:00Z',
                'updated_at' => '2025-01-15T10:30:00Z',
            ],
        ];

        $client = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $client->listWebhooks();

        $this->assertCount(1, $result);
        $this->assertEquals('webhook_123', $result[0]['id']);
    }

    public function testDeleteWebhook(): void
    {
        $client = $this->createClientWithMockHandler([
            new Response(204),
        ]);

        // Should not throw
        $client->deleteWebhook('webhook_123');
        $this->assertTrue(true);
    }

    public function testVerifyWebhookSignatureValid(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'test-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $result = Client::verifyWebhookSignature($payload, $signature, $secret);

        $this->assertTrue($result);
    }

    public function testVerifyWebhookSignatureInvalid(): void
    {
        $payload = '{"event":"test"}';
        $secret = 'test-secret';
        $signature = 'sha256=invalid';

        $result = Client::verifyWebhookSignature($payload, $signature, $secret);

        $this->assertFalse($result);
    }
}
