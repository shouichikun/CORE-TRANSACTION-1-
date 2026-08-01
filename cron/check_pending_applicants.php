#!/usr/bin/env php
<?php
// cron/check_pending_applicants.php
// Run daily: 0 9 * * * php /path/to/cron/check_pending_applicants.php

// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define base path
define('BASE_PATH', dirname(__DIR__));

// Create logs directory if it doesn't exist
$logDir = BASE_PATH . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

// Load required files
require_once BASE_PATH . '/app/config.php';
require_once BASE_PATH . '/app/email_functions.php';

// Enable error logging for cron
function cron_log($message) {
    $logFile = BASE_PATH . '/logs/cron.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

cron_log("Starting pending applicants check...");

// Get pending applicants that have been waiting 7+ days
$cutoffDate = date('Y-m-d H:i:s', strtotime('-7 days'));

$sql = "SELECT a.*, 
        u.id as user_id, u.first_name, u.last_name, u.email,
        ap.skills, ap.experience, ap.education,
        jo.title as job_title, jo.id as job_id, c.company_name,
        DATEDIFF(NOW(), a.applied_at) as days_waiting
        FROM applications a
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        JOIN clients c ON jo.client_id = c.id
        WHERE a.applied_at <= ?
        AND a.status = 'pending'
        AND (a.follow_up_sent = 0 OR a.follow_up_sent IS NULL)
        AND (a.last_follow_up_email IS NULL OR DATE(a.last_follow_up_email) < CURDATE())
        ORDER BY a.applied_at ASC";

$pendingApplicants = getRecords($sql, [$cutoffDate], "s");

cron_log("Found " . count($pendingApplicants) . " pending applicants to process.");

$processed = 0;
$successCount = 0;
$errorCount = 0;
$holdCount = 0;

foreach ($pendingApplicants as $applicant) {
    $processed++;
    $daysWaiting = $applicant['days_waiting'] ?? 7;
    
    $logMessage = "Processing: " . $applicant['email'] . " (Waiting: " . $daysWaiting . " days)";
    cron_log($logMessage);
    echo $logMessage . "\n";
    
    // Send "on hold too long" email
    try {
        $emailSent = sendHoldTooLongEmail($applicant, $applicant['company_name'] ?? 'Our Company');
    } catch (Exception $e) {
        cron_log("  ❌ Exception: " . $e->getMessage());
        $emailSent = false;
    }
    
    // Even if email has warnings, we should consider it sent if it was attempted
    // The sendHoldTooLongEmail function handles exceptions internally
    
    // Check if email was sent OR if we should still process the application
    // We process regardless to avoid infinite loops
    if ($emailSent !== false) {
        $successCount++;
        $holdCount++;
        
        $note = 'Auto-rejected after ' . $daysWaiting . ' days waiting';
        
        // Get current notes first
        $currentApp = getRecord("SELECT notes FROM applications WHERE id = ?", [$applicant['id']], "i");
        $currentNotes = $currentApp['notes'] ?? '';
        
        // Build the new notes
        if (!empty($currentNotes)) {
            $newNotes = $currentNotes . "\n" . $note;
        } else {
            $newNotes = $note;
        }
        
        // Update application record
        $updateSql = "UPDATE applications SET 
                      follow_up_sent = 1,
                      follow_up_date = NOW(),
                      qualification_status = 'not_qualified',
                      last_follow_up_email = NOW(),
                      status = 'rejected',
                      notes = ?
                      WHERE id = ?";
        $updateResult = updateRecord($updateSql, [$newNotes, $applicant['id']], "si");
        
        if ($updateResult) {
            cron_log("  ✅ Database updated for: " . $applicant['email']);
        } else {
            cron_log("  ⚠️ Database update failed for: " . $applicant['email']);
        }
        
        // Log activity
        logActivity(
            null, 
            'Auto-Rejected - Hold Too Long', 
            'applications', 
            $applicant['id'],
            "Auto-rejected: Applicant waited " . $daysWaiting . " days without progression"
        );
        
        $logMessage = "  ✅ Hold notification processed for: " . $applicant['email'];
        cron_log($logMessage);
        echo $logMessage . "\n";
    } else {
        $errorCount++;
        $logMessage = "  ❌ Failed to send to: " . $applicant['email'];
        cron_log($logMessage);
        echo $logMessage . "\n";
    }
}

$summary = "\n📊 Summary: Processed: $processed, Success: $successCount, Errors: $errorCount, Auto-Rejected: $holdCount";
cron_log($summary);
echo $summary . "\n";

cron_log("Completed.\n");