# EmailVerify PHP SDK

Official EmailVerify PHP SDK for email verification.

**Documentation:** https://emailverify.ai/docs

## Requirements

- PHP 8.1 or higher
- Composer

## Installation

```bash
composer require emailverify/php-sdk
```

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use EmailVerify\Client;

$client = new Client('your-api-key');

// Verify a single email
$result = $client->verify('user@example.com');
echo $result['status']; // 'valid', 'invalid', 'unknown', or 'accept_all'
```

## Configuration

```php
$client = new Client(
    apiKey: 'your-api-key',        // Required
    baseUrl: 'https://api.emailverify.ai/v1',  // Optional
    timeout: 30,                    // Optional: Request timeout in seconds (default: 30)
    retries: 3                      // Optional: Number of retries (default: 3)
);
```

## Single Email Verification

```php
$result = $client->verify(
    email: 'user@example.com',
    smtpCheck: true,  // Optional: Perform SMTP verification (default: true)
    timeout: 5000     // Optional: Verification timeout in ms
);

echo $result['email'];                    // 'user@example.com'
echo $result['status'];                   // 'valid'
echo $result['score'];                    // 0.95
echo $result['result']['deliverable'];    // true
echo $result['result']['disposable'];     // false
```

## Bulk Email Verification

```php
// Submit a bulk verification job
$job = $client->verifyBulk(
    emails: ['user1@example.com', 'user2@example.com', 'user3@example.com'],
    smtpCheck: true,
    webhookUrl: 'https://your-app.com/webhooks/emailverify'  // Optional
);

echo $job['job_id'];  // 'job_abc123xyz'

// Check job status
$status = $client->getBulkJobStatus($job['job_id']);
echo $status['progress_percent'];  // 45

// Wait for completion (polling)
$completed = $client->waitForBulkJobCompletion(
    jobId: $job['job_id'],
    pollInterval: 5,  // seconds
    maxWait: 600      // seconds
);

// Get results
$results = $client->getBulkJobResults(
    jobId: $job['job_id'],
    limit: 100,
    offset: 0,
    status: 'valid'  // Optional: filter by status
);

foreach ($results['results'] as $item) {
    echo "{$item['email']}: {$item['status']}\n";
}
```

## Credits

```php
$credits = $client->getCredits();

echo $credits['available'];                  // 9500
echo $credits['plan'];                       // 'Professional'
echo $credits['rate_limit']['remaining'];    // 9850
```

## Webhooks

```php
// Create a webhook
$webhook = $client->createWebhook(
    url: 'https://your-app.com/webhooks/emailverify',
    events: ['verification.completed', 'bulk.completed'],
    secret: 'your-webhook-secret'
);

// List webhooks
$webhooks = $client->listWebhooks();

// Delete a webhook
$client->deleteWebhook($webhook['id']);

// Verify webhook signature
$isValid = Client::verifyWebhookSignature(
    payload: $rawBody,
    signature: $signatureHeader,
    secret: 'your-webhook-secret'
);
```

## Error Handling

```php
use EmailVerify\Client;
use EmailVerify\Exception\AuthenticationException;
use EmailVerify\Exception\RateLimitException;
use EmailVerify\Exception\ValidationException;
use EmailVerify\Exception\InsufficientCreditsException;
use EmailVerify\Exception\NotFoundException;
use EmailVerify\Exception\TimeoutException;
use EmailVerify\Exception\EmailVerifyException;

try {
    $result = $client->verify('user@example.com');
} catch (AuthenticationException $e) {
    echo "Invalid API key\n";
} catch (RateLimitException $e) {
    echo "Rate limited. Retry after {$e->getRetryAfter()} seconds\n";
} catch (ValidationException $e) {
    echo "Invalid input: {$e->getMessage()}\n";
    echo "Details: {$e->getDetails()}\n";
} catch (InsufficientCreditsException $e) {
    echo "Not enough credits\n";
} catch (NotFoundException $e) {
    echo "Resource not found\n";
} catch (TimeoutException $e) {
    echo "Request timed out\n";
} catch (EmailVerifyException $e) {
    echo "Error [{$e->getErrorCode()}]: {$e->getMessage()}\n";
}
```

## Webhook Events

Available webhook events:
- `verification.completed` - Single email verification completed
- `bulk.completed` - Bulk job finished
- `bulk.failed` - Bulk job failed
- `credits.low` - Credits below threshold

## Verification Status Values

- `valid` - Email exists and can receive messages
- `invalid` - Email doesn't exist or can't receive messages
- `unknown` - Could not determine validity with certainty
- `accept_all` - Domain accepts all emails (catch-all)

## License

MIT
