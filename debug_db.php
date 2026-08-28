<?php
// debug_db.php
require_once 'app/config.php';

echo "<h1>Database Debug</h1>";

global $conn;
if (!$conn) {
    echo "<p style='color:red'>❌ No database connection</p>";
    exit;
}

echo "<p style='color:green'>✅ Database connected</p>";

// List all tables
$result = @pg_query($conn, "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
if ($result) {
    echo "<h2>Tables:</h2><ul>";
    while ($row = pg_fetch_row($result)) {
        echo "<li>" . $row[0] . "</li>";
    }
    echo "</ul>";
    pg_free_result($result);
} else {
    echo "<p style='color:red'>Error listing tables: " . @pg_last_error($conn) . "</p>";
}

// Check users
$result = @pg_query($conn, "SELECT COUNT(*) FROM users");
if ($result) {
    $row = pg_fetch_row($result);
    echo "<p>Users: " . $row[0] . "</p>";
    pg_free_result($result);
}

// Check applicants
$result = @pg_query($conn, "SELECT COUNT(*) FROM applicants");
if ($result) {
    $row = pg_fetch_row($result);
    echo "<p>Applicants: " . $row[0] . "</p>";
    pg_free_result($result);
}
