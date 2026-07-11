<?php
// test_login.php - Test login credentials
require_once 'app/config.php';

$email = 'hr_manager@ismers.com';
$password = 'applicant123';

echo "Testing login for: $email<br><br>";

$user = getUserByEmail($email);

if ($user) {
    echo "✅ User found!<br>";
    echo "Email: " . $user['email'] . "<br>";
    echo "Role: " . $user['role'] . "<br>";
    echo "Password hash: " . $user['password_hash'] . "<br><br>";
    
    if (password_verify($password, $user['password_hash'])) {
        echo "✅ Password VERIFIED!<br>";
        echo "You can login with:<br>";
        echo "Email: <strong>$email</strong><br>";
        echo "Password: <strong>$password</strong><br>";
    } else {
        echo "❌ Password verification FAILED!<br>";
        echo "Try resetting the password using reset_hr_user.php";
    }
} else {
    echo "❌ User NOT found!<br>";
    echo "Run reset_hr_user.php to create the user.";
}
?>