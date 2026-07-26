<?php
// send_otp.php - Send OTP email via AJAX (background)
session_start();

require_once 'app/config.php';

// Load PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'PHPMailer-master/src/Exception.php';
require_once 'PHPMailer-master/src/PHPMailer.php';
require_once 'PHPMailer-master/src/SMTP.php';

header('Content-Type: application/json');

$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$code = isset($_POST['code']) ? $_POST['code'] : '';
$email = isset($_POST['email']) ? $_POST['email'] : '';
$name = isset($_POST['name']) ? $_POST['name'] : 'User';

if ($userId <= 0 || empty($code) || empty($email)) {
    echo json_encode(['success' => false, 'error' => 'Missing required data']);
    exit;
}

try {
    $mail = new PHPMailer(true);
    
    $mail->SMTPDebug = SMTP::DEBUG_OFF;
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port       = SMTP_PORT;
    
    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress($email, $name);
    $mail->addReplyTo(MAIL_REPLY_TO, MAIL_REPLY_TO_NAME);
    
    $mail->isHTML(true);
    $mail->Subject = 'Login Verification Code - ISMERS';
    
    $mail->Body = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Login Verification</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f3ff; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            .header { text-align: center; margin-bottom: 30px; }
            .logo { display: inline-block; background: #4f46e5; color: white; font-size: 24px; font-weight: 800; padding: 8px 20px; border-radius: 12px; }
            h1 { color: #1b1b24; font-size: 24px; margin-bottom: 8px; }
            p { color: #464555; font-size: 16px; line-height: 1.6; }
            .code-box { background: #f5f3ff; border: 2px dashed #4f46e5; border-radius: 12px; padding: 20px; text-align: center; margin: 20px 0; font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #4f46e5; }
            .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #e8e5f0; color: #777587; font-size: 14px; }
            .warning { font-size: 14px; color: #92400e; background: #fef3c7; padding: 12px 16px; border-radius: 8px; margin: 16px 0; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="logo">ISMERS</div>
                <h1>Login Verification</h1>
            </div>
            <p>Hello <strong>' . htmlspecialchars($name) . '</strong>,</p>
            <p>We received a login attempt for your ISMERS account. Please use the verification code below to complete your login:</p>
            <div class="code-box">' . $code . '</div>
            <div class="warning">
                <strong>⚠️ This code will expire in 10 minutes.</strong>
            </div>
            <p>If you didn\'t try to log in, you can safely ignore this email.</p>
            <div class="footer">
                <p>&copy; ' . date('Y') . ' ISMERS. All rights reserved.</p>
            </div>
        </div>
    </body>
    </html>
    ';
    
    $mail->AltBody = "Hello " . $name . ",\n\n" .
                     "We received a login attempt for your ISMERS account.\n\n" .
                     "Your verification code is: " . $code . "\n\n" .
                     "This code will expire in 10 minutes.\n\n" .
                     "If you didn't try to log in, please ignore this email.\n\n" .
                     "— ISMERS Team";
    
    $mail->send();
    
    // Log OTP sent
    logActivity($userId, 'Login OTP Sent', 'users', $userId, 'OTP sent to: ' . $email);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log("AJAX verification email failed: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
exit;
?>