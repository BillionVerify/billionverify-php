<?php

/**
 * Basic Email Verification Examples
 *
 * This example demonstrates:
 * - Single email verification
 * - Batch verification (up to 50 emails)
 * - Getting credits balance
 * - Health check
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use EmailVerify\Client;
use EmailVerify\Exception\AuthenticationException;
use EmailVerify\Exception\RateLimitException;
use EmailVerify\Exception\ValidationException;
use EmailVerify\Exception\InsufficientCreditsException;
use EmailVerify\Exception\EmailVerifyException;

// Initialize client with your API key
$apiKey = getenv('EMAILVERIFY_API_KEY') ?: 'your-api-key-here';
$client = new Client($apiKey);

// -----------------------------------------------------------------------------
// Health Check (no authentication required)
// -----------------------------------------------------------------------------
echo "=== Health Check ===\n";

$health = Client::healthCheck();
echo "Status: {$health['status']}\n";
if (isset($health['time'])) {
    echo "Server Time: {$health['time']}\n";
}
echo "\n";

// -----------------------------------------------------------------------------
// Single Email Verification
// -----------------------------------------------------------------------------
echo "=== Single Email Verification ===\n";

try {
    // Basic verification with SMTP check (default)
    $result = $client->verify('user@example.com');

    echo "Email: {$result['data']['email']}\n";
    echo "Status: {$result['data']['status']}\n";
    echo "Score: {$result['data']['score']}\n";
    echo "Deliverable: " . ($result['data']['is_deliverable'] ? 'Yes' : 'No') . "\n";
    echo "Disposable: " . ($result['data']['is_disposable'] ? 'Yes' : 'No') . "\n";
    echo "\n";

    // Verification without SMTP check (faster, but less accurate)
    $quickResult = $client->verify('another@example.com', checkSmtp: false);
    echo "Quick check result: {$quickResult['data']['status']}\n";
    echo "\n";

} catch (AuthenticationException $e) {
    echo "Authentication failed: Invalid API key\n";
} catch (InsufficientCreditsException $e) {
    echo "Insufficient credits: {$e->getMessage()}\n";
} catch (RateLimitException $e) {
    echo "Rate limited. Retry after {$e->getRetryAfter()} seconds\n";
} catch (EmailVerifyException $e) {
    echo "Error [{$e->getErrorCode()}]: {$e->getMessage()}\n";
}

// -----------------------------------------------------------------------------
// Batch Verification (Synchronous, max 50 emails)
// -----------------------------------------------------------------------------
echo "=== Batch Verification ===\n";

try {
    $emails = [
        'user1@example.com',
        'user2@example.com',
        'user3@example.com',
        'invalid@nonexistent-domain-12345.com',
        'contact@gmail.com',
    ];

    $results = $client->verifyBatch($emails, checkSmtp: true);

    echo "Total Emails: {$results['data']['total_emails']}\n";
    echo "Valid Emails: {$results['data']['valid_emails']}\n";
    echo "Invalid Emails: {$results['data']['invalid_emails']}\n";
    echo "Credits Used: {$results['data']['credits_used']}\n";
    echo "\nResults:\n";

    foreach ($results['data']['results'] as $item) {
        $status = $item['status'];
        $email = $item['email'];
        echo "  - {$email}: {$status}\n";
    }
    echo "\n";

} catch (ValidationException $e) {
    // Thrown if more than 50 emails are provided
    echo "Validation error: {$e->getMessage()}\n";
    echo "Details: {$e->getDetails()}\n";
} catch (EmailVerifyException $e) {
    echo "Error [{$e->getErrorCode()}]: {$e->getMessage()}\n";
}

// -----------------------------------------------------------------------------
// Get Credits Balance
// -----------------------------------------------------------------------------
echo "=== Credits Balance ===\n";

try {
    $credits = $client->getCredits();

    echo "Credits Balance: {$credits['data']['credits_balance']}\n";
    echo "Credits Consumed: {$credits['data']['credits_consumed']}\n";

} catch (AuthenticationException $e) {
    echo "Authentication failed: Invalid API key\n";
} catch (EmailVerifyException $e) {
    echo "Error [{$e->getErrorCode()}]: {$e->getMessage()}\n";
}
