<?php
// debug_warnings.php - Find exactly where warnings come from

// Custom error handler to catch and log all warnings
function warningHandler($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    // Log the warning with full details
    $log = sprintf(
        "[%s] Warning: %s in %s on line %d\n",
        date('Y-m-d H:i:s'),
        $errstr,
        $errfile,
        $errline
    );
    
    // Also log the backtrace to see what called it
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
    $log .= "Stack trace:\n";
    foreach ($backtrace as $index => $trace) {
        if ($index === 0) continue; // Skip the handler itself
        $file = $trace['file'] ?? 'unknown';
        $line = $trace['line'] ?? 'unknown';
        $function = $trace['function'] ?? 'unknown';
        $class = $trace['class'] ?? '';
        $type = $trace['type'] ?? '';
        $log .= sprintf("  #%d %s%s%s() called at %s:%d\n", 
            $index-1, $class, $type, $function, $file, $line);
    }
    $log .= "\n";
    
    // Write to a log file
    file_put_contents(__DIR__ . '/warning_log.txt', $log, FILE_APPEND);
    
    // Don't display warnings
    return true;
}

// Set the error handler
set_error_handler('warningHandler', E_WARNING);

// Now include your config file
echo "<h1>Checking for warnings...</h1>";
echo "Loading config.php...<br>";

// Start output buffering to catch anything displayed
ob_start();

// Include your config
require_once __DIR__ . '/app/config.php';

// Get any output
$output = ob_get_clean();

echo "Config loaded.<br>";

// Now test each function that might cause warnings
echo "<h2>Testing functions that might cause warnings:</h2>";

// Test 1: getRecord with invalid table
echo "Test 1: getRecord with non-existent table...<br>";
$result = getRecord("SELECT * FROM nonexistent_table WHERE id = $1", [1]);
echo "Result: " . ($result === null ? "null (no warning)" : "got data") . "<br>";

// Test 2: getRecords with invalid table
echo "Test 2: getRecords with non-existent table...<br>";
$result = getRecords("SELECT * FROM nonexistent_table");
echo "Result: " . (empty($result) ? "empty array (no warning)" : "got data") . "<br>";

// Test 3: insertRecord with invalid table
echo "Test 3: insertRecord with non-existent table...<br>";
$result = insertRecord("INSERT INTO nonexistent_table (col) VALUES ($1)", ['test']);
echo "Result: " . ($result === false ? "false (no warning)" : "got ID") . "<br>";

// Test 4: updateRecord with invalid table
echo "Test 4: updateRecord with non-existent table...<br>";
$result = updateRecord("UPDATE nonexistent_table SET col = $1 WHERE id = $2", ['test', 1]);
echo "Result: " . ($result === false ? "false (no warning)" : "updated") . "<br>";

// Test 5: deleteRecord with invalid table
echo "Test 5: deleteRecord with non-existent table...<br>";
$result = deleteRecord("DELETE FROM nonexistent_table WHERE id = $1", [1]);
echo "Result: " . ($result === false ? "false (no warning)" : "deleted") . "<br>";

// Test 6: getHRStats
echo "Test 6: getHRStats...<br>";
$stats = getHRStats();
echo "Stats: " . print_r($stats, true) . "<br>";

echo "<h2>Check warning_log.txt for any warnings</h2>";
echo "<a href='warning_log.txt' target='_blank'>View warning log</a>";

// Show if any warnings were logged
if (file_exists(__DIR__ . '/warning_log.txt')) {
    echo "<h2>Warnings Found:</h2>";
    echo "<pre>" . htmlspecialchars(file_get_contents(__DIR__ . '/warning_log.txt')) . "</pre>";
} else {
    echo "<h2 style='color:green'>✅ No warnings found in this test!</h2>";
}

// Restore error handler
restore_error_handler();
?>