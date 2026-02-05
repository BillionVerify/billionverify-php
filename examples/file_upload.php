<?php

/**
 * File Upload Verification Examples
 *
 * This example demonstrates:
 * - Uploading a file for async verification
 * - Getting job status with long-polling
 * - Waiting for job completion
 * - Downloading results with filters
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use EmailVerify\Client;
use EmailVerify\Exception\AuthenticationException;
use EmailVerify\Exception\ValidationException;
use EmailVerify\Exception\NotFoundException;
use EmailVerify\Exception\TimeoutException;
use EmailVerify\Exception\EmailVerifyException;

// Initialize client with your API key
$apiKey = getenv('EMAILVERIFY_API_KEY') ?: 'your-api-key-here';
$client = new Client($apiKey);

// -----------------------------------------------------------------------------
// Upload a File for Verification
// -----------------------------------------------------------------------------
echo "=== File Upload ===\n";

try {
    // Supported formats: CSV (.csv), Excel (.xlsx, .xls), Text (.txt)
    // Limits: Max 20MB file size, 100,000 emails per file
    $filePath = '/path/to/emails.csv';

    $job = $client->uploadFile(
        filePath: $filePath,
        checkSmtp: true,           // Perform SMTP verification (default: true)
        emailColumn: 'email',      // Column name containing emails (auto-detected if null)
        preserveOriginal: true     // Keep original columns in result file (default: true)
    );

    $jobId = $job['data']['task_id'];
    echo "Job ID: {$jobId}\n";
    echo "Status: {$job['data']['status']}\n";
    echo "Total Emails: {$job['data']['total_emails']}\n";
    echo "\n";

} catch (ValidationException $e) {
    echo "Validation error: {$e->getMessage()}\n";
    exit(1);
} catch (AuthenticationException $e) {
    echo "Authentication failed: Invalid API key\n";
    exit(1);
} catch (EmailVerifyException $e) {
    echo "Error [{$e->getErrorCode()}]: {$e->getMessage()}\n";
    exit(1);
}

// -----------------------------------------------------------------------------
// Get Job Status (with Long-Polling)
// -----------------------------------------------------------------------------
echo "=== Job Status ===\n";

try {
    // Without timeout: Returns immediately with current status
    $status = $client->getFileJobStatus($jobId);
    echo "Current Status: {$status['data']['status']}\n";
    echo "Progress: {$status['data']['progress_percent']}%\n";
    echo "\n";

    // With timeout (long-polling): Waits up to N seconds for completion
    // Useful to avoid frequent polling - server holds connection until job completes or timeout
    echo "Waiting for completion (up to 60 seconds)...\n";
    $status = $client->getFileJobStatus($jobId, timeout: 60);
    echo "Status after wait: {$status['data']['status']}\n";
    echo "\n";

} catch (NotFoundException $e) {
    echo "Job not found: {$e->getMessage()}\n";
} catch (EmailVerifyException $e) {
    echo "Error [{$e->getErrorCode()}]: {$e->getMessage()}\n";
}

// -----------------------------------------------------------------------------
// Wait for Job Completion (Polling)
// -----------------------------------------------------------------------------
echo "=== Wait for Completion ===\n";

try {
    // This method polls until the job is completed or failed
    // Useful for jobs that may take longer than the long-polling timeout
    $completed = $client->waitForFileJobCompletion(
        jobId: $jobId,
        pollInterval: 5,   // Check every 5 seconds
        maxWait: 600       // Maximum wait time: 10 minutes
    );

    echo "Final Status: {$completed['data']['status']}\n";

    if ($completed['data']['status'] === 'completed') {
        echo "Valid Emails: {$completed['data']['valid_emails']}\n";
        echo "Invalid Emails: {$completed['data']['invalid_emails']}\n";
        echo "Credits Used: {$completed['data']['credits_used']}\n";
    } elseif ($completed['data']['status'] === 'failed') {
        echo "Job failed: {$completed['data']['error']}\n";
    }
    echo "\n";

} catch (TimeoutException $e) {
    echo "Job did not complete in time: {$e->getMessage()}\n";
} catch (EmailVerifyException $e) {
    echo "Error [{$e->getErrorCode()}]: {$e->getMessage()}\n";
}

// -----------------------------------------------------------------------------
// Download Results with Filters
// -----------------------------------------------------------------------------
echo "=== Download Results ===\n";

try {
    // Download all results (no filters)
    echo "Downloading all results...\n";
    $allResults = $client->getFileJobResults($jobId);
    file_put_contents('/path/to/all_results.csv', $allResults);
    echo "Saved all results to all_results.csv\n\n";

    // Download only valid emails
    echo "Downloading valid emails only...\n";
    $validOnly = $client->getFileJobResults(
        jobId: $jobId,
        valid: true
    );
    file_put_contents('/path/to/valid_emails.csv', $validOnly);
    echo "Saved valid emails to valid_emails.csv\n\n";

    // Download invalid emails
    echo "Downloading invalid emails...\n";
    $invalidOnly = $client->getFileJobResults(
        jobId: $jobId,
        invalid: true
    );
    file_put_contents('/path/to/invalid_emails.csv', $invalidOnly);
    echo "Saved invalid emails to invalid_emails.csv\n\n";

    // Download catch-all emails
    echo "Downloading catch-all emails...\n";
    $catchallOnly = $client->getFileJobResults(
        jobId: $jobId,
        catchall: true
    );
    file_put_contents('/path/to/catchall_emails.csv', $catchallOnly);
    echo "Saved catch-all emails to catchall_emails.csv\n\n";

    // Download multiple categories combined (OR logic)
    // This returns emails that are valid OR catch-all
    echo "Downloading valid and catch-all emails...\n";
    $safeEmails = $client->getFileJobResults(
        jobId: $jobId,
        valid: true,
        catchall: true
    );
    file_put_contents('/path/to/safe_emails.csv', $safeEmails);
    echo "Saved safe emails to safe_emails.csv\n\n";

    // Download risky, disposable, and role emails
    echo "Downloading risky emails...\n";
    $riskyEmails = $client->getFileJobResults(
        jobId: $jobId,
        risky: true,
        disposable: true,
        role: true
    );
    file_put_contents('/path/to/risky_emails.csv', $riskyEmails);
    echo "Saved risky emails to risky_emails.csv\n\n";

    // Download unknown emails (could not determine validity)
    echo "Downloading unknown emails...\n";
    $unknownEmails = $client->getFileJobResults(
        jobId: $jobId,
        unknown: true
    );
    file_put_contents('/path/to/unknown_emails.csv', $unknownEmails);
    echo "Saved unknown emails to unknown_emails.csv\n";

} catch (NotFoundException $e) {
    echo "Job not found or results not available: {$e->getMessage()}\n";
} catch (EmailVerifyException $e) {
    echo "Error [{$e->getErrorCode()}]: {$e->getMessage()}\n";
}
