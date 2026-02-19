<?php

/**
 * Webhook Management Examples
 *
 * This example demonstrates:
 * - Creating webhooks
 * - Listing webhooks
 * - Deleting webhooks
 * - Verifying webhook signatures
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BillionVerify\Client;
use BillionVerify\Exception\AuthenticationException;
use BillionVerify\Exception\ValidationException;
use BillionVerify\Exception\NotFoundException;
use BillionVerify\Exception\BillionVerifyException;

// Initialize client with your API key
$apiKey = getenv('BILLIONVERIFY_API_KEY') ?: 'your-api-key-here';
$client = new Client($apiKey);

// -----------------------------------------------------------------------------
// Create a Webhook
// -----------------------------------------------------------------------------
echo "=== Create Webhook ===\n";

try {
    // Available events:
    // - 'file.completed' - File verification job completed successfully
    // - 'file.failed' - File verification job failed
    $webhook = $client->createWebhook(
        url: 'https://your-app.com/webhooks/billionverify',
        events: ['file.completed', 'file.failed']
    );

    $webhookId = $webhook['data']['id'];
    $webhookSecret = $webhook['data']['secret'];

    echo "Webhook ID: {$webhookId}\n";
    echo "Webhook URL: {$webhook['data']['url']}\n";
    echo "Events: " . implode(', ', $webhook['data']['events']) . "\n";
    echo "Secret: {$webhookSecret}\n";
    echo "\n";
    echo "IMPORTANT: Store the secret securely! It's only shown once.\n";
    echo "\n";

} catch (ValidationException $e) {
    echo "Validation error: {$e->getMessage()}\n";
    echo "Details: {$e->getDetails()}\n";
} catch (AuthenticationException $e) {
    echo "Authentication failed: Invalid API key\n";
} catch (BillionVerifyException $e) {
    echo "Error [{$e->getErrorCode()}]: {$e->getMessage()}\n";
}

// -----------------------------------------------------------------------------
// List Webhooks
// -----------------------------------------------------------------------------
echo "=== List Webhooks ===\n";

try {
    $webhooks = $client->listWebhooks();

    if (empty($webhooks['data'])) {
        echo "No webhooks configured.\n";
    } else {
        echo "Total webhooks: " . count($webhooks['data']) . "\n\n";

        foreach ($webhooks['data'] as $wh) {
            echo "ID: {$wh['id']}\n";
            echo "URL: {$wh['url']}\n";
            echo "Events: " . implode(', ', $wh['events']) . "\n";
            echo "Created: {$wh['created_at']}\n";
            echo "---\n";
        }
    }
    echo "\n";

} catch (AuthenticationException $e) {
    echo "Authentication failed: Invalid API key\n";
} catch (BillionVerifyException $e) {
    echo "Error [{$e->getErrorCode()}]: {$e->getMessage()}\n";
}

// -----------------------------------------------------------------------------
// Delete a Webhook
// -----------------------------------------------------------------------------
echo "=== Delete Webhook ===\n";

try {
    // Delete the webhook we created earlier
    $client->deleteWebhook($webhookId);
    echo "Webhook {$webhookId} deleted successfully.\n";
    echo "\n";

} catch (NotFoundException $e) {
    echo "Webhook not found: {$e->getMessage()}\n";
} catch (AuthenticationException $e) {
    echo "Authentication failed: Invalid API key\n";
} catch (BillionVerifyException $e) {
    echo "Error [{$e->getErrorCode()}]: {$e->getMessage()}\n";
}

// -----------------------------------------------------------------------------
// Verify Webhook Signature
// -----------------------------------------------------------------------------
echo "=== Verify Webhook Signature ===\n";

/**
 * Example webhook handler for your application.
 *
 * When BillionVerify sends a webhook, it includes a signature in the
 * 'X-BillionVerify-Signature' header. You should verify this signature
 * to ensure the request is authentic.
 */

// In your webhook endpoint, you would do something like this:

// Get the raw request body (do NOT parse it before verification)
// $rawBody = file_get_contents('php://input');

// Get the signature from the request header
// $signature = $_SERVER['HTTP_X_BILLIONVERIFY_SIGNATURE'] ?? '';

// Your webhook secret (stored securely when you created the webhook)
// $webhookSecret = getenv('BILLIONVERIFY_WEBHOOK_SECRET');

// Example webhook payload and signature for demonstration
$examplePayload = json_encode([
    'event' => 'file.completed',
    'data' => [
        'task_id' => '7143874e-21c5-43c1-96f3-2b52ea41ae6a',
        'status' => 'completed',
        'total_emails' => 1000,
        'valid_emails' => 850,
        'invalid_emails' => 100,
        'credits_used' => 900,
    ],
    'timestamp' => time(),
]);

// Generate a signature for demonstration (in real use, this comes from the header)
$exampleSecret = 'your-webhook-secret';
$exampleSignature = 'sha256=' . hash_hmac('sha256', $examplePayload, $exampleSecret);

// Verify the signature using the static method
$isValid = Client::verifyWebhookSignature(
    payload: $examplePayload,
    signature: $exampleSignature,
    secret: $exampleSecret
);

if ($isValid) {
    echo "Signature is valid - webhook is authentic.\n";

    // Parse the payload and handle the event
    $data = json_decode($examplePayload, true);
    $event = $data['event'];

    switch ($event) {
        case 'file.completed':
            echo "File verification completed!\n";
            echo "Task ID: {$data['data']['task_id']}\n";
            echo "Valid emails: {$data['data']['valid_emails']}\n";
            // Download results, notify users, update database, etc.
            break;

        case 'file.failed':
            echo "File verification failed!\n";
            echo "Task ID: {$data['data']['task_id']}\n";
            // Handle error, notify administrators, etc.
            break;

        default:
            echo "Unknown event: {$event}\n";
    }
} else {
    echo "Invalid signature - request may be forged!\n";
    // Reject the request (return 401 or 403)
}

echo "\n";

// -----------------------------------------------------------------------------
// Complete Webhook Handler Example
// -----------------------------------------------------------------------------
echo "=== Complete Webhook Handler Example ===\n";

echo <<<'PHP'
<?php
// webhooks/billionverify.php

require __DIR__ . '/../vendor/autoload.php';

use BillionVerify\Client;

// Get the raw request body
$rawBody = file_get_contents('php://input');

// Get the signature from the header
$signature = $_SERVER['HTTP_X_BILLIONVERIFY_SIGNATURE'] ?? '';

// Your webhook secret (stored securely)
$webhookSecret = getenv('BILLIONVERIFY_WEBHOOK_SECRET');

// Verify the signature
if (!Client::verifyWebhookSignature($rawBody, $signature, $webhookSecret)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Parse the payload
$data = json_decode($rawBody, true);

// Handle the event
switch ($data['event']) {
    case 'file.completed':
        // Job completed successfully
        $taskId = $data['data']['task_id'];
        $validEmails = $data['data']['valid_emails'];

        // Your business logic here:
        // - Update database
        // - Send notification
        // - Download and process results
        break;

    case 'file.failed':
        // Job failed
        $taskId = $data['data']['task_id'];
        $error = $data['data']['error'] ?? 'Unknown error';

        // Your error handling here:
        // - Log the error
        // - Notify administrators
        // - Retry if appropriate
        break;
}

// Respond with 200 OK to acknowledge receipt
http_response_code(200);
echo json_encode(['status' => 'received']);
PHP;

echo "\n";
