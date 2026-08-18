<?php
// app/ai/AiService.php - AI Service for HR Portal with Groq + Gemini Integration

class AiService {
    private $useMock = true;
    private $apiKey;
    private $useGemini = false;
    private $geminiApiKey;
    private $geminiModel = 'gemini-pro';
    private $useGroq = false;
    private $groqApiKey;
    private $groqModel = 'gpt-oss-120b';
    
    public function __construct() {
        $this->apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
        if (defined('AI_ENABLED')) {
            $this->useMock = !AI_ENABLED || empty($this->apiKey);
        }
        
        // =============================================
        // CHECK FOR GROQ (PRIORITY 1 - FASTEST & FREE)
        // =============================================
        $this->groqApiKey = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
        $groqEnabled = defined('USE_GROQ') && USE_GROQ === true;
        
        error_log("🔍 Groq Check - API Key: " . (empty($this->groqApiKey) ? 'NOT FOUND' : 'FOUND (length: ' . strlen($this->groqApiKey) . ')'));
        error_log("🔍 Groq Check - USE_GROQ: " . ($groqEnabled ? 'true' : 'false'));
        
        if (!empty($this->groqApiKey) && $groqEnabled) {
            $this->useGroq = true;
            $this->useMock = false;
            error_log("✅ Groq AI enabled (Llama 3 70B)");
        } elseif (!empty($this->groqApiKey)) {
            error_log("ℹ️ Groq API key found but not enabled (set USE_GROQ=true)");
        }
        
        // =============================================
        // CHECK FOR GEMINI (PRIORITY 2 - Fallback)
        // =============================================
        if (!$this->useGroq) {
            $this->geminiApiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
            if (!empty($this->geminiApiKey) && defined('USE_GEMINI') && USE_GEMINI === true) {
                $this->useGemini = true;
                $this->useMock = false;
                error_log("✅ Google Gemini AI enabled");
            } elseif (!empty($this->geminiApiKey)) {
                error_log("ℹ️ Gemini API key found but not enabled (set USE_GEMINI=true)");
            }
        }
        
        // =============================================
        // FINAL STATUS
        // =============================================
        if ($this->useGroq) {
            error_log("🚀 USING GROQ AI - FASTEST & FREE!");
        } elseif ($this->useGemini) {
            error_log("🚀 USING GEMINI AI");
        } else {
            error_log("ℹ️ Using mock AI (no API keys configured or enabled)");
        }
    }
    
    /**
     * Check if Groq is being used
     */
    public function isUsingGroq() {
        return $this->useGroq;
    }
    
    /**
     * Check if Gemini is being used
     */
    public function isUsingGemini() {
        return $this->useGemini;
    }
    
    /**
     * Check if Mock is being used
     */
    public function isUsingMock() {
        return $this->useMock;
    }
    
    /**
     * Get current provider name
     */
    public function getProvider() {
        if ($this->useGroq) return 'groq';
        if ($this->useGemini) return 'gemini';
        return 'mock';
    }
    
    // =============================================
    // MAIN PUBLIC METHODS
    // =============================================
    
    /**
     * Calculate match score between job and applicant
     */
    public function calculateMatchScore($jobData, $applicantData) {
        if ($this->useGroq) {
            return $this->groqMatchScore($jobData, $applicantData);
        }
        if ($this->useGemini) {
            return $this->geminiMatchScore($jobData, $applicantData);
        }
        return $this->mockMatchScore($jobData, $applicantData);
    }
    
    /**
     * Analyze resume text and extract structured information
     */
    public function analyzeResume($text) {
        if ($this->useGroq) {
            return $this->groqResumeAnalysis($text);
        }
        if ($this->useGemini) {
            return $this->geminiResumeAnalysis($text);
        }
        return $this->mockResumeAnalysis($text);
    }
    
    /**
     * Generate interview questions based on job
     */
    public function generateInterviewQuestions($jobData) {
        if ($this->useGroq) {
            return $this->groqInterviewQuestions($jobData);
        }
        if ($this->useGemini) {
            return $this->geminiInterviewQuestions($jobData);
        }
        return $this->mockInterviewQuestions($jobData);
    }
    
    /**
     * Get job optimization suggestions
     */
    public function optimizeJobDescription($jobData) {
        if ($this->useGroq) {
            return $this->groqJobOptimization($jobData);
        }
        if ($this->useGemini) {
            return $this->geminiJobOptimization($jobData);
        }
        return $this->mockJobOptimization($jobData);
    }
    
    // =============================================
    // GROQ API METHODS
    // =============================================
    
    /**
     * Call Groq API
     */
    private function callGroq($prompt, $format = 'json') {
        if (empty($this->groqApiKey)) {
            error_log("❌ Groq API key is empty!");
            return null;
        }
        
        $url = "https://api.groq.com/openai/v1/chat/completions";
        
        $data = [
            'model' => $this->groqModel,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an expert HR and recruitment AI assistant. Always respond with valid JSON.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 1000,
            'response_format' => ['type' => 'json_object']
        ];
        
        error_log("📡 Sending request to Groq API...");
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->groqApiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("📡 Groq API Response: HTTP $httpCode");
        
        if ($httpCode !== 200) {
            error_log("❌ Groq API Error (HTTP $httpCode): " . substr($response, 0, 500));
            return null;
        }
        
        $result = json_decode($response, true);
        $content = $result['choices'][0]['message']['content'] ?? '';
        
        if (empty($content)) {
            error_log("❌ Groq API returned empty content");
            return null;
        }
        
        if ($format === 'json') {
            $json = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
            
            // Try to extract JSON from text
            preg_match('/\{[^{}]*\}/', $content, $matches);
            if (!empty($matches)) {
                return json_decode($matches[0], true);
            }
            
            error_log("❌ Could not parse JSON from Groq response: " . substr($content, 0, 200));
            return ['error' => 'Could not parse JSON', 'raw' => $content];
        }
        
        return $content;
    }
    
    /**
     * Groq job optimization
     */
    private function groqJobOptimization($jobData) {
        $title = $jobData['title'] ?? '';
        $description = $jobData['description'] ?? '';
        $skills = $jobData['skills_required'] ?? '';
        $experience = $jobData['experience_level'] ?? 'Mid';
        
        $prompt = "You are an expert HR recruiter. Optimize this job posting:
        
JOB TITLE: $title
CURRENT DESCRIPTION: $description
CURRENT SKILLS: $skills
EXPERIENCE LEVEL: $experience

Provide a JSON response with:
1. suggested_skills: Array of recommended skills (add 3-5 relevant ones)
2. improved_description: Enhanced version of the job description
3. suggested_title: Improved job title
4. salary_range: Suggested salary range (in Philippine Peso format ₱XX,XXX - ₱XX,XXX)
5. diversity_check: Object with warnings array and suggestions array

Return ONLY valid JSON.";

        $response = $this->callGroq($prompt);
        
        if ($response && !isset($response['error'])) {
            return [
                'suggested_skills' => $response['suggested_skills'] ?? [],
                'improved_description' => $response['improved_description'] ?? $description,
                'suggested_title' => $response['suggested_title'] ?? $title,
                'salary_range' => $response['salary_range'] ?? '₱50,000 - ₱80,000',
                'diversity_check' => [
                    'warnings' => $response['diversity_check']['warnings'] ?? [],
                    'suggestions' => $response['diversity_check']['suggestions'] ?? []
                ],
                'provider' => 'groq'
            ];
        }
        
        return $this->mockJobOptimization($jobData);
    }
    
    /**
     * Groq match score calculation
     */
    private function groqMatchScore($jobData, $applicantData) {
        $jobTitle = $jobData['title'] ?? 'Unknown Position';
        $jobSkills = $jobData['skills_required'] ?? '';
        $jobDescription = $jobData['description'] ?? '';
        $applicantSkills = $applicantData['skills'] ?? '';
        $applicantExperience = $applicantData['experience'] ?? '';
        $applicantEducation = $applicantData['education'] ?? '';
        $coverLetter = $applicantData['cover_letter'] ?? '';
        
        $prompt = "You are an expert HR recruiter. Analyze this job and applicant and provide a match score.
        
JOB:
Title: $jobTitle
Skills Required: $jobSkills
Description: $jobDescription

APPLICANT:
Skills: $applicantSkills
Experience: $applicantExperience
Education: $applicantEducation
Cover Letter: $coverLetter

Provide a JSON response with:
1. score: 0-100 match percentage
2. level: Excellent/Good/Fair/Low
3. recommendation: Brief recommendation
4. matched_skills: Array of skills that match
5. missing_skills: Array of skills the applicant lacks
6. breakdown: Object with skills_score, experience_score, education_score, cover_letter_score (0-100 each)

Return ONLY valid JSON.";

        $response = $this->callGroq($prompt);
        
        if ($response && !isset($response['error'])) {
            $score = $response['score'] ?? 0;
            $level = $response['level'] ?? 'Fair';
            $recommendation = $response['recommendation'] ?? '';
            $matchedSkills = $response['matched_skills'] ?? [];
            $missingSkills = $response['missing_skills'] ?? [];
            $breakdown = $response['breakdown'] ?? [];
            
            $jobSkills = array_map('strtolower', array_map('trim', explode(',', $jobData['skills_required'] ?? '')));
            $applicantSkills = array_map('strtolower', array_map('trim', explode(',', $applicantData['skills'] ?? '')));
            
            if (empty($matchedSkills) && empty($missingSkills)) {
                $matchedSkills = array_intersect($jobSkills, $applicantSkills);
                $missingSkills = array_diff($jobSkills, $applicantSkills);
            }
            
            $experience = $this->extractExperience($applicantData);
            
            return [
                'score' => $score,
                'level' => $level,
                'color' => $this->getLevelColor($level),
                'recommendation' => $recommendation,
                'matched_skills' => $matchedSkills,
                'missing_skills' => $missingSkills,
                'total_job_skills' => count($jobSkills),
                'matched_count' => count($matchedSkills),
                'applicant_experience' => $experience,
                'details' => [
                    'skills_match' => $breakdown['skills_score'] ?? count($matchedSkills),
                    'skills_missing' => count($missingSkills),
                    'experience_years' => $breakdown['experience_score'] ?? 0,
                    'education_level' => $breakdown['education_score'] ?? 'Not specified'
                ],
                'breakdown' => $breakdown,
                'provider' => 'groq'
            ];
        }
        
        return $this->mockMatchScore($jobData, $applicantData);
    }
    
    /**
     * Groq resume analysis
     */
    private function groqResumeAnalysis($text) {
        $prompt = "Extract structured information from this resume:
        
$text

Provide a JSON response with:
1. skills: Array of key skills found (max 15)
2. years_experience: Total years of experience
3. education: Highest education level
4. keywords: Key terms found
5. summary: One sentence summary

Return ONLY valid JSON.";

        $response = $this->callGroq($prompt);
        
        if ($response && !isset($response['error'])) {
            return [
                'skills' => $response['skills'] ?? [],
                'years_experience' => $response['years_experience'] ?? 0,
                'education' => $response['education'] ?? 'Not specified',
                'keywords' => $response['keywords'] ?? [],
                'summary' => $response['summary'] ?? '',
                'provider' => 'groq'
            ];
        }
        
        return $this->mockResumeAnalysis($text);
    }
    
    /**
     * Groq interview questions
     */
    private function groqInterviewQuestions($jobData) {
        $title = $jobData['title'] ?? 'the role';
        $description = $jobData['description'] ?? '';
        $skills = $jobData['skills_required'] ?? '';
        
        $prompt = "Generate interview questions for this job:
        
JOB TITLE: $title
DESCRIPTION: $description
SKILLS: $skills

Provide a JSON response with:
1. technical: Array of 4 technical questions
2. behavioral: Array of 4 behavioral questions
3. role_specific: Array of 4 role-specific questions

Return ONLY valid JSON.";

        $response = $this->callGroq($prompt);
        
        if ($response && !isset($response['error'])) {
            return [
                'technical' => $response['technical'] ?? ['What is your experience with the required technologies?'],
                'behavioral' => $response['behavioral'] ?? ['Describe a challenging situation you faced at work.'],
                'role_specific' => $response['role_specific'] ?? ['Why are you interested in this role?'],
                'provider' => 'groq'
            ];
        }
        
        return $this->mockInterviewQuestions($jobData);
    }
    
    // =============================================
    // GEMINI API METHODS (Fallback)
    // =============================================
    
    /**
     * Call Gemini API
     */
    private function callGemini($prompt, $format = 'json') {
        if (empty($this->geminiApiKey)) {
            return null;
        }
        
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->geminiModel}:generateContent?key=" . $this->geminiApiKey;
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 1000,
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("Gemini API Error (HTTP $httpCode): " . substr($response, 0, 500));
            return null;
        }
        
        $result = json_decode($response, true);
        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        
        if (empty($text)) {
            return null;
        }
        
        if ($format === 'json') {
            $json = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
            
            preg_match('/\{[^{}]*\}/', $text, $matches);
            if (!empty($matches)) {
                return json_decode($matches[0], true);
            }
            
            return ['error' => 'Could not parse JSON', 'raw' => $text];
        }
        
        return $text;
    }
    
    /**
     * Gemini job optimization
     */
    private function geminiJobOptimization($jobData) {
        $title = $jobData['title'] ?? '';
        $description = $jobData['description'] ?? '';
        $skills = $jobData['skills_required'] ?? '';
        $experience = $jobData['experience_level'] ?? 'Mid';
        
        $prompt = "You are an expert HR recruiter. Optimize this job posting:
        
JOB TITLE: $title
CURRENT DESCRIPTION: $description
CURRENT SKILLS: $skills
EXPERIENCE LEVEL: $experience

Provide a JSON response with:
1. suggested_skills: Array of recommended skills (add 3-5 relevant ones)
2. improved_description: Enhanced version of the job description
3. suggested_title: Improved job title
4. salary_range: Suggested salary range (in Philippine Peso format ₱XX,XXX - ₱XX,XXX)
5. diversity_check: Object with warnings array and suggestions array

Return ONLY valid JSON.";

        $response = $this->callGemini($prompt);
        
        if ($response && !isset($response['error'])) {
            return [
                'suggested_skills' => $response['suggested_skills'] ?? [],
                'improved_description' => $response['improved_description'] ?? $description,
                'suggested_title' => $response['suggested_title'] ?? $title,
                'salary_range' => $response['salary_range'] ?? '₱50,000 - ₱80,000',
                'diversity_check' => [
                    'warnings' => $response['diversity_check']['warnings'] ?? [],
                    'suggestions' => $response['diversity_check']['suggestions'] ?? []
                ],
                'provider' => 'gemini'
            ];
        }
        
        return $this->mockJobOptimization($jobData);
    }
    
    /**
     * Gemini match score calculation
     */
    private function geminiMatchScore($jobData, $applicantData) {
        $jobTitle = $jobData['title'] ?? 'Unknown Position';
        $jobSkills = $jobData['skills_required'] ?? '';
        $jobDescription = $jobData['description'] ?? '';
        $applicantSkills = $applicantData['skills'] ?? '';
        $applicantExperience = $applicantData['experience'] ?? '';
        $applicantEducation = $applicantData['education'] ?? '';
        $coverLetter = $applicantData['cover_letter'] ?? '';
        
        $prompt = "You are an expert HR recruiter. Analyze this job and applicant and provide a match score.
        
JOB:
Title: $jobTitle
Skills Required: $jobSkills
Description: $jobDescription

APPLICANT:
Skills: $applicantSkills
Experience: $applicantExperience
Education: $applicantEducation
Cover Letter: $coverLetter

Provide a JSON response with:
1. score: 0-100 match percentage
2. level: Excellent/Good/Fair/Low
3. recommendation: Brief recommendation
4. matched_skills: Array of skills that match
5. missing_skills: Array of skills the applicant lacks
6. breakdown: Object with skills_score, experience_score, education_score, cover_letter_score (0-100 each)

Return ONLY valid JSON.";

        $response = $this->callGemini($prompt);
        
        if ($response && !isset($response['error'])) {
            $score = $response['score'] ?? 0;
            $level = $response['level'] ?? 'Fair';
            $recommendation = $response['recommendation'] ?? '';
            $matchedSkills = $response['matched_skills'] ?? [];
            $missingSkills = $response['missing_skills'] ?? [];
            $breakdown = $response['breakdown'] ?? [];
            
            $jobSkills = array_map('strtolower', array_map('trim', explode(',', $jobData['skills_required'] ?? '')));
            $applicantSkills = array_map('strtolower', array_map('trim', explode(',', $applicantData['skills'] ?? '')));
            
            if (empty($matchedSkills) && empty($missingSkills)) {
                $matchedSkills = array_intersect($jobSkills, $applicantSkills);
                $missingSkills = array_diff($jobSkills, $applicantSkills);
            }
            
            $experience = $this->extractExperience($applicantData);
            
            return [
                'score' => $score,
                'level' => $level,
                'color' => $this->getLevelColor($level),
                'recommendation' => $recommendation,
                'matched_skills' => $matchedSkills,
                'missing_skills' => $missingSkills,
                'total_job_skills' => count($jobSkills),
                'matched_count' => count($matchedSkills),
                'applicant_experience' => $experience,
                'details' => [
                    'skills_match' => $breakdown['skills_score'] ?? count($matchedSkills),
                    'skills_missing' => count($missingSkills),
                    'experience_years' => $breakdown['experience_score'] ?? 0,
                    'education_level' => $breakdown['education_score'] ?? 'Not specified'
                ],
                'breakdown' => $breakdown,
                'provider' => 'gemini'
            ];
        }
        
        return $this->mockMatchScore($jobData, $applicantData);
    }
    
    /**
     * Gemini resume analysis
     */
    private function geminiResumeAnalysis($text) {
        $prompt = "Extract structured information from this resume:
        
$text

Provide a JSON response with:
1. skills: Array of key skills found (max 15)
2. years_experience: Total years of experience
3. education: Highest education level
4. keywords: Key terms found
5. summary: One sentence summary

Return ONLY valid JSON.";

        $response = $this->callGemini($prompt);
        
        if ($response && !isset($response['error'])) {
            return [
                'skills' => $response['skills'] ?? [],
                'years_experience' => $response['years_experience'] ?? 0,
                'education' => $response['education'] ?? 'Not specified',
                'keywords' => $response['keywords'] ?? [],
                'summary' => $response['summary'] ?? '',
                'provider' => 'gemini'
            ];
        }
        
        return $this->mockResumeAnalysis($text);
    }
    
    /**
     * Gemini interview questions
     */
    private function geminiInterviewQuestions($jobData) {
        $title = $jobData['title'] ?? 'the role';
        $description = $jobData['description'] ?? '';
        $skills = $jobData['skills_required'] ?? '';
        
        $prompt = "Generate interview questions for this job:
        
JOB TITLE: $title
DESCRIPTION: $description
SKILLS: $skills

Provide a JSON response with:
1. technical: Array of 4 technical questions
2. behavioral: Array of 4 behavioral questions
3. role_specific: Array of 4 role-specific questions

Return ONLY valid JSON.";

        $response = $this->callGemini($prompt);
        
        if ($response && !isset($response['error'])) {
            return [
                'technical' => $response['technical'] ?? ['What is your experience with the required technologies?'],
                'behavioral' => $response['behavioral'] ?? ['Describe a challenging situation you faced at work.'],
                'role_specific' => $response['role_specific'] ?? ['Why are you interested in this role?'],
                'provider' => 'gemini'
            ];
        }
        
        return $this->mockInterviewQuestions($jobData);
    }
    
    /**
     * Get level color
     */
    private function getLevelColor($level) {
        $colors = [
            'Excellent' => '#059669',
            'Good' => '#2563eb',
            'Fair' => '#d97706',
            'Low' => '#dc2626'
        ];
        return $colors[$level] ?? '#6b7280';
    }
    
    // =============================================
    // MOCK FUNCTIONS (Fallback)
    // =============================================
    
    private function mockMatchScore($jobData, $applicantData) {
        $jobSkills = [];
        if (!empty($jobData['skills_required'])) {
            $jobSkills = array_map('trim', explode(',', $jobData['skills_required']));
            $jobSkills = array_map('strtolower', $jobSkills);
        }
        
        $applicantSkills = [];
        if (!empty($applicantData['skills'])) {
            if (is_string($applicantData['skills'])) {
                $applicantSkills = array_map('trim', explode(',', $applicantData['skills']));
            } else {
                $applicantSkills = $applicantData['skills'];
            }
            $applicantSkills = array_map('strtolower', $applicantSkills);
        }
        
        $matchedSkills = array_intersect($jobSkills, $applicantSkills);
        $missingSkills = array_diff($jobSkills, $applicantSkills);
        $totalJobSkills = count($jobSkills);
        
        $score = $totalJobSkills > 0 ? round((count($matchedSkills) / $totalJobSkills) * 100) : 0;
        $score = min($score, 100);
        
        if ($score >= 80) {
            $level = 'Excellent';
            $recommendation = 'Highly Recommended - Strong match!';
            $color = '#059669';
        } elseif ($score >= 60) {
            $level = 'Good';
            $recommendation = 'Recommended - Good fit with some gaps';
            $color = '#2563eb';
        } elseif ($score >= 40) {
            $level = 'Fair';
            $recommendation = 'Consider - Needs additional review';
            $color = '#d97706';
        } else {
            $level = 'Low';
            $recommendation = 'Not Recommended - Significant gaps';
            $color = '#dc2626';
        }
        
        $experience = $this->extractExperience($applicantData);
        
        return [
            'score' => $score,
            'level' => $level,
            'color' => $color,
            'recommendation' => $recommendation,
            'matched_skills' => $matchedSkills,
            'missing_skills' => $missingSkills,
            'total_job_skills' => $totalJobSkills,
            'matched_count' => count($matchedSkills),
            'applicant_experience' => $experience,
            'details' => [
                'skills_match' => count($matchedSkills),
                'skills_missing' => count($missingSkills),
                'experience_years' => $experience['years'] ?? 0,
                'education_level' => $experience['education'] ?? 'Not specified'
            ],
            'provider' => 'mock'
        ];
    }
    
    private function mockResumeAnalysis($text) {
        $skillKeywords = [
            'PHP', 'JavaScript', 'Python', 'Java', 'C++', 'Ruby', 'Go',
            'React', 'Angular', 'Vue', 'Node.js', 'Laravel', 'Django',
            'MySQL', 'PostgreSQL', 'MongoDB', 'Redis', 'Elasticsearch',
            'AWS', 'Azure', 'GCP', 'Docker', 'Kubernetes', 'Jenkins',
            'HTML', 'CSS', 'SASS', 'LESS', 'Bootstrap', 'Tailwind',
            'Git', 'SVN', 'Mercurial', 'Agile', 'Scrum', 'Kanban',
            'Project Management', 'Leadership', 'Team Management'
        ];
        
        $foundSkills = [];
        foreach ($skillKeywords as $skill) {
            if (stripos($text, $skill) !== false) {
                $foundSkills[] = $skill;
            }
        }
        
        $years = 0;
        preg_match_all('/(\d+)\s*(?:years?|yrs?)/i', $text, $matches);
        if (!empty($matches[1])) {
            $years = max($matches[1]);
        }
        
        $education = 'Not specified';
        $eduKeywords = ['Bachelor', 'Master', 'PhD', 'Doctorate', 'Diploma', 'Degree', 'University', 'College'];
        foreach ($eduKeywords as $keyword) {
            if (stripos($text, $keyword) !== false) {
                $education = $keyword;
                break;
            }
        }
        
        return [
            'skills' => array_slice($foundSkills, 0, 10),
            'years_experience' => (int)$years,
            'education' => $education,
            'keywords' => $foundSkills,
            'summary' => substr($text, 0, 300) . '...',
            'provider' => 'mock'
        ];
    }
    
    private function mockInterviewQuestions($jobData) {
        $title = $jobData['title'] ?? 'the role';
        $skills = $jobData['skills_required'] ?? '';
        $skillList = array_slice(array_map('trim', explode(',', $skills)), 0, 3);
        
        $firstSkill = $skillList[0] ?? 'the required technologies';
        $secondSkill = $skillList[1] ?? 'your skills';
        
        return [
            'technical' => [
                "What is your experience with {$firstSkill}?",
                "Can you describe a project where you used {$secondSkill}?",
                "How do you approach testing and quality assurance?",
                "Explain your experience with database design and optimization."
            ],
            'behavioral' => [
                "Describe a challenging situation you faced at work and how you resolved it.",
                "How do you prioritize tasks when working on multiple projects?",
                "Tell me about a time you had to learn a new technology quickly.",
                "How do you handle feedback and criticism?"
            ],
            'role_specific' => [
                "Why are you interested in this {$title} position?",
                "What unique skills would you bring to this role?",
                "Where do you see yourself in the next 5 years?",
                "Do you have any questions about the role or the company?"
            ],
            'provider' => 'mock'
        ];
    }
    
    private function mockJobOptimization($jobData) {
        $currentTitle = $jobData['title'] ?? '';
        $currentSkills = $jobData['skills_required'] ?? '';
        $skillList = array_map('trim', explode(',', $currentSkills));
        
        $suggestedSkills = $skillList;
        $commonSkillMap = [
            'developer' => ['Git', 'REST APIs', 'Agile', 'Unit Testing'],
            'engineer' => ['Git', 'CI/CD', 'Linux', 'Docker'],
            'designer' => ['Figma', 'Adobe XD', 'Sketch', 'UI/UX'],
            'manager' => ['Leadership', 'Project Management', 'Agile', 'Scrum'],
            'analyst' => ['SQL', 'Excel', 'Data Visualization', 'Tableau']
        ];
        
        foreach ($commonSkillMap as $keyword => $skills) {
            if (stripos($currentTitle, $keyword) !== false) {
                foreach ($skills as $skill) {
                    if (!in_array($skill, $suggestedSkills)) {
                        $suggestedSkills[] = $skill;
                    }
                }
                break;
            }
        }
        
        $description = $jobData['description'] ?? '';
        
        return [
            'suggested_skills' => array_slice($suggestedSkills, 0, 10),
            'suggested_title' => $currentTitle,
            'improved_description' => $this->improveDescription($description),
            'salary_range' => $this->suggestSalary($currentTitle),
            'diversity_check' => [
                'warnings' => $this->checkBias($description),
                'suggestions' => [
                    'Use gender-neutral language',
                    'Focus on skills rather than years of experience',
                    'Highlight company culture and values'
                ]
            ],
            'provider' => 'mock'
        ];
    }
    
    // =============================================
    // HELPER FUNCTIONS
    // =============================================
    
    private function extractExperience($applicantData) {
        $years = 0;
        $experience = $applicantData['experience'] ?? '';
        
        if (!empty($experience)) {
            preg_match_all('/(\d+)\s*(?:years?|yrs?)/i', $experience, $matches);
            if (!empty($matches[1])) {
                $years = max($matches[1]);
            }
        }
        
        return [
            'years' => (int)$years,
            'raw_text' => $experience,
            'education' => $this->extractEducation($experience)
        ];
    }
    
    private function extractEducation($text) {
        $keywords = ['Bachelor', 'Master', 'PhD', 'Doctorate', 'Diploma', 'Degree'];
        foreach ($keywords as $keyword) {
            if (stripos($text, $keyword) !== false) {
                return $keyword;
            }
        }
        return 'Not specified';
    }
    
    private function improveDescription($description) {
        if (empty($description)) {
            return 'Add a detailed job description including responsibilities, requirements, and company culture.';
        }
        
        $improvements = [];
        
        if (strlen($description) < 200) {
            $improvements[] = 'Consider adding more detail about the role responsibilities.';
        }
        
        if (stripos($description, 'benefit') === false && stripos($description, 'perks') === false) {
            $improvements[] = 'Mention benefits and perks to attract more candidates.';
        }
        
        if (stripos($description, 'culture') === false && stripos($description, 'team') === false) {
            $improvements[] = 'Describe the company culture and team environment.';
        }
        
        return $description . "\n\n---\n\n" . implode("\n", $improvements);
    }
    
    private function suggestSalary($title) {
        $base = [
            'developer' => 60000,
            'engineer' => 65000,
            'designer' => 50000,
            'manager' => 80000,
            'analyst' => 55000,
            'senior' => 90000,
            'lead' => 100000,
            'architect' => 120000
        ];
        
        $salary = 50000;
        foreach ($base as $keyword => $amount) {
            if (stripos($title, $keyword) !== false) {
                $salary = max($salary, $amount);
                break;
            }
        }
        
        $min = $salary - 10000;
        $max = $salary + 20000;
        
        return '₱' . number_format($min, 0) . ' - ₱' . number_format($max, 0);
    }
    
    private function checkBias($text) {
        $warnings = [];
        $biasTerms = [
            'he' => 'Use "they" or "the candidate"',
            'him' => 'Use "them" or "the candidate"',
            'his' => 'Use "their" or "the candidate\'s"',
            'she' => 'Use "they" or "the candidate"',
            'her' => 'Use "them" or "the candidate"',
            'hers' => 'Use "their" or "the candidate\'s"',
            'man' => 'Use "person" or "individual"',
            'men' => 'Use "people" or "individuals"',
            'fresh graduate' => 'Consider "entry-level" or "early career"'
        ];
        
        $lowerText = strtolower($text);
        foreach ($biasTerms as $term => $suggestion) {
            if (strpos($lowerText, $term) !== false) {
                $warnings[] = "Consider avoiding '{$term}' - suggestion: {$suggestion}";
            }
        }
        
        return $warnings;
    }
}