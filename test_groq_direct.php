<?php
// test_groq_direct.php - Test with ACTUAL current Groq models

require_once __DIR__ . '/app/config.php';

echo "🧪 Groq API Test (2025 Models)\n";
echo "==============================\n\n";

$apiKey = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';

if (empty($apiKey)) {
    echo "❌ GROQ_API_KEY is not defined in config!\n";
    exit(1);
}

echo "✅ API Key: " . substr($apiKey, 0, 20) . "...\n\n";

// Groq's ACTUAL current models (as of 2025)
$models = [
    'llama-3.3-70b-versatile',
    'llama-3.1-8b-instant',
    'mixtral-8x7b-32768',
    'gemma2-9b-it',
    'deepseek-r1-distill-llama-70b'
];

$workingModel = null;

foreach ($models as $model) {
    echo "📡 Testing: $model\n";
    
    $url = "https://api.groq.com/openai/v1/chat/completions";
    
    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => 'Reply with exactly: "OK"']
        ],
        'temperature' => 0.7,
        'max_tokens' => 10,
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $result = json_decode($response, true);
        $content = $result['choices'][0]['message']['content'] ?? '';
        echo "  ✅ WORKING! Response: $content\n\n";
        $workingModel = $model;
        break;
    } else {
        $error = json_decode($response, true);
        $msg = $error['error']['message'] ?? substr($response, 0, 100);
        echo "  ❌ Failed (HTTP $httpCode): $msg\n\n";
    }
}

if ($workingModel) {
    echo "\n🎉 SUCCESS! Working model: $workingModel\n";
    echo "\n📝 Update your AiService.php:\n";
    echo "private \$groqModel = '$workingModel';\n";
} else {
    echo "\n❌ No working models found.\n";
    echo "💡 Your API key might be invalid or expired.\n";
    echo "1. Go to: https://console.groq.com/keys\n";
    echo "2. Create a NEW API key\n";
    echo "3. Update your .env file\n";
    echo "4. Run this test again\n";
}