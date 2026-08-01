<?php
// app/email_functions.php - Email utility functions using PHPMailer

require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send "on hold too long" notification
 */
function sendHoldTooLongEmail($applicant, $companyName = 'Our Company') {
    if (empty($applicant['email'])) {
        return false;
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings - using constants from config.php
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;
        
        // Disable SSL verification for testing (remove in production)
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Recipients
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME . ' - ' . $companyName);
        $mail->addAddress($applicant['email'], $applicant['first_name'] . ' ' . $applicant['last_name']);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_REPLY_TO_NAME);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Application Update - ' . $applicant['job_title'];
        $mail->Body = getHoldTooLongEmailHTML($applicant, $companyName);
        $mail->AltBody = getHoldTooLongEmailText($applicant, $companyName);
        
        // Try to send
        $sent = $mail->send();
        
        if (!$sent) {
            error_log("PHPMailer sendHoldTooLongEmail returned false: " . $mail->ErrorInfo);
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        // Log the error
        error_log("PHPMailer Exception in sendHoldTooLongEmail: " . $e->getMessage());
        error_log("PHPMailer Error Info: " . $mail->ErrorInfo);
        
        // Check if the email was actually sent despite the exception
        $errorInfo = $mail->ErrorInfo;
        if (strpos($errorInfo, 'Message sent') !== false || 
            strpos($errorInfo, '250') !== false ||
            strpos($errorInfo, 'Queued') !== false ||
            strpos($errorInfo, 'Accepted') !== false) {
            return true;
        }
        
        if (strpos($errorInfo, '250') !== false) {
            return true;
        }
        
        return false;
    }
}

/**
 * Send qualification email to applicant
 */
function sendQualificationEmail($applicant, $isQualified, $companyName = 'Our Company') {
    if (empty($applicant['email'])) {
        return false;
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings - using constants from config.php
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;
        
        // Disable SSL verification for testing (remove in production)
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        // Recipients
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME . ' - ' . $companyName);
        $mail->addAddress($applicant['email'], $applicant['first_name'] . ' ' . $applicant['last_name']);
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_REPLY_TO_NAME);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Application Status Update - ' . $applicant['job_title'];
        
        if ($isQualified) {
            $mail->Body = getQualifiedEmailHTML($applicant, $companyName);
            $mail->AltBody = getQualifiedEmailText($applicant, $companyName);
        } else {
            $mail->Body = getNotQualifiedEmailHTML($applicant, $companyName);
            $mail->AltBody = getNotQualifiedEmailText($applicant, $companyName);
        }
        
        $sent = $mail->send();
        
        if (!$sent) {
            error_log("PHPMailer sendQualificationEmail returned false: " . $mail->ErrorInfo);
            return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("PHPMailer Exception in sendQualificationEmail: " . $e->getMessage());
        error_log("PHPMailer Error Info: " . $mail->ErrorInfo);
        
        $errorInfo = $mail->ErrorInfo;
        if (strpos($errorInfo, 'Message sent') !== false || 
            strpos($errorInfo, '250') !== false ||
            strpos($errorInfo, 'Queued') !== false ||
            strpos($errorInfo, 'Accepted') !== false) {
            return true;
        }
        
        if (strpos($errorInfo, '250') !== false) {
            return true;
        }
        
        return false;
    }
}

// =============================================
// EMAIL TEMPLATES
// =============================================

function getQualifiedEmailHTML($applicant, $companyName) {
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #4f46e5; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #f9f9f9; border-radius: 0 0 8px 8px; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
            .badge { display: inline-block; padding: 8px 16px; background: #22c55e; color: white; border-radius: 4px; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Application Status Update</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>{$applicant['first_name']} {$applicant['last_name']}</strong>,</p>
                <p>Thank you for your application to the <strong>{$applicant['job_title']}</strong> position at <strong>{$companyName}</strong>.</p>
                <p>After careful review, we are pleased to inform you that you have been <span class='badge'>SHORTLISTED</span> for the next stage of the recruitment process.</p>
                <p>Our team will be in touch shortly with further details regarding the next steps.</p>
                <br>
                <p>Best regards,</p>
                <p><strong>HR Team</strong><br>{$companyName}</p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function getQualifiedEmailText($applicant, $companyName) {
    return "Dear {$applicant['first_name']} {$applicant['last_name']},\n\n" .
           "Thank you for your application to the {$applicant['job_title']} position at {$companyName}.\n\n" .
           "After careful review, we are pleased to inform you that you have been SHORTLISTED for the next stage of the recruitment process.\n\n" .
           "Our team will be in touch shortly with further details regarding the next steps.\n\n" .
           "Best regards,\nHR Team\n{$companyName}";
}

function getNotQualifiedEmailHTML($applicant, $companyName) {
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #dc2626; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #f9f9f9; border-radius: 0 0 8px 8px; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
            .badge { display: inline-block; padding: 8px 16px; background: #dc2626; color: white; border-radius: 4px; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Application Status Update</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>{$applicant['first_name']} {$applicant['last_name']}</strong>,</p>
                <p>Thank you for your application to the <strong>{$applicant['job_title']}</strong> position at <strong>{$companyName}</strong>.</p>
                <p>After careful consideration, we regret to inform you that your application has been <span class='badge'>NOT SELECTED</span> to proceed to the next stage.</p>
                <p>We appreciate your interest in joining our team and wish you all the best in your future endeavors.</p>
                <br>
                <p>Best regards,</p>
                <p><strong>HR Team</strong><br>{$companyName}</p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function getNotQualifiedEmailText($applicant, $companyName) {
    return "Dear {$applicant['first_name']} {$applicant['last_name']},\n\n" .
           "Thank you for your application to the {$applicant['job_title']} position at {$companyName}.\n\n" .
           "After careful consideration, we regret to inform you that your application has been NOT SELECTED to proceed to the next stage.\n\n" .
           "We appreciate your interest in joining our team and wish you all the best in your future endeavors.\n\n" .
           "Best regards,\nHR Team\n{$companyName}";
}

function getHoldTooLongEmailHTML($applicant, $companyName) {
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #f59e0b; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #f9f9f9; border-radius: 0 0 8px 8px; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
            .highlight { color: #dc2626; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Application Status Update</h2>
            </div>
            <div class='content'>
                <p>Dear <strong>{$applicant['first_name']} {$applicant['last_name']}</strong>,</p>
                <p>Thank you for your application to the <strong>{$applicant['job_title']}</strong> position at <strong>{$companyName}</strong>.</p>
                <p>We regret to inform you that your application has been <span class='highlight'>placed on hold</span> for more than one week and has not progressed to the next stage.</p>
                <p>Due to the extended review time and the volume of applications received, we have decided to <span class='highlight'>close your application</span> at this time.</p>
                <p>We appreciate your interest in joining our team and encourage you to apply for future openings that match your skills and experience.</p>
                <br>
                <p>Best regards,</p>
                <p><strong>HR Team</strong><br>{$companyName}</p>
            </div>
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function getHoldTooLongEmailText($applicant, $companyName) {
    return "Dear {$applicant['first_name']} {$applicant['last_name']},\n\n" .
           "Thank you for your application to the {$applicant['job_title']} position at {$companyName}.\n\n" .
           "We regret to inform you that your application has been placed on hold for more than one week and has not progressed to the next stage.\n\n" .
           "Due to the extended review time and the volume of applications received, we have decided to close your application at this time.\n\n" .
           "We appreciate your interest in joining our team and encourage you to apply for future openings that match your skills and experience.\n\n" .
           "Best regards,\nHR Team\n{$companyName}";
}