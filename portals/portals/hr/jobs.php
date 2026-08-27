    <?php
    // portals/hr/jobs.php - Manage Jobs with Advanced AI Insights + Client Job Requests
    // FIXED: PostgreSQL compatibility + proper error handling

    session_start();

require_once '../../app/config.php';
initSessionTimeout();
    require_once '../../app/ai/AiService.php';

    // =============================================
    // ERROR REPORTING - DISABLE WARNINGS
    // =============================================
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header('Location: ../../login.php');
        exit;
    }

    // Check if user has HR role
    if (!in_array($_SESSION['role'], ['hr_manager', 'recruiter', 'admin'])) {
        header('Location: ../../login.php');
        exit;
    }

    $userId = $_SESSION['user_id'];
    $fullName = $_SESSION['full_name'] ?? 'HR User';
    $firstName = $_SESSION['first_name'] ?? '';
    $email = $_SESSION['email'] ?? '';
    $role = $_SESSION['role'] ?? 'hr_manager';
    $isHRManager = $role === 'hr_manager' || $role === 'admin';

    // =============================================
    // AI SERVICE INITIALIZATION
    // =============================================
    $aiService = new AiService();

    // =============================================
    // INDUSTRY-BASED SKILLS MAPPING
    // =============================================
    function getIndustrySkills($industry, $jobTitle) {
        $industry = strtolower($industry);
        $titleLower = strtolower($jobTitle);
        
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
        
        if (isset($industrySkills[$industryKey][$subIndustryKey])) {
            return $industrySkills[$industryKey][$subIndustryKey];
        } elseif (isset($industrySkills[$industryKey]['default'])) {
            return $industrySkills[$industryKey]['default'];
        }
        
        return ['Communication', 'Problem Solving', 'Teamwork', 'Time Management', 'Leadership', 'Analytical Skills'];
    }

    /**
     * Calculate Job Quality Score
     */
    function calculateJobQualityScore($job) {
        $score = 0;
        $maxScore = 100;
        $details = [];
        
        // 1. Title quality (max 15 points)
        $titleLength = strlen($job['title'] ?? '');
        if ($titleLength >= 10 && $titleLength <= 60) {
            $score += 15;
            $details[] = 'Title length is optimal';
        } elseif ($titleLength > 0) {
            $score += 8;
            $details[] = 'Title length could be improved';
        } else {
            $details[] = 'Title is missing';
        }
        
        // 2. Description quality (max 25 points)
        $descLength = strlen($job['description'] ?? '');
        if ($descLength >= 200) {
            $score += 25;
            $details[] = 'Description is comprehensive';
        } elseif ($descLength >= 100) {
            $score += 15;
            $details[] = 'Description could be more detailed';
        } elseif ($descLength > 0) {
            $score += 5;
            $details[] = 'Description is too short';
        } else {
            $details[] = 'Description is missing';
        }
        
        // 3. Skills quality (max 20 points)
        $skills = array_filter(array_map('trim', explode(',', $job['skills_required'] ?? '')));
        $skillCount = count($skills);
        if ($skillCount >= 5) {
            $score += 20;
            $details[] = 'Good number of skills listed';
        } elseif ($skillCount >= 3) {
            $score += 12;
            $details[] = 'Consider adding more skills';
        } elseif ($skillCount > 0) {
            $score += 5;
            $details[] = 'Too few skills listed';
        } else {
            $details[] = 'No skills listed';
        }
        
        // 4. Salary range (max 10 points)
        if (!empty($job['salary_range']) && strlen($job['salary_range']) > 3) {
            $score += 10;
            $details[] = 'Salary range provided';
        } else {
            $details[] = 'Salary range not specified';
        }
        
        // 5. Location (max 10 points)
        if (!empty($job['location'])) {
            $score += 10;
            $details[] = 'Location specified';
        } else {
            $details[] = 'Location not specified';
        }
        
        // 6. Job type (max 10 points)
        if (!empty($job['job_type']) && $job['job_type'] !== 'Full-time') {
            $score += 10;
            $details[] = 'Job type specified';
        } elseif (!empty($job['job_type'])) {
            $score += 6;
            $details[] = 'Job type specified (Full-time)';
        } else {
            $details[] = 'Job type not specified';
        }
        
        // 7. Experience level (max 10 points)
        if (!empty($job['experience_level'])) {
            $score += 10;
            $details[] = 'Experience level specified';
        } else {
            $details[] = 'Experience level not specified';
        }
        
        // Determine level
        if ($score >= 80) {
            $level = 'Excellent';
            $color = '#059669';
            $bg = '#d1fae5';
            $icon = 'star';
        } elseif ($score >= 60) {
            $level = 'Good';
            $color = '#2563eb';
            $bg = '#dbeafe';
            $icon = 'thumb_up';
        } elseif ($score >= 40) {
            $level = 'Fair';
            $color = '#d97706';
            $bg = '#fef3c7';
            $icon = 'flag';
        } else {
            $level = 'Needs Improvement';
            $color = '#dc2626';
            $bg = '#fecaca';
            $icon = 'error';
        }
        
        return [
            'score' => $score,
            'level' => $level,
            'color' => $color,
            'bg' => $bg,
            'icon' => $icon,
            'details' => $details
        ];
    }

    // =============================================
    // Get filter parameters
    // =============================================
    $statusFilter = $_GET['status'] ?? 'all';
    $searchQuery = $_GET['search'] ?? '';

    // Build query conditions using PostgreSQL syntax
    $conditions = [];
    $params = [];
    $counter = 1;

    if ($statusFilter !== 'all') {
        $conditions[] = "jo.status = $" . $counter++;
        $params[] = $statusFilter;
    }

    if (!empty($searchQuery)) {
        $conditions[] = "(jo.title ILIKE $" . $counter . " OR c.company_name ILIKE $" . ($counter+1) . " OR jo.location ILIKE $" . ($counter+2) . ")";
        $searchParam = "%$searchQuery%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $counter += 3;
    }

    $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

    // Get jobs with client industry - PostgreSQL syntax
    $sql = "SELECT jo.*, c.company_name, c.industry,
            (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id) as application_count,
            (SELECT COUNT(*) FROM applications WHERE job_order_id = jo.id AND status = 'pending') as pending_count
            FROM job_orders jo
            JOIN clients c ON jo.client_id = c.id
            $whereClause
            ORDER BY 
                CASE WHEN jo.status = 'pending_review' THEN 0 ELSE 1 END,
                jo.created_at DESC";

    $jobs = @getRecords($sql, $params);
    if (!is_array($jobs)) $jobs = [];

    // Calculate quality scores for each job
    foreach ($jobs as &$job) {
        $quality = calculateJobQualityScore($job);
        $job['quality_score'] = $quality['score'];
        $job['quality_level'] = $quality['level'];
        $job['quality_color'] = $quality['color'];
        $job['quality_bg'] = $quality['bg'];
        $job['quality_icon'] = $quality['icon'];
        $job['quality_details'] = $quality['details'];
    }
    unset($job);

    // Get status counts using PostgreSQL
    $statusCounts = ['all' => count($jobs)];
    $statuses = ['open', 'ongoing', 'filled', 'cancelled', 'draft', 'pending_review'];
    foreach ($statuses as $status) {
        $countResult = @getRecord("SELECT COUNT(*) as count FROM job_orders WHERE status = $1", [$status]);
        $statusCounts[$status] = isset($countResult['count']) ? (int)$countResult['count'] : 0;
    }

    $allStatuses = [
        'all' => 'All Jobs', 
        'pending_review' => 'Pending Review', 
        'open' => 'Open', 
        'ongoing' => 'Ongoing', 
        'filled' => 'Filled', 
        'cancelled' => 'Cancelled', 
        'draft' => 'Draft'
    ];

    $statusBadges = [
        'open' => 'badge-open',
        'ongoing' => 'badge-ongoing',
        'filled' => 'badge-filled',
        'cancelled' => 'badge-cancelled',
        'draft' => 'badge-draft',
        'pending_review' => 'badge-pending'
    ];

    $statusLabels = [
        'open' => 'Open',
        'ongoing' => 'Ongoing',
        'filled' => 'Filled',
        'cancelled' => 'Cancelled',
        'draft' => 'Draft',
        'pending_review' => 'Pending Review'
    ];

    $urgencyBadges = [
        'low' => 'badge-urgency-low',
        'medium' => 'badge-urgency-medium',
        'high' => 'badge-urgency-high'
    ];

    $jobTypes = ['Full-time', 'Part-time', 'Contract', 'Temporary', 'Internship', 'Freelance'];
    $experienceLevels = ['Entry', 'Junior', 'Mid', 'Senior', 'Lead', 'Manager'];
    $jobStatuses = ['draft', 'open', 'ongoing', 'filled', 'cancelled', 'pending_review'];
    $urgencyLevels = ['low', 'medium', 'high'];

// =============================================
// Handle POST for update/delete/approve/reject - FIXED with PostgreSQL
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $jobId = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    
    // =============================================
    // APPROVE JOB REQUEST (from client)
    // =============================================
    if ($action === 'approve_job' && $jobId > 0) {
        $feedback = trim($_POST['feedback'] ?? '');
        
        // PostgreSQL syntax - use || for concatenation
        $sql = "UPDATE job_orders SET 
                status = 'open',
                request_notes = COALESCE(request_notes, '') || E'\n\n--- HR APPROVAL ---\n' || $1,
                updated_at = NOW()
                WHERE id = $2 AND status = 'pending_review'";
        
        $result = @updateRecord($sql, [$feedback, $jobId]);
        
        if ($result) {
            // Try to log activity, but don't fail if it doesn't work
            try {
                if (function_exists('logActivity')) {
                    @logActivity($userId, 'Job Request Approved', 'job_orders', $jobId, 'Approved client job request');
                }
            } catch (Exception $e) {
                // Ignore logging errors
            }
            echo json_encode(['success' => true, 'message' => 'Job request approved successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to approve job request.']);
        }
        exit;
    }
    
    // =============================================
    // REJECT JOB REQUEST (from client)
    // =============================================
    if ($action === 'reject_job' && $jobId > 0) {
        $feedback = trim($_POST['feedback'] ?? '');
        
        $sql = "UPDATE job_orders SET 
                status = 'rejected',
                request_notes = COALESCE(request_notes, '') || E'\n\n--- HR REJECTION ---\n' || $1,
                updated_at = NOW()
                WHERE id = $2 AND status = 'pending_review'";
        
        $result = @updateRecord($sql, [$feedback, $jobId]);
        
        if ($result) {
            try {
                if (function_exists('logActivity')) {
                    @logActivity($userId, 'Job Request Rejected', 'job_orders', $jobId, 'Rejected client job request');
                }
            } catch (Exception $e) {
                // Ignore logging errors
            }
            echo json_encode(['success' => true, 'message' => 'Job request rejected.']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to reject job request.']);
        }
        exit;
    }
    
    // =============================================
    // UPDATE JOB - FIXED PostgreSQL
    // =============================================
    if ($action === 'update_job' && $jobId > 0) {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $skillsRequired = trim($_POST['skills_required'] ?? '');
        $salaryRange = trim($_POST['salary_range'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $jobType = $_POST['job_type'] ?? 'Full-time';
        $experienceLevel = $_POST['experience_level'] ?? 'Entry';
        $status = $_POST['status'] ?? 'draft';
        $urgency = $_POST['urgency'] ?? 'medium';
        $positionsAvailable = (int)($_POST['positions_available'] ?? 1);
        
        $sql = "UPDATE job_orders SET 
                title = $1,
                description = $2,
                skills_required = $3,
                salary_range = $4,
                location = $5,
                job_type = $6,
                experience_level = $7,
                status = $8,
                urgency = $9,
                positions_available = $10,
                updated_at = NOW()
                WHERE id = $11";
        
        $result = @updateRecord($sql, [
            $title, $description, $skillsRequired, $salaryRange, $location,
            $jobType, $experienceLevel, $status, $urgency, $positionsAvailable,
            $jobId
        ]);
        
        if ($result) {
            try {
                if (function_exists('logActivity')) {
                    @logActivity($userId, 'Job Updated', 'job_orders', $jobId, 'Updated job: ' . $title);
                }
            } catch (Exception $e) {
                // Ignore logging errors
            }
            echo json_encode(['success' => true, 'message' => 'Job updated successfully!']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update job.']);
        }
        exit;
    }
    
    // =============================================
    // DELETE JOB - FIXED PostgreSQL
    // =============================================
    if ($action === 'delete_job' && $jobId > 0) {
        $job = @getRecord("SELECT title FROM job_orders WHERE id = $1", [$jobId]);
        if ($job) {
            $result = @deleteRecord("DELETE FROM job_orders WHERE id = $1", [$jobId]);
            if ($result) {
                try {
                    if (function_exists('logActivity')) {
                        @logActivity($userId, 'Job Deleted', 'job_orders', $jobId, 'Deleted job: ' . $job['title']);
                    }
                } catch (Exception $e) {
                    // Ignore logging errors
                }
                echo json_encode(['success' => true, 'message' => 'Job deleted successfully!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to delete job.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Job not found.']);
        }
        exit;
    }
}

    // =============================================
    // Enhanced AI Functions
    // =============================================

    /**
     * Get enhanced AI insights for a job with industry context
     */
    function getEnhancedAIJobInsights($jobId) {
        global $aiService, $conn;
        
        $job = @getRecord("
            SELECT jo.*, c.industry, c.company_name 
            FROM job_orders jo 
            JOIN clients c ON jo.client_id = c.id 
            WHERE jo.id = $1
        ", [$jobId]);
        
        if (!$job) return null;
        
        $industry = $job['industry'] ?? 'Technology';
        $title = $job['title'] ?? '';
        
        $jobData = [
            'title' => $title,
            'description' => $job['description'] ?? '',
            'skills_required' => $job['skills_required'] ?? '',
            'experience_level' => $job['experience_level'] ?? ''
        ];
        
        $aiOptimization = $aiService->optimizeJobDescription($jobData);
        $provider = $aiOptimization['provider'] ?? 'mock';
        
        $industrySkills = getIndustrySkills($industry, $title);
        $existingSkills = array_filter(array_map('trim', explode(',', $job['skills_required'] ?? '')));
        $allSkills = $aiOptimization['suggested_skills'] ?? array_unique(array_merge($industrySkills, $existingSkills));
        
        $smartDescription = $aiOptimization['improved_description'] ?? generateSmartJobDescription($job);
        
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
        $salary = $aiOptimization['salary_range'] ?? ($salaryRanges[$industry]['default'] ?? '₱50,000 - ₱80,000');
        
        $quality = calculateJobQualityScore($job);
        
        $interviewQuestions = $aiService->generateInterviewQuestions($jobData);
        
        return [
            'optimization' => [
                'suggested_skills' => is_array($allSkills) ? array_values($allSkills) : [],
                'improved_description' => $smartDescription,
                'suggested_title' => $aiOptimization['suggested_title'] ?? $title,
                'salary_range' => $salary
            ],
            'interview_questions' => $interviewQuestions,
            'quality' => $quality,
            'industry' => $industry,
            'job' => $job,
            'provider' => $provider
        ];
    }

    /**
     * Generate industry-specific job description
     */
    function generateSmartJobDescription($job) {
        $title = $job['title'] ?? 'the position';
        $skills = $job['skills_required'] ?? '';
        $experience = $job['experience_level'] ?? 'Mid';
        $industry = $job['industry'] ?? 'Technology';
        
        $skillList = array_filter(array_map('trim', explode(',', $skills)));
        
        $experienceMap = [
            'Entry' => '0-2 years',
            'Junior' => '1-3 years',
            'Mid' => '3-5 years',
            'Senior' => '5-8 years',
            'Lead' => '8+ years',
            'Manager' => '5+ years'
        ];
        $expYears = $experienceMap[$experience] ?? '3-5 years';
        
        $industrySkills = getIndustrySkills($industry, $title);
        $allSkills = array_unique(array_merge($industrySkills, $skillList));
        
        $industryDescriptions = [
            'technology' => "cutting-edge technology solutions and digital innovation",
            'finance' => "financial excellence and strategic investment",
            'healthcare' => "patient-centered care and medical excellence",
            'education' => "student success and learning excellence",
            'retail' => "customer satisfaction and retail excellence",
            'hospitality' => "exceptional guest experiences",
            'construction' => "quality construction and infrastructure",
            'media' => "creative content and audience engagement",
            'legal' => "legal excellence and client advocacy",
            'real_estate' => "property development and investment",
            'manufacturing' => "quality manufacturing and production",
            'fitness' => "health, wellness, and fitness excellence"
        ];
        
        $industryFocus = $industryDescriptions[strtolower($industry)] ?? 'professional excellence';
        
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

    // =============================================
    // Get pending review count for sidebar
    // =============================================
    $pendingReviewCount = 0;
    $pendingResult = @getRecord("SELECT COUNT(*) as count FROM job_orders WHERE status = 'pending_review'", []);
    if ($pendingResult && isset($pendingResult['count'])) {
        $pendingReviewCount = (int)$pendingResult['count'];
    }

    // Get pending applications count for sidebar
    $pendingAppsCount = 0;
    $pendingAppsResult = @getRecord("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'", []);
    if ($pendingAppsResult && isset($pendingAppsResult['count'])) {
        $pendingAppsCount = (int)$pendingAppsResult['count'];
    }

    // Get archive count for sidebar
    $totalArchived = 0;
    $archivedTables = ['examination_records', 'interview_evaluations', 'client_assignments', 'deployment_archive'];
    foreach ($archivedTables as $table) {
        $result = @getRecord("SELECT COUNT(*) as count FROM $table", []);
        if ($result && isset($result['count'])) {
            $totalArchived += (int)$result['count'];
        }
    }

    // =============================================
    // Handle AJAX requests - FIXED PostgreSQL
    // =============================================
    if (isset($_GET['ajax'])) {
        $ajaxAction = $_GET['ajax'] ?? '';
        $jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        
        if ($ajaxAction === 'view' && $jobId > 0) {
            $job = @getRecord("SELECT jo.*, c.company_name FROM job_orders jo 
                            JOIN clients c ON jo.client_id = c.id 
                            WHERE jo.id = $1", [$jobId]);
            if ($job) {
                $job['skills_list'] = explode(',', $job['skills_required'] ?? '');
                echo json_encode(['success' => true, 'job' => $job]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Job not found']);
            }
            exit;
        }
        
        if ($ajaxAction === 'edit' && $jobId > 0) {
            $job = @getRecord("SELECT * FROM job_orders WHERE id = $1", [$jobId]);
            if ($job) {
                $client = @getRecord("SELECT company_name FROM clients WHERE id = $1", [$job['client_id']]);
                $job['company_name'] = $client['company_name'] ?? 'Unknown';
                
                $skillsRequired = $job['skills_required'] ?? '';
                $parsedSkills = json_decode($skillsRequired, true);
                
                if (is_array($parsedSkills) && isset($parsedSkills['skills'])) {
                    $job['skills_required_display'] = implode(', ', $parsedSkills['skills'] ?? []);
                    $job['skills_data'] = $parsedSkills;
                } else {
                    $job['skills_required_display'] = $skillsRequired;
                    $job['skills_data'] = null;
                }
                
                echo json_encode(['success' => true, 'job' => $job]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Job not found']);
            }
            exit;
        }
        
        if ($ajaxAction === 'ai_insights' && $jobId > 0) {
            $insights = getEnhancedAIJobInsights($jobId);
            if ($insights) {
                echo json_encode(['success' => true, 'insights' => $insights]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to get AI insights']);
            }
            exit;
        }
        
        if ($ajaxAction === 'bulk_ai_analysis') {
            $results = [];
            foreach ($jobs as $job) {
                $quality = calculateJobQualityScore($job);
                $results[] = [
                    'id' => $job['id'],
                    'title' => $job['title'],
                    'quality_score' => $quality['score'],
                    'quality_level' => $quality['level']
                ];
            }
            echo json_encode(['success' => true, 'results' => $results]);
            exit;
        }
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
        <title>Manage Jobs - ISMERS AI</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
        <style>
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
            }

            * { margin: 0; padding: 0; box-sizing: border-box; }
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
            a { text-decoration: none; color: inherit; }

            /* ===== SIDEBAR ===== */
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
            .dashboard-sidebar.collapsed { width: var(--sidebar-collapsed); }
            .dashboard-sidebar.mobile-hidden { transform: translateX(-100%); }
            .dashboard-sidebar.mobile-open { transform: translateX(0); }

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
            .dashboard-sidebar.collapsed .sidebar-brand-card { padding: 1rem 0.5rem; }
            .dashboard-sidebar.collapsed .sidebar-nav { padding: 0.5rem 0.25rem; }
            .dashboard-sidebar.collapsed .sidebar-main-link { justify-content: center; padding: 0.75rem 0.5rem; }
            .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined { font-size: 1.5rem; }
            .dashboard-sidebar.collapsed .sidebar-footer .user-card { justify-content: center; padding: 0.5rem; }
            .dashboard-sidebar.collapsed .sidebar-footer .user-card .avatar { width: 2.5rem; height: 2.5rem; font-size: 0.875rem; }

            .sidebar-brand-card {
                padding: 1.5rem;
                display: flex;
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 0.5rem;
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
            .sidebar-brand-icon .material-symbols-outlined { font-size: 1.5rem; }
            .sidebar-brand-text { font-size: 0.875rem; font-weight: 600; color: var(--slate-900); }
            .sidebar-brand-category { font-size: 0.75rem; color: var(--slate-500); margin-top: 0.25rem; }
            .sidebar-nav { flex: 1; overflow-y: auto; padding: 1.5rem 1.25rem; }
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
            .sidebar-main-link:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
            .sidebar-main-link.active { background: var(--bg-surface-container-high); color: var(--primary); }
            .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; flex-shrink: 0; }
            .sidebar-main-link .nav-badge {
                margin-left: auto;
                background: var(--primary);
                color: white;
                font-size: 0.7rem;
                font-weight: 700;
                padding: 0.125rem 0.5rem;
                border-radius: 50px;
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
            .sidebar-footer .user-card .user-info .user-name { font-size: 0.875rem; font-weight: 600; color: var(--text-on-surface); }
            .sidebar-footer .user-card .user-info .user-email { font-size: 0.75rem; color: var(--text-on-surface-variant); }

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
            .sidebar-backdrop.active { display: block; opacity: 1; }

            .main-wrapper {
                flex: 1;
                display: flex;
                flex-direction: column;
                height: 100vh;
                overflow: hidden;
                margin-left: var(--sidebar-width);
                transition: margin-left 0.3s ease;
            }
            .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }

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
            .top-header-left { display: flex; align-items: center; gap: 0.75rem; }
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
            .top-header-left .separator { color: var(--outline-variant); font-weight: 300; user-select: none; }

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
            .sidebar-toggle-btn:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
            .sidebar-toggle-btn .material-symbols-outlined { font-size: 1.25rem; }

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
            .mobile-menu-btn:hover { background: var(--bg-surface-low); color: var(--text-on-surface); }
            .mobile-menu-btn .material-symbols-outlined { font-size: 1.25rem; }

            .profile-dropdown-wrapper { position: relative; }
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
            .profile-dropdown-toggle:hover { background: var(--bg-surface-low); border-color: rgba(199, 196, 216, 0.3); }
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
            .profile-dropdown-toggle .profile-name { font-size: 0.875rem; font-weight: 600; color: var(--text-on-surface); }
            .profile-dropdown-toggle .profile-role { font-size: 0.75rem; color: var(--text-on-surface-variant); font-weight: 400; }
            .profile-dropdown-toggle .material-symbols-outlined { font-size: 1rem; color: var(--text-on-surface-variant); transition: transform var(--transition-fast); }
            .profile-dropdown-toggle.open .material-symbols-outlined:last-child { transform: rotate(180deg); }

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
            .profile-dropdown-menu.open { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
            .profile-dropdown-menu .dropdown-header { padding: 0.5rem 0.875rem 0.25rem; font-size: 0.65rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-on-surface-variant); }
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
            .profile-dropdown-menu .dropdown-item:hover { background: var(--bg-surface-low); color: var(--primary); }
            .profile-dropdown-menu .dropdown-item .material-symbols-outlined { font-size: 1.125rem; color: var(--text-on-surface-variant); }
            .profile-dropdown-menu .dropdown-item:hover .material-symbols-outlined { color: var(--primary); }
            .profile-dropdown-menu .dropdown-item.danger { color: #dc2626; }
            .profile-dropdown-menu .dropdown-item.danger:hover { background: #fef2f2; color: #dc2626; }
            .profile-dropdown-menu .dropdown-item.danger .material-symbols-outlined { color: #dc2626; }
            .profile-dropdown-menu .dropdown-divider { height: 1px; background: var(--slate-200); margin: 0.25rem 0.5rem; }

            .main-scroll { flex: 1; overflow-y: auto; padding: 1.5rem 2rem; }
            .main-scroll .container { max-width: 80rem; margin: 0 auto; }

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
                .breadcrumb-bar { border-radius: var(--radius-2xl); flex-direction: row; align-items: center; justify-content: space-between; }
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
            .breadcrumb-view .material-symbols-outlined { font-size: 1.25rem; }
            .breadcrumb-view .status-dot {
                width: 0.5rem;
                height: 0.5rem;
                border-radius: 50%;
                background: #22c55e;
                animation: pulse 2s infinite;
            }
            @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

            .page-header {
                display: flex;
                flex-direction: column;
                gap: 1rem;
                margin-bottom: 1.5rem;
            }
            @media (min-width: 640px) {
                .page-header { flex-direction: row; align-items: center; justify-content: space-between; }
            }
            .page-header h1 { font-size: 1.875rem; font-weight: 700; color: var(--text-on-surface); letter-spacing: -0.025em; }
            .page-header p { font-size: 0.875rem; color: var(--text-on-surface-variant); margin-top: 0.25rem; }
            .page-header .header-actions { display: flex; gap: 0.75rem; flex-wrap: wrap; }

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
            .btn-primary { background: var(--primary); color: white; }
            .btn-primary:hover { background: var(--on-primary-fixed-variant); transform: translateY(-1px); box-shadow: var(--shadow-md); }
            .btn-outline { background: transparent; color: var(--primary); border: 1.5px solid var(--primary); }
            .btn-outline:hover { background: var(--bg-surface-low); }
            .btn-success { background: #22c55e; color: white; }
            .btn-success:hover { background: #16a34a; transform: translateY(-1px); box-shadow: var(--shadow-md); }
            .btn-danger { background: #dc2626; color: white; }
            .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: var(--shadow-md); }
            .btn-sm { padding: 0.375rem 0.75rem; font-size: 0.75rem; border-radius: 0.5rem; }
            .btn .material-symbols-outlined { font-size: 1.125rem; }
            .btn-sm .material-symbols-outlined { font-size: 1rem; }
            .btn-ai { background: linear-gradient(135deg, #7c3aed, #4f46e5); color: white; }
            .btn-ai:hover { background: linear-gradient(135deg, #6d28d9, #4338ca); transform: translateY(-1px); box-shadow: var(--shadow-md); }

            .quality-score {
                display: inline-flex;
                align-items: center;
                gap: 0.25rem;
                padding: 0.125rem 0.5rem;
                border-radius: var(--radius-full);
                font-size: 0.65rem;
                font-weight: 700;
                cursor: help;
                border: 1px solid transparent;
            }
            .quality-score .material-symbols-outlined { font-size: 0.75rem; }
            .quality-score.excellent { background: #d1fae5; color: #059669; border-color: #059669; }
            .quality-score.good { background: #dbeafe; color: #2563eb; border-color: #2563eb; }
            .quality-score.fair { background: #fef3c7; color: #d97706; border-color: #d97706; }
            .quality-score.needs-improvement { background: #fecaca; color: #dc2626; border-color: #dc2626; }

            .search-bar {
                display: flex;
                gap: 0.75rem;
                margin-bottom: 1.25rem;
                flex-wrap: wrap;
            }
            .search-bar .search-input-wrapper {
                flex: 1;
                min-width: 200px;
                position: relative;
            }
            .search-bar .search-input-wrapper .material-symbols-outlined {
                position: absolute;
                left: 0.875rem;
                top: 50%;
                transform: translateY(-50%);
                color: var(--text-on-surface-variant);
                font-size: 1.25rem;
            }
            .search-bar .search-input-wrapper input {
                width: 100%;
                padding: 0.625rem 0.875rem 0.625rem 2.75rem;
                border: 2px solid var(--slate-200);
                border-radius: 0.75rem;
                font-size: 0.875rem;
                font-family: var(--font-sans);
                transition: all var(--transition-fast);
                background: var(--bg-surface);
                color: var(--text-on-surface);
            }
            .search-bar .search-input-wrapper input:focus {
                outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
            }
            .search-bar .search-input-wrapper input::placeholder { color: var(--text-on-surface-variant); opacity: 0.6; }

            .status-filters {
                display: flex;
                gap: 0.5rem;
                flex-wrap: wrap;
                margin-bottom: 1.25rem;
            }
            .status-filter {
                padding: 0.375rem 1rem;
                border-radius: var(--radius-full);
                font-size: 0.8125rem;
                font-weight: 600;
                color: var(--text-on-surface-variant);
                background: var(--bg-surface);
                border: 2px solid var(--slate-200);
                transition: all var(--transition-fast);
                white-space: nowrap;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 0.375rem;
            }
            .status-filter:hover { border-color: var(--primary); color: var(--primary); }
            .status-filter.active { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 4px 15px rgba(79, 70, 229, 0.35); }
            .status-filter .filter-count {
                display: inline-block;
                background: rgba(0, 0, 0, 0.08);
                border-radius: var(--radius-full);
                padding: 0 0.5rem;
                font-size: 0.6875rem;
                font-weight: 700;
            }
            .status-filter.active .filter-count { background: rgba(255, 255, 255, 0.25); }

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
                font-size: 1rem;
                font-weight: 700;
                display: flex;
                align-items: center;
                gap: 0.625rem;
            }
            .card-header h3 .material-symbols-outlined { font-size: 1.25rem; color: var(--primary); }
            .card-header .job-count { font-size: 0.8125rem; color: var(--text-on-surface-variant); background: var(--bg-surface-low); padding: 0.25rem 0.75rem; border-radius: var(--radius-full); }
            .card-body { padding: 0; overflow-x: auto; }

            table { width: 100%; border-collapse: collapse; font-size: 0.875rem; min-width: 800px; }
            table thead { background: var(--bg-surface-low); }
            table th { padding: 0.75rem 1rem; text-align: left; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-on-surface-variant); border-bottom: 2px solid var(--slate-200); }
            table td { padding: 0.75rem 1rem; border-bottom: 1px solid var(--slate-200); vertical-align: middle; }
            table tbody tr:hover td { background: var(--bg-surface-low); }
            table tbody tr:last-child td { border-bottom: none; }
            .job-title { font-weight: 600; color: var(--text-on-surface); }
            .job-company { font-size: 0.8125rem; color: var(--text-on-surface-variant); }
            .job-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 0.25rem 0.75rem;
                font-size: 0.75rem;
                color: var(--text-on-surface-variant);
                margin-top: 0.25rem;
            }
            .job-meta .meta-item { display: flex; align-items: center; gap: 0.25rem; }
            .job-meta .meta-item .material-symbols-outlined { font-size: 0.875rem; }

            .badge {
                display: inline-block;
                padding: 0.1875rem 0.75rem;
                border-radius: var(--radius-full);
                font-size: 0.6875rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .badge-open { background: #d1fae5; color: #059669; }
            .badge-ongoing { background: #dbeafe; color: #2563eb; }
            .badge-filled { background: #f3e8ff; color: #7c3aed; }
            .badge-cancelled { background: #fecaca; color: #dc2626; }
            .badge-draft { background: #f3f4f6; color: #6b7280; }
            .badge-pending { background: #fef3c7; color: #d97706; border: 1px solid #fcd34d; animation: pulse-border 2s infinite; }
            @keyframes pulse-border { 0%, 100% { border-color: #fcd34d; } 50% { border-color: #f59e0b; } }

            .badge-urgency-low { background: #f3f4f6; color: #6b7280; }
            .badge-urgency-medium { background: #fef3c7; color: #d97706; }
            .badge-urgency-high { background: #fecaca; color: #dc2626; }

            .action-buttons {
                display: flex;
                gap: 0.375rem;
                justify-content: center;
                flex-wrap: wrap;
            }

            /* ===== Tooltip ===== */
            .action-btn-wrapper {
                position: relative;
                display: inline-flex;
            }
            .action-btn-wrapper .tooltip {
                visibility: hidden;
                opacity: 0;
                position: absolute;
                bottom: calc(100% + 6px);
                left: 50%;
                transform: translateX(-50%);
                background: #1b1b24;
                color: white;
                padding: 0.25rem 0.625rem;
                border-radius: 0.375rem;
                font-size: 0.65rem;
                font-weight: 500;
                white-space: nowrap;
                transition: all 0.2s ease;
                z-index: 100;
                font-family: var(--font-sans);
                pointer-events: none;
            }
            .action-btn-wrapper .tooltip::after {
                content: '';
                position: absolute;
                top: 100%;
                left: 50%;
                transform: translateX(-50%);
                border: 4px solid transparent;
                border-top-color: #1b1b24;
            }
            .action-btn-wrapper:hover .tooltip {
                visibility: visible;
                opacity: 1;
            }

            .empty-state { text-align: center; padding: 4rem 1.5rem; }
            .empty-state .material-symbols-outlined { font-size: 4rem; color: var(--slate-200); display: block; margin-bottom: 1rem; }
            .empty-state h4 { font-size: 1.125rem; font-weight: 700; color: var(--text-on-surface); margin-bottom: 0.25rem; }
            .empty-state p { font-size: 0.875rem; color: var(--text-on-surface-variant); }
            .empty-state .btn { margin-top: 1rem; }

            .modal-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
                backdrop-filter: blur(4px);
                z-index: 1000;
                justify-content: center;
                align-items: center;
                padding: 1rem;
            }
            .modal-overlay.active { display: flex; }
            .modal {
                background: var(--bg-surface);
                border-radius: var(--radius-2xl);
                max-width: 56rem;
                width: 100%;
                max-height: 90vh;
                overflow: hidden;
                box-shadow: var(--shadow-xl);
                animation: modalSlideUp 0.3s ease-out;
                display: flex;
                flex-direction: column;
            }
            @keyframes modalSlideUp {
                from { transform: translateY(20px) scale(0.95); opacity: 0; }
                to { transform: translateY(0) scale(1); opacity: 1; }
            }
            .modal-header {
                padding: 1.25rem 1.5rem;
                border-bottom: 1px solid var(--slate-200);
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-shrink: 0;
            }
            .modal-header h2 { font-size: 1.25rem; font-weight: 700; display: flex; align-items: center; gap: 0.625rem; }
            .modal-header h2 .material-symbols-outlined { font-size: 1.5rem; color: var(--primary); }
            .modal-close {
                background: none;
                border: none;
                cursor: pointer;
                padding: 0.5rem;
                border-radius: 0.5rem;
                color: var(--text-on-surface-variant);
                transition: all var(--transition-fast);
            }
            .modal-close:hover { background: var(--bg-surface-low); }
            .modal-close .material-symbols-outlined { font-size: 1.5rem; }
            .modal-body { padding: 1.5rem; overflow-y: auto; flex: 1; }
            .modal-footer {
                padding: 1rem 1.5rem;
                border-top: 1px solid var(--slate-200);
                display: flex;
                justify-content: flex-end;
                gap: 0.75rem;
                flex-shrink: 0;
                flex-wrap: wrap;
            }

            .view-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
            .view-item { margin-bottom: 0.25rem; }
            .view-item .label { font-size: 0.6875rem; font-weight: 600; color: var(--text-on-surface-variant); text-transform: uppercase; letter-spacing: 0.05em; }
            .view-item .value {
                font-size: 0.875rem;
                color: var(--text-on-surface);
                padding: 0.5rem 0.75rem;
                background: var(--bg-surface-low);
                border-radius: 0.5rem;
                margin-top: 0.125rem;
            }
            .view-item .value.skills { display: flex; flex-wrap: wrap; gap: 0.375rem; background: transparent; padding: 0; margin-top: 0.25rem; }
            .view-item .value.skills .skill-tag {
                display: inline-block;
                padding: 0.1875rem 0.625rem;
                background: rgba(79, 70, 229, 0.08);
                color: var(--primary);
                border-radius: var(--radius-full);
                font-size: 0.75rem;
                font-weight: 500;
                border: 1px solid rgba(79, 70, 229, 0.15);
            }
            .view-item.full-width { grid-column: 1 / -1; }

            .ai-insights {
                margin-top: 1.5rem;
                padding: 1rem;
                background: linear-gradient(135deg, #ede9fe, #e0e7ff);
                border-radius: var(--radius-xl);
                border: 1px solid #c7c4d8;
            }
            .ai-insights .ai-insights-header { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; }
            .ai-insights .ai-insights-header .ai-icon { color: var(--primary); }
            .ai-insights .ai-insights-header h4 { font-size: 0.875rem; font-weight: 700; color: var(--text-on-surface); }
            .ai-insights .ai-insight-item { padding: 0.5rem 0.75rem; background: rgba(255, 255, 255, 0.6); border-radius: 0.5rem; margin-bottom: 0.5rem; font-size: 0.85rem; }
            .ai-insights .ai-insight-item:last-child { margin-bottom: 0; }
            .ai-insights .ai-insight-item .insight-label { font-weight: 600; color: var(--primary); }
            .ai-insights .quality-score-display { display: flex; align-items: center; gap: 1rem; padding: 0.5rem 0.75rem; background: rgba(255, 255, 255, 0.6); border-radius: 0.5rem; margin-bottom: 0.5rem; }
            .ai-insights .quality-score-display .score-badge { font-size: 1.5rem; font-weight: 800; }
            .ai-insights .quality-score-display .score-details { font-size: 0.75rem; color: var(--text-on-surface-variant); }

            .form-group { margin-bottom: 1rem; }
            .form-group label { display: block; font-size: 0.8125rem; font-weight: 600; color: var(--text-on-surface); margin-bottom: 0.25rem; }
            .form-group label .required { color: #dc2626; margin-left: 0.125rem; }
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
            .form-group .form-control::placeholder { color: var(--text-on-surface-variant); opacity: 0.6; }
            .form-group textarea.form-control { resize: vertical; min-height: 100px; }
            .form-group select.form-control {
                appearance: none;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
                background-repeat: no-repeat;
                background-position: right 1rem center;
                padding-right: 2.5rem;
            }
            .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
            @media (max-width: 640px) { .form-row { grid-template-columns: 1fr; } }

            .loading-spinner { text-align: center; padding: 2rem; }
            .loading-spinner .spinner {
                width: 2.5rem;
                height: 2.5rem;
                border: 4px solid var(--slate-200);
                border-top-color: var(--primary);
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
                margin: 0 auto;
            }
            @keyframes spin { to { transform: rotate(360deg); } }
            .loading-spinner p { margin-top: 0.75rem; color: var(--text-on-surface-variant); font-size: 0.875rem; }

            .toast {
                position: fixed;
                bottom: 1.5rem;
                right: 1.5rem;
                padding: 0.875rem 1.5rem;
                border-radius: 0.75rem;
                color: white;
                font-weight: 600;
                font-size: 0.875rem;
                box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
                z-index: 10000;
                animation: slideUp 0.4s ease-out;
                max-width: 400px;
            }
            .toast.success { background: #22c55e; }
            .toast.error { background: #dc2626; }
            .toast.info { background: var(--primary); }
            @keyframes slideUp {
                from { opacity: 0; transform: translateY(30px) scale(0.95); }
                to { opacity: 1; transform: translateY(0) scale(1); }
            }

            @media (min-width: 768px) {
                .sidebar-backdrop { display: none !important; }
                .mobile-menu-btn { display: none !important; }
                .dashboard-sidebar { position: fixed; transform: translateX(0) !important; box-shadow: var(--shadow-xl); height: 100vh; }
                .dashboard-sidebar.mobile-hidden { transform: translateX(0) !important; }
                .main-wrapper { margin-left: var(--sidebar-width); }
                .dashboard-sidebar.collapsed ~ .main-wrapper { margin-left: var(--sidebar-collapsed); }
                .profile-dropdown-toggle .profile-name, .profile-dropdown-toggle .profile-role { display: inline; }
            }
            @media (max-width: 767px) {
                .dashboard-sidebar { position: fixed; width: var(--sidebar-width); transform: translateX(-100%); box-shadow: var(--shadow-xl); }
                .dashboard-sidebar.mobile-open { transform: translateX(0); }
                .dashboard-sidebar.collapsed { width: var(--sidebar-width); }
                .sidebar-toggle-btn { display: none !important; }
                .mobile-menu-btn { display: flex; }
                .main-wrapper { margin-left: 0 !important; }
                .main-scroll { padding: 1rem; }
                .top-header-left .separator { display: none; }
                .profile-dropdown-toggle .profile-name, .profile-dropdown-toggle .profile-role { display: none; }
                .view-grid { grid-template-columns: 1fr; }
                .modal { max-width: 100%; margin: 0.5rem; max-height: 95vh; }
                .modal-header { padding: 1rem 1.25rem; }
                .modal-body { padding: 1rem 1.25rem; }
                .modal-footer { padding: 0.75rem 1.25rem; flex-direction: column; }
                .modal-footer .btn { width: 100%; justify-content: center; }
                .action-buttons .btn-sm { font-size: 0.6875rem; padding: 0.25rem 0.5rem; }
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
                .dashboard-sidebar.collapsed .sidebar-brand-card { padding: 1.5rem; }
                .dashboard-sidebar.collapsed .sidebar-nav { padding: 1.5rem 1.25rem; }
                .dashboard-sidebar.collapsed .sidebar-main-link { justify-content: flex-start; padding: 0.75rem 1rem; }
                .dashboard-sidebar.collapsed .sidebar-main-link .material-symbols-outlined { font-size: 1.25rem; }
                .dashboard-sidebar.collapsed .sidebar-footer .user-card { justify-content: flex-start; padding: 0.5rem 0.75rem; }
            }
            @media (max-width: 480px) {
                .main-scroll { padding: 0.75rem; }
                .breadcrumb-bar { padding: 0.75rem 1rem; }
                .page-header h1 { font-size: 1.5rem; }
                .search-bar { flex-direction: column; }
                .status-filters { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 0.25rem; -webkit-overflow-scrolling: touch; }
                .status-filter { font-size: 0.75rem; padding: 0.25rem 0.75rem; }
                table { font-size: 0.8125rem; min-width: 500px; }
                table th, table td { padding: 0.5rem 0.75rem; }
                .modal-body { padding: 0.75rem 1rem; }
                .toast { max-width: 90%; bottom: 1rem; right: 1rem; }
                .quality-score { font-size: 0.55rem; padding: 0.1rem 0.4rem; }
                .quality-score .material-symbols-outlined { font-size: 0.65rem; }
            }

            .main-scroll::-webkit-scrollbar { width: 6px; }
            .main-scroll::-webkit-scrollbar-track { background: transparent; }
            .main-scroll::-webkit-scrollbar-thumb { background: var(--slate-200); border-radius: 3px; }
            .main-scroll::-webkit-scrollbar-thumb:hover { background: var(--slate-500); }

            .approval-actions {
                display: flex;
                gap: 0.375rem;
                align-items: center;
                flex-wrap: wrap;
            }
            .approval-actions .btn-sm { font-size: 0.65rem; padding: 0.2rem 0.5rem; }
            .client-request-badge {
                display: inline-block;
                font-size: 0.55rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                padding: 0.1rem 0.4rem;
                border-radius: var(--radius-full);
                background: #fef3c7;
                color: #d97706;
                border: 1px solid #fcd34d;
                margin-left: 0.5rem;
            }

            .header-logo {
    height: 2rem;
    width: auto;
    max-height: 2.5rem;
    object-fit: contain;
    border-radius: 0.375rem;
}

/* For mobile responsiveness */
@media (max-width: 480px) {
    .header-logo {
        height: 1.5rem;
    }
}
.sidebar-logo-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 3.5rem;
    height: 3.5rem;
    flex-shrink: 0;
}

.sidebar-logo {
    width: 100%;
    height: 100%;
    object-fit: contain;
    border-radius: 0.75rem;
    transition: all 0.3s ease;
}

.dashboard-sidebar.collapsed .sidebar-logo {
    width: 2.5rem;
    height: 2.5rem;
}
    </style>
</head>
<body>

<!-- ===== SIDEBAR ===== -->
<aside class="dashboard-sidebar" id="appSidebar">
    <div class="sidebar-brand-card">
        <div class="sidebar-logo-wrapper">
            <img src="logo.png" alt="ISMERS" class="sidebar-logo">
        </div>
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
             <?php 
$pendingReviewCount = 0;
$pendingResult = @getRecord("SELECT COUNT(*) as count FROM job_orders WHERE status = 'pending_review'", []);
if ($pendingResult && isset($pendingResult['count'])) {
    $pendingReviewCount = (int)$pendingResult['count'];
}
if ($pendingReviewCount > 0): ?>
    <span class="nav-badge" style="background:#d97706;"><?php echo $pendingReviewCount; ?></span>
<?php endif; ?>
            </a>
            <a href="applicants.php" class="sidebar-main-link <?php echo basename($_SERVER['PHP_SELF']) == 'applicants.php' ? 'active' : ''; ?>">
                <span class="material-symbols-outlined">people</span>
                <span class="nav-text">Applicants</span>
                <span class="nav-badge"><?php 
                    $pendingApps = getRecord("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'", [])['count'] ?? 0;
                    echo $pendingApps; 
                ?></span>
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
                    $totalArchived = 0;
                    $archivedResult = getRecord("SELECT COUNT(*) as count FROM examination_records", []);
                    $totalArchived += $archivedResult['count'] ?? 0;
                    $archivedResult = getRecord("SELECT COUNT(*) as count FROM interview_evaluations", []);
                    $totalArchived += $archivedResult['count'] ?? 0;
                    $archivedResult = getRecord("SELECT COUNT(*) as count FROM client_assignments", []);
                    $totalArchived += $archivedResult['count'] ?? 0;
                    $archivedResult = getRecord("SELECT COUNT(*) as count FROM deployment_archive", []);
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
     <!-- ===== TOP HEADER ===== -->
<header class="top-header">
    <div class="top-header-left">
        <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu">
            <span class="material-symbols-outlined">menu</span>
        </button>
        <button class="sidebar-toggle-btn" id="sidebarToggleBtn" aria-label="Toggle sidebar">
            <span class="material-symbols-outlined" id="sidebarToggleIcon">chevron_left</span>
        </button>
        <!-- ✅ Logo added here -->
        <img src="logo.png" alt="ISMERS" class="header-logo">
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
                        <span class="material-symbols-outlined">work</span>
                        <span>Jobs</span>
                        <span class="status-dot"></span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">●</span>
                        <span style="font-weight:400; color:var(--text-on-surface-variant);">
                            <?php echo $statusFilter === 'all' ? 'All' : ucfirst(str_replace('_', ' ', $statusFilter)); ?> 
                            (<?php echo count($jobs); ?> jobs)
                        </span>
                    </div>
                    <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                        Last updated: <?php echo date('M d, Y H:i'); ?>
                    </span>
                </div>

                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1>Manage Jobs</h1>
                        <p>View and manage all job postings with AI insights</p>
                    </div>
                    <div class="header-actions">
                        <button class="btn btn-ai" onclick="bulkAIAnalysis()">
                            <span class="material-symbols-outlined">auto_awesome</span>
                            Analyze All Jobs
                        </button>
                        <a href="post_job.php" class="btn btn-primary">
                            <span class="material-symbols-outlined">add</span>
                            Post New Job
                        </a>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="search-bar">
                    <div class="search-input-wrapper">
                        <span class="material-symbols-outlined">search</span>
                        <input type="text" id="searchInput" placeholder="Search jobs, companies, locations..." 
                            value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                    <button class="btn btn-primary" onclick="applyFilters()">Search</button>
                    <?php if (!empty($searchQuery) || $statusFilter !== 'all'): ?>
                        <a href="jobs.php" class="btn btn-outline">Clear Filters</a>
                    <?php endif; ?>
                </div>

                <!-- Status Filters -->
                <div class="status-filters">
                    <?php foreach ($allStatuses as $key => $label): ?>
                        <a href="?status=<?php echo $key; ?>&search=<?php echo urlencode($searchQuery); ?>" 
                        class="status-filter <?php echo $statusFilter === $key ? 'active' : ''; ?>">
                            <?php echo $label; ?>
                            <span class="filter-count"><?php echo $statusCounts[$key] ?? 0; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Jobs Table -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <span class="material-symbols-outlined">work</span>
                            <?php if ($statusFilter === 'all'): ?>
                                All Jobs
                            <?php else: ?>
                                <?php echo ucfirst(str_replace('_', ' ', $statusFilter)); ?> Jobs
                            <?php endif; ?>
                            <?php if ($pendingReviewCount > 0 && $statusFilter === 'all'): ?>
                                <span class="client-request-badge"><?php echo $pendingReviewCount; ?> pending requests</span>
                            <?php endif; ?>
                        </h3>
                        <span class="job-count"><?php echo count($jobs); ?> jobs found</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($jobs)): ?>
                            <div class="empty-state">
                                <span class="material-symbols-outlined">work_off</span>
                                <h4>No Jobs Found</h4>
                                <p>
                                    <?php if ($statusFilter !== 'all'): ?>
                                        You don't have any <?php echo str_replace('_', ' ', $statusFilter); ?> jobs.
                                    <?php else: ?>
                                        You haven't posted any jobs yet.
                                    <?php endif; ?>
                                </p>
                                <a href="post_job.php" class="btn btn-primary">Post Your First Job</a>
                            </div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Job Title</th>
                                        <th>Company</th>
                                        <th>Location</th>
                                        <th>Applications</th>
                                        <th>Quality</th>
                                        <th>Status</th>
                                        <th style="text-align:center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($jobs as $job): ?>
                                        <tr>
                                            <td>
                                                <div class="job-title">
                                                    <?php echo htmlspecialchars($job['title']); ?>
                                                    <?php if (($job['status'] ?? '') === 'pending_review'): ?>
                                                        <span class="client-request-badge">Client Request</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="job-meta">
                                                    <span class="meta-item">
                                                        <span class="material-symbols-outlined">work_history</span>
                                                        <?php echo htmlspecialchars($job['job_type'] ?? 'Full-time'); ?>
                                                    </span>
                                                    <span class="meta-item">
                                                        <span class="material-symbols-outlined">payments</span>
                                                        <?php echo htmlspecialchars($job['salary_range'] ?? 'N/A'); ?>
                                                    </span>
                                                    <span class="meta-item">
                                                        <span class="badge <?php echo $urgencyBadges[$job['urgency']] ?? 'badge-urgency-low'; ?>">
                                                            <?php echo ucfirst($job['urgency'] ?? 'Low'); ?>
                                                        </span>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="job-company"><?php echo htmlspecialchars($job['company_name']); ?></div>
                                                <div style="font-size:0.65rem; color:var(--text-on-surface-variant);">
                                                    <?php echo htmlspecialchars($job['industry'] ?? 'General'); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="font-size:0.8125rem; color:var(--text-on-surface-variant);">
                                                    <span class="material-symbols-outlined" style="font-size:1rem; vertical-align:middle;">location_on</span>
                                                    <?php echo htmlspecialchars($job['location'] ?? 'Remote'); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="font-weight:600; color:var(--text-on-surface);">
                                                    <?php echo $job['application_count'] ?? 0; ?>
                                                </div>
                                                <div style="font-size:0.75rem; color:var(--text-on-surface-variant);">
                                                    <?php echo $job['pending_count'] ?? 0; ?> pending
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (isset($job['quality_score'])): 
                                                    $levelClass = strtolower(str_replace(' ', '-', $job['quality_level']));
                                                ?>
                                                    <span class="quality-score <?php echo $levelClass; ?>" 
                                                        title="<?php echo htmlspecialchars(implode('; ', $job['quality_details'] ?? [])); ?>">
                                                        <span class="material-symbols-outlined"><?php echo $job['quality_icon'] ?? 'star'; ?></span>
                                                        <?php echo $job['quality_score']; ?>%
                                                    </span>
                                                <?php else: ?>
                                                    <span class="quality-score" style="background:#f3f4f6; color:#6b7280;">
                                                        <span class="material-symbols-outlined">help</span>
                                                        N/A
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $statusBadges[$job['status']] ?? 'badge-draft'; ?>">
                                                    <?php echo $statusLabels[$job['status']] ?? ucfirst($job['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if (($job['status'] ?? '') === 'pending_review'): ?>
                                                        <!-- Approval Actions for Client Requests -->
                                                        <div class="approval-actions">
                                                            <div class="action-btn-wrapper">
                                                                <button class="btn btn-success btn-sm" onclick="approveJob(<?php echo $job['id']; ?>)" title="Approve this job request">
                                                                    <span class="material-symbols-outlined">check_circle</span>
                                                                </button>
                                                                <span class="tooltip">Approve</span>
                                                            </div>
                                                            <div class="action-btn-wrapper">
                                                                <button class="btn btn-danger btn-sm" onclick="rejectJob(<?php echo $job['id']; ?>)" title="Reject this job request">
                                                                    <span class="material-symbols-outlined">cancel</span>
                                                                </button>
                                                                <span class="tooltip">Reject</span>
                                                            </div>
                                                            <div class="action-btn-wrapper">
                                                                <button class="btn btn-outline btn-sm" onclick="viewJob(<?php echo $job['id']; ?>)" title="View job details">
                                                                    <span class="material-symbols-outlined">visibility</span>
                                                                </button>
                                                                <span class="tooltip">View Details</span>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <!-- Regular Actions -->
                                                        <div class="action-btn-wrapper">
                                                            <button class="btn btn-outline btn-sm" onclick="viewJob(<?php echo $job['id']; ?>)" title="View job details">
                                                                <span class="material-symbols-outlined">visibility</span>
                                                            </button>
                                                            <span class="tooltip">View</span>
                                                        </div>
                                                        <div class="action-btn-wrapper">
                                                            <button class="btn btn-primary btn-sm" onclick="editJob(<?php echo $job['id']; ?>)" title="Edit this job">
                                                                <span class="material-symbols-outlined">edit</span>
                                                            </button>
                                                            <span class="tooltip">Edit</span>
                                                        </div>
                                                        <div class="action-btn-wrapper">
                                                            <button class="btn btn-ai btn-sm" onclick="viewAIInsights(<?php echo $job['id']; ?>)" title="Get AI insights and recommendations">
                                                                <span class="material-symbols-outlined">auto_awesome</span>
                                                            </button>
                                                            <span class="tooltip">AI Insights</span>
                                                        </div>
                                                        <?php if ($isHRManager || $role === 'admin'): ?>
                                                            <div class="action-btn-wrapper">
                                                                <button class="btn btn-danger btn-sm" onclick="deleteJob(<?php echo $job['id']; ?>)" title="Delete this job permanently">
                                                                    <span class="material-symbols-outlined">delete</span>
                                                                </button>
                                                                <span class="tooltip">Delete</span>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- =============================================
    MODAL
    ============================================= -->
    <div class="modal-overlay" id="jobModal">
        <div class="modal">
            <div class="modal-header">
                <h2>
                    <span class="material-symbols-outlined" id="modalIcon">work</span>
                    <span id="modalTitle">Job Details</span>
                </h2>
                <button class="modal-close" onclick="closeModal()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="loading-spinner" id="modalLoading">
                    <div class="spinner"></div>
                    <p>Loading...</p>
                </div>
                <div id="modalContent" style="display:none;"></div>
            </div>
            <div class="modal-footer" id="modalFooter">
                <button class="btn btn-outline" onclick="closeModal()">Close</button>
                <button class="btn btn-primary" id="modalActionBtn" onclick="submitEditJobFromModal()" style="display:none;">Save Changes</button>
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
    const sidebarBackdrop = document.createElement('div');
    sidebarBackdrop.className = 'sidebar-backdrop';
    sidebarBackdrop.id = 'sidebarBackdrop';
    document.body.prepend(sidebarBackdrop);

    function openMobileSidebar() {
        sidebar.classList.add('mobile-open');
        sidebarBackdrop.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeMobileSidebar() {
        sidebar.classList.remove('mobile-open');
        sidebarBackdrop.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', openMobileSidebar);
    }
    sidebarBackdrop.addEventListener('click', closeMobileSidebar);

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
    // 4. SEARCH FUNCTION
    // =============================================
    function applyFilters() {
        const search = document.getElementById('searchInput');
        if (!search) return;
        
        const status = '<?php echo $statusFilter; ?>';
        let url = 'jobs.php?';
        if (status !== 'all') url += 'status=' + status + '&';
        if (search.value) url += 'search=' + encodeURIComponent(search.value);
        window.location.href = url;
    }

    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });
    }

    // =============================================
    // 5. MODAL FUNCTIONS
    // =============================================
    const modalOverlay = document.getElementById('jobModal');
    const modalContent = document.getElementById('modalContent');
    const modalLoading = document.getElementById('modalLoading');
    const modalTitle = document.getElementById('modalTitle');
    const modalIcon = document.getElementById('modalIcon');
    const modalActionBtn = document.getElementById('modalActionBtn');

    // Store the current job ID being edited
    let currentEditJobId = null;

    function openModal() {
        if (modalOverlay) {
            modalOverlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal() {
        if (modalOverlay) {
            modalOverlay.classList.remove('active');
            document.body.style.overflow = '';
        }
        if (modalContent) modalContent.style.display = 'none';
        if (modalLoading) modalLoading.style.display = 'block';
        if (modalActionBtn) modalActionBtn.style.display = 'none';
        currentEditJobId = null;
    }

    if (modalOverlay) {
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (modalOverlay && modalOverlay.classList.contains('active')) {
                closeModal();
            } else {
                closeMobileSidebar();
                if (profileToggle) profileToggle.classList.remove('open');
                if (profileMenu) profileMenu.classList.remove('open');
            }
        }
    });

    // =============================================
    // 6. VIEW JOB
    // =============================================
    function viewJob(jobId) {
        openModal();
        if (modalTitle) modalTitle.textContent = 'Job Details';
        if (modalIcon) modalIcon.textContent = 'work';
        if (modalActionBtn) modalActionBtn.style.display = 'none';
        if (modalLoading) modalLoading.style.display = 'block';
        if (modalContent) modalContent.style.display = 'none';

        fetch('jobs.php?ajax=view&id=' + jobId)
            .then(response => response.json())
            .then(data => {
                if (modalLoading) modalLoading.style.display = 'none';
                if (modalContent) modalContent.style.display = 'block';

                if (data.success) {
                    const job = data.job;
                    const skills = job.skills_list || [];
                    const skillsHtml = skills.filter(s => s.trim()).map(s => 
                        '<span class="skill-tag">' + escapeHtml(s.trim()) + '</span>'
                    ).join('');

                    if (modalContent) {
                        modalContent.innerHTML = `
                            <div class="view-grid">
                                <div class="view-item">
                                    <div class="label">Job Title</div>
                                    <div class="value">${escapeHtml(job.title)}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Company</div>
                                    <div class="value">${escapeHtml(job.company_name)}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Location</div>
                                    <div class="value">${escapeHtml(job.location || 'Remote')}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Job Type</div>
                                    <div class="value">${escapeHtml(job.job_type || 'Full-time')}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Experience Level</div>
                                    <div class="value">${escapeHtml(job.experience_level || 'Entry')}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Salary Range</div>
                                    <div class="value">${escapeHtml(job.salary_range || 'N/A')}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Status</div>
                                    <div class="value"><span class="badge ${getStatusBadge(job.status)}">${escapeHtml(job.status || 'Draft')}</span></div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Urgency</div>
                                    <div class="value"><span class="badge ${getUrgencyBadge(job.urgency)}">${escapeHtml(job.urgency || 'Low')}</span></div>
                                </div>
                                <div class="view-item full-width">
                                    <div class="label">Required Skills</div>
                                    <div class="value skills">${skillsHtml || '<span style="color:var(--text-on-surface-variant);">No skills listed</span>'}</div>
                                </div>
                                <div class="view-item full-width">
                                    <div class="label">Job Description</div>
                                    <div class="value">${escapeHtml(job.description || 'No description provided.')}</div>
                                </div>
                                <div class="view-item full-width">
                                    <div class="label">Applications</div>
                                    <div class="value">${job.application_count || 0} total (${job.pending_count || 0} pending)</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Positions Available</div>
                                    <div class="value">${job.positions_available || 1}</div>
                                </div>
                                <div class="view-item">
                                    <div class="label">Created</div>
                                    <div class="value">${new Date(job.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                                </div>
                            </div>
                        `;
                    }
                } else {
                    if (modalContent) {
                        modalContent.innerHTML = `
                            <div style="text-align:center; padding:1rem; color:#dc2626;">
                                <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                                <p style="margin-top:0.5rem;">${data.error || 'Failed to load job details.'}</p>
                            </div>
                        `;
                    }
                }
            })
            .catch(error => {
                if (modalLoading) modalLoading.style.display = 'none';
                if (modalContent) {
                    modalContent.style.display = 'block';
                    modalContent.innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">Error loading job details. Please try again.</p>
                        </div>
                    `;
                }
            });
    }

    // =============================================
    // 7. APPROVE JOB (Client Request)
    // =============================================
    function approveJob(jobId) {
        const feedback = prompt('Optional: Add approval notes or feedback for the client:');
        
        const formData = new FormData();
        formData.append('action', 'approve_job');
        formData.append('job_id', jobId);
        formData.append('feedback', feedback || '');
        
        if (confirm('Approve this job request? It will be published as "Open".')) {
            showToast('Approving job request...', 'info');
            
            fetch('jobs.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.error || 'Failed to approve job.', 'error');
                }
            })
            .catch(error => {
                showToast('Error approving job. Please try again.', 'error');
            });
        }
    }

    // =============================================
    // 8. REJECT JOB (Client Request)
    // =============================================
    function rejectJob(jobId) {
        const feedback = prompt('Please provide a reason for rejecting this job request (optional):');
        if (feedback === null) return; // User cancelled
        
        const formData = new FormData();
        formData.append('action', 'reject_job');
        formData.append('job_id', jobId);
        formData.append('feedback', feedback || 'No reason provided.');
        
        if (confirm('Reject this job request?')) {
            showToast('Rejecting job request...', 'info');
            
            fetch('jobs.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.error || 'Failed to reject job.', 'error');
                }
            })
            .catch(error => {
                showToast('Error rejecting job. Please try again.', 'error');
            });
        }
    }

    // =============================================
    // 9. AI INSIGHTS
    // =============================================
    function viewAIInsights(jobId) {
        openModal();
        if (modalTitle) modalTitle.textContent = 'AI Insights & Recommendations';
        if (modalIcon) modalIcon.textContent = 'auto_awesome';
        if (modalActionBtn) modalActionBtn.style.display = 'none';
        if (modalLoading) modalLoading.style.display = 'block';
        if (modalContent) modalContent.style.display = 'none';

        fetch('jobs.php?ajax=ai_insights&id=' + jobId)
            .then(response => response.json())
            .then(data => {
                if (modalLoading) modalLoading.style.display = 'none';
                if (modalContent) modalContent.style.display = 'block';

                if (data.success) {
                    const insights = data.insights;
                    const job = insights.job;
                    const optimization = insights.optimization || {};
                    const questions = insights.interview_questions || {};
                    const quality = insights.quality || {};
                    const industry = insights.industry || 'General';

                    let qualityHtml = '';
                    if (quality.score !== undefined) {
                        const levelClass = quality.level ? quality.level.toLowerCase().replace(' ', '-') : 'fair';
                        const icon = quality.icon || 'star';
                        qualityHtml = `
                            <div class="quality-score-display">
                                <span class="score-badge" style="color:${quality.color || '#059669'}">
                                    ${quality.score}%
                                </span>
                                <div>
                                    <span class="quality-score ${levelClass}" style="font-size:0.8rem; padding:0.2rem 0.6rem;">
                                        <span class="material-symbols-outlined" style="font-size:0.9rem;">${icon}</span>
                                        ${quality.level || 'N/A'}
                                    </span>
                                    <div class="score-details" style="margin-top:0.25rem;">
                                        ${(quality.details || []).map(d => `• ${d}`).join('<br>')}
                                    </div>
                                </div>
                            </div>
                        `;
                    }

                    let skillsHtml = '';
                    if (optimization.suggested_skills && optimization.suggested_skills.length > 0) {
                        skillsHtml = optimization.suggested_skills.map(s => 
                            `<span class="skill-tag" style="background:#7c3aed; color:white;">${escapeHtml(s)}</span>`
                        ).join('');
                    }

                    let questionsHtml = '';
                    if (questions.technical && questions.technical.length > 0) {
                        questionsHtml += '<div style="margin-bottom:0.5rem;"><strong>Technical Questions:</strong></div><ul style="list-style:disc; list-style-position:inside; margin-bottom:0.75rem;">';
                        questions.technical.forEach(q => {
                            questionsHtml += `<li style="font-size:0.85rem; padding:0.125rem 0;">${escapeHtml(q)}</li>`;
                        });
                        questionsHtml += '</ul>';
                    }
                    if (questions.behavioral && questions.behavioral.length > 0) {
                        questionsHtml += '<div style="margin-bottom:0.5rem;"><strong>Behavioral Questions:</strong></div><ul style="list-style:disc; list-style-position:inside; margin-bottom:0.75rem;">';
                        questions.behavioral.forEach(q => {
                            questionsHtml += `<li style="font-size:0.85rem; padding:0.125rem 0;">${escapeHtml(q)}</li>`;
                        });
                        questionsHtml += '</ul>';
                    }
                    if (questions.role_specific && questions.role_specific.length > 0) {
                        questionsHtml += '<div style="margin-bottom:0.5rem;"><strong>Role-Specific Questions:</strong></div><ul style="list-style:disc; list-style-position:inside;">';
                        questions.role_specific.forEach(q => {
                            questionsHtml += `<li style="font-size:0.85rem; padding:0.125rem 0;">${escapeHtml(q)}</li>`;
                        });
                        questionsHtml += '</ul>';
                    }

                    if (modalContent) {
                        modalContent.innerHTML = `
                            <div class="view-grid">
                                <div class="view-item full-width">
                                    <div class="label">Job Title</div>
                                    <div class="value">${escapeHtml(job.title)}</div>
                                </div>
                                <div class="view-item full-width">
                                    <div class="label">Company</div>
                                    <div class="value">${escapeHtml(job.company_name)} <span style="font-size:0.75rem; color:var(--text-on-surface-variant);">(${escapeHtml(industry)})</span></div>
                                </div>
                            </div>

                            <div class="ai-insights">
                                <div class="ai-insights-header">
                                    <span class="ai-icon material-symbols-outlined">auto_awesome</span>
                                    <h4>AI Optimization Suggestions</h4>
                                    <span style="margin-left:auto; font-size:0.65rem; color:var(--text-on-surface-variant);">Powered by AI</span>
                                </div>

                                ${qualityHtml}

                                ${skillsHtml ? `
                                    <div class="ai-insight-item">
                                        <div><span class="insight-label">Industry-Specific Skills:</span></div>
                                        <div style="margin-top:0.25rem;">${skillsHtml}</div>
                                        <div style="margin-top:0.25rem; font-size:0.75rem; color:var(--text-on-surface-variant);">
                                            <button class="btn btn-sm btn-outline" onclick="applySuggestedSkills('${job.id}')" style="font-size:0.7rem; padding:0.125rem 0.5rem;">
                                                Apply to Job
                                            </button>
                                        </div>
                                    </div>
                                ` : ''}

                                ${optimization.salary_range ? `
                                    <div class="ai-insight-item">
                                        <span class="insight-label">Suggested Salary Range:</span>
                                        <span style="margin-left:0.5rem;">${escapeHtml(optimization.salary_range)}</span>
                                        <span style="margin-left:0.5rem; font-size:0.75rem;">
                                            <button class="btn btn-sm btn-outline" onclick="applySalaryRange(${job.id}, '${escapeHtml(optimization.salary_range)}')" style="font-size:0.7rem; padding:0.125rem 0.5rem;">
                                                Apply
                                            </button>
                                        </span>
                                    </div>
                                ` : ''}

                                ${optimization.suggested_title ? `
                                    <div class="ai-insight-item">
                                        <span class="insight-label">Suggested Title:</span>
                                        <span style="margin-left:0.5rem;">${escapeHtml(optimization.suggested_title)}</span>
                                        <span style="margin-left:0.5rem; font-size:0.75rem;">
                                            <button class="btn btn-sm btn-outline" onclick="applySuggestedTitle(${job.id}, '${escapeHtml(optimization.suggested_title)}')" style="font-size:0.7rem; padding:0.125rem 0.5rem;">
                                                Apply
                                            </button>
                                        </span>
                                    </div>
                                ` : ''}

                                ${optimization.improved_description ? `
                                    <div class="ai-insight-item">
                                        <span class="insight-label">Description Improvement:</span>
                                        <div style="margin-top:0.25rem; font-size:0.8rem; max-height:80px; overflow-y:auto; background:rgba(255,255,255,0.5); padding:0.5rem; border-radius:0.5rem;">
                                            ${escapeHtml(optimization.improved_description)}
                                        </div>
                                        <div style="margin-top:0.25rem; font-size:0.75rem;">
                                            <button class="btn btn-sm btn-outline" onclick="applyImprovedDescription(${job.id})" style="font-size:0.7rem; padding:0.125rem 0.5rem;">
                                                Apply to Job
                                            </button>
                                        </div>
                                    </div>
                                ` : ''}

                                ${questionsHtml ? `
                                    <div style="margin-top:0.75rem; border-top:1px solid rgba(79,70,229,0.2); padding-top:0.75rem;">
                                        <div class="ai-insights-header" style="margin-bottom:0.5rem;">
                                            <span class="ai-icon material-symbols-outlined">quiz</span>
                                            <h4>Interview Questions</h4>
                                        </div>
                                        ${questionsHtml}
                                    </div>
                                ` : ''}
                            </div>

                            <div style="margin-top:1rem; text-align:center; font-size:0.75rem; color:var(--text-on-surface-variant);">
                                <span class="material-symbols-outlined" style="font-size:0.875rem; vertical-align:middle;">info</span>
                                AI suggestions are generated based on industry best practices and job requirements.
                            </div>
                        `;
                    }
                } else {
                    if (modalContent) {
                        modalContent.innerHTML = `
                            <div style="text-align:center; padding:1rem; color:#dc2626;">
                                <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                                <p style="margin-top:0.5rem;">${data.error || 'Failed to load AI insights.'}</p>
                            </div>
                        `;
                    }
                }
            })
            .catch(error => {
                if (modalLoading) modalLoading.style.display = 'none';
                if (modalContent) {
                    modalContent.style.display = 'block';
                    modalContent.innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">Error loading AI insights. Please try again.</p>
                        </div>
                    `;
                }
            });
    }

    // =============================================
    // 10. BULK AI ANALYSIS
    // =============================================
    function bulkAIAnalysis() {
        if (!confirm('This will analyze all your jobs and show quality scores. Continue?')) return;
        
        const btn = document.querySelector('[onclick="bulkAIAnalysis()"]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined" style="animation:spin 0.8s linear infinite;">refresh</span> Analyzing...';
        }
        
        fetch('jobs.php?ajax=bulk_ai_analysis')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let message = '📊 Job Quality Analysis Results:\n\n';
                    data.results.forEach(job => {
                        const level = job.quality_level || 'N/A';
                        const score = job.quality_score || 0;
                        message += `${job.title}: ${score}% (${level})\n`;
                    });
                    alert(message);
                    showToast('Analysis complete! Check the Quality column for scores.', 'success');
                    location.reload();
                } else {
                    showToast('Failed to analyze jobs.', 'error');
                }
            })
            .catch(error => {
                showToast('Error analyzing jobs.', 'error');
            })
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<span class="material-symbols-outlined">auto_awesome</span> Analyze All Jobs';
                }
            });
    }

    // =============================================
    // 11. APPLY AI SUGGESTIONS
    // =============================================
    function applySuggestedSkills(jobId) {
        fetch('jobs.php?ajax=ai_insights&id=' + jobId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const skills = data.insights.optimization.suggested_skills || [];
                    if (skills.length > 0) {
                        updateJobField(jobId, 'skills_required', skills.join(', '));
                    }
                }
            })
            .catch(() => {});
    }

    function applySalaryRange(jobId, salary) {
        updateJobField(jobId, 'salary_range', salary);
    }

    function applySuggestedTitle(jobId, title) {
        updateJobField(jobId, 'title', title);
    }

    function applyImprovedDescription(jobId) {
        fetch('jobs.php?ajax=ai_insights&id=' + jobId)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.insights.optimization.improved_description) {
                    updateJobField(jobId, 'description', data.insights.optimization.improved_description);
                }
            })
            .catch(() => {});
    }

    function updateJobField(jobId, field, value) {
        fetch('jobs.php?ajax=edit&id=' + jobId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const job = data.job;
                    const formData = new FormData();
                    formData.append('action', 'update_job');
                    formData.append('job_id', jobId);
                    formData.append('title', field === 'title' ? value : job.title);
                    formData.append('description', field === 'description' ? value : job.description);
                    formData.append('skills_required', field === 'skills_required' ? value : job.skills_required);
                    formData.append('salary_range', field === 'salary_range' ? value : job.salary_range);
                    formData.append('location', job.location || '');
                    formData.append('job_type', job.job_type || 'Full-time');
                    formData.append('experience_level', job.experience_level || 'Entry');
                    formData.append('status', job.status || 'draft');
                    formData.append('urgency', job.urgency || 'medium');
                    formData.append('positions_available', job.positions_available || 1);

                    fetch('jobs.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            showToast('Job updated successfully with AI suggestion!', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showToast('Failed to update job: ' + (result.error || 'Unknown error'), 'error');
                        }
                    })
                    .catch(() => {
                        showToast('Error updating job. Please try again.', 'error');
                    });
                }
            })
            .catch(() => {
                showToast('Error loading job data.', 'error');
            });
    }

    // =============================================
    // 12. EDIT JOB
    // =============================================
    function editJob(jobId) {
        currentEditJobId = jobId;
        openModal();
        if (modalTitle) modalTitle.textContent = 'Edit Job';
        if (modalIcon) modalIcon.textContent = 'edit';
        if (modalActionBtn) {
            modalActionBtn.style.display = 'flex';
            modalActionBtn.textContent = 'Update Job';
        }
        if (modalLoading) modalLoading.style.display = 'block';
        if (modalContent) modalContent.style.display = 'none';

        fetch('jobs.php?ajax=edit&id=' + jobId)
            .then(response => response.json())
            .then(data => {
                if (modalLoading) modalLoading.style.display = 'none';
                if (modalContent) modalContent.style.display = 'block';

                if (data.success) {
                    const job = data.job;
                    const jobTypes = <?php echo json_encode($jobTypes); ?>;
                    const experienceLevels = <?php echo json_encode($experienceLevels); ?>;
                    const jobStatuses = <?php echo json_encode($jobStatuses); ?>;
                    const urgencyLevels = <?php echo json_encode($urgencyLevels); ?>;

                    function createOptions(options, selected) {
                        return options.map(opt => 
                            `<option value="${opt}" ${opt === selected ? 'selected' : ''}>${opt}</option>`
                        ).join('');
                    }

                    // Use skills_required_display if available (parsed JSON), otherwise use skills_required
                    const skillsDisplay = job.skills_required_display || job.skills_required || '';

                    if (modalContent) {
                        modalContent.innerHTML = `
                            <form id="editJobForm">
                                <input type="hidden" name="action" value="update_job">
                                <input type="hidden" name="job_id" value="${jobId}">

                                <div class="form-group">
                                    <label>Job Title <span class="required">*</span></label>
                                    <input type="text" name="title" class="form-control" value="${escapeHtml(job.title)}" required>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Job Type</label>
                                        <select name="job_type" class="form-control">${createOptions(jobTypes, job.job_type)}</select>
                                    </div>
                                    <div class="form-group">
                                        <label>Experience Level</label>
                                        <select name="experience_level" class="form-control">${createOptions(experienceLevels, job.experience_level)}</select>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Location</label>
                                        <input type="text" name="location" class="form-control" value="${escapeHtml(job.location || '')}" placeholder="e.g., Makati, Philippines">
                                    </div>
                                    <div class="form-group">
                                        <label>Salary Range</label>
                                        <input type="text" name="salary_range" class="form-control" value="${escapeHtml(job.salary_range || '')}" placeholder="e.g., ₱50,000 - ₱80,000">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Skills Required</label>
                                    <input type="text" name="skills_required" class="form-control" value="${escapeHtml(skillsDisplay)}" placeholder="e.g., PHP, Laravel, MySQL, JavaScript">
                                    <div style="font-size:0.75rem; color:var(--text-on-surface-variant); margin-top:0.25rem;">Separate skills with commas</div>
                                </div>

                                <div class="form-group">
                                    <label>Job Description</label>
                                    <textarea name="description" class="form-control" rows="4" placeholder="Describe the job responsibilities and requirements">${escapeHtml(job.description || '')}</textarea>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select name="status" class="form-control">${createOptions(jobStatuses, job.status)}</select>
                                    </div>
                                    <div class="form-group">
                                        <label>Urgency</label>
                                        <select name="urgency" class="form-control">${createOptions(urgencyLevels, job.urgency)}</select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Positions Available</label>
                                    <input type="number" name="positions_available" class="form-control" value="${job.positions_available || 1}" min="1">
                                </div>
                            </form>
                        `;
                    }
                } else {
                    if (modalContent) {
                        modalContent.innerHTML = `
                            <div style="text-align:center; padding:1rem; color:#dc2626;">
                                <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                                <p style="margin-top:0.5rem;">${data.error || 'Failed to load job details.'}</p>
                            </div>
                        `;
                    }
                    if (modalActionBtn) modalActionBtn.style.display = 'none';
                }
            })
            .catch(error => {
                if (modalLoading) modalLoading.style.display = 'none';
                if (modalContent) {
                    modalContent.style.display = 'block';
                    modalContent.innerHTML = `
                        <div style="text-align:center; padding:1rem; color:#dc2626;">
                            <span class="material-symbols-outlined" style="font-size:2.5rem;">error</span>
                            <p style="margin-top:0.5rem;">Error loading job details. Please try again.</p>
                        </div>
                    `;
                }
                if (modalActionBtn) modalActionBtn.style.display = 'none';
            });
    }

    // =============================================
    // 13. SUBMIT EDIT JOB FROM MODAL BUTTON
    // =============================================
    function submitEditJobFromModal() {
        const form = document.getElementById('editJobForm');
        if (!form) {
            showToast('Form not found. Please try again.', 'error');
            return;
        }
        
        // Trigger the form submit event
        submitEditJob(event, currentEditJobId);
    }

    // =============================================
    // 14. SUBMIT EDIT JOB
    // =============================================
    function submitEditJob(event, jobId) {
        if (event && event.preventDefault) {
            event.preventDefault();
        }
        
        const form = document.getElementById('editJobForm');
        if (!form) {
            showToast('Form not found. Please try again.', 'error');
            return;
        }
        
        const formData = new FormData(form);
        
        if (modalActionBtn) {
            modalActionBtn.disabled = true;
            modalActionBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:1rem; animation:spin 0.8s linear infinite;">refresh</span> Saving...';
        }

        fetch('jobs.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (modalActionBtn) {
                modalActionBtn.disabled = false;
                modalActionBtn.innerHTML = 'Save Changes';
            }

            if (data.success) {
                showToast('Job updated successfully!', 'success');
                setTimeout(() => {
                    closeModal();
                    location.reload();
                }, 1000);
            } else {
                showToast(data.error || 'Failed to update job.', 'error');
            }
        })
        .catch(error => {
            if (modalActionBtn) {
                modalActionBtn.disabled = false;
                modalActionBtn.innerHTML = 'Save Changes';
            }
            showToast('Error updating job. Please try again.', 'error');
        });
    }

    // =============================================
    // 15. DELETE JOB
    // =============================================
    function deleteJob(jobId) {
        if (!confirm('Are you sure you want to delete this job? This action cannot be undone.')) {
            return;
        }

        showToast('Deleting job...', 'info');
        
        const formData = new FormData();
        formData.append('action', 'delete_job');
        formData.append('job_id', jobId);

        fetch('jobs.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.error || 'Failed to delete job.', 'error');
            }
        })
        .catch(error => {
            showToast('Error deleting job. Please try again.', 'error');
        });
    }

    // =============================================
    // 16. TOAST SYSTEM
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
        }, 3000);
    }

    // =============================================
    // 17. UTILITY FUNCTIONS
    // =============================================
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function getStatusBadge(status) {
        const badges = {
            'open': 'badge-open',
            'ongoing': 'badge-ongoing',
            'filled': 'badge-filled',
            'cancelled': 'badge-cancelled',
            'draft': 'badge-draft',
            'pending_review': 'badge-pending'
        };
        return badges[status] || 'badge-draft';
    }

    function getUrgencyBadge(urgency) {
        const badges = {
            'low': 'badge-urgency-low',
            'medium': 'badge-urgency-medium',
            'high': 'badge-urgency-high'
        };
        return badges[urgency] || 'badge-urgency-low';
    }
    // =============================================
// SESSION ACTIVITY MONITOR
// =============================================

let sessionTimer = null;
let warningShown = false;
const SESSION_TIMEOUT = <?php echo SESSION_TIMEOUT_SECONDS; ?>; // 7 minutes
const WARNING_TIME = 60; // Show warning 60 seconds before timeout

/**
 * Update session timer display
 */
function updateSessionTimer() {
    // Get remaining time from server
    fetch('check_session.php')
        .then(response => response.json())
        .then(data => {
            const remaining = data.remaining;
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            
            // Update timer display if exists
            const timerEl = document.getElementById('sessionTimer');
            if (timerEl) {
                timerEl.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                
                // Change color when running low
                if (remaining < 60) {
                    timerEl.style.color = '#dc2626';
                    timerEl.style.fontWeight = 'bold';
                } else if (remaining < 120) {
                    timerEl.style.color = '#f59e0b';
                } else {
                    timerEl.style.color = '';
                }
            }
            
            // Show warning modal if session is about to expire
            if (remaining <= WARNING_TIME && !warningShown && remaining > 0) {
                warningShown = true;
                showSessionWarning(remaining);
            }
            
            // If session expired, redirect
            if (remaining <= 0) {
                window.location.href = '../../login.php?timeout=1';
            }
        })
        .catch(error => {
            console.log('Session check error:', error);
        });
}

/**
 * Show session expiration warning
 */
function showSessionWarning(remaining) {
    // Create modal if it doesn't exist
    let modal = document.getElementById('sessionWarningModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'sessionWarningModal';
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            z-index: 99999;
            display: none;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        `;
        
        modal.innerHTML = `
            <div style="
                background: white;
                border-radius: 1.5rem;
                max-width: 440px;
                width: 100%;
                padding: 2rem;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                animation: slideUp 0.3s ease;
                text-align: center;
            ">
                <div style="font-size: 3rem; margin-bottom: 0.5rem;">⏰</div>
                <h2 style="font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem;">Session Expiring Soon</h2>
                <p style="color: #464555; font-size: 0.875rem; margin-bottom: 1rem;">
                    Your session will expire in <strong id="warningTimer" style="color: #dc2626;">60</strong> seconds.
                    Please click "Stay Logged In" to continue.
                </p>
                <div style="display: flex; gap: 0.75rem; justify-content: center;">
                    <button onclick="extendSession()" style="
                        padding: 0.625rem 1.5rem;
                        background: #4f46e5;
                        color: white;
                        border: none;
                        border-radius: 0.75rem;
                        font-weight: 600;
                        font-size: 0.875rem;
                        cursor: pointer;
                        transition: all 0.15s;
                    ">Stay Logged In</button>
                    <button onclick="logoutNow()" style="
                        padding: 0.625rem 1.5rem;
                        background: #fef2f2;
                        color: #dc2626;
                        border: 1px solid #fecaca;
                        border-radius: 0.75rem;
                        font-weight: 600;
                        font-size: 0.875rem;
                        cursor: pointer;
                        transition: all 0.15s;
                    ">Logout</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    
    // Show modal
    modal.style.display = 'flex';
    
    // Update countdown inside modal
    const warningTimer = document.getElementById('warningTimer');
    if (warningTimer) {
        let countdown = remaining;
        const interval = setInterval(() => {
            countdown--;
            warningTimer.textContent = countdown;
            if (countdown <= 0) {
                clearInterval(interval);
                window.location.href = '../../login.php?timeout=1';
            }
        }, 1000);
        
        // Store interval to clear it when extending
        modal.dataset.interval = interval;
    }
}

/**
 * Extend session (reset timer)
 */
function extendSession() {
    // Clear any existing warning interval
    const modal = document.getElementById('sessionWarningModal');
    if (modal && modal.dataset.interval) {
        clearInterval(parseInt(modal.dataset.interval));
    }
    
    fetch('extend_session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            warningShown = false;
            if (modal) modal.style.display = 'none';
            showToast('Session extended!', 'success');
        }
    })
    .catch(error => {
        console.log('Extend session error:', error);
    });
}

/**
 * Logout immediately
 */
function logoutNow() {
    window.location.href = '../../logout.php';
}

/**
 * Show toast notification
 */
function showToast(message, type = 'info') {
    const existingToast = document.querySelector('.toast');
    if (existingToast) existingToast.remove();
    
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.style.cssText = `
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        padding: 0.875rem 1.5rem;
        border-radius: 0.75rem;
        color: white;
        font-weight: 600;
        font-size: 0.875rem;
        box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        z-index: 100000;
        animation: slideUp 0.4s ease-out;
    `;
    if (type === 'success') toast.style.background = '#22c55e';
    else if (type === 'error') toast.style.background = '#dc2626';
    else toast.style.background = '#4f46e5';
    
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        toast.style.transition = 'all 0.4s ease';
        setTimeout(() => toast.remove(), 400);
    }, 3000);
}

// =============================================
// TRACK USER ACTIVITY
// =============================================

let activityTimer = null;

function resetActivityTimer() {
    // Reset the server-side timer via AJAX
    fetch('extend_session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'reset' })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            warningShown = false;
            // Hide warning modal if shown
            const modal = document.getElementById('sessionWarningModal');
            if (modal) modal.style.display = 'none';
        }
    })
    .catch(error => console.log('Reset timer error:', error));
}

// Track user activity events
const activityEvents = ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'];
activityEvents.forEach(event => {
    document.addEventListener(event, () => {
        resetActivityTimer();
    });
});

// =============================================
// START SESSION TIMER
// =============================================

// Update timer every 10 seconds
sessionTimer = setInterval(updateSessionTimer, 10000);

// Initial update
updateSessionTimer();

console.log('⏰ Session timeout: 7 minutes');
console.log('🔄 Activity tracking enabled');

    // =============================================
    // 18. RESPONSIVE HANDLING
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
                sidebarBackdrop.classList.remove('active');
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

    console.log('📋 ISMERS Jobs Management with Enhanced AI Integration loaded successfully!');
    console.log('💪 Features: Client Job Requests (Pending Review) + Industry-Specific AI Insights + Job Quality Scores!');
    </script>

    </body>
    </html>