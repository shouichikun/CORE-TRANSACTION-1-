<?php
// check_tables.php - Check what tables and columns exist

define('PROJECT_REF', 'xpiylbzbkmymqigrvmgq');
define('DB_HOST', 'aws-0-ap-northeast-1.pooler.supabase.com');
define('DB_PORT', '6543');
define('DB_USER', 'postgres.' . PROJECT_REF);
define('DB_PASS', 'CoreTransac1');
define('DB_NAME', 'postgres');

echo "<h1>Supabase Table Structure Check</h1>";

// Connect using the working pooler connection
$conn = @pg_connect(sprintf(
    "host=%s port=%s dbname=%s user=%s password=%s",
    DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
));

if (!$conn) {
    die("❌ Could not connect to database");
}

echo "✅ Connected successfully!<br><br>";

// 1. List all tables in public schema
echo "<h2>1. All Tables in Public Schema</h2>";
$result = @pg_query($conn, "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
if ($result) {
    echo "<ul>";
    $tables = [];
    while ($row = @pg_fetch_assoc($result)) {
        $tables[] = $row['table_name'];
        echo "<li>" . htmlspecialchars($row['table_name']) . "</li>";
    }
    echo "</ul>";
    @pg_free_result($result);
} else {
    echo "❌ Failed to get tables<br>";
}

// 2. Check each table your code uses
echo "<h2>2. Required Tables Check</h2>";
$requiredTables = [
    'users',
    'job_orders', 
    'applications',
    'interview_schedules',
    'employees',
    'clients',
    'sessions',
    'applicants',
    'attendance',
    'face_logs',
    'system_logs',
    'settings'
];

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Table</th><th>Exists?</th><th>Columns</th></tr>";

foreach ($requiredTables as $table) {
    echo "<tr>";
    echo "<td><strong>" . htmlspecialchars($table) . "</strong></td>";
    
    // Check if table exists
    $checkResult = @pg_query($conn, "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '$table')");
    if ($checkResult) {
        $row = @pg_fetch_row($checkResult);
        $exists = ($row && $row[0] == 't');
        @pg_free_result($checkResult);
        
        if ($exists) {
            echo "<td style='color:green'>✅ Exists</td>";
            
            // Get columns
            $colResult = @pg_query($conn, "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '$table' ORDER BY ordinal_position");
            if ($colResult) {
                echo "<td>";
                while ($col = @pg_fetch_assoc($colResult)) {
                    echo htmlspecialchars($col['column_name']) . " (" . htmlspecialchars($col['data_type']) . ")<br>";
                }
                @pg_free_result($colResult);
                echo "</td>";
            } else {
                echo "<td>Could not get columns</td>";
            }
        } else {
            echo "<td style='color:red'>❌ Missing</td>";
            echo "<td>-</td>";
        }
    } else {
        echo "<td style='color:red'>❌ Check failed</td>";
        echo "<td>-</td>";
    }
    echo "</tr>";
}
echo "</table>";

// 3. Check specific columns in users table (since it's critical)
echo "<h2>3. Critical Columns Check (users table)</h2>";
$criticalColumns = ['id', 'email', 'password_hash', 'role', 'first_name', 'last_name', 'full_name', 'is_face_verified', 'profile_picture'];

$result = @pg_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'users'");
if ($result) {
    $existingColumns = [];
    while ($row = @pg_fetch_assoc($result)) {
        $existingColumns[] = $row['column_name'];
    }
    @pg_free_result($result);
    
    echo "<ul>";
    foreach ($criticalColumns as $col) {
        if (in_array($col, $existingColumns)) {
            echo "<li style='color:green'>✅ " . htmlspecialchars($col) . "</li>";
        } else {
            echo "<li style='color:red'>❌ " . htmlspecialchars($col) . " - MISSING</li>";
        }
    }
    echo "</ul>";
} else {
    echo "❌ Could not check users table columns<br>";
}

// 4. Test a simple query on users table
echo "<h2>4. Test Query on users table</h2>";
$result = @pg_query($conn, "SELECT COUNT(*) as count FROM users");
if ($result) {
    $row = @pg_fetch_assoc($result);
    echo "✅ users table has " . ($row['count'] ?? 0) . " records<br>";
    @pg_free_result($result);
} else {
    $error = @pg_last_error($conn);
    echo "❌ Failed to query users table: " . htmlspecialchars($error) . "<br>";
}

// 5. Check if the HR stats query would work
echo "<h2>5. HR Stats Query Test</h2>";
$testQueries = [
    "SELECT COUNT(*) as count FROM job_orders" => "job_orders table",
    "SELECT COUNT(*) as count FROM job_orders WHERE status IN ('open', 'ongoing')" => "active jobs",
    "SELECT COUNT(*) as count FROM applications" => "applications table",
    "SELECT COUNT(DISTINCT applicant_id) as count FROM applications" => "distinct applicants",
];

foreach ($testQueries as $sql => $description) {
    $result = @pg_query($conn, $sql);
    if ($result) {
        $row = @pg_fetch_assoc($result);
        echo "✅ " . htmlspecialchars($description) . ": " . ($row['count'] ?? 0) . "<br>";
        @pg_free_result($result);
    } else {
        $error = @pg_last_error($conn);
        echo "❌ " . htmlspecialchars($description) . " FAILED: " . htmlspecialchars($error) . "<br>";
    }
}

pg_close($conn);
?>