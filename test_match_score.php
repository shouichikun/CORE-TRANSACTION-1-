<?php
// test_match_score.php - Test Real AI Match Score

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/ai/AiService.php';

$ai = new AiService();

echo "🧪 Testing Real AI Match Score...\n\n";

// Sample job data
$jobData = [
    'title' => 'Senior PHP Developer',
    'skills_required' => 'PHP, Laravel, MySQL, Git, JavaScript, REST APIs, Docker',
    'description' => 'We are looking for a senior PHP developer with Laravel experience to lead our development team.',
    'experience_level' => 'Senior'
];

// Sample applicant data
$applicantData = [
    'skills' => 'PHP, Laravel, MySQL, Git, JavaScript, React, Vue.js, Docker, AWS',
    'experience' => '8 years of PHP development, 5 years with Laravel, 3 years team lead',
    'education' => 'Master of Science in Computer Science',
    'cover_letter' => 'I am extremely excited about this Senior PHP Developer position. With 8 years of experience in PHP and Laravel, and 5 years as a team lead, I believe I would be an excellent fit for this role. I have extensive experience with MySQL, Git, and modern JavaScript frameworks.',
    'resume_path' => 'resume_123456.pdf'
];

echo "📊 Job: " . $jobData['title'] . "\n";
echo "📋 Applicant: PHP Developer with 8 years experience\n\n";

$result = $ai->calculateMatchScore($jobData, $applicantData);

echo "Provider: " . ($result['provider'] ?? 'unknown') . "\n";
echo "Score: " . $result['score'] . "%\n";
echo "Level: " . $result['level'] . "\n";
echo "Recommendation: " . $result['recommendation'] . "\n";
echo "Matched Skills: " . implode(', ', $result['matched_skills']) . "\n";
echo "Missing Skills: " . implode(', ', $result['missing_skills']) . "\n";

if (isset($result['breakdown'])) {
    echo "\nBreakdown:\n";
    foreach ($result['breakdown'] as $key => $value) {
        echo "  - " . ucfirst($key) . ": " . $value . "%\n";
    }
}