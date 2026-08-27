<?php
// test_config.php - Quick test for config.php
require_once 'app/config.php';

global $conn;

echo "<h1>Config Test Results</h1>";

// Test 1: Connection
echo "<p>Connection: " . ($conn ? "✅ OK" : "❌ FAILED") . "</p>";

// Test 2: getRecord
$test = getRecord("SELECT 1 as test");
echo "<p>getRecord(): " . ($test && isset($test['test']) ? "✅ OK (" . $test['test'] . ")" : "❌ FAILED") . "</p>";

// Test 3: getRecords
$test2 = getRecords("SELECT 1 as test UNION SELECT 2 as test");
echo "<p>getRecords(): " . (count($test2) === 2 ? "✅ OK (" . count($test2) . " rows)" : "❌ FAILED") . "</p>";

// Test 4: getHRStats
$stats = getHRStats();
echo "<p>getHRStats(): " . (isset($stats['total_jobs']) ? "✅ OK" : "❌ FAILED") . "</p>";

// Test 5: Boolean helper
echo "<p>pgBoolToPhp('t'): " . (pgBoolToPhp('t') === true ? "✅ OK" : "❌ FAILED") . "</p>";
echo "<p>pgBoolToPhp('f'): " . (pgBoolToPhp('f') === false ? "✅ OK" : "❌ FAILED") . "</p>";
echo "<p>pgBoolToPhp(true): " . (pgBoolToPhp(true) === true ? "✅ OK" : "❌ FAILED") . "</p>";
echo "<p>pgBoolToPhp(1): " . (pgBoolToPhp(1) === true ? "✅ OK" : "❌ FAILED") . "</p>";
echo "<p>pgBoolToPhp(0): " . (pgBoolToPhp(0) === false ? "✅ OK" : "❌ FAILED") . "</p>";
?>