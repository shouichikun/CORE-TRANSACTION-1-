<?php
// portals/applicant/accept_offer.php - Accept or Reject Job Offer
session_start();

// ✅ Initialize session timeout
require_once '../../app/config.php';
initSessionTimeout();

// Include PHPMailer autoload
require_once '../../PHPMailer-master/src/PHPMailer.php';
require_once '../../PHPMailer-master/src/SMTP.php';
require_once '../../PHPMailer-master/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ✅ FIXED: Define missing constants if not already defined
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.gmail.com');
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', 'calicaarvy13@gmail.com');
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', 'cetc iywq dnpz wdub');
}
if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', 'tls');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);
}
if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', 'calicaarvy13@gmail.com');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'ISMERS System');
}
if (!defined('MAIL_REPLY_TO')) {
    define('MAIL_REPLY_TO', 'calicaarvy13@gmail.com');
}
if (!defined('MAIL_REPLY_TO_NAME')) {
    define('MAIL_REPLY_TO_NAME', 'HR Department');
}
if (!defined('SITE_URL')) {
    define('SITE_URL', 'http://localhost/CT1/');
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../../login.php');
    exit;
}

if ($_SESSION['role'] !== 'applicant') {
    header('Location: ../../login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$offerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($offerId <= 0) {
    header('Location: offers.php');
    exit;
}

// ✅ FIXED: Get offer details with application and job info - PostgreSQL uses $1, $2 placeholders
$offer = getRecord("
    SELECT o.*, a.id as application_id, a.applicant_id, a.job_order_id,
           u.first_name, u.last_name, u.email, u.phone,
           jo.title as job_title, jo.description as job_description,
           c.company_name, c.id as client_id,
           ap.id as applicant_id
    FROM offers o
    JOIN applications a ON o.application_id = a.id
    JOIN applicants ap ON a.applicant_id = ap.id
    JOIN users u ON ap.user_id = u.id
    JOIN job_orders jo ON a.job_order_id = jo.id
    JOIN clients c ON jo.client_id = c.id
    WHERE o.id = $1 AND u.id = $2
", [$offerId, $userId]);

if (!$offer) {
    $_SESSION['message'] = 'Offer not found.';
    $_SESSION['message_type'] = 'error';
    header('Location: offers.php');
    exit;
}

// Check if already responded
if ($offer['status'] !== 'sent') {
    $_SESSION['message'] = 'This offer has already been ' . $offer['status'] . '.';
    $_SESSION['message_type'] = 'info';
    header('Location: offers.php');
    exit;
}

// Check if offer is expired (7 days after sent date)
$sentDate = new DateTime($offer['sent_at']);
$currentDate = new DateTime();
$daysDiff = $sentDate->diff($currentDate)->days;
$isExpired = $daysDiff > 7;

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'accept') {
        // ✅ FIXED: Use PostgreSQL transaction
        $beginResult = beginTransaction();
        
        try {
            if (!$beginResult) {
                throw new Exception("Failed to start transaction.");
            }
            
            // ✅ FIXED: PostgreSQL uses $1 placeholder, no type string
            $offerUpdate = updateRecord(
                "UPDATE offers SET status = 'accepted', accepted_at = NOW(), updated_at = NOW() WHERE id = $1",
                [$offerId]
            );
            
            if (!$offerUpdate) {
                throw new Exception("Failed to update offer status.");
            }
            
            // Update application status to 'hired'
            $appUpdate = updateRecord(
                "UPDATE applications SET status = 'hired', updated_at = NOW() WHERE id = $1",
                [$offer['application_id']]
            );
            
            if (!$appUpdate) {
                throw new Exception("Failed to update application status.");
            }
            
            // Update applicant status to indicate they are hired
            $applicantUpdate = updateRecord(
                "UPDATE applicants SET is_hired = 1, hired_at = NOW(), updated_at = NOW() WHERE id = $1",
                [$offer['applicant_id']]
            );
            
            // =============================================
            // UPDATE USER ROLE TO EMPLOYEE
            // =============================================
            $userUpdate = updateRecord(
                "UPDATE users SET role = 'employee' WHERE id = $1",
                [$userId]
            );
            
            if (!$userUpdate) {
                throw new Exception("Failed to update user role to employee.");
            }
            
            // =============================================
            // CREATE EMPLOYEE RECORD
            // =============================================
            // Check if employee already exists
            $existingEmployee = getEmployeeByUserId($userId);

            if ($existingEmployee) {
                // Update existing employee
                $employeeData = [
                    'first_name' => $offer['first_name'],
                    'last_name' => $offer['last_name'],
                    'email' => $offer['email'],
                    'phone' => $offer['phone'] ?? '',
                    'position' => $offer['job_title'],
                    'department' => 'Pending Assignment',
                    'status' => 'active'
                ];
                
                $employeeResult = updateEmployee($existingEmployee['id'], $employeeData);
                
                if (!$employeeResult) {
                    throw new Exception("Failed to update employee record.");
                }
                
                $employeeId = $existingEmployee['id'];
            } else {
                // Create new employee with application_id
                $employeeData = [
                    'user_id' => $userId,
                    'application_id' => $offer['application_id'],
                    'first_name' => $offer['first_name'],
                    'last_name' => $offer['last_name'],
                    'email' => $offer['email'],
                    'phone' => $offer['phone'] ?? '',
                    'position' => $offer['job_title'],
                    'department' => 'Pending Assignment',
                    'hire_date' => $offer['start_date'] ?? date('Y-m-d'),
                    'status' => 'active'
                ];
                
                $employeeId = createEmployee($userId, $employeeData);
                
                if (!$employeeId) {
                    throw new Exception("Failed to create employee record.");
                }
            }
            
            // Log activities
            logActivity($userId, 'Offer Accepted', 'offers', $offerId, 
                'Accepted offer for ' . $offer['job_title'] . ' at ' . $offer['company_name']);
            
            logActivity($userId, 'Employee Created', 'employees', $employeeId, 
                'Employee record created from offer acceptance for ' . $offer['job_title']);
            
            logActivity($userId, 'Role Updated', 'users', $userId, 
                'User role changed from applicant to employee');
            
            // ✅ FIXED: Use PostgreSQL commit
            $commitResult = commitTransaction();
            
            if (!$commitResult) {
                throw new Exception("Failed to commit transaction.");
            }
            
            // =============================================
            // SEND WELCOME EMAIL WITH EMPLOYEE LOGIN INFO
            // =============================================
            try {
                $emailSent = sendEmployeeWelcomeEmail(
                    $offer['email'], 
                    $offer['first_name'], 
                    $offer['company_name'], 
                    $offer['job_title'],
                    $offer['start_date'] ?? null,
                    $userId
                );
                if ($emailSent) {
                    error_log("Welcome email sent successfully to: " . $offer['email']);
                } else {
                    error_log("Welcome email failed to send to: " . $offer['email']);
                }
            } catch (Exception $e) {
                error_log("Welcome email failed: " . $e->getMessage());
                // Don't fail the acceptance if email fails
            }
            
            // Update session role
            $_SESSION['role'] = 'employee';
            
            $message = 'Congratulations! You have accepted the offer and are now an employee of ' . $offer['company_name'] . '!';
            $messageType = 'success';
            
        } catch (Exception $e) {
            // ✅ FIXED: Use PostgreSQL rollback
            rollbackTransaction();
            
            error_log("Offer acceptance error: " . $e->getMessage());
            $message = 'Failed to accept offer: ' . $e->getMessage() . ' Please contact HR.';
            $messageType = 'error';
        }
    }
    
    if ($action === 'reject') {
        // Update offer status - PostgreSQL uses $1
        $rejectResult = updateRecord(
            "UPDATE offers SET status = 'rejected', updated_at = NOW() WHERE id = $1",
            [$offerId]
        );
        
        // Update application status
        $appRejectResult = updateRecord(
            "UPDATE applications SET status = 'rejected', updated_at = NOW() WHERE id = $1",
            [$offer['application_id']]
        );
        
        logActivity($userId, 'Offer Rejected', 'offers', $offerId, 
            'Rejected offer for ' . $offer['job_title'] . ' at ' . $offer['company_name']);
        
        // ✅ FIXED: Redirect to offers.php after rejection
        $_SESSION['message'] = 'You have declined the offer. We wish you the best in your future endeavors.';
        $_SESSION['message_type'] = 'info';
        
        header('Location: offers.php');
        exit;
    }
}

/**
 * Send Employee Welcome Email with Login Instructions
 */
function sendEmployeeWelcomeEmail($email, $name, $company, $position, $startDate, $userId) {
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($email, $name);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_REPLY_TO_NAME);
        $mail->addBCC(MAIL_FROM, 'HR Department');
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "🎉 Welcome to $company - You're Now an Employee!";
        
        $startDateText = $startDate ? date('F d, Y', strtotime($startDate)) : 'To be determined';
        
        // Login URL
        $loginUrl = SITE_URL . 'login.php';
        
        // HTML Body
        $htmlBody = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; color: #1b1b24; line-height: 1.6; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { 
                    background: linear-gradient(135deg, #4f46e5, #7c3aed); 
                    color: white; 
                    padding: 30px 20px; 
                    text-align: center; 
                    border-radius: 12px 12px 0 0;
                }
                .header h1 { margin: 0; font-size: 28px; }
                .header .subtitle { font-size: 16px; opacity: 0.9; margin-top: 8px; }
                .content { 
                    padding: 30px 25px; 
                    background: #f8f7fc; 
                    border: 1px solid #e2e8f0; 
                    border-radius: 0 0 12px 12px;
                }
                .welcome-box {
                    background: white;
                    padding: 20px;
                    border-radius: 10px;
                    margin: 15px 0;
                    border-left: 4px solid #4f46e5;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
                }
                .welcome-box h3 { color: #4f46e5; margin: 0 0 10px 0; }
                .details-grid {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 10px;
                    margin: 15px 0;
                }
                .detail-item {
                    background: white;
                    padding: 12px 15px;
                    border-radius: 8px;
                    border: 1px solid #e2e8f0;
                }
                .detail-item .label { 
                    font-size: 11px; 
                    text-transform: uppercase; 
                    color: #64748b; 
                    font-weight: 600;
                    letter-spacing: 0.5px;
                }
                .detail-item .value { 
                    font-size: 15px; 
                    font-weight: 600; 
                    color: #1b1b24; 
                    margin-top: 2px;
                }
                .login-info {
                    background: #eef0ff;
                    padding: 20px;
                    border-radius: 10px;
                    margin: 15px 0;
                    border: 1px solid #c7d2fe;
                }
                .login-info h4 {
                    color: #4338ca;
                    margin: 0 0 10px 0;
                    font-size: 16px;
                }
                .login-info .credential {
                    display: flex;
                    justify-content: space-between;
                    padding: 8px 0;
                    border-bottom: 1px solid #c7d2fe;
                    font-size: 14px;
                }
                .login-info .credential:last-child {
                    border-bottom: none;
                }
                .login-info .credential .label {
                    color: #4a5168;
                    font-weight: 500;
                }
                .login-info .credential .value {
                    font-weight: 600;
                    color: #1b1b24;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 30px;
                    background: #4f46e5;
                    color: white;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: 600;
                    margin: 10px 0;
                }
                .btn:hover {
                    background: #4338ca;
                }
                .cta-section {
                    background: #eef0ff;
                    padding: 20px;
                    border-radius: 10px;
                    text-align: center;
                    margin: 20px 0 10px 0;
                }
                .cta-section .emoji { font-size: 32px; display: block; margin-bottom: 8px; }
                .cta-section p { margin: 0; color: #4338ca; font-weight: 500; }
                .footer {
                    text-align: center;
                    padding: 20px;
                    color: #64748b;
                    font-size: 12px;
                    border-top: 1px solid #e2e8f0;
                    margin-top: 20px;
                }
                .footer a { color: #4f46e5; text-decoration: none; }
                .footer a:hover { text-decoration: underline; }
                @media (max-width: 480px) {
                    .details-grid { grid-template-columns: 1fr; }
                    .header h1 { font-size: 22px; }
                    .login-info .credential {
                        flex-direction: column;
                        gap: 4px;
                    }
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎉 Welcome to the Team!</h1>
                    <div class='subtitle'>You are now an official employee of $company</div>
                </div>
                <div class='content'>
                    <p>Dear <strong>$name</strong>,</p>
                    <p>We are absolutely thrilled to welcome you to the <strong>$company</strong> family!</p>
                    
                    <div class='welcome-box'>
                        <h3>📋 Your Employment Details</h3>
                        <div class='details-grid'>
                            <div class='detail-item'>
                                <div class='label'>Position</div>
                                <div class='value'>$position</div>
                            </div>
                            <div class='detail-item'>
                                <div class='label'>Company</div>
                                <div class='value'>$company</div>
                            </div>
                            <div class='detail-item'>
                                <div class='label'>Start Date</div>
                                <div class='value'>$startDateText</div>
                            </div>
                            <div class='detail-item'>
                                <div class='label'>Employee Status</div>
                                <div class='value' style='color:#059669;'>✅ Active</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class='login-info'>
                        <h4>🔐 Your Employee Account</h4>
                        <p style='font-size:14px; color:#4a5168; margin-bottom:12px;'>
                            You can now log in to the employee portal using your existing credentials:
                        </p>
                        <div class='credential'>
                            <span class='label'>Email</span>
                            <span class='value'>$email</span>
                        </div>
                        <div class='credential'>
                            <span class='label'>Password</span>
                            <span class='value'>Your existing password</span>
                        </div>
                        <div style='text-align:center; margin-top:15px;'>
                            <a href='$loginUrl' class='btn'>Login to Employee Portal</a>
                        </div>
                    </div>
                    
                    <div class='cta-section'>
                        <span class='emoji'>🚀</span>
                        <p><strong>What's Next?</strong></p>
                        <p style='font-weight:400; color:#4338ca; margin-top:4px;'>
                            Log in to access your employee dashboard, view your schedule, and track attendance.
                        </p>
                    </div>
                    
                    <div style='margin: 15px 0; padding: 15px; background: #f1f5f9; border-radius: 8px;'>
                        <p style='margin: 0; font-size: 14px;'>
                            <strong>💡 Quick Tips:</strong>
                        </p>
                        <ul style='margin: 8px 0 0 20px; font-size: 14px; color: #334155;'>
                            <li>Log in with your email and existing password</li>
                            <li>Complete your employee profile</li>
                            <li>Check your schedule regularly</li>
                            <li>Contact HR if you have any questions</li>
                        </ul>
                    </div>
                    
                    <p style='margin-top: 20px;'>
                        We are confident that you will make a great addition to our team. 
                        Your skills and experience will be valuable assets to $company.
                    </p>
                    
                    <p style='margin-top: 15px;'>
                        Once again, welcome aboard! We look forward to working with you.
                    </p>
                    
                    <p style='margin-top: 25px;'>
                        Best regards,<br>
                        <strong>The HR Team</strong><br>
                        <span style='color: #64748b;'>$company</span>
                    </p>
                </div>
                <div class='footer'>
                    <p>This is an automated message from ISMERS System.</p>
                    <p>If you have any questions, please contact HR at <a href='mailto:" . MAIL_REPLY_TO . "'>" . MAIL_REPLY_TO . "</a></p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->Body = $htmlBody;
        
        // Plain text version
        $plainText = "Dear $name,\n\n";
        $plainText .= "Congratulations and welcome to the team at $company!\n\n";
        $plainText .= "Position: $position\n";
        $plainText .= "Start Date: $startDateText\n";
        $plainText .= "Status: Active Employee\n\n";
        $plainText .= "Your Employee Account Details:\n";
        $plainText .= "Login URL: $loginUrl\n";
        $plainText .= "Email: $email\n";
        $plainText .= "Password: Your existing password\n\n";
        $plainText .= "What's Next?\n";
        $plainText .= "Log in to access your employee dashboard, view your schedule, and track attendance.\n\n";
        $plainText .= "Best regards,\n";
        $plainText .= "The HR Team\n";
        $plainText .= $company;
        
        $mail->AltBody = $plainText;
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $e->getMessage());
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Offer - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #f8f7fc;
            color: #1b1b24;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            max-width: 720px;
            width: 100%;
            margin: 0 auto;
        }
        
        .card {
            background: #ffffff;
            border-radius: 24px;
            padding: 40px 48px;
            box-shadow: 0 20px 60px rgba(26, 58, 92, 0.12);
            border: 1px solid #e2e8f0;
        }
        
        @media (max-width: 640px) {
            .card {
                padding: 24px 20px;
            }
        }
        
        .header {
            text-align: center;
            margin-bottom: 32px;
        }
        
        .header .icon {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #eef0ff;
            color: #4f46e5;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 36px;
        }
        
        .header h1 {
            font-size: 28px;
            font-weight: 800;
            color: #0a0e1a;
        }
        
        .header p {
            font-size: 16px;
            color: #4a5168;
            margin-top: 4px;
        }
        
        .offer-details {
            background: #f8f9fc;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            border: 1px solid #e2e8f0;
        }
        
        .offer-details .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .offer-details .detail-row:last-child {
            border-bottom: none;
        }
        
        .offer-details .label {
            color: #4a5168;
            font-size: 14px;
            font-weight: 500;
        }
        
        .offer-details .value {
            font-weight: 600;
            color: #0a0e1a;
            font-size: 14px;
            text-align: right;
        }
        
        .offer-details .value .salary {
            color: #059669;
            font-size: 18px;
        }
        
        .message {
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 14px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .message .material-symbols-outlined {
            flex-shrink: 0;
            margin-top: 1px;
        }
        
        .message.success {
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #065f46;
        }
        
        .message.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        
        .message.info {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1e40af;
        }
        
        .expired-notice {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            color: #92400e;
            margin-bottom: 20px;
        }
        
        .expired-notice .material-symbols-outlined {
            font-size: 32px;
            display: block;
            margin-bottom: 8px;
        }
        
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
            text-decoration: none;
            flex: 1;
            justify-content: center;
            min-height: 56px;
        }
        
        .btn-accept {
            background: #059669;
            color: white;
            box-shadow: 0 4px 14px rgba(5, 150, 105, 0.35);
        }
        
        .btn-accept:hover:not(:disabled) {
            background: #047857;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(5, 150, 105, 0.45);
        }
        
        .btn-reject {
            background: #dc2626;
            color: white;
            box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
        }
        
        .btn-reject:hover:not(:disabled) {
            background: #b91c1c;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.45);
        }
        
        .btn-outline {
            background: transparent;
            color: #4a5168;
            border: 2px solid #e2e8f0;
        }
        
        .btn-outline:hover {
            background: #f8f9fc;
            border-color: #4a5168;
        }
        
        .btn-primary {
            background: #4f46e5;
            color: white;
            box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
        }
        
        .btn-primary:hover {
            background: #4338ca;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.45);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        
        .btn .material-symbols-outlined {
            font-size: 20px;
        }
        
        .success-actions {
            text-align: center;
            margin-top: 16px;
        }
        
        .success-actions .btn {
            flex: none;
            padding: 12px 32px;
        }
        
        .footer-note {
            text-align: center;
            margin-top: 24px;
            font-size: 13px;
            color: #4a5168;
        }
        
        .footer-note a {
            color: #4f46e5;
            font-weight: 600;
            text-decoration: none;
        }
        
        .footer-note a:hover {
            text-decoration: underline;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 480px) {
            .actions {
                flex-direction: column;
            }
            .btn {
                flex: none;
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="card">
        
        <!-- Header -->
        <div class="header">
            <div class="icon">
                <span class="material-symbols-outlined">description</span>
            </div>
            <h1>Job Offer</h1>
            <p>You have received a job offer from <strong><?php echo htmlspecialchars($offer['company_name']); ?></strong></p>
        </div>
        
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <span class="material-symbols-outlined">
                    <?php echo $messageType === 'success' ? 'check_circle' : ($messageType === 'error' ? 'error' : 'info'); ?>
                </span>
                <div><?php echo htmlspecialchars($message); ?></div>
            </div>
        <?php endif; ?>
        
        <!-- Check if already responded -->
        <?php if ($offer['status'] !== 'sent'): ?>
            <div class="message info">
                <span class="material-symbols-outlined">info</span>
                <div>
                    This offer has been <strong><?php echo $offer['status']; ?></strong>.
                    <?php if ($offer['status'] === 'accepted'): ?>
                        <br>Welcome to the team! You are now an employee of <?php echo htmlspecialchars($offer['company_name']); ?>.
                    <?php endif; ?>
                </div>
            </div>
            <div class="success-actions">
                <a href="offers.php" class="btn btn-primary">
                    <span class="material-symbols-outlined">arrow_back</span>
                    Go to Offers
                </a>
            </div>
            
        <?php elseif ($isExpired): ?>
            <!-- Expired Offer -->
            <div class="expired-notice">
                <span class="material-symbols-outlined">warning</span>
                <strong>This offer has expired.</strong>
                <p style="margin-top:4px;">The offer was sent on <?php echo date('M d, Y', strtotime($offer['sent_at'])); ?> and expired after 7 days.</p>
                <p style="margin-top:4px;">Please contact HR for further assistance.</p>
            </div>
            <div class="actions">
                <a href="offers.php" class="btn btn-outline" style="flex:1;">
                    <span class="material-symbols-outlined">arrow_back</span>
                    View Offers
                </a>
            </div>
            
        <?php else: ?>
            <!-- Offer Details -->
            <div class="offer-details">
                <div class="detail-row">
                    <span class="label">Position</span>
                    <span class="value"><?php echo htmlspecialchars($offer['job_title']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Company</span>
                    <span class="value"><?php echo htmlspecialchars($offer['company_name']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Salary Offered</span>
                    <span class="value salary">₱<?php echo number_format($offer['salary_offered'] ?? 0, 2); ?></span>
                </div>
                <?php if (!empty($offer['start_date'])): ?>
                <div class="detail-row">
                    <span class="label">Proposed Start Date</span>
                    <span class="value"><?php echo date('M d, Y', strtotime($offer['start_date'])); ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-row">
                    <span class="label">Offer Date</span>
                    <span class="value"><?php echo date('M d, Y', strtotime($offer['offer_date'])); ?></span>
                </div>
                <?php if (!empty($offer['benefits'])): ?>
                <div class="detail-row" style="flex-direction:column; align-items:flex-start; gap:4px; padding-bottom:0;">
                    <span class="label">Benefits</span>
                    <span class="value" style="text-align:left; white-space:pre-wrap; width:100%;"><?php echo nl2br(htmlspecialchars($offer['benefits'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Actions -->
            <div class="actions">
                <form method="POST" action="" style="flex:1;">
                    <input type="hidden" name="action" value="accept">
                    <button type="submit" class="btn btn-accept" id="acceptBtn">
                        <span class="material-symbols-outlined">check_circle</span>
                        Accept Offer
                    </button>
                </form>
                <form method="POST" action="" style="flex:1;">
                    <input type="hidden" name="action" value="reject">
                    <button type="submit" class="btn btn-reject" id="rejectBtn">
                        <span class="material-symbols-outlined">cancel</span>
                        Decline Offer
                    </button>
                </form>
            </div>
            
            <div class="footer-note">
                <span class="material-symbols-outlined" style="font-size:16px; vertical-align:middle;">info</span>
                This offer expires on <strong><?php echo date('M d, Y', strtotime($offer['sent_at'] . ' +7 days')); ?></strong>
                <br>
                <a href="offers.php">View all offers</a>
            </div>
            
        <?php endif; ?>
        
    </div>
</div>

<script>
    // Handle form submission with loading state
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const btn = this.querySelector('button[type="submit"]');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<span class="loading-spinner"></span> Processing...';
                
                // Store original HTML to restore if needed
                btn.dataset.originalHtml = originalHtml;
            }
        });
    });
    
    // Confirm for reject - using form submit event instead of click
    document.querySelector('form input[name="action"][value="reject"]')?.closest('form')?.addEventListener('submit', function(e) {
        if (!confirm('Are you sure you want to reject this offer? This action cannot be undone.')) {
            e.preventDefault();
            // Re-enable the button
            const btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = btn.dataset.originalHtml || btn.innerHTML;
            }
        }
    });
</script>
<script src="/CT1/session_guard.js"></script>
</body>
</html>