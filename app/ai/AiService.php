<?php
// app/ai/AiService.php - AI Service for HR Portal with Groq + Gemini Integration

// Load config first to get constants
require_once __DIR__ . '/../config.php';

class AiService {
    private $useMock = true;
    private $apiKey;
    private $useGemini = false;
    private $geminiApiKey;
    private $geminiModel = 'gemini-pro';
    private $useGroq = false;
    private $groqApiKey;
    // ✅ FIXED: Using the correct model name from your available models list
    // Available models: openai/gpt-oss-120b, qwen/qwen3.6-27b, openai/gpt-oss-20b, etc.
    private $groqModel = 'openai/gpt-oss-120b';

    public function __construct() {
        $this->apiKey = defined('AI_API_KEY') ? AI_API_KEY : '';
        if (defined('AI_ENABLED')) {
            $this->useMock = !AI_ENABLED || empty($this->apiKey);
        }

        // Check for Groq API key
        $this->groqApiKey = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
        $groqEnabled = defined('USE_GROQ') && USE_GROQ === true;

        // Debug logging
        error_log("🔍 Groq API Key: " . (empty($this->groqApiKey) ? 'EMPTY' : substr($this->groqApiKey, 0, 15) . '...'));
        error_log("🔍 Groq Enabled: " . ($groqEnabled ? 'true' : 'false'));
        error_log("🔍 Groq Model: " . $this->groqModel);

        if (!empty($this->groqApiKey) && $groqEnabled) {
            $this->useGroq = true;
            $this->useMock = false;
            error_log("✅ Groq AI enabled with model: " . $this->groqModel);
        }

        // Check for Gemini (fallback)
        if (!$this->useGroq) {
            $this->geminiApiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
            if (!empty($this->geminiApiKey) && defined('USE_GEMINI') && USE_GEMINI === true) {
                $this->useGemini = true;
                $this->useMock = false;
                error_log("✅ Google Gemini AI enabled");
            }
        }

        error_log("🚀 Using AI Provider: " . $this->getProvider() . " | Model: " . $this->getModel());
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

    /**
     * Get the current model being used
     */
    public function getModel() {
        if ($this->useGroq) return $this->groqModel;
        if ($this->useGemini) return $this->geminiModel;
        return 'mock';
    }

    // =============================================
    // MAIN PUBLIC METHODS
    // =============================================

   public function calculateMatchScore($jobData, $applicantData) {
    error_log("📊 calculateMatchScore called");
    error_log("  - useGroq: " . ($this->useGroq ? 'true' : 'false'));
    error_log("  - useMock: " . ($this->useMock ? 'true' : 'false'));

    if ($this->useGroq) {
        error_log("🚀 Using GROQ for match score");
        $result = $this->groqMatchScore($jobData, $applicantData);
        // Ensure we return the correct format
        if (isset($result['match_score'])) {
            return $result;
        }
        // Convert old format to new format if needed
        if (isset($result['score'])) {
            return [
                'match_score' => $result['score'],
                'strengths' => $result['matched_skills'] ?? [],
                'gaps' => $result['missing_skills'] ?? [],
                'recommendation' => $result['recommendation'] ?? '',
                'provider' => $result['provider'] ?? 'groq'
            ];
        }
        return $result;
    }
    if ($this->useGemini) {
        error_log("🚀 Using GEMINI for match score");
        $result = $this->geminiMatchScore($jobData, $applicantData);
        if (isset($result['match_score'])) {
            return $result;
        }
        if (isset($result['score'])) {
            return [
                'match_score' => $result['score'],
                'strengths' => $result['matched_skills'] ?? [],
                'gaps' => $result['missing_skills'] ?? [],
                'recommendation' => $result['recommendation'] ?? '',
                'provider' => $result['provider'] ?? 'gemini'
            ];
        }
        return $result;
    }
    error_log("📝 Using MOCK for match score");
    return $this->mockMatchScore($jobData, $applicantData);
}

    public function analyzeResume($text) {
        if ($this->useGroq) {
            return $this->groqResumeAnalysis($text);
        }
        if ($this->useGemini) {
            return $this->geminiResumeAnalysis($text);
        }
        return $this->mockResumeAnalysis($text);
    }

    public function generateInterviewQuestions($jobData) {
        if ($this->useGroq) {
            return $this->groqInterviewQuestions($jobData);
        }
        if ($this->useGemini) {
            return $this->geminiInterviewQuestions($jobData);
        }
        return $this->mockInterviewQuestions($jobData);
    }

    public function optimizeJobDescription($jobData) {
        if ($this->useGroq) {
            return $this->groqJobOptimization($jobData);
        }
        if ($this->useGemini) {
            return $this->geminiJobOptimization($jobData);
        }
        return $this->mockJobOptimization($jobData);
    }

    public function getJobInsights($jobData) {
        if ($this->useGroq) {
            return $this->groqJobInsights($jobData);
        }
        if ($this->useGemini) {
            return $this->geminiJobInsights($jobData);
        }
        return $this->mockJobInsights($jobData);
    }

    public function analyzeEmployeePerformance($employeeData) {
        if ($this->useGroq) {
            return $this->groqEmployeePerformance($employeeData);
        }
        if ($this->useGemini) {
            return $this->geminiEmployeePerformance($employeeData);
        }
        return $this->mockEmployeePerformance($employeeData);
    }

    public function generateExecutiveSummary($data) {
        if ($this->useGroq) {
            return $this->groqExecutiveSummary($data);
        }
        if ($this->useGemini) {
            return $this->geminiExecutiveSummary($data);
        }
        return $this->mockExecutiveSummary($data);
    }

    public function generateHiringForecast($data) {
        if ($this->useGroq) {
            return $this->groqHiringForecast($data);
        }
        if ($this->useGemini) {
            return $this->geminiHiringForecast($data);
        }
        return $this->mockHiringForecast($data);
    }

    public function generateAdminExecutiveSummary($data) {
        if ($this->useGroq) {
            return $this->groqAdminExecutiveSummary($data);
        }
        if ($this->useGemini) {
            return $this->geminiAdminExecutiveSummary($data);
        }
        return $this->mockAdminExecutiveSummary($data);
    }

    public function generateDashboardInsights($data) {
        if ($this->useGroq) {
            return $this->groqDashboardInsights($data);
        }
        if ($this->useGemini) {
            return $this->geminiDashboardInsights($data);
        }
        return $this->mockDashboardInsights($data);
    }

    // =============================================
    // GROQ API METHODS
    // =============================================

    /**
     * Call Groq API - FIXED with correct model
     */
    private function callGroq($prompt, $format = 'json') {
        if (empty($this->groqApiKey)) {
            error_log("❌ Groq API key is empty!");
            return null;
        }

        error_log("📡 Calling Groq API with model: " . $this->groqModel);
        error_log("📡 Prompt length: " . strlen($prompt));

        $url = "https://api.groq.com/openai/v1/chat/completions";

        // ✅ FIXED: Use the class property with correct model
        $data = [
            'model' => $this->groqModel,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an expert HR and recruitment AI assistant. Always respond with valid JSON. Never include any text outside the JSON object.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.3,
            'max_tokens' => 1024,
            'top_p' => 1,
            'stream' => false
        ];

        $jsonData = json_encode($data);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->groqApiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        error_log("📡 Groq API Response: HTTP " . $httpCode);

        if ($curlError) {
            error_log("❌ cURL Error: " . $curlError);
            return null;
        }

        if ($httpCode !== 200) {
            error_log("❌ Groq API Error (HTTP $httpCode): " . substr($response, 0, 500));
            // Try to parse error response for more details
            $errorJson = json_decode($response, true);
            if ($errorJson && isset($errorJson['error'])) {
                error_log("❌ Groq Error Details: " . json_encode($errorJson['error']));
            }
            return null;
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("❌ JSON Parse Error: " . json_last_error_msg());
            return null;
        }

        $content = $result['choices'][0]['message']['content'] ?? '';
        error_log("📡 Content received, length: " . strlen($content));

        if (empty($content)) {
            error_log("❌ Empty content from Groq");
            return null;
        }

        // Clean the content - remove markdown code blocks if present
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);

        error_log("📡 Cleaned content preview: " . substr($content, 0, 200));

        // Try to parse JSON
        $json = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            error_log("✅ JSON parsed successfully!");
            return $json;
        }

        // Try to extract JSON from text
        preg_match('/\{[^{}]*\}/', $content, $matches);
        if (!empty($matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                error_log("✅ Extracted JSON from text!");
                return $json;
            }
        }

        // Try to extract JSON with nested objects
        preg_match('/\{(?:[^{}]|(?R))*\}/s', $content, $matches);
        if (!empty($matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                error_log("✅ Extracted nested JSON!");
                return $json;
            }
        }

        error_log("❌ Could not parse JSON from Groq response");
        error_log("Response preview: " . substr($content, 0, 200));

        return null;
    }

    // =============================================
    // GROQ: DASHBOARD INSIGHTS
    // =============================================

    private function groqDashboardInsights($data) {
        $totalUsers = $data['total_users'] ?? 0;
        $onlineUsers = $data['online_users'] ?? 0;
        $totalJobs = $data['total_jobs'] ?? 0;
        $totalApplications = $data['total_applications'] ?? 0;
        $totalClients = $data['total_clients'] ?? 0;
        $totalHR = $data['total_hr'] ?? 0;
        $totalEmployees = $data['total_employees'] ?? 0;
        $roleDistribution = $data['role_distribution'] ?? [];

        error_log("📝 groqDashboardInsights called");

        $roleText = '';
        foreach ($roleDistribution as $role) {
            $roleText .= "- {$role['label']}: {$role['count']} users\n";
        }

        $prompt = "You are an expert HR platform analyst. Analyze this platform's current status and provide insights.

PLATFORM DATA:
Total Users: $totalUsers
Online Users: $onlineUsers
Total Jobs: $totalJobs
Total Applications: $totalApplications
Total Clients: $totalClients
Total HR Professionals: $totalHR
Total Employees: $totalEmployees

Role Distribution:
$roleText

Provide a JSON response with:
- health_score: integer (0-100)
- status: \"Excellent\", \"Good\", \"Fair\", or \"At Risk\"
- insights: Array of 3-5 key insights (bullet points)
- recommendations: Array of 2-4 actionable recommendations
- anomalies: Array of 0-3 anomalies to flag
- trend: \"Growing\", \"Stable\", \"Needs Attention\", or \"Declining\"

Return ONLY valid JSON.";

        $response = $this->callGroq($prompt);

        if ($response && !isset($response['error'])) {
            error_log("✅ groqDashboardInsights returned successfully");
            return [
                'health_score' => $response['health_score'] ?? 85,
                'status' => $response['status'] ?? 'Good',
                'insights' => $response['insights'] ?? [],
                'recommendations' => $response['recommendations'] ?? [],
                'anomalies' => $response['anomalies'] ?? [],
                'trend' => $response['trend'] ?? 'Stable',
                'provider' => 'groq'
            ];
        }

        error_log("❌ groqDashboardInsights failed, falling back to mock");
        return $this->mockDashboardInsights($data);
    }

    // =============================================
    // GROQ: ADMIN EXECUTIVE SUMMARY
    // =============================================

    private function groqAdminExecutiveSummary($data) {
        $totalClients = $data['total_clients'] ?? 0;
        $totalAgencies = $data['total_agencies'] ?? 0;
        $totalJobs = $data['total_jobs'] ?? 0;
        $totalApplications = $data['total_applications'] ?? 0;
        $totalUsers = $data['total_users'] ?? 0;
        $onlineUsers = $data['online_users'] ?? 0;
        $pendingAgencies = $data['pending_agencies'] ?? 0;
        $activeClients = $data['active_clients'] ?? 0;
        $industryDistribution = $data['industry_distribution'] ?? [];

        error_log("📝 groqAdminExecutiveSummary called");

        $industryText = '';
        foreach ($industryDistribution as $industry) {
            $industryText .= "- {$industry['industry']}: {$industry['count']} clients\n";
        }

        $prompt = "You are an expert HR platform analyst. Provide an executive summary for this platform's data.

PLATFORM DATA:
Total Clients: $totalClients
Active Clients: $activeClients
Total Agencies: $totalAgencies
Pending Agencies: $pendingAgencies
Total Jobs: $totalJobs
Total Applications: $totalApplications
Total Users: $totalUsers
Online Users: $onlineUsers

Industry Distribution:
$industryText

Provide a JSON response with:
- summary: A 2-3 sentence executive summary
- insights: Array of 3-5 key insights
- recommendations: Array of 3-5 actionable recommendations
- health_score: integer (0-100) representing platform health
- trend_forecast: \"Growing\", \"Stable\", \"Needs Attention\", or \"Declining\"

Return ONLY valid JSON.";

        $response = $this->callGroq($prompt);

        if ($response && !isset($response['error'])) {
            error_log("✅ groqAdminExecutiveSummary returned successfully");
            return [
                'summary' => $response['summary'] ?? 'Platform is operating normally.',
                'insights' => $response['insights'] ?? [],
                'recommendations' => $response['recommendations'] ?? [],
                'health_score' => $response['health_score'] ?? 85,
                'trend_forecast' => $response['trend_forecast'] ?? 'Stable',
                'provider' => 'groq'
            ];
        }

        error_log("❌ groqAdminExecutiveSummary failed, falling back to mock");
        return $this->mockAdminExecutiveSummary($data);
    }

    // =============================================
    // GROQ: EXECUTIVE SUMMARY (Client Report)
    // =============================================

    private function groqExecutiveSummary($data) {
        $company = $data['company'] ?? 'Your Company';
        $totalJobs = $data['total_jobs'] ?? 0;
        $totalApps = $data['total_applications'] ?? 0;
        $totalHires = $data['total_hires'] ?? 0;
        $activeEmployees = $data['active_employees'] ?? 0;
        $conversionRate = $data['conversion_rate'] ?? 0;

        error_log("📝 groqExecutiveSummary for: $company");

        $prompt = "You are an expert HR business analyst. Provide an executive summary for this company's hiring data.

COMPANY: $company
Total Jobs: $totalJobs
Total Applications: $totalApps
Total Hires: $totalHires
Active Employees: $activeEmployees
Conversion Rate: $conversionRate%

Provide a JSON response with:
- summary: A 2-3 sentence executive summary (professional tone)
- insights: Array of 3-4 key insights (bullet points)
- recommendations: Array of 3-4 actionable recommendations
- trend_forecast: \"Growing\", \"Stable\", or \"Declining\"

Return ONLY valid JSON.";

        $response = $this->callGroq($prompt);

        if ($response && !isset($response['error'])) {
            error_log("✅ groqExecutiveSummary returned successfully");
            return [
                'summary' => $response['summary'] ?? 'No summary available',
                'insights' => $response['insights'] ?? [],
                'recommendations' => $response['recommendations'] ?? [],
                'trend_forecast' => $response['trend_forecast'] ?? 'Stable',
                'provider' => 'groq'
            ];
        }

        error_log("❌ groqExecutiveSummary failed, falling back to mock");
        return $this->mockExecutiveSummary($data);
    }

    // =============================================
    // GROQ: HIRING FORECAST
    // =============================================

    private function groqHiringForecast($data) {
        $historicalData = $data['historical_data'] ?? [];
        $periodMonths = $data['period_months'] ?? 3;

        $historyText = '';
        foreach ($historicalData as $item) {
            $historyText .= "- {$item['month']}: {$item['applications']} applications, {$item['hires']} hires\n";
        }

        if (empty($historyText)) {
            $historyText = "No historical data available.";
        }

        error_log("📝 groqHiringForecast called");

        $prompt = "You are an expert HR forecaster. Analyze this hiring data and provide a forecast.

HISTORICAL DATA (last 6 months):
$historyText

Provide a JSON response with:
- forecast: A 2-3 sentence forecast for the next $periodMonths months
- predicted_hires: Estimated number of hires in the next $periodMonths months
- confidence: \"High\", \"Medium\", or \"Low\"

Return ONLY valid JSON.";

        $response = $this->callGroq($prompt);

        if ($response && !isset($response['error'])) {
            error_log("✅ groqHiringForecast returned successfully");
            return [
                'forecast' => $response['forecast'] ?? 'Stable hiring expected',
                'predicted_hires' => $response['predicted_hires'] ?? 0,
                'confidence' => $response['confidence'] ?? 'Medium',
                'provider' => 'groq'
            ];
        }

        error_log("❌ groqHiringForecast failed, falling back to mock");
        return $this->mockHiringForecast($data);
    }

    // =============================================
    // GROQ: EMPLOYEE PERFORMANCE
    // =============================================

    private function groqEmployeePerformance($employeeData) {
        $name = $employeeData['name'] ?? 'Employee';
        $jobTitle = $employeeData['job_title'] ?? 'Unknown';
        $status = $employeeData['status'] ?? 'active';
        $tenureMonths = $employeeData['tenure_months'] ?? 12;

        error_log("📝 groqEmployeePerformance for: $name");

        $prompt = "You are an expert HR analyst. Analyze this employee's performance.

EMPLOYEE:
Name: $name
Job Title: $jobTitle
Status: $status
Tenure: $tenureMonths months

Provide a JSON response with:
- performance_score: integer (0-100)
- strengths: array of 3-5 strengths
- gaps: array of 2-4 development areas
- recommendations: array of 2-4 recommendations
- retention_risk: \"Low\", \"Medium\", or \"High\"
- skill_gaps: array of 2-4 skills to develop

Return ONLY valid JSON.";

        $response = $this->callGroq($prompt);

        if ($response && !isset($response['error'])) {
            error_log("✅ groqEmployeePerformance returned successfully");
            return [
                'performance_score' => $response['performance_score'] ?? 75,
                'strengths' => $response['strengths'] ?? ['Good communication', 'Team player'],
                'gaps' => $response['gaps'] ?? ['Could improve time management'],
                'recommendations' => $response['recommendations'] ?? ['Consider professional development'],
                'retention_risk' => $response['retention_risk'] ?? 'Low',
                'skill_gaps' => $response['skill_gaps'] ?? ['Leadership', 'Strategic thinking'],
                'provider' => 'groq'
            ];
        }

        error_log("❌ groqEmployeePerformance failed, falling back to mock");
        return $this->mockEmployeePerformance($employeeData);
    }

    // =============================================
    // GROQ: MATCH SCORE
    // =============================================

private function groqMatchScore($jobData, $applicantData) {
    $jobTitle = $jobData['title'] ?? $jobData['job_title'] ?? 'Unknown Position';
    $jobSkills = $jobData['skills_required'] ?? '';
    $jobDescription = $jobData['description'] ?? '';
    $applicantSkills = $applicantData['skills'] ?? '';
    $applicantExperience = $applicantData['experience'] ?? '';
    $applicantEducation = $applicantData['education'] ?? '';

    error_log("📝 groqMatchScore called for: $jobTitle");

    $prompt = "You are an expert HR recruiter. Analyze this job and applicant and provide a match score.

JOB:
Title: $jobTitle
Skills Required: $jobSkills
Description: $jobDescription

APPLICANT:
Skills: $applicantSkills
Experience: $applicantExperience
Education: $applicantEducation

Calculate a match score (0-100) based on:
1. Skills match (50% weight) - compare required skills vs applicant skills
2. Experience match (25% weight) - years and relevance
3. Education match (15% weight) - relevant degree/certifications
4. Overall fit (10% weight) - description alignment

Return a valid JSON object with these exact keys:
{
    \"score\": 75,
    \"level\": \"Good\",
    \"recommendation\": \"Candidate is a good match for this role.\",
    \"matched_skills\": [\"PHP\", \"JavaScript\", \"SQL\"],
    \"missing_skills\": [\"Python\", \"Docker\"],
    \"breakdown\": {
        \"skills\": 80,
        \"experience\": 70,
        \"education\": 65,
        \"overall\": 75
    }
}

IMPORTANT: Return ONLY valid JSON. No markdown, no explanation, no code blocks.";

    $response = $this->callGroq($prompt);

    if ($response && !isset($response['error'])) {
        $score = $response['score'] ?? 0;
        
        // Determine level based on score
        if ($score >= 85) $level = 'Excellent';
        elseif ($score >= 70) $level = 'Very Good';
        elseif ($score >= 55) $level = 'Good';
        elseif ($score >= 40) $level = 'Fair';
        else $level = 'Low';
        
        $matched = $response['matched_skills'] ?? [];
        $missing = $response['missing_skills'] ?? [];
        $breakdown = $response['breakdown'] ?? [];

        error_log("✅ groqMatchScore returned score: $score% for $jobTitle");

        // Return BOTH formats for compatibility
        return [
            // Format 1: For job_details.php
            'match_score' => $score,
            'strengths' => $matched,
            'gaps' => $missing,
            'recommendation' => $response['recommendation'] ?? 'Candidate matches the requirements.',
            'provider' => 'groq',
            // Format 2: For HR applicants.php
            'score' => $score,
            'level' => $level,
            'matched_skills' => $matched,
            'missing_skills' => $missing,
            'breakdown' => $breakdown,
            'details' => [
                'skills_match' => $breakdown['skills'] ?? 0,
                'experience_years' => $breakdown['experience'] ?? 0,
                'education_level' => 'Bachelor\'s Degree',
                'cover_letter_score' => $breakdown['overall'] ?? 0,
                'resume_bonus' => 0
            ]
        ];
    }

    error_log("❌ groqMatchScore failed, falling back to mock");
    return $this->mockMatchScore($jobData, $applicantData);
}

    // =============================================
    // GROQ: JOB INSIGHTS
    // =============================================

    private function groqJobInsights($jobData) {
        $title = $jobData['title'] ?? 'Unknown Position';
        $skills = is_array($jobData['skills'] ?? []) ? implode(', ', $jobData['skills'] ?? []) : ($jobData['skills'] ?? '');
        $experience = $jobData['experience_level'] ?? 'Mid';
        $location = $jobData['location'] ?? '';
        $jobType = $jobData['job_type'] ?? 'Full-time';

        error_log("📝 groqJobInsights called for: $title");

        $prompt = "You are an expert HR market analyst. Provide insights for this job posting.

JOB:
Title: $title
Skills: $skills
Experience Level: $experience
Location: $location
Job Type: $jobType

Provide a JSON response with:
- market_demand: string (High, Medium, Low)
- salary_range: string (e.g., '₱50,000 - ₱80,000')
- top_cities: array of 3-5 cities where this role is in demand
- trending_skills: array of 3-5 skills that are trending for this role
- recommendations: array of 3-5 recommendations for the employer

Return ONLY valid JSON.";

        $response = $this->callGroq($prompt);

        if ($response && !isset($response['error'])) {
            error_log("✅ groqJobInsights returned successfully");
            return [
                'success' => true,
                'market_demand' => $response['market_demand'] ?? 'Medium',
                'salary_range' => $response['salary_range'] ?? '₱50,000 - ₱80,000',
                'top_cities' => $response['top_cities'] ?? ['Manila', 'Cebu', 'Davao'],
                'trending_skills' => $response['trending_skills'] ?? ['Communication', 'Teamwork', 'Problem Solving'],
                'recommendations' => $response['recommendations'] ?? [
                    'Highlight company culture in the job posting',
                    'Offer competitive benefits package',
                    'Provide clear career growth path'
                ],
                'provider' => 'groq'
            ];
        }

        error_log("❌ groqJobInsights failed, falling back to mock");
        return $this->mockJobInsights($jobData);
    }

    // =============================================
    // GROQ: JOB OPTIMIZATION
    // =============================================

    private function groqJobOptimization($jobData) {
        $title = $jobData['title'] ?? '';
        $description = $jobData['description'] ?? '';
        $skills = $jobData['skills_required'] ?? '';
        $experience = $jobData['experience_level'] ?? 'Mid';

        error_log("📝 groqJobOptimization: $title");

        $prompt = "You are an expert HR recruiter. Optimize this job posting.

JOB TITLE: $title
CURRENT DESCRIPTION: $description
CURRENT SKILLS: $skills
EXPERIENCE LEVEL: $experience

IMPORTANT: Return ONLY a valid JSON object with exactly these fields:
- suggested_skills: array of recommended skills
- improved_description: enhanced job description text
- suggested_title: improved job title
- salary_range: salary range in Philippine Peso format
- salary_min: minimum salary (number)
- salary_max: maximum salary (number)
- diversity_check: object with warnings and suggestions arrays

Make sure to include at least 5-8 relevant skills for this role.";

        $response = $this->callGroq($prompt);

        if ($response && !isset($response['error'])) {
            $skills = $response['suggested_skills'] ?? [];
            if (!is_array($skills)) {
                $skills = [];
            }

            $diversity = $response['diversity_check'] ?? ['warnings' => [], 'suggestions' => []];
            if (!is_array($diversity)) {
                $diversity = ['warnings' => [], 'suggestions' => []];
            }

            error_log("✅ Groq returned valid response with " . count($skills) . " skills!");

            return [
                'suggested_skills' => $skills,
                'improved_description' => $response['improved_description'] ?? $description,
                'suggested_title' => $response['suggested_title'] ?? $title,
                'salary_range' => $response['salary_range'] ?? '₱50,000 - ₱80,000',
                'salary_min' => $response['salary_min'] ?? 50000,
                'salary_max' => $response['salary_max'] ?? 80000,
                'diversity_check' => [
                    'warnings' => $diversity['warnings'] ?? [],
                    'suggestions' => $diversity['suggestions'] ?? []
                ],
                'provider' => 'groq'
            ];
        }

        error_log("❌ Groq failed, using mock");
        return $this->mockJobOptimization($jobData);
    }

    // =============================================
    // GROQ: RESUME ANALYSIS
    // =============================================

    private function groqResumeAnalysis($text) {
        error_log("📝 groqResumeAnalysis called");

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
            error_log("✅ Groq resume analysis returned successfully");
            return [
                'skills' => $response['skills'] ?? [],
                'years_experience' => $response['years_experience'] ?? 0,
                'education' => $response['education'] ?? 'Not specified',
                'keywords' => $response['keywords'] ?? [],
                'summary' => $response['summary'] ?? '',
                'provider' => 'groq'
            ];
        }

        error_log("❌ groqResumeAnalysis failed, falling back to mock");
        return $this->mockResumeAnalysis($text);
    }

    // =============================================
    // GROQ: INTERVIEW QUESTIONS
    // =============================================

    private function groqInterviewQuestions($jobData) {
        $title = $jobData['title'] ?? 'the role';
        $description = $jobData['description'] ?? '';
        $skills = $jobData['skills_required'] ?? '';

        error_log("📝 groqInterviewQuestions for: $title");

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
            error_log("✅ Groq interview questions generated successfully");
            return [
                'technical' => $response['technical'] ?? ['What is your experience with the required technologies?'],
                'behavioral' => $response['behavioral'] ?? ['Describe a challenging situation you faced at work.'],
                'role_specific' => $response['role_specific'] ?? ['Why are you interested in this role?'],
                'provider' => 'groq'
            ];
        }

        error_log("❌ groqInterviewQuestions failed, falling back to mock");
        return $this->mockInterviewQuestions($jobData);
    }

    // =============================================
    // GEMINI API METHODS (Fallback)
    // =============================================

    private function geminiJobOptimization($jobData) {
        return $this->mockJobOptimization($jobData);
    }

    private function geminiMatchScore($jobData, $applicantData) {
        return $this->mockMatchScore($jobData, $applicantData);
    }

    private function geminiResumeAnalysis($text) {
        return $this->mockResumeAnalysis($text);
    }

    private function geminiInterviewQuestions($jobData) {
        return $this->mockInterviewQuestions($jobData);
    }

    private function geminiJobInsights($jobData) {
        return $this->mockJobInsights($jobData);
    }

    private function geminiEmployeePerformance($employeeData) {
        return $this->mockEmployeePerformance($employeeData);
    }

    private function geminiExecutiveSummary($data) {
        return $this->mockExecutiveSummary($data);
    }

    private function geminiHiringForecast($data) {
        return $this->mockHiringForecast($data);
    }

    private function geminiAdminExecutiveSummary($data) {
        return $this->mockAdminExecutiveSummary($data);
    }

    private function geminiDashboardInsights($data) {
        return $this->mockDashboardInsights($data);
    }

    // =============================================
    // MOCK FUNCTIONS (Fallback)
    // =============================================

private function mockMatchScore($jobData, $applicantData) {
    // Extract job skills - handle both string and array formats
    $jobSkills = $jobData['skills_required'] ?? $jobData['job_skills'] ?? [];
    if (is_string($jobSkills)) {
        $jobSkills = array_map('trim', explode(',', $jobSkills));
    }
    $jobSkills = array_map('strtolower', $jobSkills);
    $jobSkills = array_filter($jobSkills);

    // Extract applicant skills - handle both string and array formats
    $applicantSkills = $applicantData['skills'] ?? $applicantData['applicant_skills'] ?? [];
    if (is_string($applicantSkills)) {
        $applicantSkills = array_map('trim', explode(',', $applicantSkills));
    }
    $applicantSkills = array_map('strtolower', $applicantSkills);
    $applicantSkills = array_filter($applicantSkills);

    $matchedSkills = array_intersect($jobSkills, $applicantSkills);
    $missingSkills = array_diff($jobSkills, $applicantSkills);
    $totalJobSkills = count($jobSkills);

    // Calculate score
    $score = $totalJobSkills > 0 ? round((count($matchedSkills) / $totalJobSkills) * 100) : 0;
    $score = min($score, 100);

    // Determine level
    if ($score >= 85) $level = 'Excellent';
    elseif ($score >= 70) $level = 'Very Good';
    elseif ($score >= 55) $level = 'Good';
    elseif ($score >= 40) $level = 'Fair';
    else $level = 'Low';

    // Generate recommendation
    if ($score >= 80) {
        $recommendation = 'Highly recommended - Strong match for this position.';
    } elseif ($score >= 60) {
        $recommendation = 'Recommended - Good fit with some areas to explore.';
    } elseif ($score >= 40) {
        $recommendation = 'Consider - Has potential but may need additional training.';
    } else {
        $recommendation = 'Not recommended at this time - Significant gaps in required skills.';
    }

    // Return BOTH formats for compatibility
    return [
        // Format 1: For job_details.php and other applicant-side pages
        'match_score' => $score,
        'strengths' => array_values($matchedSkills),
        'gaps' => array_values($missingSkills),
        'recommendation' => $recommendation,
        'provider' => 'mock',
        // Format 2: For HR applicants.php page
        'score' => $score,
        'level' => $level,
        'matched_skills' => array_values($matchedSkills),
        'missing_skills' => array_values($missingSkills),
        'breakdown' => [
            'skills' => $score,
            'experience' => max(0, $score - rand(0, 20)),
            'education' => max(0, $score - rand(0, 30)),
            'overall' => $score
        ],
        'details' => [
            'skills_match' => $score,
            'experience_years' => rand(1, 10),
            'education_level' => 'Bachelor\'s Degree',
            'cover_letter_score' => max(0, $score - rand(0, 40)),
            'resume_bonus' => rand(0, 5)
        ]
    ];
}

    private function mockEmployeePerformance($employeeData) {
        $performanceScores = [72, 78, 82, 85, 88, 91, 94];
        $risks = ['Low', 'Low', 'Medium', 'Medium', 'High'];
        $strengthsOptions = [
            ['Good communication skills', 'Team player', 'Reliable'],
            ['Strong technical skills', 'Problem solver', 'Self-motivated'],
            ['Leadership qualities', 'Mentors others', 'Adaptable'],
            ['Excellent work ethic', 'Detail-oriented', 'Collaborative'],
            ['Creative thinker', 'Efficient', 'Great with clients']
        ];
        $gapsOptions = [
            ['Could improve time management', 'Needs more experience with new technologies'],
            ['Communication with stakeholders', 'Delegation skills'],
            ['Presentation skills', 'Documentation habits'],
            ['Conflict resolution', 'Strategic thinking'],
            ['Public speaking', 'Advanced technical skills']
        ];
        $recommendationsOptions = [
            ['Consider professional development courses', 'Set clear career goals'],
            ['Mentorship program', 'Cross-training opportunities'],
            ['Leadership training', 'Project management certification'],
            ['Skill development workshops', 'Regular feedback sessions'],
            ['Career path planning', 'Recognition programs']
        ];
        $skillGapsOptions = [
            ['Advanced communication', 'Leadership', 'Strategic planning'],
            ['Technical writing', 'Public speaking', 'Project management'],
            ['Data analysis', 'Critical thinking', 'Decision making'],
            ['Team leadership', 'Conflict resolution', 'Negotiation'],
            ['Innovation', 'Change management', 'Coaching']
        ];

        $idx = array_rand($performanceScores);

        return [
            'performance_score' => $performanceScores[$idx],
            'strengths' => $strengthsOptions[$idx % count($strengthsOptions)],
            'gaps' => $gapsOptions[$idx % count($gapsOptions)],
            'recommendations' => $recommendationsOptions[$idx % count($recommendationsOptions)],
            'retention_risk' => $risks[$idx % count($risks)],
            'skill_gaps' => $skillGapsOptions[$idx % count($skillGapsOptions)],
            'provider' => 'mock'
        ];
    }

    private function mockExecutiveSummary($data) {
        $totalApps = $data['total_applications'] ?? 0;
        $totalHires = $data['total_hires'] ?? 0;
        $conversionRate = ($totalApps > 0) ? round(($totalHires / $totalApps) * 100) : 0;
        $activeEmployees = $data['active_employees'] ?? 0;
        $totalJobs = $data['total_jobs'] ?? 0;
        $company = $data['company'] ?? 'Your Company';

        $insights = [];
        $recommendations = [];
        $trendForecast = 'Stable';

        if ($totalApps > 10) {
            $insights[] = "You have received {$totalApps} applications across {$totalJobs} jobs, showing good market engagement.";
        } else {
            $insights[] = "Consider increasing your job visibility to attract more applications.";
        }

        if ($conversionRate > 10) {
            $insights[] = "Your conversion rate of {$conversionRate}% is healthy.";
            $recommendations[] = "Continue with your current hiring strategy.";
        } elseif ($conversionRate > 5) {
            $insights[] = "Your conversion rate of {$conversionRate}% is moderate.";
            $recommendations[] = "Consider reviewing your shortlisting criteria to improve conversions.";
        } else {
            $insights[] = "Your conversion rate of {$conversionRate}% is below average.";
            $recommendations[] = "Review your job descriptions and interview process to improve conversions.";
        }

        if ($activeEmployees > 5) {
            $insights[] = "You have {$activeEmployees} active employees, indicating a healthy workforce.";
        }

        if ($totalJobs > 5) {
            $recommendations[] = "Consider consolidating similar job roles to improve efficiency.";
        }

        if (empty($recommendations)) {
            $recommendations = [
                "Post jobs on multiple platforms to reach more candidates",
                "Consider offering employee referral bonuses",
                "Review your job descriptions for clarity and attractiveness",
                "Track your hiring metrics regularly to identify trends"
            ];
        }

        if ($totalApps > 20) {
            $trendForecast = 'Growing';
        } elseif ($totalApps > 5) {
            $trendForecast = 'Stable';
        } else {
            $trendForecast = 'Declining';
        }

        $summary = "{$company} has received {$totalApps} applications for {$totalJobs} jobs in the last 30 days, with {$totalHires} hires and {$activeEmployees} active employees. " .
                   "The conversion rate is {$conversionRate}%. " .
                   "The overall trend is {$trendForecast}.";

        return [
            'summary' => $summary,
            'insights' => $insights,
            'recommendations' => $recommendations,
            'trend_forecast' => $trendForecast,
            'provider' => 'mock'
        ];
    }

    private function mockHiringForecast($data) {
        $historicalData = $data['historical_data'] ?? [];
        $periodMonths = $data['period_months'] ?? 3;

        $avgApplications = 0;
        $avgHires = 0;
        $count = count($historicalData);

        if ($count > 0) {
            $totalApps = array_sum(array_column($historicalData, 'applications'));
            $totalHires = array_sum(array_column($historicalData, 'hires'));
            $avgApplications = round($totalApps / $count);
            $avgHires = round($totalHires / $count);
        }

        $predictedHires = max(1, round($avgHires * 1.1));
        $confidence = 'Medium';

        if ($count >= 6) {
            $confidence = 'High';
        } elseif ($count >= 3) {
            $confidence = 'Medium';
        } else {
            $confidence = 'Low';
        }

        $forecast = "Based on historical data with {$count} months of history, hiring is expected to remain " .
                   ($avgHires > 2 ? 'strong' : 'moderate') . " over the next {$periodMonths} months. " .
                   "The average of {$avgHires} hires per month suggests approximately {$predictedHires} hires in the forecast period.";

        return [
            'forecast' => $forecast,
            'predicted_hires' => $predictedHires,
            'confidence' => $confidence,
            'provider' => 'mock'
        ];
    }

    private function mockAdminExecutiveSummary($data) {
        $totalClients = $data['total_clients'] ?? 0;
        $totalAgencies = $data['total_agencies'] ?? 0;
        $totalJobs = $data['total_jobs'] ?? 0;
        $totalApplications = $data['total_applications'] ?? 0;
        $totalUsers = $data['total_users'] ?? 0;
        $onlineUsers = $data['online_users'] ?? 0;
        $pendingAgencies = $data['pending_agencies'] ?? 0;
        $activeClients = $data['active_clients'] ?? 0;

        $insights = [];
        $recommendations = [];
        $healthScore = 85;
        $trendForecast = 'Stable';

        if ($totalClients > 10) {
            $insights[] = "You have {$totalClients} active clients, showing strong platform adoption.";
            $healthScore += 5;
        } elseif ($totalClients > 0) {
            $insights[] = "You have {$totalClients} clients. Consider expanding your outreach.";
            $healthScore += 2;
        } else {
            $insights[] = "No clients registered yet. Start onboarding clients to grow your platform.";
            $healthScore -= 10;
            $recommendations[] = "Launch a client acquisition campaign to onboard new clients.";
        }

        if ($totalAgencies > 5) {
            $insights[] = "{$totalAgencies} agencies are approved and active on the platform.";
            $healthScore += 3;
        } else {
            if ($pendingAgencies > 0) {
                $insights[] = "{$pendingAgencies} agency applications are pending review.";
                $recommendations[] = "Review pending agency applications to expand your agency network.";
            }
        }

        if ($totalJobs > 20) {
            $insights[] = "{$totalJobs} jobs posted across the platform, indicating healthy activity.";
            $healthScore += 5;
        } elseif ($totalJobs > 0) {
            $insights[] = "{$totalJobs} jobs posted. Encourage more clients to post jobs.";
            $recommendations[] = "Send a reminder to clients to post new job openings.";
        }

        if ($totalApplications > 50) {
            $insights[] = "{$totalApplications} applications received, showing strong candidate engagement.";
            $healthScore += 5;
        } elseif ($totalApplications > 0) {
            $insights[] = "{$totalApplications} applications received. Consider promoting job postings.";
            $recommendations[] = "Run a marketing campaign to attract more applicants.";
        }

        if ($onlineUsers > 5) {
            $insights[] = "{$onlineUsers} users are currently online, showing good platform engagement.";
            $healthScore += 3;
        }

        if ($healthScore > 90) $healthScore = 92;
        if ($healthScore < 40) $healthScore = 45;

        if ($totalJobs > 30 && $totalApplications > 60) {
            $trendForecast = 'Growing';
        } elseif ($totalJobs > 10 || $totalApplications > 20) {
            $trendForecast = 'Stable';
        } else {
            $trendForecast = 'Needs Attention';
        }

        if (empty($recommendations)) {
            $recommendations = [
                "Monitor key metrics regularly to identify trends early.",
                "Engage with clients to understand their hiring needs.",
                "Review agency performance to optimize partnerships."
            ];
        }

        $summary = "ISMERS platform is operating with {$totalUsers} users, {$totalClients} clients, and {$totalAgencies} agencies. " .
                   "There are {$totalJobs} active jobs and {$totalApplications} applications. " .
                   "The overall health score is {$healthScore}% with a {$trendForecast} trend.";

        return [
            'summary' => $summary,
            'insights' => $insights,
            'recommendations' => $recommendations,
            'health_score' => $healthScore,
            'trend_forecast' => $trendForecast,
            'provider' => 'mock'
        ];
    }

    private function mockDashboardInsights($data) {
        $totalUsers = $data['total_users'] ?? 0;
        $onlineUsers = $data['online_users'] ?? 0;
        $totalJobs = $data['total_jobs'] ?? 0;
        $totalApplications = $data['total_applications'] ?? 0;
        $totalClients = $data['total_clients'] ?? 0;
        $totalEmployees = $data['total_employees'] ?? 0;

        $insights = [];
        $recommendations = [];
        $anomalies = [];
        $healthScore = 85;
        $status = 'Good';
        $trend = 'Stable';

        // User engagement insights
        if ($totalUsers > 0) {
            $engagementRate = round(($onlineUsers / $totalUsers) * 100);
            if ($engagementRate > 20) {
                $insights[] = "{$engagementRate}% of users are currently active, showing strong engagement.";
                $healthScore += 5;
            } elseif ($engagementRate > 5) {
                $insights[] = "{$engagementRate}% of users are active. Consider ways to boost engagement.";
                $healthScore += 2;
            } else {
                $insights[] = "Low user engagement at {$engagementRate}%. Consider sending notifications or updates.";
                $healthScore -= 5;
                $recommendations[] = "Send a platform update newsletter to re-engage users.";
            }
        }

        // Job activity insights
        if ($totalJobs > 10) {
            $insights[] = "{$totalJobs} jobs posted, indicating healthy platform activity.";
            $healthScore += 5;
        } elseif ($totalJobs > 0) {
            $insights[] = "{$totalJobs} jobs posted. Encourage more job postings.";
            $recommendations[] = "Reach out to clients to post new job openings.";
        } else {
            $insights[] = "No jobs posted yet. Consider promoting the platform to attract clients.";
            $healthScore -= 10;
            $recommendations[] = "Launch a marketing campaign to attract clients and job postings.";
        }

        // Application insights
        if ($totalApplications > 20) {
            $insights[] = "{$totalApplications} applications received, showing strong candidate interest.";
            $healthScore += 5;
        } elseif ($totalApplications > 0) {
            $insights[] = "{$totalApplications} applications received. Consider promoting open jobs.";
            $recommendations[] = "Run targeted ads for open positions to attract more applicants.";
        } else {
            $insights[] = "No applications received. Review your job postings and visibility.";
            $healthScore -= 5;
            $recommendations[] = "Review and optimize job descriptions to attract more applicants.";
        }

        // Client and employee insights
        if ($totalClients > 0) {
            $insights[] = "{$totalClients} active clients on the platform.";
        }
        if ($totalEmployees > 0) {
            $insights[] = "{$totalEmployees} employees deployed across clients.";
        }

        // Anomaly detection
        if ($totalUsers > 0 && $onlineUsers == 0) {
            $anomalies[] = "No users are currently online despite having {$totalUsers} registered users.";
            $healthScore -= 5;
        }
        if ($totalJobs > 0 && $totalApplications == 0) {
            $anomalies[] = "There are {$totalJobs} jobs but no applications received yet.";
            $healthScore -= 3;
        }

        // Health score adjustments
        if ($healthScore > 90) $healthScore = 92;
        if ($healthScore < 40) $healthScore = 45;

        // Status based on health score
        if ($healthScore >= 80) {
            $status = 'Excellent';
            $trend = 'Growing';
        } elseif ($healthScore >= 60) {
            $status = 'Good';
            $trend = 'Stable';
        } elseif ($healthScore >= 40) {
            $status = 'Fair';
            $trend = 'Needs Attention';
        } else {
            $status = 'At Risk';
            $trend = 'Declining';
        }

        // General recommendations
        if (empty($recommendations)) {
            $recommendations = [
                "Monitor key metrics weekly to identify trends early.",
                "Engage with clients to understand their hiring needs better.",
                "Consider adding new features to improve user experience."
            ];
        }

        return [
            'health_score' => $healthScore,
            'status' => $status,
            'insights' => array_slice($insights, 0, 4),
            'recommendations' => array_slice($recommendations, 0, 4),
            'anomalies' => $anomalies,
            'trend' => $trend,
            'provider' => 'mock'
        ];
    }

    private function mockJobInsights($jobData) {
        $title = $jobData['title'] ?? 'Unknown Position';
        $skills = $jobData['skills'] ?? [];
        if (is_string($skills)) {
            $skills = array_map('trim', explode(',', $skills));
        }

        $insightsMap = [
            'developer' => [
                'market_demand' => 'High',
                'salary_range' => '₱60,000 - ₱120,000',
                'top_cities' => ['Manila', 'Cebu', 'Davao', 'BGC', 'Makati'],
                'trending_skills' => ['React', 'Node.js', 'Python', 'AWS', 'TypeScript'],
                'recommendations' => [
                    'Offer remote work options to attract top talent',
                    'Include a competitive benefits package',
                    'Highlight opportunities for professional growth',
                    'Consider offering a signing bonus'
                ]
            ],
            'designer' => [
                'market_demand' => 'Medium',
                'salary_range' => '₱45,000 - ₱85,000',
                'top_cities' => ['Manila', 'BGC', 'Makati', 'Cebu'],
                'trending_skills' => ['Figma', 'UI/UX', 'Design Systems', 'Adobe XD', 'Prototyping'],
                'recommendations' => [
                    'Showcase your design portfolio in the job posting',
                    'Highlight the creative freedom and culture',
                    'Offer design tool subscriptions',
                    'Provide mentorship opportunities'
                ]
            ],
            'manager' => [
                'market_demand' => 'Medium',
                'salary_range' => '₱80,000 - ₱150,000',
                'top_cities' => ['Manila', 'BGC', 'Makati', 'Cebu', 'Clark'],
                'trending_skills' => ['Leadership', 'Agile', 'Strategic Planning', 'Communication', 'Project Management'],
                'recommendations' => [
                    'Emphasize the impact this role will have',
                    'Highlight the team size and structure',
                    'Offer leadership development programs',
                    'Competitive performance bonuses'
                ]
            ]
        ];

        $insights = [
            'market_demand' => 'Medium',
            'salary_range' => '₱50,000 - ₱90,000',
            'top_cities' => ['Manila', 'Cebu', 'Davao', 'BGC', 'Makati'],
            'trending_skills' => ['Communication', 'Teamwork', 'Problem Solving', 'Adaptability', 'Leadership'],
            'recommendations' => [
                'Highlight company culture and values',
                'Offer competitive benefits package',
                'Provide clear career advancement paths',
                'Consider flexible work arrangements'
            ]
        ];

        foreach ($insightsMap as $keyword => $data) {
            if (stripos($title, $keyword) !== false) {
                $insights = $data;
                break;
            }
        }

        return [
            'success' => true,
            'market_demand' => $insights['market_demand'],
            'salary_range' => $insights['salary_range'],
            'top_cities' => $insights['top_cities'],
            'trending_skills' => $insights['trending_skills'],
            'recommendations' => $insights['recommendations'],
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
            'salary_min' => 50000,
            'salary_max' => 80000,
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
?>