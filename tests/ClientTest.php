<?php

declare(strict_types=1);

namespace EmailVerify\Tests;

use EmailVerify\Client;
use EmailVerify\Exception\AuthenticationException;
use EmailVerify\Exception\InsufficientCreditsException;
use EmailVerify\Exception\NotFoundException;
use EmailVerify\Exception\RateLimitException;
use EmailVerify\Exception\ValidationException;
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

        $result = $client->verify('test@example.com', false, 5000);

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
            new Response(403, [], json_encode([
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

    public function testVerifyBulkSuccess(): void
    {
        $responseData = [
            'job_id' => 'job_123',
            'status' => 'processing',
            'total' => 3,
            'processed' => 0,
            'valid' => 0,
            'invalid' => 0,
            'unknown' => 0,
            'credits_used' => 3,
            'created_at' => '2025-01-15T10:30:00Z',
        ];

        $client = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $client->verifyBulk([
            'user1@example.com',
            'user2@example.com',
            'user3@example.com',
        ]);

        $this->assertEquals('job_123', $result['job_id']);
        $this->assertEquals('processing', $result['status']);
        $this->assertEquals(3, $result['total']);
    }

    public function testVerifyBulkTooManyEmails(): void
    {
        $client = new Client('test-key');
        $emails = array_fill(0, 10001, 'test@example.com');

        $this->expectException(ValidationException::class);
        $client->verifyBulk($emails);
    }

    public function testGetBulkJobStatus(): void
    {
        $responseData = [
            'job_id' => 'job_123',
            'status' => 'processing',
            'total' => 100,
            'processed' => 50,
            'valid' => 40,
            'invalid' => 5,
            'unknown' => 5,
            'credits_used' => 100,
            'created_at' => '2025-01-15T10:30:00Z',
            'progress_percent' => 50,
        ];

        $client = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $client->getBulkJobStatus('job_123');

        $this->assertEquals('job_123', $result['job_id']);
        $this->assertEquals(50, $result['progress_percent']);
    }

    public function testGetBulkJobResults(): void
    {
        $responseData = [
            'job_id' => 'job_123',
            'total' => 100,
            'limit' => 50,
            'offset' => 0,
            'results' => [
                [
                    'email' => 'test@example.com',
                    'status' => 'valid',
                    'result' => ['deliverable' => true],
                    'score' => 0.95,
                ],
            ],
        ];

        $client = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $client->getBulkJobResults('job_123', 50, 0, 'valid');

        $this->assertEquals('job_123', $result['job_id']);
        $this->assertCount(1, $result['results']);
        $this->assertEquals('test@example.com', $result['results'][0]['email']);
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
            'events' => ['verification.completed'],
            'created_at' => '2025-01-15T10:30:00Z',
        ];

        $client = $this->createClientWithMockHandler([
            new Response(200, [], json_encode($responseData)),
        ]);

        $result = $client->createWebhook(
            'https://example.com/webhook',
            ['verification.completed'],
            'secret'
        );

        $this->assertEquals('webhook_123', $result['id']);
        $this->assertEquals('https://example.com/webhook', $result['url']);
    }

    public function testListWebhooks(): void
    {
        $responseData = [
            [
                'id' => 'webhook_123',
                'url' => 'https://example.com/webhook',
                'events' => ['verification.completed'],
                'created_at' => '2025-01-15T10:30:00Z',
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
