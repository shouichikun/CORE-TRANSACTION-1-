<?php
// portals/client/test_job.php
session_start();
require_once '../../app/config.php';

$jobId = isset($_GET['id']) ? intval($_GET['id']) : 0;

echo "<h1>🔍 Job Test Page</h1>";
echo "<p><strong>Job ID from URL:</strong> " . $jobId . "</p>";

// Get user info
$userId = $_SESSION['user_id'] ?? 'NOT SET';
echo "<p><strong>User ID:</strong> " . $userId . "</p>";

// Get client info
$client = getRecord("SELECT * FROM clients WHERE user_id = $1", [$userId]);
if ($client) {
    echo "<p><strong>Client ID:</strong> " . $client['id'] . "</p>";
    echo "<p><strong>Client Name:</strong> " . $client['company_name'] . "</p>";
} else {
    echo "<p style='color:red;'>❌ No client found for this user!</p>";
}

// Get job info
if ($jobId > 0) {
    $job = getRecord("SELECT * FROM job_orders WHERE id = $1", [$jobId]);
    if ($job) {
        echo "<p><strong>Job Title:</strong> " . $job['title'] . "</p>";
        echo "<p><strong>Job client_id:</strong> " . $job['client_id'] . "</p>";
        echo "<p><strong>Job Status:</strong> " . $job['status'] . "</p>";
        
        if ($client && $job['client_id'] == $client['id']) {
            echo "<p style='color:green;'>✅ Job belongs to your client!</p>";
        } else {
            echo "<p style='color:red;'>❌ Job does NOT belong to your client!</p>";
        }
    } else {
        echo "<p style='color:red;'>❌ Job ID $jobId not found!</p>";
    }
} else {
    echo "<p style='color:red;'>❌ No job ID provided!</p>";
}

echo "<hr>";
echo "<h3>Try these links:</h3>";
echo "<p><a href='job-details.php?id=6'>job-details.php?id=6</a></p>";
echo "<p><a href='jobs.php'>Back to Jobs</a></p>";
?>