<?php
// app/ai_helper.php - AI Integration

/**
 * Call Ollama API
 */
function callOllama($prompt, $model = 'hermes3') {
    $url = 'http://localhost:11434/api/generate';
    
    $data = [
        'model' => $model,
        'prompt' => $prompt,
        'stream' => false,
        'options' => [
            'temperature' => 0.3,
            'num_predict' => 800
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        return ['error' => 'Ollama error: ' . $error];
    }
    
    if ($httpCode !== 200) {
        return ['error' => 'Ollama returned HTTP ' . $httpCode];
    }
    
    return json_decode($response, true);
}

/**
 * Check if Ollama is running
 */
function isOllamaRunning() {
    $ch = curl_init('http://localhost:11434/api/tags');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $httpCode === 200;
}

// =============================================
// NEW: RESUME SCORING
// =============================================

/**
 * Score a resume against a job description
 */
function scoreResume($resumeText, $jobTitle, $jobDescription, $jobSkills = '') {
    $prompt = "You are an expert HR recruiter. Analyze this resume against the job requirements.
        
        JOB TITLE: $jobTitle
        JOB DESCRIPTION: $jobDescription
        JOB SKILLS: $jobSkills
        
        RESUME:
        $resumeText
        
        Provide a detailed analysis with the following EXACT format:
        
        MATCH_SCORE: [0-100]
        MATCHING_SKILLS: [comma separated list of skills that match]
        MISSING_SKILLS: [comma separated list of skills that are missing]
        STRENGTHS: [bullet points of candidate strengths]
        WEAKNESSES: [bullet points of areas to improve]
        SUMMARY: [2-3 sentences overall assessment]
        RECOMMENDATIONS: [bullet points of specific recommendations to improve]
        
        Be strict but fair. Score 80+ only if most requirements are met.";
    
    $result = callOllama($prompt);
    
    if (isset($result['error'])) {
        return $result;
    }
    
    $response = $result['response'] ?? '';
    
    // Parse response
    $score = 0;
    $matchingSkills = '';
    $missingSkills = '';
    $strengths = '';
    $weaknesses = '';
    $summary = '';
    $recommendations = '';
    
    if (preg_match('/MATCH_SCORE:\s*(\d+)/i', $response, $matches)) {
        $score = min(100, (int)$matches[1]);
    }
    
    if (preg_match('/MATCHING_SKILLS:\s*(.+)/i', $response, $matches)) {
        $matchingSkills = trim($matches[1]);
    }
    
    if (preg_match('/MISSING_SKILLS:\s*(.+)/i', $response, $matches)) {
        $missingSkills = trim($matches[1]);
    }
    
    if (preg_match('/STRENGTHS:\s*(.+)/is', $response, $matches)) {
        $strengths = trim($matches[1]);
    }
    
    if (preg_match('/WEAKNESSES:\s*(.+)/is', $response, $matches)) {
        $weaknesses = trim($matches[1]);
    }
    
    if (preg_match('/SUMMARY:\s*(.+)/is', $response, $matches)) {
        $summary = trim($matches[1]);
    }
    
    if (preg_match('/RECOMMENDATIONS:\s*(.+)/is', $response, $matches)) {
        $recommendations = trim($matches[1]);
    }
    
    return [
        'success' => true,
        'score' => $score,
        'matching_skills' => $matchingSkills,
        'missing_skills' => $missingSkills,
        'strengths' => $strengths,
        'weaknesses' => $weaknesses,
        'summary' => $summary,
        'recommendations' => $recommendations,
        'full_response' => $response
    ];
}

// =============================================
// NEW: CAREER PATH SUGGESTIONS
// =============================================

/**
 * Suggest career paths based on skills and experience
 */
function suggestCareerPath($currentRole, $skills, $experience, $interests = '') {
    $prompt = "You are a career coach. Based on the following profile, suggest career paths.
        
        CURRENT ROLE: $currentRole
        SKILLS: $skills
        EXPERIENCE: $experience
        INTERESTS: $interests
        
        Provide a detailed career roadmap with the following EXACT format:
        
        CURRENT_LEVEL: [Entry/Junior/Mid/Senior/Lead]
        NEXT_STEP: [Next role title]
        NEXT_LEVEL_SKILLS: [Skills needed for next level]
        LONG_TERM_PATHS: [3-4 possible career directions]
        RECOMMENDED_PATH: [Best path with reasoning]
        SKILLS_TO_LEARN: [Priority skills to learn next]
        TIMELINE: [Estimated timeline for career progression]
        ADVICE: [Personalized career advice]
        
        Be realistic and specific. Base recommendations on the skills provided.";
    
    $result = callOllama($prompt);
    
    if (isset($result['error'])) {
        return $result;
    }
    
    $response = $result['response'] ?? '';
    
    // Parse response
    $currentLevel = '';
    $nextStep = '';
    $nextLevelSkills = '';
    $longTermPaths = '';
    $recommendedPath = '';
    $skillsToLearn = '';
    $timeline = '';
    $advice = '';
    
    if (preg_match('/CURRENT_LEVEL:\s*(.+)/i', $response, $matches)) {
        $currentLevel = trim($matches[1]);
    }
    
    if (preg_match('/NEXT_STEP:\s*(.+)/i', $response, $matches)) {
        $nextStep = trim($matches[1]);
    }
    
    if (preg_match('/NEXT_LEVEL_SKILLS:\s*(.+)/i', $response, $matches)) {
        $nextLevelSkills = trim($matches[1]);
    }
    
    if (preg_match('/LONG_TERM_PATHS:\s*(.+)/is', $response, $matches)) {
        $longTermPaths = trim($matches[1]);
    }
    
    if (preg_match('/RECOMMENDED_PATH:\s*(.+)/i', $response, $matches)) {
        $recommendedPath = trim($matches[1]);
    }
    
    if (preg_match('/SKILLS_TO_LEARN:\s*(.+)/i', $response, $matches)) {
        $skillsToLearn = trim($matches[1]);
    }
    
    if (preg_match('/TIMELINE:\s*(.+)/i', $response, $matches)) {
        $timeline = trim($matches[1]);
    }
    
    if (preg_match('/ADVICE:\s*(.+)/is', $response, $matches)) {
        $advice = trim($matches[1]);
    }
    
    return [
        'success' => true,
        'current_level' => $currentLevel,
        'next_step' => $nextStep,
        'next_level_skills' => $nextLevelSkills,
        'long_term_paths' => $longTermPaths,
        'recommended_path' => $recommendedPath,
        'skills_to_learn' => $skillsToLearn,
        'timeline' => $timeline,
        'advice' => $advice,
        'full_response' => $response
    ];
}

/**
 * Get career level based on experience
 */
function getCareerLevel($experience) {
    $years = 0;
    if (preg_match('/(\d+)\s*years?/i', $experience, $matches)) {
        $years = (int)$matches[1];
    }
    
    if ($years >= 8) return 'Senior';
    if ($years >= 5) return 'Mid';
    if ($years >= 2) return 'Junior';
    return 'Entry';
}

/**
 * Get career progression levels
 */
function getCareerProgression($currentLevel) {
    $levels = [
        'Entry' => ['Junior', '6-12 months', 'Focus on learning fundamentals and building portfolio.'],
        'Junior' => ['Mid', '1-2 years', 'Work on complex projects and mentor juniors.'],
        'Mid' => ['Senior', '2-3 years', 'Lead projects and architectural decisions.'],
        'Senior' => ['Lead', '3-5 years', 'Mentor teams and drive technical strategy.'],
        'Lead' => ['Manager/Architect', '3-5 years', 'Focus on people management or system architecture.']
    ];
    
    return $levels[$currentLevel] ?? ['Unknown', 'Unknown', 'Keep learning and growing.'];
}
?>