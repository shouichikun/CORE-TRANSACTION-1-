<?php
// portals/hr/post_job.php - Post New Job with REAL AI Integration
session_start();

require_once '../../app/config.php';
require_once '../../app/ai/AiService.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

// Check if user has HR role
if (!in_array($_SESSION['role'], ['hr_manager', 'recruiter'])) {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'HR User';
$firstName = $_SESSION['first_name'] ?? '';
$email = $_SESSION['email'] ?? '';
$role = $_SESSION['role'] ?? 'hr_manager';

// =============================================
// AI SERVICE INITIALIZATION
// =============================================
$aiService = new AiService();

// Database helper function
if (!function_exists('getRecord')) {
    function getRecord($sql, $params = [], $types = "") {
        global $conn;
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return ['count' => 0];
        }
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ?? ['count' => 0];
    }
}

// Get user's clients with industry info
$clients = getRecords("SELECT id, company_name, industry FROM clients WHERE user_id = ? OR is_active = 1", [$userId], "i");
$hasClients = !empty($clients);

// Initialize variables
$successMessage = '';
$errorMessage = '';
$formData = [];

// Job types and levels
$jobTypes = ['Full-time', 'Part-time', 'Contract', 'Temporary', 'Internship', 'Freelance'];
$experienceLevels = ['Entry', 'Junior', 'Mid', 'Senior', 'Lead', 'Manager'];
$jobStatuses = ['draft', 'open', 'ongoing', 'filled', 'cancelled'];
$urgencyLevels = ['low', 'medium', 'high'];

// =============================================
// INDUSTRY-BASED SKILLS MAPPING
// =============================================
function getIndustrySkills($industry, $jobTitle) {
    $industry = strtolower($industry);
    $titleLower = strtolower($jobTitle);
    
    // Industry-specific skill sets
    $industrySkills = [
        'technology' => [
            'software' => ['PHP', 'JavaScript', 'Python', 'Java', 'SQL', 'Git', 'Docker', 'REST APIs', 'Agile', 'Scrum'],
            'web' => ['HTML5', 'CSS3', 'React', 'Vue.js', 'Angular', 'Node.js', 'Laravel', 'WordPress', 'Responsive Design'],
            'mobile' => ['Swift', 'Kotlin', 'React Native', 'Flutter', 'Firebase', 'REST APIs', 'Git', 'App Store', 'Play Store'],
            'data' => ['Python', 'SQL', 'Power BI', 'Tableau', 'ETL', 'Data Visualization', 'Statistical Analysis', 'Machine Learning'],
            'devops' => ['AWS', 'Docker', 'Kubernetes', 'Linux', 'Jenkins', 'Terraform', 'CI/CD', 'Ansible', 'Cloud Computing'],
            'security' => ['Network Security', 'Penetration Testing', 'OWASP', 'Kali Linux', 'Burp Suite', 'Firewall', 'Encryption'],
            'default' => ['Programming', 'Problem Solving', 'Git', 'Teamwork', 'Communication', 'Agile', 'Testing']
        ],
        'finance' => [
            'accounting' => ['Financial Reporting', 'QuickBooks', 'Xero', 'Payroll', 'Tax Compliance', 'Accounts Payable', 'Accounts Receivable'],
            'investment' => ['Financial Analysis', 'Portfolio Management', 'Investment Banking', 'Bloomberg', 'Excel', 'Financial Modeling'],
            'banking' => ['Risk Management', 'Compliance', 'Anti-Money Laundering', 'Financial Analysis', 'Regulatory Reporting', 'Customer Service'],
            'insurance' => ['Underwriting', 'Claims Processing', 'Risk Assessment', 'Insurance Regulations', 'Customer Service', 'Data Analysis'],
            'default' => ['Financial Analysis', 'Excel', 'Budgeting', 'Compliance', 'Attention to Detail', 'Analytical Skills']
        ],
        'healthcare' => [
            'nursing' => ['Patient Care', 'Medical Records', 'Vital Signs', 'Medication Administration', 'EMR Systems', 'CPR Certified'],
            'medical' => ['Medical Terminology', 'Patient Care', 'HIPAA Compliance', 'Clinical Documentation', 'Anatomy', 'Physiology'],
            'pharma' => ['Pharmaceutical Knowledge', 'Regulatory Compliance', 'Drug Safety', 'Clinical Research', 'FDA Regulations'],
            'wellness' => ['Health Promotion', 'Wellness Programs', 'Nutrition', 'Health Education', 'Fitness Assessment', 'Rehabilitation'],
            'default' => ['Patient Care', 'Communication', 'Medical Knowledge', 'Empathy', 'Attention to Detail', 'Teamwork']
        ],
        'education' => [
            'teaching' => ['Lesson Planning', 'Curriculum Development', 'Classroom Management', 'Student Assessment', 'Educational Technology'],
            'administration' => ['Educational Leadership', 'Staff Management', 'Curriculum Design', 'Student Services', 'Policy Development'],
            'training' => ['Instructional Design', 'Training Delivery', 'Learning Management Systems', 'Curriculum Development', 'Assessment'],
            'default' => ['Teaching', 'Communication', 'Patience', 'Organization', 'Creativity', 'Assessment']
        ],
        'retail' => [
            'store' => ['Inventory Management', 'Visual Merchandising', 'Customer Service', 'Point of Sale', 'Staff Training', 'Loss Prevention'],
            'ecommerce' => ['E-commerce Platforms', 'Digital Marketing', 'SEO', 'Analytics', 'Customer Service', 'Inventory Management'],
            'supply_chain' => ['Logistics', 'Supply Chain Management', 'Inventory Control', 'Vendor Management', 'Procurement'],
            'default' => ['Customer Service', 'Sales', 'Communication', 'Teamwork', 'Problem Solving', 'Inventory Management']
        ],
        'hospitality' => [
            'hotel' => ['Front Desk', 'Guest Services', 'Bookings', 'Property Management', 'Housekeeping', 'Event Planning'],
            'restaurant' => ['Food Service', 'Kitchen Management', 'Inventory Management', 'Menu Planning', 'Food Safety', 'Customer Service'],
            'tourism' => ['Travel Planning', 'Tour Guide', 'Customer Service', 'Booking Systems', 'Destination Knowledge'],
            'default' => ['Customer Service', 'Communication', 'Teamwork', 'Problem Solving', 'Multitasking', 'Organization']
        ],
        'construction' => [
            'engineering' => ['AutoCAD', 'Revit', 'Civil Engineering', 'Project Management', 'Safety Compliance', 'Structural Design'],
            'project' => ['Project Management', 'Safety Compliance', 'Budgeting', 'Blueprint Reading', 'Construction Methods'],
            'skilled' => ['Blueprint Reading', 'Safety Protocols', 'Tool Knowledge', 'Trade Skills', 'Problem Solving', 'Teamwork'],
            'default' => ['Safety Compliance', 'Project Management', 'Blueprint Reading', 'Communication', 'Teamwork', 'Problem Solving']
        ],
        'media' => [
            'content' => ['Content Creation', 'Video Production', 'Editing', 'Social Media', 'Copywriting', 'Photoshop', 'Premiere Pro'],
            'digital' => ['Digital Marketing', 'SEO', 'SEM', 'Analytics', 'Ad Campaigns', 'Social Media Strategy'],
            'broadcast' => ['Broadcast Production', 'Video Editing', 'Audio Engineering', 'Script Writing', 'On-Air Talent'],
            'default' => ['Content Creation', 'Communication', 'Creativity', 'Social Media', 'Video Production', 'Copywriting']
        ],
        'legal' => [
            'law' => ['Legal Research', 'Document Review', 'Case Management', 'Legal Writing', 'Client Communication'],
            'paralegal' => ['Legal Documentation', 'Case Management', 'Legal Research', 'Client Communication', 'Scheduling'],
            'compliance' => ['Compliance Management', 'Risk Assessment', 'Policy Development', 'Regulatory Reporting'],
            'default' => ['Legal Research', 'Attention to Detail', 'Communication', 'Organization', 'Problem Solving']
        ],
        'real_estate' => [
            'property' => ['Property Management', 'Sales', 'Customer Service', 'Marketing', 'Negotiation', 'Market Analysis'],
            'development' => ['Real Estate Development', 'Project Management', 'Zoning Regulations', 'Construction', 'Feasibility Studies'],
            'default' => ['Property Management', 'Sales', 'Negotiation', 'Marketing', 'Communication', 'Market Knowledge']
        ],
        'manufacturing' => [
            'production' => ['Production Planning', 'Quality Control', 'Lean Manufacturing', 'Six Sigma', 'Safety Compliance', 'Machine Operation'],
            'quality' => ['Quality Assurance', 'ISO Standards', 'Auditing', 'Statistical Analysis', 'Root Cause Analysis'],
            'supply' => ['Supply Chain', 'Inventory Management', 'Logistics', 'Procurement', 'Vendor Management'],
            'default' => ['Safety Compliance', 'Quality Control', 'Production Planning', 'Problem Solving', 'Teamwork']
        ],
        'fitness' => [
            'training' => ['Personal Training', 'Exercise Science', 'Nutrition', 'First Aid', 'Group Fitness', 'Client Assessment'],
            'coaching' => ['Sports Coaching', 'Athlete Development', 'Performance Training', 'Team Management', 'Game Strategy'],
            'wellness' => ['Wellness Program', 'Health Education', 'Fitness Assessment', 'Nutrition', 'Lifestyle Coaching'],
            'default' => ['Physical Fitness', 'Nutrition Knowledge', 'Communication', 'Motivation', 'First Aid', 'CPR']
        ]
    ];
    
    // Find matching industry
    $industryKey = 'default';
    $subIndustryKey = 'default';
    
    foreach ($industrySkills as $ind => $subIndustries) {
        if (stripos($industry, $ind) !== false || stripos($ind, $industry) !== false) {
            $industryKey = $ind;
            foreach ($subIndustries as $sub => $skills) {
                if (stripos($titleLower, $sub) !== false) {
                    $subIndustryKey = $sub;
                    break 2;
                }
            }
            break;
        }
    }
    
    // Get skills for the matched sub-industry or fallback to default
    if (isset($industrySkills[$industryKey][$subIndustryKey])) {
        return $industrySkills[$industryKey][$subIndustryKey];
    } elseif (isset($industrySkills[$industryKey]['default'])) {
        return $industrySkills[$industryKey]['default'];
    }
    
    // Fallback: generic skills based on job title
    $genericSkills = [
        'developer' => ['PHP', 'JavaScript', 'Python', 'SQL', 'Git', 'Problem Solving', 'Teamwork', 'Communication'],
        'designer' => ['Figma', 'Adobe Creative Suite', 'UI/UX', 'Visual Design', 'Prototyping', 'User Research'],
        'manager' => ['Leadership', 'Project Management', 'Communication', 'Strategic Planning', 'Team Management', 'Budgeting'],
        'analyst' => ['Data Analysis', 'SQL', 'Excel', 'Critical Thinking', 'Problem Solving', 'Communication'],
        'sales' => ['Sales Strategy', 'Negotiation', 'CRM', 'Communication', 'Lead Generation', 'Customer Relationship'],
        'hr' => ['Recruitment', 'Employee Relations', 'Performance Management', 'Communication', 'Compliance', 'HRIS'],
        'marketing' => ['Digital Marketing', 'SEO', 'Content Strategy', 'Analytics', 'Social Media', 'Brand Management'],
        'finance' => ['Financial Analysis', 'Budgeting', 'Excel', 'Financial Reporting', 'Compliance', 'Analytical Skills'],
        'operations' => ['Process Improvement', 'Project Management', 'Operations Management', 'Analytics', 'Leadership'],
        'support' => ['Customer Service', 'Problem Solving', 'Communication', 'Patience', 'Multitasking', 'Empathy']
    ];
    
    foreach ($genericSkills as $key => $skills) {
        if (stripos($titleLower, $key) !== false) {
            return $skills;
        }
    }
    
    return ['Communication', 'Problem Solving', 'Teamwork', 'Time Management', 'Leadership', 'Analytical Skills'];
}

// =============================================
// GENERATE JOB DESCRIPTION BASED ON TITLE & INDUSTRY
// =============================================
function generateSmartDescription($jobData, $clientIndustry = '') {
    $title = $jobData['title'] ?? 'the position';
    $skills = $jobData['skills_required'] ?? '';
    $experience = $jobData['experience_level'] ?? 'Mid';
    $industry = !empty($clientIndustry) ? $clientIndustry : detectIndustryFromTitle($title);
    
    $skillList = array_map('trim', explode(',', $skills));
    $skillList = array_filter($skillList);
    
    $experienceMap = [
        'Entry' => '0-2 years',
        'Junior' => '1-3 years',
        'Mid' => '3-5 years',
        'Senior' => '5-8 years',
        'Lead' => '8+ years',
        'Manager' => '5+ years'
    ];
    $expYears = $experienceMap[$experience] ?? '3-5 years';
    
    // Get industry-specific skills
    $industrySkills = getIndustrySkills($industry, $title);
    $allSkills = array_unique(array_merge($industrySkills, $skillList));
    
    // Industry-specific description templates
    $industryDescriptions = [
        'technology' => "cutting-edge technology solutions and digital innovation",
        'finance' => "financial excellence, strategic investment, and regulatory compliance",
        'healthcare' => "patient-centered care, medical excellence, and wellness programs",
        'education' => "student success, learning excellence, and innovative teaching methods",
        'retail' => "customer satisfaction, retail excellence, and brand loyalty",
        'hospitality' => "exceptional guest experiences and world-class hospitality",
        'construction' => "quality construction projects and infrastructure development",
        'media' => "creative content production and audience engagement",
        'legal' => "legal excellence, justice, and client advocacy",
        'real_estate' => "property development and real estate investment",
        'manufacturing' => "quality manufacturing and production excellence",
        'fitness' => "health, wellness, and physical fitness excellence"
    ];
    
    $industryFocus = $industryDescriptions[strtolower($industry)] ?? 'professional excellence and client satisfaction';
    
    // Build the description
    $fullDescription = "We are seeking a talented and experienced {$title} to join our team in the {$industry} industry. This is an exciting opportunity for a professional who is passionate about {$industryFocus}.\n\n";
    
    $fullDescription .= "Key Responsibilities:\n";
    $fullDescription .= "• Lead and manage key initiatives within the {$title} role\n";
    $fullDescription .= "• Collaborate with cross-functional teams to achieve organizational goals\n";
    $fullDescription .= "• Drive innovation and implement best practices in the {$industry} sector\n";
    $fullDescription .= "• Ensure high-quality deliverables and client satisfaction\n";
    $fullDescription .= "• Stay up-to-date with industry trends and emerging practices\n\n";
    
    $fullDescription .= "Required Qualifications:\n";
    $fullDescription .= "• {$expYears} of experience in a similar role\n";
    if (!empty($allSkills)) {
        $fullDescription .= "• Strong proficiency in: " . implode(', ', array_slice($allSkills, 0, 8)) . "\n";
    }
    $fullDescription .= "• Excellent communication and interpersonal skills\n";
    $fullDescription .= "• Strong problem-solving and analytical abilities\n";
    $fullDescription .= "• Bachelor's degree in a related field\n\n";
    
    $fullDescription .= "What We Offer:\n";
    $fullDescription .= "• Competitive salary and benefits package\n";
    $fullDescription .= "• Professional development and growth opportunities\n";
    $fullDescription .= "• Collaborative and inclusive work culture\n";
    $fullDescription .= "• Opportunity to work on impactful projects in the {$industry} industry\n";
    $fullDescription .= "• Flexible work arrangements\n\n";
    
    $fullDescription .= "If you are passionate about the {$industry} industry and want to make a difference, we would love to hear from you!";
    
    return $fullDescription;
}

function detectIndustryFromTitle($title) {
    $titleLower = strtolower($title);
    $industryMap = [
        'developer' => 'Technology',
        'engineer' => 'Technology',
        'programmer' => 'Technology',
        'designer' => 'Technology',
        'analyst' => 'Finance',
        'accountant' => 'Finance',
        'finance' => 'Finance',
        'teacher' => 'Education',
        'educator' => 'Education',
        'professor' => 'Education',
        'nurse' => 'Healthcare',
        'doctor' => 'Healthcare',
        'medical' => 'Healthcare',
        'coach' => 'Fitness',
        'trainer' => 'Fitness',
        'fitness' => 'Fitness',
        'sales' => 'Retail',
        'marketing' => 'Media',
        'hr' => 'Technology',
        'recruiter' => 'Technology',
        'chef' => 'Hospitality',
        'restaurant' => 'Hospitality',
        'hotel' => 'Hospitality'
    ];
    
    foreach ($industryMap as $keyword => $industry) {
        if (stripos($titleLower, $keyword) !== false) {
            return $industry;
        }
    }
    return 'Technology';
}

// =============================================
// Get AI suggestions via AJAX - USING REAL AI
// =============================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'ai_suggestions') {
    header('Content-Type: application/json');
    
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $skills = $_POST['skills_required'] ?? '';
    $experience_level = $_POST['experience_level'] ?? '';
    $client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
    
    // Get client industry
    $clientIndustry = 'Technology';
    if ($client_id > 0) {
        $client = getRecord("SELECT industry FROM clients WHERE id = ?", [$client_id], "i");
        if ($client && !empty($client['industry'])) {
            $clientIndustry = $client['industry'];
        }
    }
    
    $jobData = [
        'title' => $title,
        'description' => $description,
        'skills_required' => $skills,
        'experience_level' => $experience_level
    ];
    
    // =============================================
    // USE REAL AI VIA AiService
    // =============================================
    try {
        $aiSuggestions = $aiService->optimizeJobDescription($jobData);
        
        // Check if we got real AI or mock
        $provider = $aiSuggestions['provider'] ?? 'mock';
        
        // If provider is mock, use our fallback
        if ($provider === 'mock') {
            // Fallback to industry-based skills
            $industrySkills = getIndustrySkills($clientIndustry, $title);
            $existingSkills = array_map('trim', explode(',', $skills));
            $existingSkills = array_filter($existingSkills);
            $allSkills = array_unique(array_merge($industrySkills, $existingSkills));
            $smartDescription = generateSmartDescription($jobData, $clientIndustry);
            
            // Salary ranges based on industry
            $salaryRanges = [
                'Technology' => ['default' => '₱50,000 - ₱90,000'],
                'Finance' => ['default' => '₱55,000 - ₱100,000'],
                'Healthcare' => ['default' => '₱40,000 - ₱80,000'],
                'Education' => ['default' => '₱35,000 - ₱70,000'],
                'Retail' => ['default' => '₱35,000 - ₱65,000'],
                'Hospitality' => ['default' => '₱30,000 - ₱60,000'],
                'Fitness' => ['default' => '₱30,000 - ₱55,000'],
                'Construction' => ['default' => '₱40,000 - ₱75,000'],
                'Media' => ['default' => '₱35,000 - ₱70,000'],
                'Legal' => ['default' => '₱40,000 - ₱80,000'],
                'Real Estate' => ['default' => '₱35,000 - ₱70,000'],
                'Manufacturing' => ['default' => '₱40,000 - ₱75,000']
            ];
            
            $salary = $salaryRanges[$clientIndustry]['default'] ?? '₱50,000 - ₱80,000';
            
            $diversityWarnings = [];
            $diversitySuggestions = [];
            if (stripos($description, 'he') !== false || stripos($description, 'she') !== false) {
                $diversityWarnings[] = "Avoid gender-specific pronouns in the description";
                $diversitySuggestions[] = "Use 'they' or 'the candidate' instead";
            }
            if (stripos($description, 'fresh graduate') !== false) {
                $diversityWarnings[] = "Consider removing 'fresh graduate' requirement";
                $diversitySuggestions[] = "Use 'entry-level' or 'early career' instead";
            }
            
            echo json_encode([
                'success' => true,
                'suggestions' => [
                    'suggested_skills' => array_values($allSkills),
                    'improved_description' => $smartDescription,
                    'suggested_title' => $title,
                    'salary_range' => $salary,
                    'diversity_check' => [
                        'warnings' => $diversityWarnings,
                        'suggestions' => $diversitySuggestions
                    ],
                    'optimized_full_description' => $smartDescription,
                    'industry' => $clientIndustry,
                    'provider' => 'fallback'
                ]
            ]);
        } else {
            // Real AI response
            echo json_encode([
                'success' => true,
                'suggestions' => [
                    'suggested_skills' => $aiSuggestions['suggested_skills'] ?? [],
                    'improved_description' => $aiSuggestions['improved_description'] ?? $description,
                    'suggested_title' => $aiSuggestions['suggested_title'] ?? $title,
                    'salary_range' => $aiSuggestions['salary_range'] ?? '₱50,000 - ₱80,000',
                    'diversity_check' => $aiSuggestions['diversity_check'] ?? ['warnings' => [], 'suggestions' => []],
                    'optimized_full_description' => $aiSuggestions['improved_description'] ?? $description,
                    'industry' => $clientIndustry,
                    'provider' => 'groq'
                ]
            ]);
        }
    } catch (Exception $e) {
        error_log("AI Error: " . $e->getMessage());
        
        // Fallback to safe response
        $industrySkills = getIndustrySkills($clientIndustry, $title);
        $existingSkills = array_map('trim', explode(',', $skills));
        $existingSkills = array_filter($existingSkills);
        $allSkills = array_unique(array_merge($industrySkills, $existingSkills));
        $smartDescription = generateSmartDescription($jobData, $clientIndustry);
        
        echo json_encode([
            'success' => true,
            'suggestions' => [
                'suggested_skills' => array_values($allSkills),
                'improved_description' => $smartDescription,
                'suggested_title' => $title,
                'salary_range' => '₱50,000 - ₱80,000',
                'diversity_check' => ['warnings' => [], 'suggestions' => []],
                'optimized_full_description' => $smartDescription,
                'industry' => $clientIndustry,
                'provider' => 'fallback'
            ]
        ]);
    }
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'client_id' => (int)$_POST['client_id'] ?? 0,
        'title' => trim($_POST['title'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'skills_required' => trim($_POST['skills_required'] ?? ''),
        'salary_range' => trim($_POST['salary_range'] ?? ''),
        'location' => trim($_POST['location'] ?? ''),
        'job_type' => $_POST['job_type'] ?? 'Full-time',
        'experience_level' => $_POST['experience_level'] ?? 'Entry',
        'status' => $_POST['status'] ?? 'open',
        'urgency' => $_POST['urgency'] ?? 'medium',
        'positions_available' => (int)($_POST['positions_available'] ?? 1),
        'application_deadline' => $_POST['application_deadline'] ?? ''
    ];
    
    $errors = [];
    if (empty($formData['client_id'])) $errors[] = 'Please select a client company.';
    if (empty($formData['title'])) $errors[] = 'Job title is required.';
    if (empty($formData['description'])) $errors[] = 'Job description is required.';
    if (empty($formData['skills_required'])) $errors[] = 'Skills required is required.';
    
    if (empty($errors)) {
        $sql = "INSERT INTO job_orders (
            client_id, title, description, skills_required, salary_range, 
            location, job_type, experience_level, status, urgency, 
            positions_available, application_deadline, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $jobId = insertRecord($sql, [
            $formData['client_id'],
            $formData['title'],
            $formData['description'],
            $formData['skills_required'],
            $formData['salary_range'],
            $formData['location'],
            $formData['job_type'],
            $formData['experience_level'],
            $formData['status'],
            $formData['urgency'],
            $formData['positions_available'],
            $formData['application_deadline'],
            $userId
        ], "issssssssssis");
        
        if ($jobId) {
            logActivity($userId, 'Job Posted', 'job_orders', $jobId, 'Posted job: ' . $formData['title']);
            $successMessage = 'Job posted successfully!';
            $formData = [];
            header('Refresh: 2; URL=jobs.php');
        } else {
            $errorMessage = 'Failed to post job. Please try again.';
        }
    } else {
        $errorMessage = implode('<br>', $errors);
    }
}

// Get client list for dropdown
$clientOptions = '';
foreach ($clients as $client) {
    $selected = ($formData['client_id'] ?? '') == $client['id'] ? 'selected' : '';
    $industry = htmlspecialchars($client['industry'] ?? 'General');
    $clientOptions .= "<option value=\"{$client['id']}\" $selected data-industry=\"{$industry}\">" . htmlspecialchars($client['company_name']) . " ({$industry})</option>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Post Job - ISMERS AI</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        /* ==========================================================================
           MATERIAL 3 DESIGN SYSTEM - POST JOB WITH AI
           ========================================================================== */
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
   SIDEBAR - STANDARDIZED
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

/* Hide text when collapsed */
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

/* Sidebar Brand */
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

/* Sidebar Navigation */
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

/* Sidebar Footer */
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

/* Sidebar Backdrop */
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
            max-width: 56rem;
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

        .page-header .header-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* =============================================
           BUTTONS - SMALLER AI BUTTONS
        ============================================= */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.8rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.75rem;
            border: none;
            cursor: pointer;
            transition: all var(--transition-fast);
            font-family: var(--font-sans);
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--on-primary-fixed-variant);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1.5px solid var(--primary);
            padding: 0.35rem 0.75rem;
        }

        .btn-outline:hover {
            background: var(--bg-surface-low);
        }

        .btn-success {
            background: #22c55e;
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

      .btn-sm {
    padding: 0.25rem 0.6rem;
    font-size: 0.7rem;
    border-radius: 0.4rem;
}

       .btn-sm .material-symbols-outlined {
    font-size: 0.65rem !important;
}

        .btn-sm .material-symbols-outlined {
            font-size: 0.8rem;
        }

        .btn-ai {
            background: linear-gradient(135deg, #7c3aed, #4f46e5);
            color: white;
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        .btn-ai:hover {
            background: linear-gradient(135deg, #6d28d9, #4338ca);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-ai .material-symbols-outlined {
            font-size: 0.9rem;
        }

        /* =============================================
           AI MODAL
        ============================================= */
        .ai-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
        }

        .ai-modal-overlay.active {
            display: flex;
        }

        .ai-modal {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 56rem;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.4s ease-out;
            display: flex;
            flex-direction: column;
        }

        .ai-modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .ai-modal-header h2 {
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .ai-modal-header h2 .material-symbols-outlined {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .ai-modal-close {
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.4rem;
            border-radius: 0.5rem;
            color: var(--text-on-surface-variant);
            transition: all var(--transition-fast);
        }

        .ai-modal-close:hover {
            background: var(--bg-surface-low);
        }

        .ai-modal-close .material-symbols-outlined {
            font-size: 1.25rem;
        }

        .ai-modal-body {
            padding: 1.25rem;
            overflow-y: auto;
            flex: 1;
        }

        .ai-modal-footer {
            padding: 0.75rem 1.25rem;
            border-top: 1px solid var(--slate-200);
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            flex-shrink: 0;
        }

        /* AI Loading States - Dot Animation */
        .ai-loading {
            text-align: center;
            padding: 2rem 1.5rem;
        }

        .ai-loading .ai-icon-large {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
            display: block;
        }

        .ai-loading .ai-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-on-surface);
            margin-bottom: 0.25rem;
        }

        .ai-loading .ai-subtitle {
            font-size: 0.8rem;
            color: var(--text-on-surface-variant);
            margin-bottom: 1rem;
        }

        .ai-dots {
            display: inline-flex;
            gap: 0.4rem;
            justify-content: center;
        }

        .ai-dots .dot {
            width: 0.6rem;
            height: 0.6rem;
            background: var(--primary);
            border-radius: 50%;
            animation: dotBounce 1.4s ease-in-out infinite;
        }

        .ai-dots .dot:nth-child(1) { animation-delay: 0s; }
        .ai-dots .dot:nth-child(2) { animation-delay: 0.2s; }
        .ai-dots .dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes dotBounce {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
            40% { transform: scale(1); opacity: 1; }
        }

        .ai-progress-text {
            margin-top: 0.75rem;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            min-height: 1.25rem;
        }

        /* AI Results */
        .ai-result {
            display: none;
        }

        .ai-result.visible {
            display: block;
        }

        .ai-result-section {
            margin-bottom: 0.75rem;
            padding: 0.75rem;
            background: var(--bg-surface-low);
            border-radius: 0.5rem;
            border: 1px solid var(--slate-200);
        }

        .ai-result-section:last-child {
            margin-bottom: 0;
        }

        .ai-result-section .section-header {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            margin-bottom: 0.25rem;
        }

        .ai-result-section .section-header .section-icon {
            color: var(--primary);
            font-size: 1rem;
        }

        .ai-result-section .section-header .section-title {
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--text-on-surface);
        }

        .ai-result-section .section-header .section-badge {
            font-size: 0.55rem;
            font-weight: 600;
            color: var(--primary);
            background: rgba(79, 70, 229, 0.1);
            padding: 0.1rem 0.5rem;
            border-radius: var(--radius-full);
            border: 1px solid rgba(79, 70, 229, 0.2);
        }

        .ai-result-section .section-content {
            font-size: 0.8rem;
            color: var(--text-on-surface);
            line-height: 1.6;
            white-space: pre-wrap;
        }

       .btn-sm {
    padding: 0.15rem 0.5rem;
    font-size: 0.6rem;
    border-radius: 0.3rem;
    min-height: 1.2rem;
}

.btn-sm .material-symbols-outlined {
    font-size: 0.65rem !important;
}

.ai-result-section .section-content .skill-tag {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    background: var(--primary);
    color: white;
    border-radius: var(--radius-full);
    font-size: 0.8rem;
    font-weight: 500;
    margin: 0.15rem;
}

        .ai-result-section .section-content .skill-tag.suggested {
            background: #7c3aed;
        }

        .ai-result-section .section-content .warning-item {
            padding: 0.25rem 0.5rem;
            background: #fef2f2;
            border-radius: 0.25rem;
            border-left: 3px solid #dc2626;
            margin-bottom: 0.25rem;
            font-size: 0.75rem;
            color: #92400e;
        }

        .ai-result-section .section-content .warning-item:last-child {
            margin-bottom: 0;
        }

        .ai-result-section .section-content .tip-item {
            padding: 0.125rem 0;
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
        }

        .ai-apply-btn {
            background: #22c55e;
            color: white;
            padding: 0.4rem 0.8rem;
            font-size: 0.75rem;
        }

        .ai-apply-btn:hover {
            background: #16a34a;
        }

        /* =============================================
           MESSAGES
        ============================================= */
        .message {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.8rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            border: 1px solid transparent;
        }

        .message .material-symbols-outlined {
            font-size: 1rem;
            flex-shrink: 0;
            margin-top: 0.0625rem;
        }

        .message.success {
            background: #f0fdf4;
            border-color: #bbf7d0;
            color: #16a34a;
        }

        .message.error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }

        .message.info {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #2563eb;
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
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--slate-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h3 .material-symbols-outlined {
            font-size: 1.125rem;
            color: var(--primary);
        }

        .card-header .required-label {
            font-size: 0.7rem;
            color: var(--text-on-surface-variant);
        }

        .card-body {
            padding: 1.25rem;
        }

        /* =============================================
           FORM ELEMENTS
        ============================================= */
        .form-group {
            margin-bottom: 1rem;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-on-surface);
            margin-bottom: 0.2rem;
        }

        .form-group label .required {
            color: #dc2626;
            margin-left: 0.125rem;
        }

        .form-group .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 2px solid var(--slate-200);
            border-radius: 0.5rem;
            font-size: 0.8rem;
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

        .form-group .form-control::placeholder {
            color: var(--text-on-surface-variant);
            opacity: 0.6;
        }

        .form-group .form-control:disabled {
            background: var(--bg-surface-low);
            cursor: not-allowed;
            opacity: 0.7;
        }

        .form-group textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .form-group select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        .form-group .helper-text {
            font-size: 0.7rem;
            color: var(--text-on-surface-variant);
            margin-top: 0.2rem;
        }

        .form-group .helper-text .material-symbols-outlined {
            font-size: 0.8rem;
            vertical-align: middle;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }

        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .form-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1.25rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--slate-200);
            flex-wrap: wrap;
        }

        /* =============================================
           TOAST
        ============================================= */
        .toast {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            z-index: 10000;
            animation: slideUp 0.4s ease-out;
            max-width: 350px;
        }

        .toast.success {
            background: #22c55e;
        }

        .toast.error {
            background: #dc2626;
        }

        .toast.info {
            background: var(--primary);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
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
                padding: 0.75rem;
            }

            .top-header-left .separator {
                display: none;
            }

            .profile-dropdown-toggle .profile-name,
            .profile-dropdown-toggle .profile-role {
                display: none;
            }

            .card-body {
                padding: 0.75rem 1rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .ai-modal {
                max-width: 100%;
                margin: 0.5rem;
                max-height: 95vh;
            }

            .ai-modal-header {
                padding: 0.75rem 1rem;
            }

            .ai-modal-body {
                padding: 0.75rem 1rem;
            }

            .ai-modal-footer {
                padding: 0.5rem 1rem;
                flex-direction: column;
            }

            .ai-modal-footer .btn {
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
        }

        @media (max-width: 480px) {
            .main-scroll {
                padding: 0.5rem;
            }

            .breadcrumb-bar {
                padding: 0.5rem 0.75rem;
            }

            .page-header h1 {
                font-size: 1.25rem;
            }

            .card-header {
                padding: 0.75rem 1rem;
            }

            .card-header h3 {
                font-size: 0.875rem;
            }

            .card-body {
                padding: 0.5rem 0.75rem;
            }

            .form-group {
                margin-bottom: 0.625rem;
            }

            .toast {
                max-width: 90%;
                bottom: 0.75rem;
                right: 0.75rem;
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
    </style>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<aside class="dashboard-sidebar" id="appSidebar">
    <div class="sidebar-brand-card">
        <span class="sidebar-brand-icon">
            <span class="material-symbols-outlined">add_circle</span>
        </span>
        <p class="sidebar-brand-text">ISMERS</p>
        <p class="sidebar-brand-category">HR Portal</p>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Main</div>
        <a href="dashboard.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">dashboard</span>
            <span class="nav-text">Dashboard</span>
        </a>
        <a href="clients.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'clients.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">business</span>
            <span class="nav-text">Clients</span>
        </a>
        <a href="jobs.php" class="sidebar-main-link <?php echo in_array(basename($_SERVER['PHP_SELF']), ['jobs.php', 'job_view.php', 'post_job.php']) ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">work</span>
            <span class="nav-text">My Jobs</span>
        </a>
        <a href="applicants.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'applicants.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">people</span>
            <span class="nav-text">Applicants</span>
            <span class="nav-badge"><?php 
                // Get pending applications count
                $pendingApps = getRecord("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'", [], "")['count'] ?? 0;
                echo $pendingApps; 
            ?></span>
        </a>
        <a href="pipeline.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'pipeline.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">view_kanban</span>
            <span class="nav-text">Pipeline</span>
        </a>
        <a href="interviews.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'interviews.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">calendar_month</span>
            <span class="nav-text">Interviews</span>
        </a>
        <a href="offers.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'offers.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">description</span>
            <span class="nav-text">Offers</span>
        </a>
        <a href="archive.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'archive.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">archive</span>
            <span class="nav-text">Archive</span>
            <span class="nav-badge"><?php 
                // Get total archive count
                $totalArchived = 0;
                $archivedResult = getRecord("SELECT COUNT(*) as count FROM examination_records", [], "");
                $totalArchived += $archivedResult['count'] ?? 0;
                $archivedResult = getRecord("SELECT COUNT(*) as count FROM interview_evaluations", [], "");
                $totalArchived += $archivedResult['count'] ?? 0;
                $archivedResult = getRecord("SELECT COUNT(*) as count FROM client_assignments", [], "");
                $totalArchived += $archivedResult['count'] ?? 0;
                $archivedResult = getRecord("SELECT COUNT(*) as count FROM deployment_archive", [], "");
                $totalArchived += $archivedResult['count'] ?? 0;
                echo $totalArchived;
            ?></span>
        </a>
        <a href="apply_agency.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'apply_agency.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">apartment</span>
            <span class="nav-text">Apply as Agency</span>
        </a>
        <a href="deployments.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'deployments.php' ? 'active' : ''; ?>">
            <span class="material-symbols-outlined">assignment</span>
            <span class="nav-text">Deployments</span>
        </a>
    </nav>
    <div class="sidebar-footer">
        <div class="user-card">
            <span class="avatar"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?></span>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($fullName); ?></div>
                <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
            </div>
        </div>
    </div>
</aside>

<!-- =============================================
MAIN CONTENT
============================================= -->
<div class="main-wrapper" id="mainWrapper">
    <!-- Top Header -->
    <header class="top-header">
        <div class="top-header-left">
            <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
                <span class="material-symbols-outlined">menu</span>
            </button>
            <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
                <span class="material-symbols-outlined">chevron_left</span>
            </button>
            <span class="separator">|</span>
            <span style="font-weight:600; font-size:0.875rem; color:var(--text-on-surface);">
                <?php 
                    $pageTitle = basename($_SERVER['PHP_SELF'], '.php');
                    echo ucwords(str_replace('_', ' ', $pageTitle));
                ?>
            </span>
        </div>
        <div class="profile-dropdown-wrapper">
            <button class="profile-dropdown-toggle" id="profileToggle" aria-label="Profile menu">
                <span class="avatar-small"><?php echo strtoupper(substr($firstName, 0, 1) ?: 'H'); ?></span>
                <span class="profile-name"><?php echo htmlspecialchars($firstName); ?></span>
                <span class="profile-role"><?php echo ucfirst(str_replace('_', ' ', $role)); ?></span>
                <span class="material-symbols-outlined">expand_more</span>
            </button>
            <div class="profile-dropdown-menu" id="profileMenu">
                <div class="dropdown-header">Account</div>
                <button class="dropdown-item" onclick="window.location.href='profile.php'">
                    <span class="material-symbols-outlined">person</span> Profile
                </button>
                <div class="dropdown-divider"></div>
                <button class="dropdown-item danger" onclick="window.location.href='../../logout.php'">
                    <span class="material-symbols-outlined">logout</span> Logout
                </button>
            </div>
        </div>
    </header>

    <!-- Scrollable Content -->
    <main class="main-scroll">
        <div class="container">

            <!-- Breadcrumb -->
            <div class="breadcrumb-bar">
                <div class="breadcrumb-view">
                    <span class="material-symbols-outlined">add_circle</span>
                    <span>Post Job</span>
                    <span class="status-dot"></span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                    <span style="font-weight:400; color:var(--text-on-surface-variant);">New Job Posting</span>
                </div>
                <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                    <?php echo date('M d, Y H:i'); ?>
                </span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>Post New Job</h1>
                    <p>AI-powered job posting with industry-specific suggestions</p>
                </div>
                <div class="header-actions">
                    <a href="jobs.php" class="btn btn-outline">
                        <span class="material-symbols-outlined">arrow_back</span>
                        Back to Jobs
                    </a>
                </div>
            </div>

            <!-- Messages -->
            <?php if (!empty($successMessage)): ?>
                <div class="message success">
                    <span class="material-symbols-outlined">check_circle</span>
                    <div>
                        <strong><?php echo htmlspecialchars($successMessage); ?></strong>
                        <span style="display:block; font-weight:400;">Redirecting to jobs list...</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errorMessage)): ?>
                <div class="message error">
                    <span class="material-symbols-outlined">error</span>
                    <div>
                        <strong>Error:</strong>
                        <span style="display:block; font-weight:400;"><?php echo $errorMessage; ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- No Clients Message -->
            <?php if (!$hasClients): ?>
                <div class="message info">
                    <span class="material-symbols-outlined">info</span>
                    <div>
                        <strong>No clients available.</strong>
                        <span style="display:block; font-weight:400;">Please contact an admin to add client companies before posting jobs.</span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Post Job Form -->
            <div class="card">
                <div class="card-header">
                    <h3>
                        <span class="material-symbols-outlined">description</span>
                        Job Details
                    </h3>
                    <span class="required-label">Fields with <span style="color:#dc2626;">*</span> are required</span>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="postJobForm" novalidate>

                        <!-- Client -->
                        <div class="form-group">
                            <label>Client Company <span class="required">*</span></label>
                            <select name="client_id" id="clientSelect" class="form-control" required <?php echo !$hasClients ? 'disabled' : ''; ?>>
                                <option value="">Select a client company</option>
                                <?php echo $clientOptions; ?>
                            </select>
                            <?php if (!$hasClients): ?>
                                <div class="helper-text">
                                    <span class="material-symbols-outlined">warning</span>
                                    No clients available. Please contact admin.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Job Title -->
                        <div class="form-group">
                            <label>Job Title <span class="required">*</span></label>
                            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                                <input type="text" name="title" id="jobTitle" class="form-control" 
                                       placeholder="e.g., Senior PHP Developer" 
                                       value="<?php echo htmlspecialchars($formData['title'] ?? ''); ?>" required
                                       style="flex:1; min-width:200px;">
                                <button type="button" class="btn btn-ai btn-sm" onclick="openAIModal()" id="aiSuggestBtn">
                                    <span class="material-symbols-outlined">auto_awesome</span>
                                    Get AI Suggestions
                                </button>
                            </div>
                            <div class="helper-text">
                                <span class="material-symbols-outlined">info</span>
                                Enter a job title and click "Get AI Suggestions" for industry-specific optimization
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="form-group">
                            <label>Job Description <span class="required">*</span></label>
                            <textarea name="description" id="jobDescription" class="form-control" 
                                      placeholder="Describe the role, responsibilities, and requirements" 
                                      rows="5" required><?php echo htmlspecialchars($formData['description'] ?? ''); ?></textarea>
                            <div class="helper-text">
                                <span class="material-symbols-outlined">info</span>
                                Provide a clear and detailed description of the job
                            </div>
                        </div>

                        <!-- Skills Required -->
                        <div class="form-group">
                            <label>Skills Required <span class="required">*</span></label>
                            <input type="text" name="skills_required" id="jobSkills" class="form-control" 
                                   placeholder="e.g., PHP, Laravel, MySQL, JavaScript, React" 
                                   value="<?php echo htmlspecialchars($formData['skills_required'] ?? ''); ?>" required>
                            <div class="helper-text">
                                <span class="material-symbols-outlined">info</span>
                                Separate skills with commas
                            </div>
                        </div>

                        <!-- Job Type + Experience Level -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>Job Type</label>
                                <select name="job_type" id="jobType" class="form-control">
                                    <?php foreach ($jobTypes as $type): ?>
                                        <option value="<?php echo $type; ?>" <?php echo ($formData['job_type'] ?? 'Full-time') === $type ? 'selected' : ''; ?>>
                                            <?php echo $type; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Experience Level</label>
                                <select name="experience_level" id="experienceLevel" class="form-control">
                                    <?php foreach ($experienceLevels as $level): ?>
                                        <option value="<?php echo $level; ?>" <?php echo ($formData['experience_level'] ?? 'Entry') === $level ? 'selected' : ''; ?>>
                                            <?php echo $level; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Positions Available -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>Positions Available</label>
                                <input type="number" name="positions_available" class="form-control" 
                                       value="<?php echo htmlspecialchars($formData['positions_available'] ?? 1); ?>" min="1">
                            </div>
                        </div>

                        <!-- Location + Salary -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>Location</label>
                                <input type="text" name="location" class="form-control" 
                                       placeholder="e.g., Makati, Philippines" 
                                       value="<?php echo htmlspecialchars($formData['location'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label>Salary Range</label>
                                <input type="text" name="salary_range" class="form-control" 
                                       placeholder="e.g., ₱50,000 - ₱80,000" 
                                       value="<?php echo htmlspecialchars($formData['salary_range'] ?? ''); ?>">
                            </div>
                        </div>

                        <!-- Application Deadline -->
                        <div class="form-group">
                            <label>Application Deadline</label>
                            <input type="date" name="application_deadline" class="form-control" 
                                   value="<?php echo htmlspecialchars($formData['application_deadline'] ?? ''); ?>">
                            <div class="helper-text">
                                <span class="material-symbols-outlined">calendar_today</span>
                                Leave empty for ongoing applications
                            </div>
                        </div>

                        <!-- Status + Urgency -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>Status</label>
                                <select name="status" class="form-control">
                                    <?php foreach ($jobStatuses as $status): ?>
                                        <option value="<?php echo $status; ?>" <?php echo ($formData['status'] ?? 'open') === $status ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Urgency</label>
                                <select name="urgency" class="form-control">
                                    <?php foreach ($urgencyLevels as $urgency): ?>
                                        <option value="<?php echo $urgency; ?>" <?php echo ($formData['urgency'] ?? 'medium') === $urgency ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($urgency); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary" <?php echo !$hasClients ? 'disabled' : ''; ?>>
                                <span class="material-symbols-outlined">publish</span>
                                Publish Job
                            </button>
                            <button type="button" class="btn btn-ai" onclick="openAIModal()">
                                <span class="material-symbols-outlined">auto_awesome</span>
                                Get AI Suggestions
                            </button>
                            <button type="reset" class="btn btn-outline">
                                <span class="material-symbols-outlined">clear</span>
                                Clear All
                            </button>
                            <a href="jobs.php" class="btn btn-outline">
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
AI MODAL
============================================= -->
<div class="ai-modal-overlay" id="aiModal">
    <div class="ai-modal">
        <div class="ai-modal-header">
            <h2>
                <span class="material-symbols-outlined">auto_awesome</span>
                AI Job Optimizer
            </h2>
            <button class="ai-modal-close" onclick="closeAIModal()">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="ai-modal-body" id="aiModalBody">
            <!-- Loading State -->
            <div class="ai-loading" id="aiLoading">
                <span class="ai-icon-large material-symbols-outlined">auto_awesome</span>
                <div class="ai-title">AI is analyzing your job posting</div>
                <div class="ai-subtitle">Creating industry-optimized job description</div>
                <div class="ai-dots">
                    <span class="dot"></span>
                    <span class="dot"></span>
                    <span class="dot"></span>
                </div>
                <div class="ai-progress-text" id="aiProgressText">Analyzing job requirements...</div>
            </div>

            <!-- Results State -->
            <div class="ai-result" id="aiResult">
                <!-- Results will be dynamically inserted here -->
            </div>
        </div>
        <div class="ai-modal-footer" id="aiModalFooter">
            <button class="btn btn-outline" onclick="closeAIModal()">Cancel</button>
            <button class="btn btn-success" id="applyAiSuggestionsBtn" onclick="applyAISuggestions()" style="display:none;">
                <span class="material-symbols-outlined">check_circle</span>
                Apply All
            </button>
        </div>
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
const mainWrapper = document.getElementById('mainWrapper');
const isMobile = window.innerWidth <= 768;
const savedState = localStorage.getItem('sidebarCollapsed');

if (savedState === 'true' && !isMobile) {
    sidebar.classList.add('collapsed');
    const icon = sidebarToggleBtn.querySelector('.material-symbols-outlined');
    if (icon) icon.textContent = 'chevron_right';
}

sidebarToggleBtn.addEventListener('click', function() {
    if (window.innerWidth <= 768) return;
    sidebar.classList.toggle('collapsed');
    const icon = this.querySelector('.material-symbols-outlined');
    if (icon) {
        icon.textContent = sidebar.classList.contains('collapsed') ? 'chevron_right' : 'chevron_left';
    }
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
});

// =============================================
// 2. MOBILE SIDEBAR
// =============================================
const mobileMenuBtn = document.getElementById('mobileMenuBtn');
const sidebarBackdrop = document.getElementById('sidebarBackdrop');

function openMobileSidebar() {
    sidebar.classList.add('mobile-open');
    if (sidebarBackdrop) sidebarBackdrop.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeMobileSidebar() {
    sidebar.classList.remove('mobile-open');
    if (sidebarBackdrop) sidebarBackdrop.classList.remove('active');
    document.body.style.overflow = '';
}

if (mobileMenuBtn) {
    mobileMenuBtn.addEventListener('click', openMobileSidebar);
}
if (sidebarBackdrop) {
    sidebarBackdrop.addEventListener('click', closeMobileSidebar);
}

document.querySelectorAll('.sidebar-main-link').forEach(link => {
    link.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
            closeMobileSidebar();
        }
    });
});

// =============================================
// 3. PROFILE DROPDOWN
// =============================================
const profileToggle = document.getElementById('profileToggle');
const profileMenu = document.getElementById('profileMenu');

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
}

// =============================================
// 4. AI MODAL FUNCTIONS
// =============================================
let currentAISuggestions = null;
let progressInterval = null;
const progressMessages = [
    'Analyzing job requirements...',
    'Detecting industry and role type...',
    'Generating optimized job description...',
    'Suggesting industry-specific skills...',
    'Calculating market salary range...',
    'Reviewing for diversity and inclusion...',
    'Finalizing AI recommendations...'
];

function openAIModal() {
    const title = document.getElementById('jobTitle').value;
    const description = document.getElementById('jobDescription').value;
    const skills = document.getElementById('jobSkills').value;
    const clientId = document.getElementById('clientSelect').value;
    
    if (!title && !description && !skills) {
        showToast('Please fill in at least the job title to get suggestions.', 'info');
        return;
    }
    
    if (!clientId) {
        showToast('Please select a client company first for industry-specific suggestions.', 'info');
        return;
    }
    
    // Reset modal state
    const modal = document.getElementById('aiModal');
    const loading = document.getElementById('aiLoading');
    const result = document.getElementById('aiResult');
    const applyBtn = document.getElementById('applyAiSuggestionsBtn');
    
    loading.style.display = 'block';
    result.style.display = 'none';
    result.innerHTML = '';
    applyBtn.style.display = 'none';
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    // Start progress animation
    let progressIndex = 0;
    const progressText = document.getElementById('aiProgressText');
    progressText.textContent = progressMessages[0];
    
    if (progressInterval) clearInterval(progressInterval);
    progressInterval = setInterval(function() {
        progressIndex = (progressIndex + 1) % progressMessages.length;
        progressText.textContent = progressMessages[progressIndex];
    }, 1500);
    
    // Send request to AI
    const formData = new FormData();
    formData.append('title', title);
    formData.append('description', description);
    formData.append('skills_required', skills);
    formData.append('experience_level', document.getElementById('experienceLevel').value);
    formData.append('client_id', clientId);
    
    fetch('post_job.php?ajax=ai_suggestions', {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        clearInterval(progressInterval);
        
        if (data.success) {
            currentAISuggestions = data.suggestions;
            displayAIResults(data.suggestions);
        } else {
            loading.style.display = 'none';
            result.style.display = 'block';
            result.innerHTML = `
                <div style="text-align:center; padding:1.5rem; color:#dc2626;">
                    <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                    <p style="margin-top:0.5rem; font-size:0.875rem;">${data.error || 'Failed to get AI suggestions. Please try again.'}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        clearInterval(progressInterval);
        loading.style.display = 'none';
        result.style.display = 'block';
        result.innerHTML = `
            <div style="text-align:center; padding:1.5rem; color:#dc2626;">
                <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                <p style="margin-top:0.5rem; font-size:0.875rem;">Error connecting to AI service. Please try again.</p>
            </div>
        `;
    });
}

function displayAIResults(suggestions) {
    const loading = document.getElementById('aiLoading');
    const result = document.getElementById('aiResult');
    const applyBtn = document.getElementById('applyAiSuggestionsBtn');
    
    loading.style.display = 'none';
    result.style.display = 'block';
    applyBtn.style.display = 'flex';
    
    let html = '';
    
    // Industry tag
    if (suggestions.industry) {
        html += `
            <div style="margin-bottom:0.75rem; padding:0.25rem 0.75rem; background:#e0e7ff; border-radius:0.25rem; display:inline-block; font-size:0.7rem; font-weight:600; color:#1e40af;">
                <span class="material-symbols-outlined" style="font-size:0.8rem; vertical-align:middle;">business</span>
                Industry: ${escapeHtml(suggestions.industry)}
            </div>
        `;
    }
    
    // Optimized Description
    if (suggestions.optimized_full_description || suggestions.improved_description) {
        const desc = suggestions.optimized_full_description || suggestions.improved_description;
        html += `
            <div class="ai-result-section">
                <div class="section-header">
                    <span class="section-icon material-symbols-outlined">description</span>
                    <span class="section-title">Optimized Job Description</span>
                    <span class="section-badge">AI Generated</span>
                </div>
                <div class="section-content" style="white-space:pre-wrap; font-size:0.75rem; line-height:1.6; max-height:200px; overflow-y:auto; background:rgba(255,255,255,0.5); padding:0.5rem; border-radius:0.25rem;">
                    ${escapeHtml(desc)}
                </div>
                <div style="margin-top:0.25rem;">
                    <button class="btn btn-sm btn-primary" onclick="applyOptimizedDescription()">
                        <span class="material-symbols-outlined" style="font-size:0.8rem;">check</span>
                        Apply Description
                    </button>
                </div>
            </div>
        `;
    }
    
    // Suggested Skills
    if (suggestions.suggested_skills && suggestions.suggested_skills.length > 0) {
        html += `
            <div class="ai-result-section">
                <div class="section-header">
                    <span class="section-icon material-symbols-outlined">psychology</span>
                    <span class="section-title">Industry-Specific Skills</span>
                    <span class="section-badge">${suggestions.suggested_skills.length} skills</span>
                </div>
                <div class="section-content">
                    ${suggestions.suggested_skills.map(s => `<span class="skill-tag suggested">${escapeHtml(s)}</span>`).join('')}
                    <div style="margin-top:0.25rem;">
                        <button class="btn btn-sm btn-primary" onclick="applySuggestedSkills()">
                            <span class="material-symbols-outlined" style="font-size:0.8rem;">check</span>
                            Apply Skills
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Suggested Title
    if (suggestions.suggested_title && suggestions.suggested_title !== document.getElementById('jobTitle').value) {
        html += `
            <div class="ai-result-section">
                <div class="section-header">
                    <span class="section-icon material-symbols-outlined">title</span>
                    <span class="section-title">Suggested Job Title</span>
                </div>
                <div class="section-content">
                    <strong>${escapeHtml(suggestions.suggested_title)}</strong>
                    <div style="margin-top:0.125rem; font-size:0.7rem; color:var(--text-on-surface-variant);">
                        Current: ${escapeHtml(document.getElementById('jobTitle').value || 'Not set')}
                    </div>
                    <div style="margin-top:0.25rem;">
                        <button class="btn btn-sm btn-primary" onclick="applySuggestedTitle()">
                            <span class="material-symbols-outlined" style="font-size:0.8rem;">check</span>
                            Apply Title
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Salary Range
    if (suggestions.salary_range) {
        html += `
            <div class="ai-result-section">
                <div class="section-header">
                    <span class="section-icon material-symbols-outlined">payments</span>
                    <span class="section-title">Suggested Salary Range</span>
                    <span class="section-badge">Market Rate</span>
                </div>
                <div class="section-content">
                    <strong>${escapeHtml(suggestions.salary_range)}</strong>
                    <div style="margin-top:0.25rem;">
                        <button class="btn btn-sm btn-primary" onclick="applySalaryRange()">
                            <span class="material-symbols-outlined" style="font-size:0.8rem;">check</span>
                            Apply Salary
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Diversity Warnings
    if (suggestions.diversity_check && suggestions.diversity_check.warnings && suggestions.diversity_check.warnings.length > 0) {
        html += `
            <div class="ai-result-section" style="border-color:#fcd34d; background:rgba(252,211,77,0.08);">
                <div class="section-header">
                    <span class="section-icon material-symbols-outlined" style="color:#d97706;">warning</span>
                    <span class="section-title" style="color:#d97706;">Diversity & Inclusion</span>
                    <span class="section-badge" style="color:#d97706; border-color:#d97706;">${suggestions.diversity_check.warnings.length}</span>
                </div>
                <div class="section-content">
                    ${suggestions.diversity_check.warnings.map(w => `<div class="warning-item">${escapeHtml(w)}</div>`).join('')}
                    ${suggestions.diversity_check.suggestions ? `
                        <div style="margin-top:0.25rem;">
                            <strong style="font-size:0.7rem; color:var(--text-on-surface-variant);">Tips:</strong>
                            ${suggestions.diversity_check.suggestions.map(s => `<div class="tip-item">• ${escapeHtml(s)}</div>`).join('')}
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    }
    
    if (!html) {
        html = `
            <div style="text-align:center; padding:1.5rem; color:var(--text-on-surface-variant);">
                <span class="material-symbols-outlined" style="font-size:2.5rem;">check_circle</span>
                <p style="margin-top:0.5rem; font-size:0.875rem;">No additional suggestions available. Your job posting looks great!</p>
            </div>
        `;
    }
    
    result.innerHTML = html;
}

function closeAIModal() {
    const modal = document.getElementById('aiModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
    if (progressInterval) {
        clearInterval(progressInterval);
        progressInterval = null;
    }
}

// =============================================
// 5. APPLY AI SUGGESTIONS
// =============================================
function applyOptimizedDescription() {
    if (!currentAISuggestions) return;
    const desc = currentAISuggestions.optimized_full_description || currentAISuggestions.improved_description;
    if (desc) {
        document.getElementById('jobDescription').value = desc;
        showToast('Optimized description applied successfully!', 'success');
    }
}

function applySuggestedSkills() {
    if (!currentAISuggestions || !currentAISuggestions.suggested_skills) return;
    const skillsInput = document.getElementById('jobSkills');
    const currentSkills = skillsInput.value.trim();
    const newSkills = currentSkills ? currentSkills + ', ' + currentAISuggestions.suggested_skills.join(', ') : currentAISuggestions.suggested_skills.join(', ');
    skillsInput.value = newSkills;
    showToast('Suggested skills applied successfully!', 'success');
}

function applySuggestedTitle() {
    if (!currentAISuggestions || !currentAISuggestions.suggested_title) return;
    document.getElementById('jobTitle').value = currentAISuggestions.suggested_title;
    showToast('Suggested title applied successfully!', 'success');
}

function applySalaryRange() {
    if (!currentAISuggestions || !currentAISuggestions.salary_range) return;
    document.querySelector('input[name="salary_range"]').value = currentAISuggestions.salary_range;
    showToast('Salary range applied successfully!', 'success');
}

function applyAISuggestions() {
    applyOptimizedDescription();
    applySuggestedSkills();
    applySuggestedTitle();
    applySalaryRange();
    closeAIModal();
    showToast('All AI suggestions applied successfully!', 'success');
}

// =============================================
// 6. FORM VALIDATION
// =============================================
const postJobForm = document.getElementById('postJobForm');
if (postJobForm) {
    postJobForm.addEventListener('submit', function(e) {
        const clientSelect = this.querySelector('select[name="client_id"]');
        const titleInput = this.querySelector('input[name="title"]');
        const descInput = this.querySelector('textarea[name="description"]');
        const skillsInput = this.querySelector('input[name="skills_required"]');
        let hasError = false;

        [clientSelect, titleInput, descInput, skillsInput].forEach(el => {
            if (el) el.style.borderColor = '';
        });

        if (!clientSelect || !clientSelect.value) {
            if (clientSelect) clientSelect.style.borderColor = '#dc2626';
            hasError = true;
        }

        if (!titleInput || !titleInput.value.trim()) {
            if (titleInput) titleInput.style.borderColor = '#dc2626';
            hasError = true;
        }

        if (!descInput || !descInput.value.trim()) {
            if (descInput) descInput.style.borderColor = '#dc2626';
            hasError = true;
        }

        if (!skillsInput || !skillsInput.value.trim()) {
            if (skillsInput) skillsInput.style.borderColor = '#dc2626';
            hasError = true;
        }

        if (hasError) {
            e.preventDefault();
            showToast('Please fill in all required fields.', 'error');
            
            const firstError = [clientSelect, titleInput, descInput, skillsInput].find(el => 
                el && el.style.borderColor === '#dc2626'
            );
            if (firstError) firstError.focus();
        }
    });
}

document.querySelectorAll('.form-control').forEach(el => {
    el.addEventListener('input', function() {
        this.style.borderColor = '';
    });
    el.addEventListener('change', function() {
        this.style.borderColor = '';
    });
});

// =============================================
// 7. TOAST SYSTEM
// =============================================
function showToast(message, type = 'info') {
    const existingToast = document.querySelector('.toast');
    if (existingToast) existingToast.remove();

    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        toast.style.transition = 'all 0.4s ease';
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}

// =============================================
// 8. UTILITY FUNCTIONS
// =============================================
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// =============================================
// 9. RESPONSIVE HANDLING
// =============================================
let resizeTimer;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function() {
        const width = window.innerWidth;
        if (width <= 768) {
            sidebar.classList.remove('collapsed');
        } else {
            sidebar.classList.remove('mobile-open');
            if (sidebarBackdrop) sidebarBackdrop.classList.remove('active');
            document.body.style.overflow = '';
            const saved = localStorage.getItem('sidebarCollapsed');
            if (saved === 'true') {
                sidebar.classList.add('collapsed');
            } else {
                sidebar.classList.remove('collapsed');
            }
        }
    }, 250);
});

// =============================================
// 10. KEYBOARD ACCESSIBILITY
// =============================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('aiModal').classList.contains('active')) {
            closeAIModal();
        } else {
            closeMobileSidebar();
            if (profileToggle) profileToggle.classList.remove('open');
            if (profileMenu) profileMenu.classList.remove('open');
        }
    }
});

console.log('📝 ISMERS Post Job with INDUSTRY-BASED AI Integration loaded successfully!');
console.log('💪 Skills and descriptions are now tailored to your client\'s industry!');
</script>

</body>
</html>