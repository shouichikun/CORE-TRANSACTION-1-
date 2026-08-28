<?php
/**
 * ============================================================
 * SUPABASE CONNECTION TEST
 * ============================================================
 *
 * Purpose:
 * - Check PHP version
 * - Check cURL
 * - Check HTTPS connectivity
 * - Test Supabase REST API
 * - Test access to the "jobs" table
 *
 * IMPORTANT:
 * Use ONLY:
 * - Supabase Publishable Key (sb_publishable_...)
 * OR
 * - Legacy anon/public key
 *
 * NEVER use:
 * - sb_secret_...
 * - service_role key
 * ============================================================
 */

// ============================================================
// SUPABASE CONFIGURATION
// ============================================================

$supabaseUrl = 'https://xpyilbzbkmymqigrvmgq.supabase.co';

/*
 * PASTE YOUR SUPABASE PUBLISHABLE/ANON KEY HERE.
 *
 * Example:
 *
 * $supabaseKey = 'sb_publishable_d1-wiYJ08jah0GWlI3FPbA_wNiFt0Cw';
 *
 * DO NOT use your sb_secret key.
 * DO NOT use your service_role key.
 */
$supabaseKey = 'sb_publishable_d1-wiYJ08jah0GWlI3FPbA_wNiFt0Cw';


// ============================================================
// BASIC PAGE STYLING
// ============================================================

?>
<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Supabase Connection Test</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 40px 20px;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f6f8;
            color: #1f2937;
        }

        .container {
            max-width: 1000px;
            margin: auto;
        }

        h1 {
            margin-bottom: 8px;
        }

        .subtitle {
            color: #6b7280;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
        }

        .card h2 {
            margin-top: 0;
        }

        .status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .warning {
            background: #fef3c7;
            color: #92400e;
        }

        .info {
            background: #dbeafe;
            color: #1e40af;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }

        td:first-child {
            width: 220px;
            font-weight: bold;
        }

        code {
            background: #f3f4f6;
            padding: 3px 6px;
            border-radius: 5px;
            word-break: break-all;
        }

        pre {
            background: #111827;
            color: #e5e7eb;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .job {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }

        .job-title {
            font-weight: bold;
            font-size: 17px;
            margin-bottom: 5px;
        }

        .small {
            color: #6b7280;
            font-size: 13px;
        }

    </style>

</head>

<body>

<div class="container">

    <h1>Supabase Connection Test</h1>

    <div class="subtitle">
        Testing HostForge → PHP → Supabase REST API
    </div>


<?php

// ============================================================
// TEST 1 — PHP VERSION
// ============================================================

?>

<div class="card">

    <h2>1. PHP Environment</h2>

    <table>

        <tr>
            <td>PHP Version</td>
            <td>
                <code><?php echo htmlspecialchars(PHP_VERSION); ?></code>
            </td>
        </tr>

        <tr>
            <td>Server Software</td>
            <td>
                <code>
                    <?php
                    echo htmlspecialchars(
                        $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
                    );
                    ?>
                </code>
            </td>
        </tr>

        <tr>
            <td>Operating System</td>
            <td>
                <code>
                    <?php echo htmlspecialchars(PHP_OS); ?>
                </code>
            </td>
        </tr>

    </table>

</div>


<?php

// ============================================================
// TEST 2 — CURL
// ============================================================

?>

<div class="card">

    <h2>2. cURL Availability</h2>

<?php

if (function_exists('curl_init')) {

    echo '<span class="status success">cURL ENABLED</span>';

} else {

    echo '<span class="status error">cURL NOT AVAILABLE</span>';

}

?>

</div>


<?php

// ============================================================
// TEST 3 — CONFIGURATION
// ============================================================

?>

<div class="card">

    <h2>3. Supabase Configuration</h2>

    <table>

        <tr>
            <td>Supabase URL</td>

            <td>
                <code>
                    <?php echo htmlspecialchars($supabaseUrl); ?>
                </code>
            </td>
        </tr>

        <tr>
            <td>API Key</td>

            <td>

<?php

if (
    $supabaseKey ===
    'PASTE_YOUR_SUPABASE_PUBLISHABLE_KEY_HERE'
) {

    echo '<span class="status error">
            API KEY NOT CONFIGURED
          </span>';

} else {

    /*
     * Only show a masked version of the key.
     */

    $keyLength = strlen($supabaseKey);

    if ($keyLength > 12) {

        $maskedKey =
            substr($supabaseKey, 0, 8)
            . '********'
            . substr($supabaseKey, -4);

    } else {

        $maskedKey = '********';

    }

    echo '<code>'
        . htmlspecialchars($maskedKey)
        . '</code>';

}

?>

            </td>
        </tr>

    </table>

</div>


<?php

// ============================================================
// STOP IF CURL IS NOT AVAILABLE
// ============================================================

if (!function_exists('curl_init')) {

    echo '
    <div class="card">
        <h2>RESULT</h2>

        <span class="status error">
            TEST FAILED
        </span>

        <p>
            PHP cURL is not enabled on this HostForge server.
        </p>
    </div>
    ';

    echo '</div></body></html>';

    exit;
}


// ============================================================
// STOP IF API KEY IS NOT CONFIGURED
// ============================================================

if (
    $supabaseKey ===
    'PASTE_YOUR_SUPABASE_PUBLISHABLE_KEY_HERE'
) {

    echo '
    <div class="card">
        <h2>RESULT</h2>

        <span class="status warning">
            API KEY REQUIRED
        </span>

        <p>
            Put your Supabase Publishable/Anon key
            inside this file first.
        </p>
    </div>
    ';

    echo '</div></body></html>';

    exit;
}


// ============================================================
// TEST 4 — SUPABASE JOBS TABLE
// ============================================================

?>

<div class="card">

    <h2>4. Supabase API → Jobs Table</h2>

<?php

/*
 * We request only 10 rows for testing.
 *
 * This prevents accidentally loading a huge table.
 */

$endpoint =
    $supabaseUrl
    . '/rest/v1/jobs?select=*&limit=10';


$ch = curl_init($endpoint);


curl_setopt_array($ch, [

    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_HTTPHEADER => [

        'apikey: ' . $supabaseKey,

        'Authorization: Bearer ' . $supabaseKey,

        'Content-Type: application/json',

        'Accept: application/json'

    ],

    CURLOPT_TIMEOUT => 20,

    CURLOPT_CONNECTTIMEOUT => 10,

    CURLOPT_SSL_VERIFYPEER => true,

    CURLOPT_SSL_VERIFYHOST => 2

]);


$response = curl_exec($ch);


// ============================================================
// CURL ERROR
// ============================================================

if ($response === false) {

    $curlError = curl_error($ch);

    $curlErrorNumber = curl_errno($ch);

    echo '
        <span class="status error">
            CONNECTION FAILED
        </span>

        <p>
            <strong>cURL Error Number:</strong>
            ' . htmlspecialchars($curlErrorNumber) . '
        </p>

        <p>
            <strong>cURL Error:</strong>
            ' . htmlspecialchars($curlError) . '
        </p>
    ';

    curl_close($ch);

    echo '</div></div></body></html>';

    exit;
}


// ============================================================
// HTTP INFORMATION
// ============================================================

$httpCode =
    curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

$contentType =
    curl_getinfo(
        $ch,
        CURLINFO_CONTENT_TYPE
    );

$totalTime =
    curl_getinfo(
        $ch,
        CURLINFO_TOTAL_TIME
    );


curl_close($ch);


// ============================================================
// DISPLAY HTTP STATUS
// ============================================================

echo '<p>';

echo '<strong>HTTP Status:</strong> ';

if ($httpCode >= 200 && $httpCode < 300) {

    echo '<span class="status success">'
        . htmlspecialchars($httpCode)
        . '</span>';

} elseif ($httpCode == 401 || $httpCode == 403) {

    echo '<span class="status error">'
        . htmlspecialchars($httpCode)
        . '</span>';

} else {

    echo '<span class="status warning">'
        . htmlspecialchars($httpCode)
        . '</span>';

}

echo '</p>';


// ============================================================
// CONTENT TYPE
// ============================================================

echo '<p>';
echo '<strong>Content-Type:</strong> ';
echo '<code>'
    . htmlspecialchars(
        $contentType ?? 'Unknown'
    )
    . '</code>';
echo '</p>';


// ============================================================
// RESPONSE TIME
// ============================================================

echo '<p>';
echo '<strong>Response Time:</strong> ';
echo '<code>'
    . htmlspecialchars(
        number_format($totalTime, 3)
    )
    . ' seconds</code>';
echo '</p>';


// ============================================================
// DECODE JSON
// ============================================================

$data = json_decode(
    $response,
    true
);


// ============================================================
// JSON ERROR
// ============================================================

if (
    json_last_error() !== JSON_ERROR_NONE
) {

    echo '
        <h3>Invalid JSON Response</h3>

        <pre>'
        . htmlspecialchars($response)
        . '</pre>
    ';

} else {

    // ========================================================
    // HTTP 2XX = SUCCESS
    // ========================================================

    if (
        $httpCode >= 200 &&
        $httpCode < 300
    ) {

        echo '
            <span class="status success">
                SUPABASE CONNECTION SUCCESSFUL
            </span>
        ';

        echo '<h3>Returned Data</h3>';

        echo '<pre>'
            . htmlspecialchars(
                json_encode(
                    $data,
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_SLASHES
                )
            )
            . '</pre>';


        // ====================================================
        // COUNT RESULTS
        // ====================================================

        if (is_array($data)) {

            echo '<p>';

            echo '<strong>Rows returned:</strong> '
                . count($data);

            echo '</p>';


            // =================================================
            // DISPLAY BASIC JOB INFORMATION
            // =================================================

            if (count($data) > 0) {

                echo '<h3>Jobs Found</h3>';

                foreach ($data as $job) {

                    echo '<div class="job">';


                    /*
                     * Try several common column names.
                     */

                    $title =
                        $job['title']
                        ?? $job['job_title']
                        ?? $job['position']
                        ?? $job['name']
                        ?? 'Job title not found';


                    echo '<div class="job-title">';

                    echo htmlspecialchars(
                        (string)$title
                    );

                    echo '</div>';


                    /*
                     * Display ID if available.
                     */

                    if (isset($job['id'])) {

                        echo '<div class="small">';
                        echo 'ID: ';
                        echo htmlspecialchars(
                            (string)$job['id']
                        );
                        echo '</div>';

                    }


                    echo '</div>';

                }

            } else {

                echo '
                    <div style="
                        padding:15px;
                        border-radius:8px;
                        background:#fef3c7;
                        color:#92400e;
                    ">
                        <strong>
                            Supabase connection works,
                            but the jobs table returned
                            ZERO ROWS.
                        </strong>

                        <p>
                            This usually means either:
                        </p>

                        <ul>
                            <li>
                                The table really has no
                                visible rows
                            </li>

                            <li>
                                Row Level Security (RLS)
                                is blocking the request
                            </li>

                            <li>
                                The current API role
                                does not have SELECT access
                            </li>
                        </ul>

                    </div>
                ';

            }

        }

    }

    // ========================================================
    // UNAUTHORIZED
    // ========================================================

    elseif (
        $httpCode == 401
    ) {

        echo '
            <span class="status error">
                HTTP 401 - UNAUTHORIZED
            </span>

            <h3>Supabase Response</h3>

            <pre>'
            . htmlspecialchars(
                json_encode(
                    $data,
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_SLASHES
                )
            )
            . '</pre>

            <p>
                The HostForge server reached Supabase,
                but the API key was rejected.
            </p>
        ';

    }

    // ========================================================
    // FORBIDDEN
    // ========================================================

    elseif (
        $httpCode == 403
    ) {

        echo '
            <span class="status error">
                HTTP 403 - FORBIDDEN
            </span>

            <h3>Supabase Response</h3>

            <pre>'
            . htmlspecialchars(
                json_encode(
                    $data,
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_SLASHES
                )
            )
            . '</pre>

            <p>
                The API request reached Supabase,
                but access to this resource is forbidden.
                Check your RLS policies and database permissions.
            </p>
        ';

    }

    // ========================================================
    // OTHER HTTP ERROR
    // ========================================================

    else {

        echo '
            <span class="status error">
                SUPABASE REQUEST FAILED
            </span>

            <h3>Supabase Response</h3>

            <pre>'
            . htmlspecialchars(
                json_encode(
                    $data,
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_SLASHES
                )
            )
            . '</pre>
        ';

    }

}

?>

</div>


<?php

// ============================================================
// FINAL DIAGNOSIS
// ============================================================

?>

<div class="card">

    <h2>5. Quick Diagnosis</h2>

<?php

if (
    $httpCode >= 200 &&
    $httpCode < 300 &&
    is_array($data)
) {

    if (count($data) > 0) {

        echo '
            <span class="status success">
                HOSTFORGE → SUPABASE WORKS
            </span>

            <p>
                Your HostForge server successfully
                connected to Supabase and retrieved
                records from the jobs table.
            </p>

            <p>
                If your applicant dashboard is still
                empty, the problem is most likely
                inside the applicant dashboard code,
                filtering logic, authentication/session,
                or JavaScript/PHP data processing.
            </p>
        ';

    } else {

        echo '
            <span class="status warning">
                SUPABASE CONNECTS BUT NO JOBS RETURNED
            </span>

            <p>
                The HostForge server can reach Supabase,
                but the jobs query returned zero rows.
            </p>

            <p>
                The next thing to investigate is
                Supabase RLS/policies or the exact
                query used by your application.
            </p>
        ';

    }

} elseif (
    $httpCode == 401
) {

    echo '
        <span class="status error">
            API KEY PROBLEM
        </span>

        <p>
            HostForge reached Supabase, but the
            supplied API key was rejected.
        </p>
    ';

} elseif (
    $httpCode == 403
) {

    echo '
        <span class="status error">
            PERMISSION / RLS PROBLEM
        </span>

        <p>
            HostForge reached Supabase, but the
            request does not have permission to
            read the jobs table.
        </p>
    ';

} else {

    echo '
        <span class="status error">
            CONNECTION / SERVER PROBLEM
        </span>

        <p>
            The HostForge environment could not
            successfully complete the Supabase request.
        </p>
    ';

}

?>

</div>

</div>

</body>
</html>
