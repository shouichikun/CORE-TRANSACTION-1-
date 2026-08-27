<?php
// test_env.php - Test if .env is being loaded

echo "📁 Checking .env file...\n\n";

$envFile = __DIR__ . '/.env';

if (file_exists($envFile)) {
    echo "✅ .env file found at: " . $envFile . "\n\n";
    
    $content = file_get_contents($envFile);
    echo "📄 .env contents:\n";
    echo "------------------------\n";
    echo $content;
    echo "\n------------------------\n\n";
    
    // Parse it manually
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            if ($key === 'GEMINI_API_KEY') {
                echo "🔑 GEMINI_API_KEY found: " . substr($value, 0, 20) . "...\n";
            }
            if ($key === 'USE_GEMINI') {
                echo "⚙️ USE_GEMINI = " . $value . "\n";
            }
        }
    }
    
} else {
    echo "❌ .env file NOT found at: " . $envFile . "\n";
    echo "💡 Create the file with:\n";
    echo "GEMINI_API_KEY=your-key-here\n";
    echo "USE_GEMINI=true\n";
}