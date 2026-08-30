<?php
// portals/applicant/edit_profile.php - Edit Profile with AI Resume Analysis
// ✅ FULLY SECURE with PostgreSQL compatibility

session_start();

// Include configuration file
require_once '../../app/config.php';
initSessionTimeout();
require_once '../../app/ai/AiService.php';

// =============================================
// FIX: Check if constants are already defined before defining them
// =============================================
if (!defined('MAX_FILE_SIZE')) {
    define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
}
if (!defined('MAX_USER_STORAGE')) {
    define('MAX_USER_STORAGE', 20 * 1024 * 1024); // 20MB per user
}
if (!defined('ALLOWED_EXTENSIONS')) {
    define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'txt']);
}
if (!defined('ALLOWED_MIME_TYPES')) {
    define('ALLOWED_MIME_TYPES', [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain'
    ]);
}
if (!defined('MAX_UPLOADS_PER_MINUTE')) {
    define('MAX_UPLOADS_PER_MINUTE', 3);
}

// =============================================
// LOAD PDF PARSER - FIXED WITH FALLBACKS
// =============================================

// Try autoloader first
$autoloadPaths = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../autoload.php',
    __DIR__ . '/vendor/autoload.php'
];

$autoloadLoaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloadLoaded = true;
        break;
    }
}

// If autoloader didn't work, try direct includes
if (!class_exists('\Smalot\PdfParser\Parser') && !class_exists('Smalot\PdfParser\Parser')) {
    $directPaths = [
        __DIR__ . '/../../vendor/smalot/pdfparser/src/Parser.php',
        __DIR__ . '/../../vendor/smalot/pdfparser/src/Smalot/PdfParser/Parser.php',
        __DIR__ . '/../../smalot/pdfparser/src/Parser.php',
        __DIR__ . '/../../smalot/pdfparser/src/Smalot/PdfParser/Parser.php'
    ];
    
    foreach ($directPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
}

// Also try to include PHPMailer if needed elsewhere
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    $mailPaths = [
        __DIR__ . '/../../PHPMailer-master/src/PHPMailer.php',
        __DIR__ . '/../../vendor/phpmailer/phpmailer/src/PHPMailer.php'
    ];
    foreach ($mailPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
}

// Check if PDF parser is available
$pdfParserAvailable = class_exists('\Smalot\PdfParser\Parser') || class_exists('Smalot\PdfParser\Parser');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has the correct role
if ($_SESSION['role'] !== 'applicant') {
    header('Location: ../../login.php');
    exit;
}

// Get user data
$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Applicant';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';

// Get applicant data
$applicant = getApplicantByUserId($userId);
$applicantId = $applicant['id'] ?? 0;

// =============================================
// SECURE FILE VALIDATION FUNCTIONS
// =============================================

/**
 * Validate file extension against allowed list
 */
function isValidExtension($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, ALLOWED_EXTENSIONS);
}

/**
 * Validate MIME type using finfo
 */
function isValidMimeType($filepath) {
    if (!function_exists('finfo_open')) {
        // Fallback to mime_content_type if finfo not available
        $mime = mime_content_type($filepath);
        return in_array($mime, ALLOWED_MIME_TYPES);
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filepath);
    finfo_close($finfo);
    
    return in_array($mime, ALLOWED_MIME_TYPES);
}

/**
 * Validate file signature (magic bytes)
 */
function isValidFileSignature($filepath, $extension) {
    $handle = fopen($filepath, 'rb');
    if (!$handle) return false;
    
    $bytes = fread($handle, 8);
    fclose($handle);
    
    if (empty($bytes)) return false;
    
    $hex = bin2hex($bytes);
    
    $signatures = [
        'pdf' => ['25504446'], // %PDF
        'doc' => ['d0cf11e0a1b11ae1'], // DOC/PPT/XLS
        'docx' => ['504b0304'], // ZIP (DOCX)
        'txt' => []
    ];
    
    if (!isset($signatures[$extension])) {
        return false;
    }
    
    // TXT files don't have a specific signature
    if ($extension === 'txt') {
        return true;
    }
    
    foreach ($signatures[$extension] as $sig) {
        if (strpos($hex, $sig) === 0) {
            return true;
        }
    }
    
    return false;
}

/**
 * Scan file content for malicious patterns
 */
function scanFileContent($filepath) {
    $content = file_get_contents($filepath);
    if (empty($content)) return true;
    
    $patterns = [
        '/(<\?php|<\?=)/i',
        '/eval\s*\(/i',
        '/base64_decode\s*\(/i',
        '/system\s*\(/i',
        '/exec\s*\(/i',
        '/shell_exec\s*\(/i',
        '/passthru\s*\(/i',
        '/popen\s*\(/i',
        '/proc_open\s*\(/i',
        '/assert\s*\(/i',
        '/include\s*\(/i',
        '/require\s*\(/i',
        '/file_get_contents\s*\(/i',
        '/fopen\s*\(/i',
        '/curl_exec\s*\(/i',
        '/(\bwget\b|\bcurl\b|\bftp\b)/i'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content)) {
            error_log("Suspicious content detected in file: " . basename($filepath));
            return false;
        }
    }
    
    return true;
}

/**
 * Check rate limit for uploads
 */
function checkUploadRateLimit($userId) {
    $result = getRecord(
        "SELECT COUNT(*) as count FROM upload_logs 
         WHERE user_id = $1 AND created_at > NOW() - INTERVAL '1 minute'",
        [$userId]
    );
    $count = $result ? (int)$result['count'] : 0;
    return $count < MAX_UPLOADS_PER_MINUTE;
}

/**
 * Log upload attempt
 */
function logUploadAttempt($userId, $filename, $status, $error = null) {
    $sql = "INSERT INTO upload_logs (user_id, filename, status, error_message, created_at) 
            VALUES ($1, $2, $3, $4, NOW())";
    return insertRecord($sql, [$userId, $filename, $status, $error]);
}

/**
 * Securely handle file upload
 */
function secureUploadResume($file, $userId, $applicantId) {
    global $conn;
    
    // 1. Check upload error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.'
        ];
        return ['success' => false, 'error' => $errors[$file['error']] ?? 'Unknown upload error.'];
    }
    
    // 2. Check rate limit
    if (!checkUploadRateLimit($userId)) {
        return ['success' => false, 'error' => 'Too many uploads. Please wait a moment.'];
    }
    
    // 3. Validate file size
    if ($file['size'] > MAX_FILE_SIZE) {
        logUploadAttempt($userId, $file['name'], 'failed', 'File size exceeds limit');
        return ['success' => false, 'error' => 'File size exceeds ' . (MAX_FILE_SIZE / 1024 / 1024) . 'MB limit.'];
    }
    
    if ($file['size'] === 0) {
        logUploadAttempt($userId, $file['name'], 'failed', 'Empty file');
        return ['success' => false, 'error' => 'Empty file uploaded.'];
    }
    
    // 4. Validate extension
    $filename = $file['name'];
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    if (!isValidExtension($filename)) {
        logUploadAttempt($userId, $filename, 'failed', 'Invalid extension');
        return ['success' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS)];
    }
    
    // 5. Create secure upload directory
    $uploadDir = __DIR__ . '/../../uploads/resumes/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create upload directory.'];
        }
    }
    
    // 6. Check if directory is writable
    if (!is_writable($uploadDir)) {
        return ['success' => false, 'error' => 'Upload directory is not writable.'];
    }
    
    // 7. Generate unique filename (prevent overwrites)
    $newFilename = 'resume_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $filepath = $uploadDir . $newFilename;
    
    // 8. Move file to destination
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        logUploadAttempt($userId, $filename, 'failed', 'Move failed');
        return ['success' => false, 'error' => 'Failed to move uploaded file.'];
    }
    
    // 9. Validate MIME type (using finfo)
    if (!isValidMimeType($filepath)) {
        unlink($filepath);
        logUploadAttempt($userId, $filename, 'failed', 'Invalid MIME type');
        return ['success' => false, 'error' => 'Invalid file content type. Only PDF, DOC, DOCX, and TXT files are allowed.'];
    }
    
    // 10. Validate file signature (magic bytes)
    if (!isValidFileSignature($filepath, $extension)) {
        unlink($filepath);
        logUploadAttempt($userId, $filename, 'failed', 'Invalid file signature');
        return ['success' => false, 'error' => 'File appears to be corrupted or not a valid document.'];
    }
    
    // 11. Scan for malicious content
    if (!scanFileContent($filepath)) {
        unlink($filepath);
        logUploadAttempt($userId, $filename, 'failed', 'Malicious content detected');
        return ['success' => false, 'error' => 'File contains suspicious content and has been rejected.'];
    }
    
    // 12. Check if file is readable
    if (!is_readable($filepath)) {
        unlink($filepath);
        return ['success' => false, 'error' => 'Uploaded file cannot be read.'];
    }
    
    // 13. Calculate total user storage
    $userUploads = getRecords(
        "SELECT resume_path FROM applicants WHERE id = $1 AND resume_path IS NOT NULL",
        [$applicantId]
    );
    
    $totalUserStorage = $file['size'];
    foreach ($userUploads as $upload) {
        $oldPath = __DIR__ . '/../../' . $upload['resume_path'];
        if (file_exists($oldPath) && $oldPath !== $filepath) {
            $totalUserStorage += filesize($oldPath);
        }
    }
    
    // Max storage per user
    if ($totalUserStorage > MAX_USER_STORAGE) {
        unlink($filepath);
        logUploadAttempt($userId, $filename, 'failed', 'Storage limit exceeded');
        return ['success' => false, 'error' => 'Total storage limit exceeded (' . (MAX_USER_STORAGE / 1024 / 1024) . 'MB max per user). Please delete old files.'];
    }
    
    // 14. Delete old resume file if exists
    if ($applicantId) {
        $oldResume = getRecord("SELECT resume_path FROM applicants WHERE id = $1", [$applicantId]);
        if ($oldResume && !empty($oldResume['resume_path'])) {
            $oldPath = __DIR__ . '/../../' . $oldResume['resume_path'];
            if (file_exists($oldPath) && is_file($oldPath) && $oldPath !== $filepath) {
                unlink($oldPath);
            }
        }
    }
    
    // 15. Log successful upload
    logUploadAttempt($userId, $filename, 'success');
    
    // 16. Return success with file path
    return [
        'success' => true,
        'filepath' => $filepath,
        'filename' => $newFilename,
        'relative_path' => 'uploads/resumes/' . $newFilename,
        'size' => $file['size'],
        'extension' => $extension,
        'original_name' => $filename
    ];
}

// =============================================
// GET PENDING OFFERS COUNT FOR SIDEBAR BADGE
// =============================================
$pendingOffers = 0;
if ($applicantId) {
    $offersResult = getRecord("
        SELECT COUNT(*) as count FROM offers o
        JOIN applications a ON o.application_id = a.id
        WHERE a.applicant_id = $1 AND o.status = 'sent'
    ", [$applicantId]);
    $pendingOffers = (int)($offersResult['count'] ?? 0);
}

// Get applications count for the badge
$applications = [];
if ($applicantId) {
    $applications = getApplicationsByApplicant($applicantId);
}
$totalApplications = count($applications);

// Get user data
$user = getUserById($userId);

// Get applicant interests
$interests = [];
if ($applicantId) {
    $interests = getApplicantInterests($applicantId);
}

// Initialize variables
$successMessage = '';
$errorMessage = '';
$aiAnalysisResult = null;
$aiError = '';
$resumeAnalysisResult = null;
$resumeUploadError = '';
$showAIFilledData = false;
$aiFilledData = [];

// =============================================
// AI RESUME ANALYSIS
// =============================================
function analyzeProfileWithAI($applicantData) {
    try {
        // Build resume text from all fields
        $resumeText = "";
        
        if (!empty($applicantData['career_objective'])) {
            $resumeText .= "CAREER OBJECTIVE: " . $applicantData['career_objective'] . "\n\n";
        }
        
        if (!empty($applicantData['skills'])) {
            $resumeText .= "SKILLS: " . $applicantData['skills'] . "\n\n";
        }
        
        if (!empty($applicantData['experience'])) {
            $resumeText .= "EXPERIENCE: " . $applicantData['experience'] . "\n\n";
        }
        
        if (!empty($applicantData['education'])) {
            $resumeText .= "EDUCATION: " . $applicantData['education'] . "\n\n";
        }
        
        if (strlen(trim($resumeText)) < 50) {
            return [
                'success' => false,
                'error' => 'Profile is too short for AI analysis. Add more details to get insights.'
            ];
        }
        
        $aiService = new AiService();
        $analysis = $aiService->analyzeResume($resumeText);
        
        if (empty($analysis['skills']) && empty($analysis['summary'])) {
            return [
                'success' => false,
                'error' => 'AI analysis returned no data. Please try again.'
            ];
        }
        
        return [
            'success' => true,
            'data' => $analysis,
            'provider' => $analysis['provider'] ?? 'unknown'
        ];
        
    } catch (Exception $e) {
        error_log("AI Analysis Error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => 'AI analysis failed: ' . $e->getMessage()
        ];
    }
}

/**
 * Extract text from uploaded resume file
 */
function extractResumeText($filePath) {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $text = '';
    
    try {
        if ($extension === 'pdf') {
            // Try using the Parser with full namespace
            if (class_exists('\Smalot\PdfParser\Parser')) {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText();
                $text = preg_replace('/\s+/', ' ', $text);
                $text = trim($text);
                return $text;
            }
            
            // Try without leading backslash
            if (class_exists('Smalot\PdfParser\Parser')) {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($filePath);
                $text = $pdf->getText();
                $text = preg_replace('/\s+/', ' ', $text);
                $text = trim($text);
                return $text;
            }
            
            // If class doesn't exist, try direct include
            $possiblePaths = [
                __DIR__ . '/../../vendor/smalot/pdfparser/src/Parser.php',
                __DIR__ . '/../../vendor/smalot/pdfparser/src/Smalot/PdfParser/Parser.php',
                __DIR__ . '/../../smalot/pdfparser/src/Parser.php',
                __DIR__ . '/../../smalot/pdfparser/src/Smalot/PdfParser/Parser.php'
            ];
            
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    require_once $path;
                    if (class_exists('\Smalot\PdfParser\Parser')) {
                        $parser = new \Smalot\PdfParser\Parser();
                        $pdf = $parser->parseFile($filePath);
                        $text = $pdf->getText();
                        $text = preg_replace('/\s+/', ' ', $text);
                        $text = trim($text);
                        return $text;
                    }
                }
            }
            
            error_log("PDF Parser could not be loaded for file: " . $filePath);
            return null;
        }
        elseif ($extension === 'docx') {
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($filePath) === true) {
                    $content = $zip->getFromName('word/document.xml');
                    $zip->close();
                    if ($content) {
                        $text = strip_tags($content);
                        $text = preg_replace('/\s+/', ' ', $text);
                        $text = trim($text);
                    }
                }
            }
            return $text;
        }
        elseif ($extension === 'doc') {
            if (function_exists('exec') && exec("which antiword 2>/dev/null")) {
                $text = shell_exec("antiword " . escapeshellarg($filePath) . " 2>/dev/null");
            }
            if (empty($text)) {
                $text = file_get_contents($filePath);
                $text = preg_replace('/[^\x20-\x7E\x0A\x0D]/', ' ', $text);
                $text = preg_replace('/\s+/', ' ', $text);
                $text = trim($text);
            }
            return $text;
        }
        elseif ($extension === 'txt') {
            $text = file_get_contents($filePath);
            return $text;
        }
    } catch (Exception $e) {
        error_log("Text extraction error: " . $e->getMessage());
        return null;
    }
    
    return null;
}

/**
 * Parse resume text and fill profile fields
 */
function parseResumeWithAI($resumeText) {
    try {
        if (strlen(trim($resumeText)) < 50) {
            return [
                'success' => false,
                'error' => 'Resume text is too short. Please upload a complete resume.'
            ];
        }
        
        $aiService = new AiService();
        $analysis = $aiService->analyzeResume($resumeText);
        
        if (empty($analysis['skills']) && empty($analysis['summary'])) {
            return [
                'success' => false,
                'error' => 'AI could not parse the resume. Please try again.'
            ];
        }
        
        $profileData = [];
        
        if (!empty($analysis['skills'])) {
            $profileData['skills'] = implode(', ', $analysis['skills']);
        }
        
        if (!empty($analysis['summary'])) {
            $profileData['career_objective'] = $analysis['summary'];
        }
        
        if (!empty($analysis['keywords'])) {
            $keywords = array_slice($analysis['keywords'], 0, 10);
            $profileData['experience'] = "Key skills and experience: " . implode(', ', $keywords);
        }
        
        if (!empty($analysis['education']) && $analysis['education'] !== 'Not specified') {
            $profileData['education'] = $analysis['education'];
        }
        
        return [
            'success' => true,
            'data' => $profileData,
            'analysis' => $analysis,
            'provider' => $analysis['provider'] ?? 'unknown'
        ];
        
    } catch (Exception $e) {
        error_log("Resume Parse Error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => 'Resume parsing failed: ' . $e->getMessage()
        ];
    }
}

// =============================================
// HANDLE RESUME UPLOAD - SECURE VERSION
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_resume') {
    if (isset($_FILES['resume_file']) && $_FILES['resume_file']['error'] === UPLOAD_ERR_OK) {
        
        $uploadResult = secureUploadResume($_FILES['resume_file'], $userId, $applicantId);
        
        if (!$uploadResult['success']) {
            $resumeUploadError = $uploadResult['error'];
        } else {
            // Update applicant with resume path
            $updateResult = updateApplicant($applicantId, ['resume_path' => $uploadResult['relative_path']]);
            
            if (!$updateResult) {
                unlink($uploadResult['filepath']);
                $resumeUploadError = 'Failed to update database. File was deleted.';
            } else {
                // Extract text from resume
                $resumeText = extractResumeText($uploadResult['filepath']);
                
                if ($resumeText && strlen(trim($resumeText)) > 50) {
                    $parseResult = parseResumeWithAI($resumeText);
                    
                    if ($parseResult['success']) {
                        $resumeAnalysisResult = $parseResult;
                        $showAIFilledData = true;
                        $aiFilledData = $parseResult['data'];
                        $successMessage = '✅ Resume uploaded and analyzed! Review the extracted data below.';
                    } else {
                        $resumeUploadError = $parseResult['error'];
                        $successMessage = '✅ Resume uploaded but AI analysis failed.';
                    }
                } else {
                    $successMessage = '✅ Resume uploaded successfully! (Could not extract text for analysis)';
                }
            }
        }
    } else {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'Please select a file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.'
        ];
        $errorCode = isset($_FILES['resume_file']['error']) ? $_FILES['resume_file']['error'] : UPLOAD_ERR_NO_FILE;
        $resumeUploadError = $uploadErrors[$errorCode] ?? 'Unknown upload error.';
    }
}

// =============================================
// HANDLE SAVE PROFILE FROM AI DATA
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_from_ai') {
    $careerObjective = trim($_POST['career_objective'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $experience = trim($_POST['experience'] ?? '');
    $education = trim($_POST['education'] ?? '');
    
    $_SESSION['ai_filled_data'] = [
        'career_objective' => $careerObjective,
        'skills' => $skills,
        'experience' => $experience,
        'education' => $education
    ];
    
    header('Location: edit_profile.php?ai_filled=1');
    exit;
}

// =============================================
// HANDLE SAVE & ANALYZE (Final Save) - PostgreSQL
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_and_analyze') {
    $careerObjective = trim($_POST['career_objective'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $experience = trim($_POST['experience'] ?? '');
    $education = trim($_POST['education'] ?? '');
    
    $applicantData = [
        'career_objective' => $careerObjective,
        'skills' => $skills,
        'experience' => $experience,
        'education' => $education
    ];
    
    if (empty($careerObjective) && empty($skills) && empty($experience) && empty($education)) {
        $errorMessage = 'No data to save. Please fill in at least one field.';
    } else {
        $updateResult = updateApplicant($applicantId, $applicantData);
        
        if ($updateResult) {
            // Run AI analysis
            $aiResult = analyzeProfileWithAI($applicantData);
            
            if ($aiResult['success']) {
                $analysis = $aiResult['data'];
                
                $aiSql = "UPDATE applicants SET 
                    ai_skills = $1,
                    ai_years_experience = $2,
                    ai_education = $3,
                    ai_profile_strength = $4,
                    ai_last_analysis = NOW()
                WHERE id = $5";
                
                $aiSkills = json_encode($analysis['skills'] ?? []);
                $aiExperience = (int)($analysis['years_experience'] ?? 0);
                $aiEducation = $analysis['education'] ?? '';
                $aiStrength = calculateProfileStrength($applicantData);
                
                $aiUpdateResult = updateRecord($aiSql, [
                    $aiSkills,
                    $aiExperience,
                    $aiEducation,
                    $aiStrength,
                    $applicantId
                ]);
                
                if (!$aiUpdateResult) {
                    error_log("AI data update failed (non-critical)");
                }
            }
            
            $_SESSION['profile_update_success'] = true;
            header('Location: profile.php?updated=1');
            exit;
            
        } else {
            $errorMessage = 'Failed to update profile. Please try again.';
        }
    }
}

// Check if AI data was filled from session
if (isset($_GET['ai_filled']) && $_GET['ai_filled'] == 1 && isset($_SESSION['ai_filled_data'])) {
    $showAIFilledData = true;
    $aiFilledData = $_SESSION['ai_filled_data'];
    $successMessage = '📋 AI data loaded into form. Review and click "Save & Analyze" to save.';
}

// Handle regular form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $careerObjective = trim($_POST['career_objective'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $experience = trim($_POST['experience'] ?? '');
    $education = trim($_POST['education'] ?? '');
    
    $applicantData = [
        'career_objective' => $careerObjective,
        'skills' => $skills,
        'experience' => $experience,
        'education' => $education
    ];
    
    $applicantUpdated = updateApplicant($applicantId, $applicantData);
    
    if ($applicantUpdated) {
        $aiResult = analyzeProfileWithAI($applicantData);
        
        if ($aiResult['success']) {
            $aiAnalysisResult = $aiResult['data'];
            
            try {
                $aiSql = "UPDATE applicants SET 
                    ai_skills = $1,
                    ai_years_experience = $2,
                    ai_education = $3,
                    ai_profile_strength = $4,
                    ai_last_analysis = NOW()
                WHERE id = $5";
                
                $aiSkills = json_encode($aiAnalysisResult['skills'] ?? []);
                $aiExperience = (int)($aiAnalysisResult['years_experience'] ?? 0);
                $aiEducation = $aiAnalysisResult['education'] ?? '';
                $aiStrength = calculateProfileStrength($applicantData);
                
                updateRecord($aiSql, [
                    $aiSkills,
                    $aiExperience,
                    $aiEducation,
                    $aiStrength,
                    $applicantId
                ]);
            } catch (Exception $e) {
                error_log("AI data update failed (non-critical): " . $e->getMessage());
            }
            
            $successMessage = 'Profile updated successfully! AI analysis completed.';
        } else {
            $successMessage = 'Profile updated successfully!';
            $aiError = $aiResult['error'] ?? 'AI analysis could not be completed.';
        }
    } else {
        $errorMessage = 'Failed to update profile. Please try again.';
    }
}

// Handle manual AI analysis trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze_ai') {
    $applicantData = [
        'career_objective' => $applicant['career_objective'] ?? '',
        'skills' => $applicant['skills'] ?? '',
        'experience' => $applicant['experience'] ?? '',
        'education' => $applicant['education'] ?? ''
    ];
    
    $aiResult = analyzeProfileWithAI($applicantData);
    
    if ($aiResult['success']) {
        $aiAnalysisResult = $aiResult['data'];
        $successMessage = 'AI analysis completed! Check the results below.';
    } else {
        $aiError = $aiResult['error'] ?? 'AI analysis failed.';
    }
}

/**
 * Calculate profile strength based on filled fields
 */
function calculateProfileStrength($applicant) {
    $score = 0;
    $total = 0;
    
    $fields = [
        'career_objective' => 20,
        'skills' => 25,
        'experience' => 25,
        'education' => 20,
        'phone' => 5,
        'address' => 5,
    ];
    
    foreach ($fields as $field => $weight) {
        $total += $weight;
        if (!empty($applicant[$field])) {
            $score += $weight;
        }
    }
    
    return $total > 0 ? round(($score / $total) * 100) : 0;
}

// Get stored AI analysis data from applicant record
$storedAiSkills = [];
$storedAiExperience = 0;
$storedAiEducation = '';
$profileStrength = 0;
$hasResume = !empty($applicant['resume_path']);

if ($applicant) {
    $storedAiSkills = !empty($applicant['ai_skills']) ? json_decode($applicant['ai_skills'], true) : [];
    $storedAiExperience = (int)($applicant['ai_years_experience'] ?? 0);
    $storedAiEducation = $applicant['ai_education'] ?? '';
    $profileStrength = (int)($applicant['ai_profile_strength'] ?? calculateProfileStrength($applicant));
}

$interviewCount = 0;
if ($applicantId) {
    $interviewResult = getRecord("
        SELECT COUNT(*) as count FROM applications 
        WHERE applicant_id = $1 AND interview_date IS NOT NULL
    ", [$applicantId]);
    $interviewCount = (int)($interviewResult['count'] ?? 0);
}

// Check if PDF parser is available
$pdfParserAvailable = class_exists('\Smalot\PdfParser\Parser') || class_exists('Smalot\PdfParser\Parser');

if (isset($_SESSION['ai_filled_data']) && !isset($_GET['ai_filled'])) {
    unset($_SESSION['ai_filled_data']);
}

// Create upload_logs table if it doesn't exist (run once)
function ensureUploadLogsTable() {
    $sql = "CREATE TABLE IF NOT EXISTS upload_logs (
        id SERIAL PRIMARY KEY,
        user_id INTEGER NOT NULL,
        filename VARCHAR(255),
        status VARCHAR(50),
        error_message TEXT,
        created_at TIMESTAMP DEFAULT NOW()
    )";
    executeQuery($sql);
}
ensureUploadLogsTable();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Edit Profile - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ========================================================================== */
        /* MATERIAL 3 DESIGN SYSTEM - EDIT PROFILE */
        /* ========================================================================== */
        :root {
            --bg-background: #f8f7fc;
            --bg-surface: #ffffff;
            --bg-surface-low: #f5f3ff;
            --bg-surface-container-low: #f5f3ff;
            --bg-surface-container-lowest: #ffffff;
            --bg-surface-container-high: #ede9fe;
            --text-on-surface: #1b1b24;
            --text-on-surface-variant: #464555;
            --text-on-background: #1b1b24;
            --outline-variant: #c7c4d8;
            --primary: #4f46e5;
            --primary-container: #4f46e5;
            --on-primary: #ffffff;
            --on-primary-fixed-variant: #4338ca;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-500: #64748b;
            --slate-900: #0f172a;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.06), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
            --radius-full: 9999px;
            --font-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --font-label: 'Public Sans', system-ui, -apple-system, sans-serif;
            --transition-fast: 0.15s ease;
            --transition-smooth: 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            --sidebar-width: 280px;
            --sidebar-collapsed: 72px;
            --success-color: #22c55e;
            --warning-color: #f59e0b;
            --error-color: #dc2626;
            --info-color: #3b82f6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-sans);
            background: var(--bg-background);
            color: var(--text-on-surface);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: row;
            overflow: hidden;
            height: 100vh;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* =============================================
           SIDEBAR - FIXED
        ============================================= */
        .dashboard-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 50;
            background: var(--bg-surface);
            display: flex;
            flex-direction: column;
            height: 100vh;
            width: var(--sidebar-width);
            border-right: 1px solid var(--slate-200);
            transition: width 0.3s ease, transform 0.3s ease;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            flex-shrink: 0;
        }

        .dashboard-sidebar.collapsed {
            width: var(--sidebar-collapsed);
        }

        .dashboard-sidebar.mobile-hidden {
            transform: translateX(-100%);
        }

        .dashboard-sidebar.mobile-open {
            transform: translateX(0);
        }

        .dashboard-sidebar .sidebar-brand-text,
        .dashboard-sidebar .sidebar-brand-category,
        .dashboard-sidebar .sidebar-nav .nav-label,
        .dashboard-sidebar .sidebar-nav .nav-text,
        .dashboard-sidebar .sidebar-nav .nav-badge,
        .dashboard-sidebar .sidebar-footer .user-info {
            opacity: 1;
            transition: opacity 0.3s ease;
            overflow: hidden;
            white-space: nowrap;
        }

        .dashboard-sidebar.collapsed .sidebar-brand-text,
        .dashboard-sidebar.collapsed .sidebar-brand-category,
        .dashboard-sidebar.collapsed .sidebar-nav .nav-label,
        .dashboard-sidebar.collapsed .sidebar-nav .nav-text,
        .dashboard-sidebar.collapsed .sidebar-nav .nav-badge,
        .dashboard-sidebar.collapsed .sidebar-footer .user-info {
            opacity: 0;
            width: 0;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }

        .dashboard-sidebar.collapsed .sidebar-brand-card {
            padding: 1rem 0.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-nav {
            padding: 0.5rem 0.25rem;
        }

        .dashboard-sidebar.collapsed .sidebar-main-link {
            justify-content: center;
            padding: 0.75rem 0.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined {
            font-size: 1.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-footer .user-card {
            justify-content: center;
            padding: 0.5rem;
        }

        .dashboard-sidebar.collapsed .sidebar-footer .user-card .avatar {
            width: 2.5rem;
            height: 2.5rem;
            font-size: 0.875rem;
        }

        .sidebar-brand-card {
            border-radius: 2rem;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.75rem;
        }

        .sidebar-brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 1.75rem;
            background: var(--slate-100);
            color: var(--primary);
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .sidebar-brand-icon .material-symbols-outlined {
            font-size: 1.5rem;
        }

        .sidebar-brand-text {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--slate-900);
        }

        .sidebar-brand-category {
            font-size: 0.75rem;
            color: var(--slate-500);
            margin-top: 0.25rem;
        }

        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 1.25rem;
        }

        .sidebar-nav .nav-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--slate-500);
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.5rem;
        }

        .sidebar-main-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
            margin-bottom: 0.25rem;
            font-family: var(--font-label);
            font-weight: 500;
            font-size: 0.875rem;
        }

        .sidebar-main-link:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .sidebar-main-link.active {
            background: var(--bg-surface-container-high);
            color: var(--primary);
        }

        .sidebar-main-link .material-symbols-outlined {
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .sidebar-main-link .nav-text {
            transition: opacity 0.3s ease;
        }

        .sidebar-main-link .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.125rem 0.5rem;
            border-radius: 50px;
            transition: opacity 0.3s ease;
        }

        .sidebar-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--slate-200);
        }

        .sidebar-footer .user-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 1rem;
            background: var(--bg-surface-low);
        }

        .sidebar-footer .user-card .avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.875rem;
            flex-shrink: 0;
        }

        .sidebar-footer .user-card .user-info .user-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .sidebar-footer .user-card .user-info .user-email {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-backdrop {
            display: none;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(17, 24, 39, 0.5);
            backdrop-filter: blur(8px);
            z-index: 40;
            transition: opacity 0.3s ease;
            opacity: 0;
        }

        .sidebar-backdrop.active {
            display: block;
            opacity: 1;
        }

        /* =============================================
           MAIN CONTENT
        ============================================= */
        .main-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s ease;
        }

        .dashboard-sidebar.collapsed ~ .main-wrapper {
            margin-left: var(--sidebar-collapsed);
        }

        /* =============================================
           TOP HEADER
        ============================================= */
        .top-header {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(199, 196, 216, 0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 4rem;
            padding: 0 1.5rem;
            flex-shrink: 0;
            z-index: 30;
            width: 100%;
        }

        .top-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .top-header-left .logo {
            width: 2rem;
            height: 2rem;
            border-radius: 0.5rem;
            background: var(--slate-100);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.875rem;
            color: var(--primary);
            border: 1px solid rgba(199, 196, 216, 0.3);
        }

        .top-header-left .separator {
            color: var(--outline-variant);
            font-weight: 300;
            user-select: none;
        }

        .sidebar-toggle-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(199, 196, 216, 0.3);
            background: transparent;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            min-width: 2.5rem;
            min-height: 2.5rem;
        }

        .sidebar-toggle-btn:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .sidebar-toggle-btn .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .mobile-menu-btn {
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(199, 196, 216, 0.3);
            background: transparent;
            color: var(--text-on-surface-variant);
            cursor: pointer;
            transition: all var(--transition-fast);
            min-width: 2.5rem;
            min-height: 2.5rem;
        }

        .mobile-menu-btn:hover {
            background: var(--bg-surface-low);
            color: var(--text-on-surface);
        }

        .mobile-menu-btn .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .profile-dropdown-wrapper {
            position: relative;
        }

        .profile-dropdown-toggle {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.375rem 0.75rem 0.375rem 0.375rem;
            border-radius: var(--radius-full);
            border: 1px solid transparent;
            background: transparent;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .profile-dropdown-toggle:hover {
            background: var(--bg-surface-low);
            border-color: rgba(199, 196, 216, 0.3);
        }

        .profile-dropdown-toggle .avatar-small {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.75rem;
            flex-shrink: 0;
        }

        .profile-dropdown-toggle .profile-name {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-on-surface);
        }

        .profile-dropdown-toggle .profile-role {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            font-weight: 400;
        }

        .profile-dropdown-toggle .material-symbols-outlined {
            font-size: 1rem;
            color: var(--text-on-surface-variant);
            transition: transform var(--transition-fast);
        }

        .profile-dropdown-toggle.open .material-symbols-outlined:last-child {
            transform: rotate(180deg);
        }

        .profile-dropdown-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 0.5rem);
            width: 14rem;
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--slate-200);
            padding: 0.5rem;
            z-index: 50;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-0.5rem) scale(0.95);
            transition: all var(--transition-smooth);
            transform-origin: top right;
        }

        .profile-dropdown-menu.open {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        .profile-dropdown-menu .dropdown-header {
            padding: 0.5rem 0.875rem 0.25rem;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }

        .profile-dropdown-menu .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-on-surface);
            transition: all var(--transition-fast);
            cursor: pointer;
            border: none;
            background: transparent;
            width: 100%;
            text-align: left;
            font-family: var(--font-sans);
        }

        .profile-dropdown-menu .dropdown-item:hover {
            background: var(--bg-surface-low);
            color: var(--primary);
        }

        .profile-dropdown-menu .dropdown-item .material-symbols-outlined {
            font-size: 1.125rem;
            color: var(--text-on-surface-variant);
        }

        .profile-dropdown-menu .dropdown-item:hover .material-symbols-outlined {
            color: var(--primary);
        }

        .profile-dropdown-menu .dropdown-item.danger {
            color: #dc2626;
        }

        .profile-dropdown-menu .dropdown-item.danger:hover {
            background: #fef2f2;
            color: #dc2626;
        }

        .profile-dropdown-menu .dropdown-item.danger .material-symbols-outlined {
            color: #dc2626;
        }

        .profile-dropdown-menu .dropdown-divider {
            height: 1px;
            background: var(--slate-200);
            margin: 0.25rem 0.5rem;
        }

        /* =============================================
           MAIN SCROLLABLE AREA
        ============================================= */
        .main-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 2rem;
        }

        .main-scroll .container {
            max-width: 80rem;
            margin: 0 auto;
        }

        /* =============================================
           BREADCRUMB
        ============================================= */
        .breadcrumb-bar {
            background: var(--bg-surface-container-lowest);
            border-radius: var(--radius-xl);
            border: 1px solid rgba(199, 196, 216, 0.3);
            padding: 1rem 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .breadcrumb-bar {
                border-radius: var(--radius-2xl);
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .breadcrumb-view {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.875rem;
            border-radius: 0.75rem;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid rgba(79, 70, 229, 0.2);
        }

        .breadcrumb-view .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .breadcrumb-view .status-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 50%;
            background: #22c55e;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        /* =============================================
           PAGE HEADER
        ============================================= */
        .page-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 640px) {
            .page-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .page-header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-on-surface);
            letter-spacing: -0.025em;
        }

        .page-header p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        /* =============================================
           RESUME UPLOAD SECTION
        ============================================= */
        .resume-upload-section {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            border-radius: var(--radius-xl);
            border: 2px dashed #86efac;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
            text-align: center;
            position: relative;
            transition: all var(--transition-fast);
        }

        .resume-upload-section:hover {
            border-color: var(--primary);
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
        }

        .resume-upload-section .upload-icon {
            font-size: 2.5rem;
            color: var(--primary);
            display: block;
            margin-bottom: 0.5rem;
        }

        .resume-upload-section h4 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-on-surface);
        }

        .resume-upload-section p {
            font-size: 0.8125rem;
            color: var(--text-on-surface-variant);
            margin: 0.25rem 0 0.75rem;
        }

        .resume-upload-section .file-input-wrapper {
            display: inline-block;
            position: relative;
            overflow: hidden;
        }

        .resume-upload-section .file-input-wrapper input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .resume-upload-section .file-input-wrapper .btn {
            pointer-events: none;
        }

        .resume-upload-section .file-name {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.5rem;
            display: none;
        }

        .resume-upload-section .file-name.show {
            display: block;
        }

        .resume-upload-section .parser-status {
            font-size: 0.75rem;
            margin-top: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 0.375rem;
            display: inline-block;
        }

        .resume-upload-section .parser-status.available {
            background: #d1fae5;
            color: #065f46;
        }

        .resume-upload-section .parser-status.unavailable {
            background: #fef3c7;
            color: #92400e;
        }

        /* =============================================
           AI INSIGHTS BANNER
        ============================================= */
        .ai-insights-banner {
            background: linear-gradient(135deg, #eef0ff, #e0e7ff);
            border-radius: var(--radius-xl);
            border: 1px solid rgba(79, 70, 229, 0.2);
            padding: 1rem 1.5rem;
            margin-bottom: 1.25rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .ai-insights-banner .ai-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .ai-insights-banner .ai-left .ai-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 0.75rem;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .ai-insights-banner .ai-left .ai-text {
            font-size: 0.875rem;
            color: var(--text-on-surface);
        }

        .ai-insights-banner .ai-left .ai-text strong {
            color: var(--primary);
        }

        .ai-insights-banner .ai-right {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .ai-insights-banner .ai-right .ai-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.25rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.6875rem;
            font-weight: 600;
            background: var(--primary);
            color: white;
        }

        .ai-insights-banner .ai-right .ai-badge .material-symbols-outlined {
            font-size: 0.875rem;
        }

        .btn-ai-analyze {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.75rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            background: var(--primary);
            color: white;
        }

        .btn-ai-analyze:hover {
            background: var(--on-primary-fixed-variant);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-ai-analyze .material-symbols-outlined {
            font-size: 0.875rem;
        }

        /* =============================================
           AI ANALYSIS RESULTS
        ============================================= */
        .ai-results-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
            display: <?php echo ($aiAnalysisResult || $aiError || $resumeAnalysisResult || $showAIFilledData) ? 'block' : 'none'; ?>;
        }

        .ai-results-card .ai-results-header {
            padding: 0.875rem 1.25rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f8f7fc, #f0eeff);
        }

        .ai-results-card .ai-results-header h3 {
            font-size: 0.9375rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .ai-results-card .ai-results-header h3 .material-symbols-outlined {
            color: var(--primary);
        }

        .ai-results-card .ai-results-header .ai-provider {
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
            background: var(--bg-surface);
            padding: 0.125rem 0.625rem;
            border-radius: var(--radius-full);
        }

        .ai-results-body {
            padding: 1.25rem;
        }

        .ai-skill-tag {
            display: inline-block;
            padding: 0.25rem 0.875rem;
            background: rgba(79, 70, 229, 0.08);
            color: var(--primary);
            border-radius: 50px;
            font-size: 0.8125rem;
            font-weight: 500;
            border: 1px solid rgba(79, 70, 229, 0.15);
            margin: 0.1875rem;
        }

        .ai-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .ai-stat-item {
            background: var(--bg-surface-low);
            border-radius: 0.75rem;
            padding: 0.625rem 1rem;
            border: 1px solid var(--slate-200);
        }

        .ai-stat-item .ai-stat-label {
            font-size: 0.625rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-on-surface-variant);
        }

        .ai-stat-item .ai-stat-value {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--text-on-surface);
            margin-top: 0.125rem;
        }

        .ai-error {
            padding: 0.75rem 1rem;
            background: #fef2f2;
            border-radius: 0.75rem;
            color: #991b1b;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            border: 1px solid #fecaca;
        }

        .ai-error .material-symbols-outlined {
            font-size: 1.125rem;
            color: #dc2626;
        }

        /* =============================================
           PROFILE STRENGTH INDICATOR
        ============================================= */
        .strength-indicator {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            padding: 0.75rem 1rem;
            background: var(--bg-surface-low);
            border-radius: 0.75rem;
            border: 1px solid var(--slate-200);
        }

        .strength-indicator .strength-bar {
            flex: 1;
            height: 0.5rem;
            border-radius: var(--radius-full);
            background: var(--slate-200);
            overflow: hidden;
        }

        .strength-indicator .strength-bar .strength-fill {
            height: 100%;
            border-radius: var(--radius-full);
            transition: width 0.8s ease;
            width: <?php echo $profileStrength; ?>%;
            background: <?php 
                if ($profileStrength >= 80) echo '#22c55e';
                elseif ($profileStrength >= 60) echo '#f59e0b';
                elseif ($profileStrength >= 40) echo '#f97316';
                else echo '#dc2626';
            ?>;
        }

        .strength-indicator .strength-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            min-width: 3.5rem;
            text-align: right;
        }

        /* =============================================
           BUTTONS
        ============================================= */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.25);
        }

        .btn-primary:hover {
            background: var(--on-primary-fixed-variant);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.35);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
            box-shadow: 0 4px 14px rgba(34, 197, 94, 0.25);
        }

        .btn-success:hover {
            background: #16a34a;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.35);
        }

        .btn .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .btn-sm {
            padding: 0.375rem 0.875rem;
            font-size: 0.8125rem;
        }

        /* =============================================
           SAVE & ANALYZE BUTTON - HIGHLIGHTED
        ============================================= */
        .btn-save-analyze {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
        }

        .btn-save-analyze:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.45);
            background: linear-gradient(135deg, #4338ca, #6d28d9);
        }

        /* =============================================
           FORM CARD
        ============================================= */
        .card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            border: 1px solid var(--slate-200);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.75rem;
        }

        .card-header h3 {
            font-size: 1.125rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.625rem;
        }

        .card-header h3 .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* =============================================
           FORM ELEMENTS
        ============================================= */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }

        .form-group label .required {
            color: #dc2626;
            margin-left: 0.125rem;
        }

        .form-group .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.75rem;
            font-size: 0.875rem;
            font-family: var(--font-sans);
            transition: all var(--transition-fast);
            background: var(--bg-surface);
            color: var(--text-on-surface);
        }

        .form-group .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .form-group .form-control.ai-filled {
            border-color: #86efac;
            background: #f0fdf4;
        }

        .form-group .form-control::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }

        .form-group textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }

        .form-group .helper-text {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        .form-group .helper-text .material-symbols-outlined {
            font-size: 0.875rem;
            vertical-align: middle;
        }

        .form-group .char-count {
            text-align: right;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.25rem;
        }

        /* =============================================
           MESSAGES
        ============================================= */
        .message {
            padding: 0.875rem 1.25rem;
            border-radius: 0.75rem;
            font-size: 0.875rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            border: 1px solid transparent;
        }

        .message .material-symbols-outlined {
            font-size: 1.25rem;
            flex-shrink: 0;
            margin-top: 0.0625rem;
        }

        .message.success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #16a34a;
        }

        .message.success .material-symbols-outlined {
            color: #16a34a;
        }

        .message.error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }

        .message.error .material-symbols-outlined {
            color: #dc2626;
        }

        .message.info {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1e40af;
        }

        .message.info .material-symbols-outlined {
            color: #1e40af;
        }

        /* =============================================
           FORM ACTIONS
        ============================================= */
        .form-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--slate-200);
            flex-wrap: wrap;
        }

        .form-actions .btn {
            flex: 1;
            justify-content: center;
        }

        /* =============================================
           SAVING PROGRESS MODAL - 3 DOTS
        ============================================= */
        .saving-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 15, 30, 0.8);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
            animation: fadeIn 0.4s ease;
        }
        .saving-modal.active {
            display: flex;
        }

        .saving-modal-content {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 400px;
            width: 100%;
            padding: 2.5rem 2rem 2rem;
            box-shadow: 0 40px 80px rgba(0, 0, 0, 0.3);
            animation: modalSlideUp 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .saving-modal-content::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 30% 20%, rgba(79, 70, 229, 0.05), transparent 60%);
            pointer-events: none;
        }

        .saving-modal-content > * {
            position: relative;
            z-index: 1;
        }

        .saving-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2.5rem;
            background: linear-gradient(135deg, #eef0ff, #e0e7ff);
            color: var(--primary);
            transition: all 0.5s ease;
        }

        .saving-icon.done {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #059669;
            animation: iconCelebrate 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes iconCelebrate {
            0% { transform: scale(0.5) rotate(-10deg); opacity: 0.5; }
            50% { transform: scale(1.2) rotate(5deg); }
            70% { transform: scale(0.9) rotate(-3deg); }
            100% { transform: scale(1) rotate(0deg); opacity: 1; }
        }

        .saving-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }

        .saving-subtitle {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-bottom: 1.5rem;
            min-height: 1.5rem;
            transition: all 0.3s ease;
        }

        /* 3 Dots Progress */
        .dots-progress {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 1rem 0 1.5rem;
        }

        .dots-progress .dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #e5e7eb;
            transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            transform: scale(0.8);
        }

        .dots-progress .dot.active {
            background: var(--primary);
            transform: scale(1.2);
            box-shadow: 0 0 24px rgba(79, 70, 229, 0.35);
            animation: dotPulse 1.2s ease-in-out infinite;
        }

        .dots-progress .dot.done {
            background: var(--success-color);
            transform: scale(1);
        }

        .dots-progress .dot:nth-child(1) { transition-delay: 0ms; }
        .dots-progress .dot:nth-child(2) { transition-delay: 100ms; }
        .dots-progress .dot:nth-child(3) { transition-delay: 200ms; }

        @keyframes dotPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.35); }
            50% { box-shadow: 0 0 0 16px rgba(79, 70, 229, 0); }
        }

        .dots-progress-label {
            font-size: 0.7rem;
            font-weight: 500;
            color: var(--text-on-surface-variant);
            text-align: center;
            margin-top: 0.25rem;
        }

        @media (max-width: 480px) {
            .saving-modal-content { padding: 1.75rem 1.25rem; margin: 0.5rem; }
            .saving-icon { width: 64px; height: 64px; font-size: 2rem; }
            .dots-progress .dot { width: 12px; height: 12px; }
        }

        /* =============================================
           RESPONSIVE
        ============================================= */
        @media (min-width: 768px) {
            .sidebar-backdrop {
                display: none !important;
            }

            .mobile-menu-btn {
                display: none !important;
            }

            .dashboard-sidebar {
                position: fixed;
                transform: translateX(0) !important;
                box-shadow: var(--shadow-xl);
                height: 100vh;
            }

            .dashboard-sidebar.mobile-hidden {
                transform: translateX(0) !important;
            }

            .main-wrapper {
                margin-left: var(--sidebar-width);
            }

            .dashboard-sidebar.collapsed ~ .main-wrapper {
                margin-left: var(--sidebar-collapsed);
            }

            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role {
                display: inline;
            }

            .page-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        @media (max-width: 767px) {
            .dashboard-sidebar {
                position: fixed;
                width: var(--sidebar-width);
                transform: translateX(-100%);
                box-shadow: var(--shadow-xl);
            }

            .dashboard-sidebar.mobile-open {
                transform: translateX(0);
            }

            .dashboard-sidebar.collapsed {
                width: var(--sidebar-width);
            }

            .sidebar-toggle-btn {
                display: none !important;
            }

            .mobile-menu-btn {
                display: flex;
            }

            .main-wrapper {
                margin-left: 0 !important;
            }

            .main-scroll {
                padding: 1rem;
            }

            .top-header-left .separator {
                display: none;
            }

            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role {
                display: none;
            }

            .card-body {
                padding: 1rem 1.25rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                flex: none;
                width: 100%;
            }

            .ai-insights-banner {
                flex-direction: column;
                align-items: stretch;
            }

            .ai-insights-banner .ai-right {
                justify-content: stretch;
            }

            .ai-insights-banner .ai-right .btn-ai-analyze {
                width: 100%;
                justify-content: center;
            }

            .dashboard-sidebar.collapsed .sidebar-brand-text,
            .dashboard-sidebar.collapsed .sidebar-brand-category,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-label,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-text,
            .dashboard-sidebar.collapsed .sidebar-nav .nav-badge,
            .dashboard-sidebar.collapsed .sidebar-footer .user-info {
                opacity: 1;
                width: auto;
                overflow: visible;
            }

            .dashboard-sidebar.collapsed .sidebar-brand-card {
                padding: 1.5rem;
            }

            .dashboard-sidebar.collapsed .sidebar-nav {
                padding: 1.5rem 1.25rem;
            }

            .dashboard-sidebar.collapsed .sidebar-main-link {
                justify-content: flex-start;
                padding: 0.75rem 1rem;
            }

            .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined {
                font-size: 1.25rem;
            }

            .dashboard-sidebar.collapsed .sidebar-footer .user-card {
                justify-content: flex-start;
                padding: 0.5rem 0.75rem;
            }

            .resume-upload-section {
                padding: 1rem;
            }
        }

        @media (max-width: 480px) {
            .main-scroll {
                padding: 0.75rem;
            }

            .breadcrumb-bar {
                padding: 0.75rem 1rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .card-header {
                padding: 0.75rem 1rem;
            }

            .card-header h3 {
                font-size: 1rem;
            }

            .card-body {
                padding: 0.75rem 1rem;
            }

            .form-group {
                margin-bottom: 0.875rem;
            }

            .ai-stat-grid {
                grid-template-columns: 1fr;
            }

            .ai-results-body {
                padding: 0.875rem 1rem;
            }

            .resume-upload-section .upload-icon {
                font-size: 2rem;
            }
        }

        /* Scrollbar Styling */
        .main-scroll::-webkit-scrollbar {
            width: 6px;
        }

        .main-scroll::-webkit-scrollbar-track {
            background: transparent;
        }

        .main-scroll::-webkit-scrollbar-thumb {
            background: var(--slate-200);
            border-radius: 3px;
        }

        .main-scroll::-webkit-scrollbar-thumb:hover {
            background: var(--slate-500);
        }
         
        .sidebar-logo {
            width: 3.5rem;
            height: 3.5rem;
            object-fit: contain;
            border-radius: 0.75rem;
            display: block;
            margin: 0 auto;
        }

        .dashboard-sidebar.collapsed .sidebar-logo {
            width: 2.5rem;
            height: 2.5rem;
        }

        .sidebar-brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 1.75rem;
            background-size: contain !important;
            background-repeat: no-repeat !important;
            background-position: center !important;
            background-color: transparent !important;
            flex-shrink: 0;
        }

        /* Security badges */
        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.125rem 0.5rem;
            border-radius: var(--radius-full);
            font-size: 0.6rem;
            font-weight: 600;
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #86efac;
        }
        .security-badge .material-symbols-outlined {
            font-size: 0.75rem;
        }
    </style>
</head>
<body>

    <!-- Sidebar Backdrop (Mobile) -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- =============================================
    SIDEBAR - FIXED POSITION
    ============================================= -->
    <aside class="dashboard-sidebar" id="appSidebar">
        <div class="sidebar-brand-card">
            <img src="logo.png" alt="ISMERS" class="sidebar-logo">
            <p class="sidebar-brand-category">Applicant Portal</p>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Main Menu</div>

            <a href="dashboard.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="nav-text">Dashboard</span>
            </a>

            <a href="profile.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">person</span>
                <span class="nav-text">My Profile</span>
            </a>

            <a href="applications.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">Applications</span>
                <span class="nav-badge"><?php echo $totalApplications; ?></span>
            </a>

            <a href="offers.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">description</span>
                <span class="nav-text">My Offers</span>
                <span class="nav-badge"><?php echo $pendingOffers; ?></span>
            </a>

            <a href="interview.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="nav-text">Interviews</span>
                <span class="nav-badge"><?php echo $interviewCount; ?></span>
            </a>

            <a href="job_search.php" class="sidebar-main-link">
                <span class="material-symbols-outlined">search</span>
                <span class="nav-text">Job Search</span>
            </a>

        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></span>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- =============================================
    MAIN CONTENT - PUSHED BY SIDEBAR
    ============================================= -->
    <div class="main-wrapper" id="mainWrapper">

        <!-- Top Header -->
        <header class="top-header">
            <div class="top-header-left">
                <img src="logo.png" alt="ISMERS" class="logo" style="height: 2rem; width: auto;">
                <span class="separator">|</span>
                <button class="sidebar-toggle-btn" id="sidebarToggleBtn" type="button" title="Toggle Sidebar">
                    <span class="material-symbols-outlined" id="sidebarToggleIcon">menu_open</span>
                </button>
                <button class="mobile-menu-btn" id="mobileMenuBtn" type="button" title="Open Menu">
                    <span class="material-symbols-outlined">menu</span>
                </button>
                <span class="logo-text" style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface); display:none;">ISMERS</span>
            </div>

            <!-- Profile Dropdown -->
            <div class="profile-dropdown-wrapper">
                <button class="profile-dropdown-toggle" id="profileDropdownToggle" type="button" aria-expanded="false">
                    <div class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'A'); ?></div>
                    <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                    <span class="profile-role">Applicant</span>
                    <span class="material-symbols-outlined">expand_more</span>
                </button>

                <!-- Dropdown Menu -->
                <div class="profile-dropdown-menu" id="profileDropdownMenu">
                    <div class="dropdown-header">Account</div>
                    <a href="settings.php" class="dropdown-item">
                        <span class="material-symbols-outlined">settings</span>
                        Settings
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="../../logout.php" class="dropdown-item danger">
                        <span class="material-symbols-outlined">logout</span>
                        Log Out
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Scrollable Area -->
        <main class="main-scroll" id="mainScroll">
            <div class="container">

                <!-- Breadcrumb -->
                <div class="breadcrumb-bar">
                    <div class="breadcrumb-view">
                        <span class="material-symbols-outlined">edit</span>
                        <span>Edit Profile</span>
                        <span class="status-dot"></span>
                    </div>
                    <a href="profile.php" class="btn btn-outline" style="padding:0.375rem 1rem; font-size:0.8125rem;">
                        <span class="material-symbols-outlined" style="font-size:0.875rem;">arrow_back</span>
                        Back to Profile
                    </a>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Edit Profile</h1>
                        <p>Update your professional resume information</p>
                    </div>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <span class="security-badge">
                            <span class="material-symbols-outlined">verified</span>
                            Secure Upload
                        </span>
                        <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                            Max: <?php echo MAX_FILE_SIZE / 1024 / 1024; ?>MB
                        </span>
                    </div>
                </div>

                <!-- Profile Strength Indicator -->
                <div class="strength-indicator">
                    <span class="material-symbols-outlined" style="color:var(--primary); font-size:1.125rem;">auto_awesome</span>
                    <span style="font-size:0.8125rem; font-weight:500; color:var(--text-on-surface-variant);">Profile Strength</span>
                    <div class="strength-bar">
                        <div class="strength-fill" style="width: <?php echo $profileStrength; ?>%;"></div>
                    </div>
                    <span class="strength-label"><?php echo $profileStrength; ?>%</span>
                </div>

                <!-- =============================================
                RESUME UPLOAD SECTION
                ============================================= -->
                <div class="resume-upload-section" id="resumeUploadSection">
                    <span class="upload-icon">📄</span>
                    <h4>Upload Your Resume</h4>
                    <p>Upload your resume (PDF, DOC, DOCX, or TXT) and let AI automatically fill your profile</p>
                    
                    <div style="margin-bottom:0.75rem;">
                        <span class="parser-status <?php echo $pdfParserAvailable ? 'available' : 'unavailable'; ?>">
                            <?php if ($pdfParserAvailable): ?>
                                ✅ PDF Parser Available
                            <?php else: ?>
                                ⚠️ PDF Parser Not Available - PDF files may not work
                            <?php endif; ?>
                        </span>
                        <span style="display:inline-block; margin-left:0.5rem; font-size:0.65rem; color:var(--text-on-surface-variant);">
                            🔒 File validated: extension, MIME type, size, and content
                        </span>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" id="resumeUploadForm">
                        <input type="hidden" name="action" value="upload_resume">
                        <div class="file-input-wrapper">
                            <button type="button" class="btn btn-success">
                                <span class="material-symbols-outlined">upload</span>
                                Choose Resume File
                            </button>
                            <input type="file" name="resume_file" id="resumeFile" accept=".pdf,.doc,.docx,.txt">
                        </div>
                        <div class="file-name" id="fileName">
                            <?php if ($hasResume): ?>
                                ✅ Current: <?php echo htmlspecialchars(basename($applicant['resume_path'])); ?>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-primary" style="margin-top:0.75rem; display:none;" id="uploadBtn">
                            <span class="material-symbols-outlined">auto_awesome</span>
                            Upload & Analyze
                        </button>
                    </form>
                </div>

                <!-- AI Insights Banner -->
                <div class="ai-insights-banner">
                    <div class="ai-left">
                        <div class="ai-icon">
                            <span class="material-symbols-outlined">auto_awesome</span>
                        </div>
                        <div class="ai-text">
                            <strong>AI Resume Analysis</strong>
                            <span style="display:block; font-size:0.75rem; color:var(--text-on-surface-variant);">
                                Get AI-powered insights on your skills and experience
                            </span>
                        </div>
                    </div>
                    <div class="ai-right">
                        <?php if (!empty($storedAiSkills)): ?>
                            <span class="ai-badge">
                                <span class="material-symbols-outlined">check_circle</span>
                                Analyzed
                            </span>
                        <?php endif; ?>
                        <form method="POST" action="" style="margin:0;">
                            <input type="hidden" name="action" value="analyze_ai">
                            <button type="submit" class="btn-ai-analyze">
                                <span class="material-symbols-outlined">psychology</span>
                                <?php echo empty($storedAiSkills) ? 'Analyze Profile' : 'Re-analyze'; ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- AI Results -->
                <?php if ($aiAnalysisResult): ?>
                <div class="ai-results-card" style="display:block;">
                    <div class="ai-results-header">
                        <h3>
                            <span class="material-symbols-outlined">psychology</span>
                            AI Analysis Results
                        </h3>
                        <span class="ai-provider">Powered by <?php echo ucfirst($aiAnalysisResult['provider'] ?? 'AI'); ?></span>
                    </div>
                    <div class="ai-results-body">
                        <?php if (!empty($aiAnalysisResult['skills'])): ?>
                        <div style="margin-bottom:0.75rem;">
                            <div style="font-size:0.75rem; font-weight:600; color:var(--text-on-surface-variant); margin-bottom:0.25rem;">Detected Skills</div>
                            <div>
                                <?php foreach ($aiAnalysisResult['skills'] as $skill): ?>
                                    <span class="ai-skill-tag"><?php echo htmlspecialchars($skill); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="ai-stat-grid">
                            <?php if (!empty($aiAnalysisResult['years_experience'])): ?>
                            <div class="ai-stat-item">
                                <div class="ai-stat-label">Years Experience</div>
                                <div class="ai-stat-value"><?php echo $aiAnalysisResult['years_experience']; ?> years</div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($aiAnalysisResult['education']) && $aiAnalysisResult['education'] !== 'Not specified'): ?>
                            <div class="ai-stat-item">
                                <div class="ai-stat-label">Education Level</div>
                                <div class="ai-stat-value"><?php echo htmlspecialchars($aiAnalysisResult['education']); ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($aiAnalysisResult['keywords']) && count($aiAnalysisResult['keywords']) > 0): ?>
                            <div class="ai-stat-item">
                                <div class="ai-stat-label">Keywords Found</div>
                                <div class="ai-stat-value"><?php echo count($aiAnalysisResult['keywords']); ?> keywords</div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($aiAnalysisResult['summary'])): ?>
                        <div style="margin-top:0.5rem; padding:0.75rem 1rem; background:var(--bg-surface-low); border-radius:0.75rem; border:1px solid var(--slate-200);">
                            <div style="font-size:0.75rem; font-weight:600; color:var(--text-on-surface-variant);">AI Summary</div>
                            <div style="font-size:0.875rem; color:var(--text-on-surface); margin-top:0.125rem;"><?php echo htmlspecialchars($aiAnalysisResult['summary']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Resume Analysis Results / AI Filled Data -->
                <?php if ($showAIFilledData || $resumeAnalysisResult): ?>
                <div class="ai-results-card" style="display:block; border-color: #86efac;">
                    <div class="ai-results-header" style="background:linear-gradient(135deg, #f0fdf4, #dcfce7);">
                        <h3>
                            <span class="material-symbols-outlined" style="color:#059669;">description</span>
                            <?php echo $showAIFilledData ? '📋 AI Data Loaded' : 'Resume Analysis Complete'; ?>
                        </h3>
                        <span class="ai-provider" style="background:#d1fae5; color:#059669;">
                            <?php 
                            $provider = $resumeAnalysisResult['provider'] ?? 'AI';
                            echo '✨ ' . ucfirst($provider) . ' Extracted';
                            ?>
                        </span>
                    </div>
                    <div class="ai-results-body">
                        <div style="margin-bottom:0.75rem; padding:0.75rem 1rem; background:#f0fdf4; border-radius:0.75rem; border:1px solid #86efac;">
                            <div style="font-size:0.8125rem; color:#065f46;">
                                <span class="material-symbols-outlined" style="font-size:1rem; vertical-align:middle;">check_circle</span>
                                <?php if ($showAIFilledData): ?>
                                    AI data has been loaded into the form below. Review and click <strong>"Save & Analyze"</strong> to save.
                                <?php else: ?>
                                    AI has extracted the following information from your resume. Click <strong>"Save from AI"</strong> to load into the form.
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php 
                        $displayData = $showAIFilledData ? $aiFilledData : ($resumeAnalysisResult['data'] ?? []);
                        $displayAnalysis = $resumeAnalysisResult['analysis'] ?? null;
                        ?>
                        
                        <?php if (!empty($displayAnalysis['skills']) || !empty($displayData['skills'])): ?>
                        <div style="margin-bottom:0.75rem;">
                            <div style="font-size:0.75rem; font-weight:600; color:var(--text-on-surface-variant); margin-bottom:0.25rem;">Extracted Skills</div>
                            <div>
                                <?php 
                                $skillsList = !empty($displayAnalysis['skills']) ? $displayAnalysis['skills'] : explode(', ', $displayData['skills'] ?? '');
                                foreach ($skillsList as $skill): 
                                    if (empty($skill)) continue;
                                ?>
                                    <span class="ai-skill-tag" style="background:rgba(5,150,105,0.1); color:#059669; border-color:rgba(5,150,105,0.2);"><?php echo htmlspecialchars(trim($skill)); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!$showAIFilledData): ?>
                        <form method="POST" action="" id="saveFromAIForm">
                            <input type="hidden" name="action" value="save_from_ai">
                            
                            <div class="form-group">
                                <label>Career Objective</label>
                                <textarea name="career_objective" class="form-control" rows="2"><?php echo htmlspecialchars($displayData['career_objective'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Skills</label>
                                <textarea name="skills" class="form-control" rows="2"><?php echo htmlspecialchars($displayData['skills'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Experience</label>
                                <textarea name="experience" class="form-control" rows="3"><?php echo htmlspecialchars($displayData['experience'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Education</label>
                                <textarea name="education" class="form-control" rows="2"><?php echo htmlspecialchars($displayData['education'] ?? ''); ?></textarea>
                            </div>

                            <div style="display:flex; gap:0.75rem; margin-top:0.5rem;">
                                <button type="submit" class="btn btn-success" style="flex:2;">
                                    <span class="material-symbols-outlined">upload</span>
                                    Save from AI
                                </button>
                                <button type="button" class="btn btn-outline" onclick="document.getElementById('saveFromAIForm').reset();" style="flex:1;">
                                    <span class="material-symbols-outlined">refresh</span>
                                    Reset
                                </button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div style="padding:0.75rem 1rem; background:#dbeafe; border-radius:0.75rem; border:1px solid #93c5fd;">
                            <div style="font-size:0.8125rem; color:#1e40af;">
                                <span class="material-symbols-outlined" style="font-size:1rem; vertical-align:middle;">info</span>
                                AI data is ready in the form below. Review and click <strong>"Save & Analyze"</strong> to save to your profile.
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($resumeUploadError): ?>
                <div class="message error">
                    <span class="material-symbols-outlined">error</span>
                    <span><?php echo htmlspecialchars($resumeUploadError); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($aiError): ?>
                <div class="message error">
                    <span class="material-symbols-outlined">error</span>
                    <span><?php echo htmlspecialchars($aiError); ?></span>
                </div>
                <?php endif; ?>

                <!-- Success Message -->
                <?php if (!empty($successMessage)): ?>
                <div class="message success">
                    <span class="material-symbols-outlined">check_circle</span>
                    <span><?php echo htmlspecialchars($successMessage); ?></span>
                </div>
                <?php endif; ?>

                <!-- Error Message -->
                <?php if (!empty($errorMessage)): ?>
                <div class="message error">
                    <span class="material-symbols-outlined">error</span>
                    <span><?php echo htmlspecialchars($errorMessage); ?></span>
                </div>
                <?php endif; ?>

                <!-- Edit Form -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">edit_note</span>
                            Resume Information
                        </h3>
                        <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">Fields marked with <span style="color:#dc2626;">*</span> are required</span>
                    </div>
                    <div class="card-body">

                        <form method="POST" action="" id="editProfileForm">
                            <input type="hidden" name="action" value="save_and_analyze" id="formAction">

                            <!-- Career Objective -->
                            <div class="form-group">
                                <label for="careerObjective">Career Objective</label>
                                <textarea id="careerObjective" name="career_objective" class="form-control <?php echo isset($aiFilledData['career_objective']) ? 'ai-filled' : ''; ?>" 
                                          placeholder="e.g., Motivated and detail-oriented Software Developer seeking a position in a dynamic tech company to apply my skills and contribute to organizational growth." 
                                          maxlength="500"><?php echo htmlspecialchars(isset($aiFilledData['career_objective']) ? $aiFilledData['career_objective'] : ($applicant['career_objective'] ?? '')); ?></textarea>
                                <div class="helper-text">
                                    <span class="material-symbols-outlined">info</span>
                                    Briefly describe your career goals and what you're looking for (max 500 characters).
                                </div>
                                <div class="char-count"><span id="careerCharCount">0</span>/500</div>
                            </div>

                            <!-- Skills -->
                            <div class="form-group">
                                <label for="skills">Key Skills <span class="required">*</span></label>
                                <textarea id="skills" name="skills" class="form-control <?php echo isset($aiFilledData['skills']) ? 'ai-filled' : ''; ?>" 
                                          placeholder="e.g., PHP, Laravel, MySQL, JavaScript, HTML, CSS, Git, Leadership, Communication" 
                                          required><?php echo htmlspecialchars(isset($aiFilledData['skills']) ? $aiFilledData['skills'] : ($applicant['skills'] ?? '')); ?></textarea>
                                <div class="helper-text">
                                    <span class="material-symbols-outlined">info</span>
                                    List your technical and soft skills separated by commas. AI will help analyze your skills.
                                </div>
                            </div>

                            <!-- Experience -->
                            <div class="form-group">
                                <label for="experience">Experience</label>
                                <textarea id="experience" name="experience" class="form-control <?php echo isset($aiFilledData['experience']) ? 'ai-filled' : ''; ?>" 
                                          placeholder="e.g., Job Title - Company Name (Month Year - Month Year)&#10;• Key responsibility or achievement #1&#10;• Key responsibility or achievement #2"><?php echo htmlspecialchars(isset($aiFilledData['experience']) ? $aiFilledData['experience'] : ($applicant['experience'] ?? '')); ?></textarea>
                                <div class="helper-text">
                                    <span class="material-symbols-outlined">info</span>
                                    Describe your work experience. Include job title, company, dates, and key achievements.
                                </div>
                            </div>

                            <!-- Education -->
                            <div class="form-group">
                                <label for="education">Education</label>
                                <textarea id="education" name="education" class="form-control <?php echo isset($aiFilledData['education']) ? 'ai-filled' : ''; ?>" 
                                          placeholder="e.g., B.S. in Computer Science - University of the Philippines (2016 - 2020)&#10;GPA: 1.75"><?php echo htmlspecialchars(isset($aiFilledData['education']) ? $aiFilledData['education'] : ($applicant['education'] ?? '')); ?></textarea>
                                <div class="helper-text">
                                    <span class="material-symbols-outlined">info</span>
                                    List your educational background including degree, institution, and years.
                                </div>
                            </div>

                            <!-- AI Analysis Note -->
                            <div style="padding:0.75rem 1rem; background:rgba(79,70,229,0.04); border-radius:0.75rem; border:1px solid rgba(79,70,229,0.1); margin-top:0.5rem; display:flex; align-items:center; gap:0.5rem;">
                                <span class="material-symbols-outlined" style="color:var(--primary);">auto_awesome</span>
                                <span style="font-size:0.8125rem; color:var(--text-on-surface-variant);">
                                    <strong>AI will analyze</strong> your profile after saving to extract skills, experience, and education insights.
                                </span>
                            </div>

                            <!-- Form Actions -->
                            <div class="form-actions">
                                <button type="submit" class="btn btn-save-analyze" id="saveAnalyzeBtn">
                                    <span class="material-symbols-outlined">auto_awesome</span>
                                    Save & Analyze
                                </button>
                                <a href="profile.php" class="btn btn-outline">
                                    <span class="material-symbols-outlined">cancel</span>
                                    Cancel
                                </a>
                            </div>

                        </form>

                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- =============================================
    SAVING PROGRESS MODAL - 3 DOTS
    ============================================= -->
    <div class="saving-modal" id="savingModal">
        <div class="saving-modal-content">
            <div class="saving-icon" id="savingIcon">
                <span class="material-symbols-outlined" id="savingIconSymbol">auto_awesome</span>
            </div>
            <h3 class="saving-title" id="savingTitle">Saving Profile</h3>
            <p class="saving-subtitle" id="savingSubtitle">Analyzing your profile with AI...</p>

            <div class="dots-progress" id="dotsProgress">
                <span class="dot" id="dot0"></span>
                <span class="dot" id="dot1"></span>
                <span class="dot" id="dot2"></span>
            </div>
            <div class="dots-progress-label" id="progressLabel">Step 1 of 3</div>
        </div>
    </div>

    <!-- =============================================
    JAVASCRIPT
    ============================================= -->
    <script>
        // =============================================
        // 1. SIDEBAR TOGGLE
        // =============================================
        const sidebar = document.getElementById('appSidebar');
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        const sidebarToggleIcon = document.getElementById('sidebarToggleIcon');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');

        const savedState = localStorage.getItem('sidebarCollapsed');
        const isDesktop = window.innerWidth >= 768;

        if (savedState === 'true' && isDesktop) {
            sidebar.classList.add('collapsed');
            sidebarToggleIcon.textContent = 'menu';
        }

        sidebarToggleBtn.addEventListener('click', function() {
            if (window.innerWidth < 768) return;
            sidebar.classList.toggle('collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            sidebarToggleIcon.textContent = isCollapsed ? 'menu' : 'menu_open';
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        });

        // =============================================
        // 2. MOBILE SIDEBAR
        // =============================================
        function openMobileSidebar() {
            sidebar.classList.add('mobile-open');
            sidebar.classList.remove('mobile-hidden');
            sidebarBackdrop.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeMobileSidebar() {
            sidebar.classList.remove('mobile-open');
            sidebar.classList.add('mobile-hidden');
            sidebarBackdrop.classList.remove('active');
            document.body.style.overflow = '';
        }

        mobileMenuBtn.addEventListener('click', openMobileSidebar);
        sidebarBackdrop.addEventListener('click', closeMobileSidebar);

        document.querySelectorAll('.sidebar-main-link').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth < 768) {
                    closeMobileSidebar();
                }
            });
        });

        // =============================================
        // 3. RESPONSIVE HANDLING
        // =============================================
        let resizeTimer;

        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                const width = window.innerWidth;

                if (width >= 768) {
                    closeMobileSidebar();
                    sidebar.classList.remove('mobile-open', 'mobile-hidden');
                    const saved = localStorage.getItem('sidebarCollapsed');
                    if (saved === 'true') {
                        sidebar.classList.add('collapsed');
                        sidebarToggleIcon.textContent = 'menu';
                    } else {
                        sidebar.classList.remove('collapsed');
                        sidebarToggleIcon.textContent = 'menu_open';
                    }
                } else {
                    sidebar.classList.add('mobile-hidden');
                    sidebar.classList.remove('collapsed');
                    sidebarToggleIcon.textContent = 'menu_open';
                }
            }, 250);
        });

        // =============================================
        // 4. CHARACTER COUNTER
        // =============================================
        const careerInput = document.getElementById('careerObjective');
        const careerCounter = document.getElementById('careerCharCount');

        if (careerInput && careerCounter) {
            careerInput.addEventListener('input', function() {
                const length = this.value.length;
                careerCounter.textContent = length;
                if (length > 500) {
                    careerCounter.style.color = '#dc2626';
                } else {
                    careerCounter.style.color = '';
                }
            });
            careerCounter.textContent = careerInput.value.length;
        }

        // =============================================
        // 5. RESUME UPLOAD HANDLER
        // =============================================
        const resumeFile = document.getElementById('resumeFile');
        const fileName = document.getElementById('fileName');
        const uploadBtn = document.getElementById('uploadBtn');

        if (resumeFile) {
            resumeFile.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    const file = this.files[0];
                    
                    // Validate file size client-side
                    const maxSize = <?php echo MAX_FILE_SIZE; ?>;
                    if (file.size > maxSize) {
                        alert('File size exceeds ' + (maxSize / 1024 / 1024) + 'MB limit.');
                        this.value = '';
                        return;
                    }
                    
                    // Validate extension
                    const allowedExtensions = ['pdf', 'doc', 'docx', 'txt'];
                    const ext = file.name.split('.').pop().toLowerCase();
                    if (!allowedExtensions.includes(ext)) {
                        alert('Invalid file type. Allowed: ' + allowedExtensions.join(', '));
                        this.value = '';
                        return;
                    }
                    
                    fileName.textContent = '📎 Selected: ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
                    fileName.classList.add('show');
                    uploadBtn.style.display = 'inline-flex';
                    
                    setTimeout(function() {
                        document.getElementById('resumeUploadForm').submit();
                    }, 1000);
                } else {
                    fileName.classList.remove('show');
                    uploadBtn.style.display = 'none';
                }
            });
        }

        // =============================================
        // 6. PROFILE DROPDOWN
        // =============================================
        const profileToggle = document.getElementById('profileDropdownToggle');
        const profileMenu = document.getElementById('profileDropdownMenu');

        if (profileToggle && profileMenu) {
            profileToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                this.classList.toggle('open');
                profileMenu.classList.toggle('open');
            });

            document.addEventListener('click', function(e) {
                if (!profileToggle.contains(e.target) && !profileMenu.contains(e.target)) {
                    profileToggle.classList.remove('open');
                    profileMenu.classList.remove('open');
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    profileToggle.classList.remove('open');
                    profileMenu.classList.remove('open');
                }
            });
        }

        // =============================================
        // 7. SAVING MODAL - 3 DOTS PROGRESS
        // =============================================
        function showSavingModal() {
            const modal = document.getElementById('savingModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            let step = 0;
            const totalSteps = 3;
            const steps = ['Saving', 'Analyzing', 'Complete!'];
            const icons = ['save', 'psychology', 'check_circle'];
            
            const dotIds = ['dot0', 'dot1', 'dot2'];
            const icon = document.getElementById('savingIcon');
            const iconSymbol = document.getElementById('savingIconSymbol');
            const title = document.getElementById('savingTitle');
            const subtitle = document.getElementById('savingSubtitle');
            const label = document.getElementById('progressLabel');
            
            function updateProgress() {
                // Update dots
                for (let i = 0; i < totalSteps; i++) {
                    const dot = document.getElementById(dotIds[i]);
                    dot.className = 'dot';
                    if (i < step) {
                        dot.classList.add('done');
                    } else if (i === step) {
                        dot.classList.add('active');
                    }
                }
                
                // Update text
                title.textContent = steps[step] || 'Processing...';
                subtitle.textContent = step === totalSteps - 1 ? 'Profile complete! Redirecting...' : 'Please wait while we process your profile...';
                iconSymbol.textContent = icons[step] || 'auto_awesome';
                label.textContent = `Step ${step + 1} of ${totalSteps}`;
                
                if (step === totalSteps - 1) {
                    icon.className = 'saving-icon done';
                    document.getElementById('savingTitle').textContent = '✅ Profile Complete!';
                    document.getElementById('savingSubtitle').textContent = 'Redirecting to your profile...';
                }
                
                step++;
                
                if (step < totalSteps) {
                    setTimeout(updateProgress, 500);
                } else {
                    setTimeout(function() {
                        window.location.href = 'profile.php?updated=1';
                    }, 600);
                }
            }
            
            setTimeout(updateProgress, 400);
        }

        // =============================================
        // 8. SAVE & ANALYZE FORM SUBMIT
        // =============================================
        document.getElementById('editProfileForm')?.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('#saveAnalyzeBtn');
            if (submitBtn) {
                e.preventDefault();
                showSavingModal();
                setTimeout(() => {
                    this.submit();
                }, 300);
            }
        });

        // =============================================
        // 9. SAVE FROM AI FORM - PREVENT AUTO SUBMIT
        // =============================================
        document.getElementById('saveFromAIForm')?.addEventListener('submit', function(e) {
            // Allow normal submit - it redirects to the same page with ai_filled=1
        });

        // =============================================
        // 10. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (window.innerWidth < 768) {
                    closeMobileSidebar();
                }
                if (profileToggle) profileToggle.classList.remove('open');
                if (profileMenu) profileMenu.classList.remove('open');
            }
        });

        // =============================================
        // 11. INITIAL STATE
        // =============================================
        if (window.innerWidth < 768) {
            sidebar.classList.add('mobile-hidden');
        }

        console.log('✏️ ISMERS Edit Profile Page loaded successfully!');
        console.log('🤖 AI Resume Analysis enabled');
        console.log('📄 Resume Upload & Auto-Fill enabled');
        console.log('📦 PDF Parser: ' + ('<?php echo $pdfParserAvailable ? 'Available' : 'Not Available'; ?>'));
        console.log('🔒 Secure upload: MIME validation, signature check, content scanning');
        console.log('🎯 3-Step Progress Modal enabled');
    </script>
<script src="/session_guard.js"></script>
</body>
</html>
