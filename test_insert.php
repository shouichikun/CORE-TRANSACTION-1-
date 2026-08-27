<?php
// test_insert.php - Test database insertion
require_once 'app/config.php';

echo "<h1>Testing Database Insert</h1>";

$applicationId = 1; // Change to a valid application ID
$offerDate = date('Y-m-d');
$userId = 1; // Change to a valid user ID

$sql = "INSERT INTO offers (
    application_id, 
    offer_date, 
    start_date, 
    salary_offered, 
    benefits, 
    notes, 
    status, 
    created_by
) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)";

$params = [
    $applicationId,
    $offerDate,
    null, // start_date
    50000.00, // salary_offered
    null, // benefits
    null, // notes
    $userId
];

$types = "isssssi";

echo "<pre>";
echo "SQL: " . $sql . "\n";
echo "Params: " . json_encode($params) . "\n";
echo "Types: " . $types . "\n";

$result = insertRecord($sql, $params, $types);

if ($result) {
    echo "✅ Insert successful! ID: " . $result . "\n";
} else {
    global $conn;
    echo "❌ Insert failed! Error: " . mysqli_error($conn) . "\n";
}
echo "</pre>";
?>