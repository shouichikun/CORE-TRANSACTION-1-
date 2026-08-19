<?php
// test_models.php - Check available models with your NEW API key
require_once 'app/config.php';

$apiKey = GROQ_API_KEY;

echo "🔑 Checking models with your NEW API key...\n\n";

$ch = curl_init('https://api.groq.com/openai/v1/models');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    echo "✅ Available Models:\n\n";
    foreach ($data['data'] ?? [] as $model) {
        echo "  - " . $model['id'] . "\n";
    }
} else {
    echo "❌ Failed to list models (HTTP $httpCode)\n";
    echo "Response: " . substr($response, 0, 500) . "\n";
}