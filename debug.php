<?php
// debug_db.php - Database Connection Debugger

echo "<h1>Supabase PostgreSQL Connection Debug</h1>";

// 1. Check if PostgreSQL extension is loaded
echo "<h2>1. PostgreSQL Extension Check</h2>";
if (function_exists('pg_connect')) {
    echo "✅ PostgreSQL extension is loaded<br>";
} else {
    echo "❌ PostgreSQL extension is NOT loaded - Please enable pgsql in php.ini<br>";
    exit;
}

// 2. Your connection credentials
define('PROJECT_REF', 'xpiylbzbkmymqigrvmgq');
define('DB_HOST', 'aws-0-ap-northeast-1.pooler.supabase.com');
define('DB_PORT', '6543');
define('DB_USER', 'postgres.' . PROJECT_REF);
define('DB_PASS', 'CoreTransac1');
define('DB_NAME', 'postgres');

echo "<h2>2. Connection Credentials</h2>";
echo "Project Ref: " . PROJECT_REF . "<br>";
echo "Host: " . DB_HOST . "<br>";
echo "Port: " . DB_PORT . "<br>";
echo "User: " . DB_USER . "<br>";
echo "Database: " . DB_NAME . "<br>";

// 3. Try each connection method
echo "<h2>3. Testing Connection Methods</h2>";

// Method 1: Pooler connection
echo "<h3>Method 1: Pooler Connection</h3>";
$conn1 = @pg_connect(sprintf(
    "host=%s port=%s dbname=%s user=%s password=%s",
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
));

if ($conn1) {
    echo "✅ Pooler connection SUCCESSFUL<br>";
    $status = pg_connection_status($conn1);
    echo "Connection status: " . ($status === PGSQL_CONNECTION_OK ? "OK" : "FAILED") . "<br>";
    pg_close($conn1);
} else {
    $error = function_exists('pg_last_error') ? @pg_last_error() : 'Unknown error';
    echo "❌ Pooler connection FAILED<br>";
    echo "Error: " . htmlspecialchars($error) . "<br>";
}

// Method 2: Direct connection
echo "<h3>Method 2: Direct Connection</h3>";
$conn2 = @pg_connect(sprintf(
    "host=db.%s.supabase.co port=5432 dbname=postgres user=postgres password=%s",
    PROJECT_REF, DB_PASS
));

if ($conn2) {
    echo "✅ Direct connection SUCCESSFUL<br>";
    $status = pg_connection_status($conn2);
    echo "Connection status: " . ($status === PGSQL_CONNECTION_OK ? "OK" : "FAILED") . "<br>";
    pg_close($conn2);
} else {
    $error = function_exists('pg_last_error') ? @pg_last_error() : 'Unknown error';
    echo "❌ Direct connection FAILED<br>";
    echo "Error: " . htmlspecialchars($error) . "<br>";
}

// Method 3: Alternative pooler format
echo "<h3>Method 3: Alternative Pooler Format</h3>";
$conn3 = @pg_connect(sprintf(
    "host=%s.pooler.supabase.com port=%s dbname=%s user=%s password=%s",
    PROJECT_REF, DB_PORT, DB_NAME, DB_USER, DB_PASS
));

if ($conn3) {
    echo "✅ Alternative pooler connection SUCCESSFUL<br>";
    $status = pg_connection_status($conn3);
    echo "Connection status: " . ($status === PGSQL_CONNECTION_OK ? "OK" : "FAILED") . "<br>";
    pg_close($conn3);
} else {
    $error = function_exists('pg_last_error') ? @pg_last_error() : 'Unknown error';
    echo "❌ Alternative pooler connection FAILED<br>";
    echo "Error: " . htmlspecialchars($error) . "<br>";
}

// 4. If any connection worked, test queries
echo "<h2>4. Testing Database Queries</h2>";

// Use the first successful connection or try again
$testConn = @pg_connect(sprintf(
    "host=%s port=%s dbname=%s user=%s password=%s",
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
));

if (!$testConn) {
    $testConn = @pg_connect(sprintf(
        "host=db.%s.supabase.co port=5432 dbname=postgres user=postgres password=%s",
        PROJECT_REF, DB_PASS
    ));
}

if ($testConn) {
    echo "✅ Connected successfully for testing<br><br>";
    
    // Test 1: Check if we can query
    echo "<h3>Test 1: Check PostgreSQL Version</h3>";
    $result = @pg_query($testConn, "SELECT version()");
    if ($result) {
        $row = @pg_fetch_row($result);
        echo "✅ PostgreSQL version: " . htmlspecialchars($row[0]) . "<br>";
        @pg_free_result($result);
    } else {
        $error = @pg_last_error($testConn);
        echo "❌ Failed to get version: " . htmlspecialchars($error) . "<br>";
    }
    
    // Test 2: List all tables
    echo "<h3>Test 2: List Tables</h3>";
    $result = @pg_query($testConn, "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
    if ($result) {
        echo "✅ Tables found:<br>";
        echo "<ul>";
        while ($row = @pg_fetch_assoc($result)) {
            echo "<li>" . htmlspecialchars($row['table_name']) . "</li>";
        }
        echo "</ul>";
        @pg_free_result($result);
    } else {
        $error = @pg_last_error($testConn);
        echo "❌ Failed to list tables: " . htmlspecialchars($error) . "<br>";
    }
    
    // Test 3: Check specific tables that your code uses
    echo "<h3>Test 3: Check Required Tables</h3>";
    $requiredTables = ['users', 'job_orders', 'applications', 'interview_schedules', 'employees', 'clients', 'sessions'];
    
    foreach ($requiredTables as $table) {
        $result = @pg_query($testConn, "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '$table')");
        if ($result) {
            $row = @pg_fetch_row($result);
            $exists = ($row && $row[0] == 't');
            if ($exists) {
                echo "✅ Table '$table' exists<br>";
            } else {
                echo "❌ Table '$table' does NOT exist<br>";
            }
            @pg_free_result($result);
        } else {
            $error = @pg_last_error($testConn);
            echo "❌ Failed to check table '$table': " . htmlspecialchars($error) . "<br>";
        }
    }
    
    // Test 4: Check specific columns
    echo "<h3>Test 4: Check Table Structures</h3>";
    
    // Check users table columns
    $result = @pg_query($testConn, "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'users'");
    if ($result) {
        echo "✅ Users table columns:<br>";
        echo "<ul>";
        while ($row = @pg_fetch_assoc($result)) {
            echo "<li>" . htmlspecialchars($row['column_name']) . "</li>";
        }
        echo "</ul>";
        @pg_free_result($result);
    } else {
        $error = @pg_last_error($testConn);
        echo "❌ Failed to get users columns: " . htmlspecialchars($error) . "<br>";
    }
    
    pg_close($testConn);
} else {
    echo "❌ Could not establish any connection for testing<br>";
}

// 5. Environment check
echo "<h2>5. Environment Information</h2>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Operating System: " . PHP_OS . "<br>";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "<br>";
?>