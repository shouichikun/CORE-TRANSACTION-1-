<?php
// portals/hr/includes/functions.php - HR Shared Functions

// =============================================
// MATCH SCORE CALCULATION (FIXED - Proper try/catch)
// =============================================

/**
 * Calculate match score between an applicant and a job
 * Uses skill matching, experience, and education
 */
function calculateMatchScore($applicantId, $jobId) {
    try {
        global $conn;
        
        // Get applicant data
        $applicant = getRecord("
            SELECT ap.skills, ap.experience, ap.education,
                   u.id as user_id
            FROM applicants ap
            JOIN users u ON ap.user_id = u.id
            WHERE ap.id = ?
        ", [$applicantId], "i");
        
        if (!$applicant) {
            return ['score' => 0, 'details' => [], 'level' => ['label' => 'Low', 'color' => '#dc2626', 'icon' => '📉']];
        }
        
        // Get job data
        $job = getRecord("
            SELECT skills_required, experience_level, location, title
            FROM job_orders
            WHERE id = ?
        ", [$jobId], "i");
        
        if (!$job) {
            return ['score' => 0, 'details' => [], 'level' => ['label' => 'Low', 'color' => '#dc2626', 'icon' => '📉']];
        }
        
        $score = 0;
        $maxScore = 100;
        $details = [];
        
        // 1. SKILL MATCHING (50 points)
        $jobSkills = array_map('trim', explode(',', $job['skills_required'] ?? ''));
        $applicantSkills = array_map('trim', explode(',', $applicant['skills'] ?? ''));
        $applicantSkills = array_filter($applicantSkills);
        $jobSkills = array_filter($jobSkills);
        
        if (!empty($jobSkills) && !empty($applicantSkills)) {
            $matchedSkills = array_intersect(
                array_map('strtolower', $jobSkills),
                array_map('strtolower', $applicantSkills)
            );
            $skillMatchPercent = count($matchedSkills) / count($jobSkills);
            $skillScore = min(50, $skillMatchPercent * 50);
            $score += $skillScore;
            $details['skills'] = [
                'score' => round($skillScore, 1),
                'matched' => count($matchedSkills),
                'total' => count($jobSkills),
                'matched_list' => array_values($matchedSkills)
            ];
        } else {
            $details['skills'] = ['score' => 0, 'matched' => 0, 'total' => count($jobSkills)];
        }
        
        // 2. EXPERIENCE MATCHING (30 points)
        $expScore = 0;
        $jobExp = strtolower($job['experience_level'] ?? 'entry');
        $applicantExp = strtolower($applicant['experience'] ?? '');
        
        $expLevels = ['entry' => 1, 'junior' => 2, 'mid' => 3, 'senior' => 4, 'lead' => 5, 'manager' => 6];
        
        $jobExpLevel = $expLevels[$jobExp] ?? 1;
        $applicantExpLevel = 1;
        
        // Try to extract experience level from applicant's experience text
        foreach ($expLevels as $level => $levelValue) {
            if (stripos($applicantExp, $level) !== false) {
                $applicantExpLevel = $levelValue;
                break;
            }
        }
        
        // Also check years of experience
        preg_match('/(\d+)\s*(?:years?|yrs?)/i', $applicantExp, $yearsMatch);
        $yearsExp = $yearsMatch[1] ?? 0;
        
        if ($yearsExp >= 5) $applicantExpLevel = max($applicantExpLevel, 4);
        elseif ($yearsExp >= 3) $applicantExpLevel = max($applicantExpLevel, 3);
        elseif ($yearsExp >= 1) $applicantExpLevel = max($applicantExpLevel, 2);
        
        // Calculate experience match
        $expDiff = abs($jobExpLevel - $applicantExpLevel);
        if ($expDiff == 0) {
            $expScore = 30;
        } elseif ($expDiff == 1) {
            $expScore = 24;
        } elseif ($expDiff == 2) {
            $expScore = 15;
        } elseif ($expDiff == 3) {
            $expScore = 8;
        } else {
            $expScore = 0;
        }
        $score += $expScore;
        $details['experience'] = [
            'score' => round($expScore, 1),
            'job_level' => $job['experience_level'],
            'applicant_level' => $applicantExpLevel
        ];
        
        // 3. EDUCATION MATCHING (20 points)
        $eduScore = 0;
        $applicantEdu = strtolower($applicant['education'] ?? '');
        $eduKeywords = ['bachelor', 'master', 'phd', 'doctorate', 'degree', 'diploma', 'bs', 'ba', 'ms'];
        $eduFound = 0;
        foreach ($eduKeywords as $keyword) {
            if (stripos($applicantEdu, $keyword) !== false) $eduFound++;
        }
        
        if ($eduFound >= 3) $eduScore = 20;
        elseif ($eduFound >= 2) $eduScore = 14;
        elseif ($eduFound >= 1) $eduScore = 7;
        else $eduScore = 0;
        
        $score += $eduScore;
        $details['education'] = ['score' => round($eduScore, 1)];
        
        // Round final score
        $finalScore = round(min($maxScore, $score), 1);
        
        return [
            'score' => $finalScore,
            'details' => $details,
            'level' => getMatchLevel($finalScore)
        ];
        
    } catch (Exception $e) {
        error_log('calculateMatchScore error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
        return ['score' => 0, 'details' => [], 'level' => ['label' => 'Low', 'color' => '#dc2626', 'icon' => '📉']];
    }
}

function getMatchLevel($score) {
    if ($score >= 80) return ['label' => 'Excellent', 'color' => '#22c55e', 'icon' => '🚀'];
    if ($score >= 60) return ['label' => 'Good', 'color' => '#2563eb', 'icon' => '👍'];
    if ($score >= 40) return ['label' => 'Fair', 'color' => '#f59e0b', 'icon' => '👀'];
    return ['label' => 'Low', 'color' => '#dc2626', 'icon' => '📉'];
}

// =============================================
// EMAIL NOTIFICATION FUNCTIONS
// =============================================

function sendEmailNotification($to, $subject, $message, $template = 'custom') {
    global $userId, $conn;
    
    // For development/testing, we'll log emails
    // In production, replace with PHPMailer or SendGrid
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: ISMERS <noreply@ismers.com>\r\n";
    
    $htmlMessage = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #1b1b24; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #4f46e5; padding: 20px; text-align: center; color: white; border-radius: 8px 8px 0 0; }
            .content { background: #ffffff; padding: 30px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 8px 8px; }
            .footer { text-align: center; padding: 20px; font-size: 12px; color: #64748b; }
            .btn { display: inline-block; padding: 10px 20px; background: #4f46e5; color: white; text-decoration: none; border-radius: 8px; }
            .badge { display: inline-block; padding: 4px 12px; border-radius: 50px; font-size: 12px; font-weight: 600; }
            .badge-success { background: #d1fae5; color: #059669; }
            .badge-warning { background: #fef3c7; color: #d97706; }
            .badge-info { background: #dbeafe; color: #2563eb; }
        </style>
    </head>
    <body>
        <div class=\"container\">
            <div class=\"header\">
                <h1>ISMERS</h1>
                <p style=\"margin:0; opacity:0.8;\">Service Management & Enterprise Resource System</p>
            </div>
            <div class=\"content\">
                $message
            </div>
            <div class=\"footer\">
                <p>© 2026 ISMERS. All rights reserved.</p>
                <p style=\"font-size:11px;\">This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    $success = mail($to, $subject, $htmlMessage, $headers);
    
    // Log the email
    $logSql = "INSERT INTO email_logs (recipient_email, recipient_name, subject, template, status, created_by) 
               VALUES (?, ?, ?, ?, ?, ?)";
    insertRecord($logSql, [
        $to,
        $to,
        $subject,
        $template,
        $success ? 'sent' : 'failed',
        $userId ?? null
    ], "sssssi");
    
    return $success;
}

function sendStatusUpdateEmail($applicationId, $newStatus, $feedback = '') {
    global $conn;
    
    $app = getRecord("
        SELECT a.*, u.id as user_id, u.first_name, u.last_name, u.email,
               jo.title as job_title, c.company_name
        FROM applications a
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        JOIN clients c ON jo.client_id = c.id
        WHERE a.id = ?
    ", [$applicationId], "i");
    
    if (!$app) return false;
    
    $statusLabels = [
        'pending' => 'Pending Review',
        'shortlisted' => 'Shortlisted',
        'interviewed' => 'Interviewed',
        'hired' => 'Hired',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn'
    ];
    
    $statusColors = [
        'pending' => 'warning',
        'shortlisted' => 'info',
        'interviewed' => 'info',
        'hired' => 'success',
        'rejected' => 'warning',
        'withdrawn' => 'warning'
    ];
    
    $statusLabel = $statusLabels[$newStatus] ?? ucfirst($newStatus);
    $statusColor = $statusColors[$newStatus] ?? 'info';
    
    $message = "
        <h2>Application Status Update</h2>
        <p>Dear <strong>" . htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) . "</strong>,</p>
        <p>Your application for <strong>" . htmlspecialchars($app['job_title']) . "</strong> at <strong>" . htmlspecialchars($app['company_name']) . "</strong> has been updated.</p>
        
        <div style=\"text-align:center; margin: 20px 0;\">
            <span class=\"badge badge-" . $statusColor . "\">" . $statusLabel . "</span>
        </div>
        
        " . (!empty($feedback) ? "<p><strong>Feedback:</strong> " . htmlspecialchars($feedback) . "</p>" : "") . "
        
        <p>You can log in to your ISMERS account to view more details about your application status.</p>
        
        <p style=\"margin-top: 30px;\">
            <a href=\"https://ismers.com/portals/applicant/dashboard.php\" class=\"btn\">View My Applications</a>
        </p>
    ";
    
    return sendEmailNotification(
        $app['email'],
        'Application Status Update - ' . $statusLabel,
        $message,
        'status_update'
    );
}

function sendInterviewScheduledEmail($applicationId, $interviewDate, $interviewNotes = '') {
    global $conn;
    
    $app = getRecord("
        SELECT a.*, u.id as user_id, u.first_name, u.last_name, u.email,
               jo.title as job_title, c.company_name
        FROM applications a
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        JOIN clients c ON jo.client_id = c.id
        WHERE a.id = ?
    ", [$applicationId], "i");
    
    if (!$app) return false;
    
    $formattedDate = date('F j, Y', strtotime($interviewDate));
    $formattedTime = date('g:i A', strtotime($interviewDate));
    
    $message = "
        <h2>Interview Scheduled</h2>
        <p>Dear <strong>" . htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) . "</strong>,</p>
        <p>We are pleased to inform you that your interview for <strong>" . htmlspecialchars($app['job_title']) . "</strong> at <strong>" . htmlspecialchars($app['company_name']) . "</strong> has been scheduled.</p>
        
        <div style=\"background: #f5f3ff; padding: 20px; border-radius: 8px; margin: 20px 0;\">
            <p><strong>📅 Date:</strong> " . $formattedDate . "</p>
            <p><strong>🕐 Time:</strong> " . $formattedTime . "</p>
            " . (!empty($interviewNotes) ? "<p><strong>📝 Notes:</strong> " . htmlspecialchars($interviewNotes) . "</p>" : "") . "
        </div>
        
        <p>Please make sure to be prepared and on time. If you need to reschedule, please contact us as soon as possible.</p>
        
        <p style=\"margin-top: 30px;\">
            <a href=\"https://ismers.com/portals/applicant/dashboard.php\" class=\"btn\">View Interview Details</a>
        </p>
    ";
    
    return sendEmailNotification(
        $app['email'],
        'Interview Scheduled - ' . htmlspecialchars($app['job_title']),
        $message,
        'interview_scheduled'
    );
}

function sendOfferEmail($offerId) {
    global $conn;
    
    $offer = getRecord("
        SELECT o.*, a.*, u.id as user_id, u.first_name, u.last_name, u.email,
               jo.title as job_title, c.company_name
        FROM offers o
        JOIN applications a ON o.application_id = a.id
        JOIN applicants ap ON a.applicant_id = ap.id
        JOIN users u ON ap.user_id = u.id
        JOIN job_orders jo ON a.job_order_id = jo.id
        JOIN clients c ON jo.client_id = c.id
        WHERE o.id = ?
    ", [$offerId], "i");
    
    if (!$offer) return false;
    
    $formattedDate = date('F j, Y', strtotime($offer['offer_date']));
    $startDate = !empty($offer['start_date']) ? date('F j, Y', strtotime($offer['start_date'])) : 'To be confirmed';
    $salary = !empty($offer['salary_offered']) ? number_format($offer['salary_offered'], 2) : 'To be discussed';
    
    $message = "
        <h2>🎉 Job Offer!</h2>
        <p>Dear <strong>" . htmlspecialchars($offer['first_name'] . ' ' . $offer['last_name']) . "</strong>,</p>
        <p>Congratulations! We are thrilled to offer you the position of <strong>" . htmlspecialchars($offer['job_title']) . "</strong> at <strong>" . htmlspecialchars($offer['company_name']) . "</strong>.</p>
        
        <div style=\"background: #f0fdf4; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #bbf7d0;\">
            <p><strong>📋 Position:</strong> " . htmlspecialchars($offer['job_title']) . "</p>
            <p><strong>🏢 Company:</strong> " . htmlspecialchars($offer['company_name']) . "</p>
            <p><strong>📅 Offer Date:</strong> " . $formattedDate . "</p>
            <p><strong>📅 Start Date:</strong> " . $startDate . "</p>
            <p><strong>💰 Salary:</strong> ₱" . $salary . "</p>
            " . (!empty($offer['benefits']) ? "<p><strong>🎁 Benefits:</strong> " . htmlspecialchars($offer['benefits']) . "</p>" : "") . "
        </div>
        
        <p>Please review the offer details and let us know your decision at your earliest convenience.</p>
        
        <p style=\"margin-top: 30px;\">
            <a href=\"https://ismers.com/portals/applicant/offers.php?view=" . $offerId . "\" class=\"btn\">Review Offer</a>
        </p>
    ";
    
    return sendEmailNotification(
        $offer['email'],
        'Job Offer - ' . htmlspecialchars($offer['job_title']),
        $message,
        'offer'
    );
}
?>