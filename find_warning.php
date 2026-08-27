<?php
// test_single.php - Test a single file for warnings

$file = $_GET['file'] ?? '';

if (empty($file)) {
    die("No file specified");
}

$fullPath = __DIR__ . '/' . $file;

if (!file_exists($fullPath)) {
    die("File not found: $fullPath");
}

echo "<h1>Testing: $file</h1>";

// Custom error handler
function testErrorHandler($errno, $errstr, $errfile, $errline) {
    echo "<div style='background:#ffcccc;padding:10px;margin:10px;border:1px solid red;'>";
    echo "<h3>⚠️ Warning Detected</h3>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($errstr) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars(basename($errfile)) . "</p>";
    echo "<p><strong>Line:</strong> $errline</p>";
    
    // Show what called it
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    echo "<p><strong>Called from:</strong></p><ul>";
    foreach ($backtrace as $index => $trace) {
        if ($index < 2) continue;
        $file = isset($trace['file']) ? basename($trace['file']) : 'unknown';
        $line = isset($trace['line']) ? $trace['line'] : 'unknown';
        $function = isset($trace['function']) ? $trace['function'] : 'unknown';
        echo "<li>" . htmlspecialchars("$function() at $file:$line") . "</li>";
    }
    echo "</ul></div>";
    
    return true;
}

set_error_handler('testErrorHandler', E_WARNING);

// Include the file
echo "<h2>Including file...</h2>";
try {
    require_once $fullPath;
    echo "<p style='color:green'>✅ File loaded successfully</p>";
} catch (Throwable $e) {
    echo "<p style='color:red'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

restore_error_handler();
?>