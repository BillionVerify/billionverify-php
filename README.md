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
echo $result['data']['status']; // 'valid', 'invalid', 'unknown', 'catchall', 'risky', 'disposable', 'role'
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
    checkSmtp: true  // Optional: Perform SMTP verification (default: true)
);

echo $result['data']['email'];           // 'user@example.com'
echo $result['data']['status'];          // 'valid'
echo $result['data']['score'];           // 0.95
echo $result['data']['is_deliverable'];  // true
echo $result['data']['is_disposable'];   // false
```

## Batch Email Verification

Verify up to 50 emails in a single synchronous request.

```php
$results = $client->verifyBatch(
    emails: ['user1@example.com', 'user2@example.com', 'user3@example.com'],
    checkSmtp: true  // Optional
);

echo $results['data']['total_emails'];  // 3
echo $results['data']['valid_emails'];  // 2
echo $results['data']['credits_used'];  // 2

foreach ($results['data']['results'] as $item) {
    echo "{$item['email']}: {$item['status']}\n";
}
```

## File Upload Verification

For larger lists (up to 100,000 emails), upload a file for asynchronous processing.

```php
// Upload a file
$job = $client->uploadFile(
    filePath: '/path/to/emails.csv',
    checkSmtp: true,           // Optional: Perform SMTP verification (default: true)
    emailColumn: 'email',      // Optional: Column name (auto-detected if null)
    preserveOriginal: true     // Optional: Keep original columns in result (default: true)
);

echo $job['data']['task_id'];  // '7143874e-21c5-43c1-96f3-2b52ea41ae6a'

// Check job status (with optional long-polling)
$status = $client->getFileJobStatus(
    jobId: $job['data']['task_id'],
    timeout: 60  // Optional: Wait up to 60 seconds for completion (0-300)
);
echo $status['data']['progress_percent'];  // 45

// Wait for completion (polling)
$completed = $client->waitForFileJobCompletion(
    jobId: $job['data']['task_id'],
    pollInterval: 5,  // seconds
    maxWait: 600      // seconds
);

// Download results with optional filters
$results = $client->getFileJobResults(
    jobId: $job['data']['task_id'],
    valid: true,       // Include valid emails
    invalid: false,    // Exclude invalid emails
    catchall: true,    // Include catch-all emails
    role: false,       // Exclude role emails
    unknown: false,    // Exclude unknown emails
    disposable: false, // Exclude disposable emails
    risky: false       // Exclude risky emails
);
```

## Health Check

Check API health (no authentication required).

```php
// Static method - can be called without an API key
$health = Client::healthCheck();
echo $health['status'];  // 'ok'
echo $health['time'];    // Unix timestamp

// With custom base URL
$health = Client::healthCheck('https://api.emailverify.ai');
```

## Credits

```php
$credits = $client->getCredits();

echo $credits['data']['credits_balance'];  // 9500
echo $credits['data']['credits_consumed']; // 500
```

## Webhooks

```php
// Create a webhook (secret is returned in response - store it securely!)
$webhook = $client->createWebhook(
    url: 'https://your-app.com/webhooks/emailverify',
    events: ['file.completed', 'file.failed']
);

echo $webhook['data']['id'];      // Webhook ID
echo $webhook['data']['secret'];  // Store this securely!

// List webhooks
$webhooks = $client->listWebhooks();

// Delete a webhook
$client->deleteWebhook($webhook['data']['id']);

// Verify webhook signature
$isValid = Client::verifyWebhookSignature(
    payload: $rawBody,
    signature: $signatureHeader,
    secret: 'your-stored-webhook-secret'
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
    echo "Not enough credits (HTTP 402)\n";
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
- `file.completed` - File verification job completed successfully
- `file.failed` - File verification job failed

## Verification Status Values

- `valid` - Email exists and can receive messages
- `invalid` - Email doesn't exist or can't receive messages
- `unknown` - Could not determine validity with certainty
- `catchall` - Domain accepts all emails (catch-all server)
- `risky` - Email is deliverable but may have issues
- `disposable` - Temporary/disposable email address
- `role` - Role-based email (info@, support@, etc.)

## Credits Usage

- `invalid` emails: 0 credits (not charged)
- `unknown` status: 0 credits (not charged)
- All other statuses (`valid`, `risky`, `disposable`, `catchall`, `role`): 1 credit each

## License

MIT
