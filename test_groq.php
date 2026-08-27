<?php
// test_groq.php - Test Groq AI

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/ai/AiService.php';

echo "🧪 Testing Groq AI Integration...\n\n";

echo "Checking constants:\n";
echo "GROQ_API_KEY: " . (defined('GROQ_API_KEY') ? substr(GROQ_API_KEY, 0, 20) . '...' : 'NOT DEFINED') . "\n";
echo "USE_GROQ: " . (defined('USE_GROQ') ? (USE_GROQ ? 'true' : 'false') : 'NOT DEFINED') . "\n\n";

$ai = new AiService();

echo "Provider Status:\n";
echo "Using Groq: " . ($ai->isUsingGroq() ? '✅ YES' : '❌ NO') . "\n";
echo "Using Gemini: " . ($ai->isUsingGemini() ? '✅ YES' : '❌ NO') . "\n";
echo "Using Mock: " . ($ai->isUsingMock() ? '✅ YES' : '❌ NO') . "\n";
echo "Active Provider: " . $ai->getProvider() . "\n\n";

$jobData = [
    'title' => 'Fitness Coach',
    'description' => 'We are looking for a fitness coach.',
    'skills_required' => 'Communication, Fitness',
    'experience_level' => 'Entry'
];

echo "📝 Generating job optimization suggestions...\n";
$result = $ai->optimizeJobDescription($jobData);

echo "\n📊 Results:\n";
echo "Provider: " . ($result['provider'] ?? 'unknown') . "\n";
echo "Suggested Skills: " . implode(', ', $result['suggested_skills']) . "\n";
echo "Suggested Title: " . $result['suggested_title'] . "\n";
echo "Salary Range: " . $result['salary_range'] . "\n";
echo "Improved Description:\n" . substr($result['improved_description'], 0, 300) . "...\n";