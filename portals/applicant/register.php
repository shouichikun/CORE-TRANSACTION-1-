<?php
// portals/applicant/register.php - Applicant Registration (Step 1)
session_start();

// Include configuration file
require_once '../../app/config.php';

// Initialize variables
$error = '';
$success = false;
$formData = [];

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $formData = [
        'firstName' => trim($_POST['firstName'] ?? ''),
        'lastName' => trim($_POST['lastName'] ?? ''),
        'middleInitial' => trim($_POST['middleInitial'] ?? ''),
        'suffix' => trim($_POST['suffix'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'gender' => $_POST['gender'] ?? '',
        'birthMonth' => $_POST['birthMonth'] ?? '',
        'birthDay' => $_POST['birthDay'] ?? '',
        'birthYear' => $_POST['birthYear'] ?? '',
        'placeOfBirth' => trim($_POST['placeOfBirth'] ?? ''),
        'region' => $_POST['region'] ?? '',
        'city' => $_POST['city'] ?? ''
    ];

    // Validate required fields
    $errors = [];

    if (empty($formData['firstName'])) {
        $errors[] = 'First name is required.';
    }
    if (empty($formData['lastName'])) {
        $errors[] = 'Last name is required.';
    }
    if (empty($formData['email'])) {
        $errors[] = 'Email address is required.';
    } elseif (!validateEmail($formData['email'])) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (empty($formData['password'])) {
        $errors[] = 'Password is required.';
    } elseif (strlen($formData['password']) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if (empty($formData['gender'])) {
        $errors[] = 'Gender is required.';
    }
    if (empty($formData['birthMonth']) || empty($formData['birthDay']) || empty($formData['birthYear'])) {
        $errors[] = 'Complete birthday is required.';
    }
    if (empty($formData['placeOfBirth'])) {
        $errors[] = 'Place of birth is required.';
    }
    if (empty($formData['region'])) {
        $errors[] = 'Region is required.';
    }
    if (empty($formData['city'])) {
        $errors[] = 'City is required.';
    }

    // Check if email already exists
    if (empty($errors)) {
        $existingUser = getUserByEmail($formData['email']);
        if ($existingUser) {
            $errors[] = 'This email is already registered. Please use a different email or login.';
        }
    }

    // If no errors, proceed with registration
    if (empty($errors)) {
        // Hash password
        $hashedPassword = password_hash($formData['password'], PASSWORD_DEFAULT);

        // Format birthday
        $birthDate = $formData['birthYear'] . '-' . 
                     str_pad($formData['birthMonth'], 2, '0', STR_PAD_LEFT) . '-' . 
                     str_pad($formData['birthDay'], 2, '0', STR_PAD_LEFT);

        // Prepare user data
        $userData = [
            'email' => $formData['email'],
            'password_hash' => $hashedPassword,
            'role' => 'applicant',
            'full_name' => $formData['firstName'] . ' ' . $formData['lastName'],
            'first_name' => $formData['firstName'],
            'last_name' => $formData['lastName'],
            'middle_initial' => $formData['middleInitial'],
            'suffix' => $formData['suffix'],
            'gender' => $formData['gender'],
            'birth_date' => $birthDate,
            'place_of_birth' => $formData['placeOfBirth'],
            'region' => $formData['region'],
            'city' => $formData['city']
        ];

        // Begin transaction
        beginTransaction();

        // Insert user into database
        $userId = createUser($userData);

        if ($userId) {
            // Create applicant profile
            $applicantData = [
                'phone' => '',
                'address' => '',
                'skills' => '',
                'experience' => '',
                'education' => ''
            ];
            $applicantId = createApplicant($userId, $applicantData);

            if ($applicantId) {
                // Commit transaction
                commitTransaction();

                // Set session for the new user
                $_SESSION['user_id'] = $userId;
                $_SESSION['role'] = 'applicant';
                $_SESSION['full_name'] = $userData['full_name'];
                $_SESSION['email'] = $userData['email'];
                $_SESSION['applicant_id'] = $applicantId;
                $_SESSION['registration_step'] = 1;

                // Log the registration
                logFaceScan($userId, 'registration', 0, 'success');

                // Redirect to step 2
                header('Location: register_step2.php');
                exit;
            } else {
                // Rollback transaction if applicant creation fails
                rollbackTransaction();
                $errors[] = 'Failed to create applicant profile. Please try again.';
            }
        } else {
            // Rollback transaction if user creation fails
            rollbackTransaction();
            $errors[] = 'Failed to create account. Please try again.';
        }
    }

    // If there are errors, store them for display
    if (!empty($errors)) {
        $error = implode('|', $errors);
        // Store form data to repopulate fields
        $_SESSION['register_form_data'] = $formData;
    }
}

// Check for session data to repopulate form
if (isset($_SESSION['register_form_data'])) {
    $formData = $_SESSION['register_form_data'];
    // Clear session data after use
    unset($_SESSION['register_form_data']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - ISMERS</title>
    
    <style>
        /* ===== RESET & BASE ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-dark: #0a2647;
            --primary-blue: #1a3a5c;
            --primary-medium: #2c5f8a;
            --primary-light: #4a90d9;
            --primary-lighter: #6db3f2;
            --primary-gradient: linear-gradient(135deg, #1a3a5c 0%, #4a90d9 100%);
            --white: #ffffff;
            --gray-light: #f8f9fc;
            --gray-border: #e8ecf1;
            --text-dark: #1a2a3a;
            --text-gray: #5a6a7a;
            --shadow-sm: 0 2px 8px rgba(26, 58, 92, 0.08);
            --shadow-md: 0 8px 30px rgba(26, 58, 92, 0.12);
            --shadow-lg: 0 20px 60px rgba(26, 58, 92, 0.15);
            --radius: 16px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --strength-weak: #dc2626;
            --strength-fair: #f59e0b;
            --strength-good: #3b82f6;
            --strength-strong: #22c55e;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--gray-light);
            color: var(--text-dark);
            line-height: 1.6;
            min-height: 100vh;
            background: linear-gradient(160deg, #f0f5ff 0%, #ffffff 50%, #f8faff 100%);
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* ===== NAVBAR ===== */
        .navbar {
            background: var(--white);
            padding: 16px 40px;
            border-bottom: 1px solid var(--gray-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 22px;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .nav-brand .brand-icon {
            width: 38px;
            height: 38px;
            background: var(--primary-gradient);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
            font-weight: 800;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .nav-actions a {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-gray);
            transition: var(--transition);
        }

        .nav-actions a:hover {
            color: var(--primary-blue);
        }

        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-outline {
            background: transparent;
            color: var(--primary-blue);
            border: 2px solid var(--primary-blue);
        }

        .btn-outline:hover {
            background: var(--primary-blue);
            color: white;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: white;
            box-shadow: 0 4px 15px rgba(74, 144, 217, 0.35);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(74, 144, 217, 0.45);
        }

        .btn-large {
            padding: 14px 36px;
            font-size: 16px;
        }

        .btn-block {
            width: 100%;
            justify-content: center;
        }

        /* ===== REGISTER CONTAINER ===== */
        .register-wrapper {
            max-width: 720px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .register-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 48px 48px 40px;
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(26, 58, 92, 0.06);
        }

        /* ===== HEADER ===== */
        .register-header {
            text-align: center;
            margin-bottom: 36px;
        }

        .register-header h1 {
            font-size: 28px;
            font-weight: 800;
            color: var(--primary-dark);
        }

        .register-header p {
            font-size: 15px;
            color: var(--text-gray);
            margin-top: 4px;
        }

        /* ===== STEP INDICATOR ===== */
        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0;
            margin-bottom: 40px;
            position: relative;
        }

        .step-indicator .step-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            color: var(--text-gray);
            background: var(--gray-light);
            border: 3px solid var(--gray-border);
            transition: var(--transition);
            position: relative;
            z-index: 2;
        }

        .step-indicator .step-circle.active {
            background: var(--primary-gradient);
            color: white;
            border-color: var(--primary-light);
            box-shadow: 0 4px 15px rgba(74, 144, 217, 0.35);
        }

        .step-indicator .step-circle.done {
            background: #22c55e;
            color: white;
            border-color: #22c55e;
        }

        .step-indicator .step-circle .checkmark {
            display: none;
        }

        .step-indicator .step-circle.done .number {
            display: none;
        }

        .step-indicator .step-circle.done .checkmark {
            display: inline;
        }

        .step-indicator .step-line {
            width: 80px;
            height: 3px;
            background: var(--gray-border);
            transition: var(--transition);
            z-index: 1;
        }

        .step-indicator .step-line.done {
            background: #22c55e;
        }

        .step-indicator .step-line.active {
            background: var(--primary-light);
        }

        .step-indicator .step-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-gray);
            text-align: center;
            margin-top: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .step-indicator .step-circle-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* ===== FORM ===== */
        .form-section {
            animation: fadeInUp 0.4s ease-out;
        }

        .form-section .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 4px;
        }

        .form-section .section-subtitle {
            font-size: 14px;
            color: var(--text-gray);
            margin-bottom: 24px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 4px;
        }

        .form-group label .required {
            color: #dc2626;
            margin-left: 2px;
        }

        .form-group .input-wrapper {
            position: relative;
        }

        .form-group .input-wrapper .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-gray);
            pointer-events: none;
        }

        .form-group .input-wrapper .input-icon svg {
            width: 18px;
            height: 18px;
            stroke: var(--text-gray);
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 2px solid var(--gray-border);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            background: var(--gray-light);
            transition: var(--transition);
            color: var(--text-dark);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-light);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(74, 144, 217, 0.1);
        }

        .form-group input::placeholder {
            color: #aab3c0;
        }

        .form-group select {
            padding-right: 40px;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%235a6a7a'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            cursor: pointer;
        }

        .form-group select:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* ===== BIRTHDAY DROPDOWN GROUP ===== */
        .birthday-group {
            display: flex;
            gap: 10px;
        }

        .birthday-group .birthday-select {
            flex: 1;
            position: relative;
        }

        .birthday-group .birthday-select select {
            width: 100%;
            padding: 12px 12px 12px 12px;
            border: 2px solid var(--gray-border);
            border-radius: 10px;
            font-size: 14px;
            font-family: inherit;
            background: var(--gray-light);
            transition: var(--transition);
            color: var(--text-dark);
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M6 8L1 3h10z' fill='%235a6a7a'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            cursor: pointer;
            padding-right: 32px;
        }

        .birthday-group .birthday-select select:focus {
            outline: none;
            border-color: var(--primary-light);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(74, 144, 217, 0.1);
        }

        .birthday-group .birthday-select select:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .birthday-group .birthday-select .birthday-label {
            position: absolute;
            top: -8px;
            left: 12px;
            font-size: 10px;
            font-weight: 600;
            color: var(--text-gray);
            background: var(--white);
            padding: 0 4px;
            pointer-events: none;
        }

        /* ===== PASSWORD STRENGTH METER ===== */
        .password-strength {
            margin-top: 8px;
        }

        .password-strength .strength-bar {
            display: flex;
            gap: 6px;
            height: 6px;
            margin-bottom: 6px;
        }

        .password-strength .strength-bar .bar-segment {
            flex: 1;
            background: var(--gray-border);
            border-radius: 4px;
            transition: var(--transition);
        }

        .password-strength .strength-bar .bar-segment.weak {
            background: var(--strength-weak);
        }

        .password-strength .strength-bar .bar-segment.fair {
            background: var(--strength-fair);
        }

        .password-strength .strength-bar .bar-segment.good {
            background: var(--strength-good);
        }

        .password-strength .strength-bar .bar-segment.strong {
            background: var(--strength-strong);
        }

        .password-strength .strength-text {
            font-size: 12px;
            font-weight: 600;
        }

        .password-strength .strength-text.weak { color: var(--strength-weak); }
        .password-strength .strength-text.fair { color: var(--strength-fair); }
        .password-strength .strength-text.good { color: var(--strength-good); }
        .password-strength .strength-text.strong { color: var(--strength-strong); }

        .password-requirements {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 16px;
            margin-top: 6px;
            font-size: 12px;
            color: var(--text-gray);
        }

        .password-requirements .req {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 2px 0;
        }

        .password-requirements .req .req-icon {
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .password-requirements .req .req-icon.met {
            color: #22c55e;
        }

        .password-requirements .req .req-icon.unmet {
            color: var(--text-gray);
        }

        .password-requirements .req .req-text.met {
            color: #22c55e;
        }

        .password-requirements .req .req-text.unmet {
            color: var(--text-gray);
        }

        /* Password toggle */
        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            transition: var(--transition);
            color: var(--text-gray);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-password:hover {
            color: var(--primary-blue);
        }

        .toggle-password svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* ===== FORM ACTIONS ===== */
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid var(--gray-border);
        }

        .form-actions .btn-back {
            background: none;
            border: none;
            color: var(--text-gray);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .form-actions .btn-back:hover {
            color: var(--primary-blue);
        }

        .form-actions .btn-back svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* ===== SIGN IN LINK ===== */
        .signin-link {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            color: var(--text-gray);
        }

        .signin-link a {
            color: var(--primary-light);
            font-weight: 700;
            transition: var(--transition);
        }

        .signin-link a:hover {
            color: var(--primary-blue);
            text-decoration: underline;
        }

        /* ===== MODAL ===== */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
            animation: fadeIn 0.3s ease-out;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--white);
            border-radius: var(--radius);
            padding: 40px 48px;
            max-width: 480px;
            width: 100%;
            box-shadow: var(--shadow-lg);
            animation: slideUp 0.4s ease-out;
            text-align: center;
        }

        .modal .modal-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .modal .modal-icon.error {
            background: #fef2f2;
            color: #dc2626;
        }

        .modal .modal-icon.success {
            background: #f0fdf4;
            color: #22c55e;
        }

        .modal .modal-icon svg {
            width: 32px;
            height: 32px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .modal h2 {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .modal p {
            font-size: 15px;
            color: var(--text-gray);
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .modal .error-list {
            text-align: left;
            background: var(--gray-light);
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 20px;
            border: 1px solid var(--gray-border);
        }

        .modal .error-list li {
            list-style: none;
            font-size: 14px;
            color: var(--text-gray);
            padding: 4px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal .error-list li .bullet {
            color: #dc2626;
            font-weight: 700;
        }

        .modal .btn-modal {
            padding: 12px 40px;
            border: none;
            border-radius: 50px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .modal .btn-modal.primary {
            background: var(--primary-gradient);
            color: white;
            box-shadow: 0 4px 15px rgba(74, 144, 217, 0.35);
        }

        .modal .btn-modal.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(74, 144, 217, 0.45);
        }

        .modal .btn-modal.success {
            background: #22c55e;
            color: white;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.35);
        }

        .modal .btn-modal.success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(34, 197, 94, 0.45);
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .navbar {
                padding: 12px 20px;
            }

            .register-card {
                padding: 32px 24px 28px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-row-3 {
                grid-template-columns: 1fr 1fr;
            }

            .step-indicator .step-line {
                width: 40px;
            }

            .step-indicator .step-circle {
                width: 36px;
                height: 36px;
                font-size: 14px;
            }

            .step-indicator .step-label {
                font-size: 9px;
            }

            .form-actions {
                flex-direction: column;
                gap: 12px;
            }

            .form-actions .btn-back {
                order: 2;
            }

            .password-requirements {
                grid-template-columns: 1fr;
            }

            .modal {
                padding: 32px 24px;
            }

            .birthday-group {
                flex-direction: column;
                gap: 8px;
            }
        }

        @media (max-width: 480px) {
            .register-card {
                padding: 24px 16px 20px;
            }

            .form-row-3 {
                grid-template-columns: 1fr;
            }

            .step-indicator .step-line {
                width: 30px;
            }

            .step-indicator .step-circle {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>

    <!-- ===== NAVBAR ===== -->
    <nav class="navbar">
        <a href="../../index.php" class="nav-brand">
            <span class="brand-icon">I</span>
            ISMERS
        </a>
        <div class="nav-actions">
            <a href="../../login.php">Sign In</a>
            <a href="../../index.php" class="btn btn-outline">Home</a>
        </div>
    </nav>

    <!-- ===== REGISTER ===== -->
    <div class="register-wrapper">
        <div class="register-card">

            <!-- Header -->
            <div class="register-header">
                <h1>Create Your Account</h1>
                <p>Start your journey with ISMERS</p>
            </div>

            <!-- Step Indicator (2 Steps) -->
            <div class="step-indicator">
                <!-- Step 1: User Details -->
                <div class="step-circle-wrapper">
                    <div class="step-circle active" id="step1Circle">
                        <span class="number">1</span>
                        <span class="checkmark">
                            <svg width="18" height="18" viewBox="0 0 24 24" stroke="white" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        </span>
                    </div>
                    <span class="step-label">User Details</span>
                </div>

                <div class="step-line" id="line1"></div>

                <!-- Step 2: Area of Interest -->
                <div class="step-circle-wrapper">
                    <div class="step-circle" id="step2Circle">
                        <span class="number">2</span>
                        <span class="checkmark">
                            <svg width="18" height="18" viewBox="0 0 24 24" stroke="white" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        </span>
                    </div>
                    <span class="step-label">Area of Interest</span>
                </div>
            </div>

            <!-- ===== STEP 1: USER DETAILS ===== -->
            <div class="form-section" id="step1">
                <div class="section-title">Personal Information</div>
                <div class="section-subtitle">Please provide your basic details to get started.</div>

                <form id="registerForm" method="POST" action="">
                    <!-- Name Row: First + Last + Middle Initial -->
                    <div class="form-row-3">
                        <div class="form-group">
                            <label>First Name <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <span class="input-icon">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                </span>
                                <input type="text" id="firstName" name="firstName" placeholder="John"
                                       value="<?php echo htmlspecialchars($formData['firstName'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Last Name <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <span class="input-icon">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                </span>
                                <input type="text" id="lastName" name="lastName" placeholder="Doe"
                                       value="<?php echo htmlspecialchars($formData['lastName'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Middle Initial</label>
                            <div class="input-wrapper">
                                <span class="input-icon">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                </span>
                                <input type="text" id="middleInitial" name="middleInitial" placeholder="M"
                                       maxlength="1" style="text-transform:uppercase;"
                                       value="<?php echo htmlspecialchars($formData['middleInitial'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Suffix -->
                    <div class="form-group" style="max-width:200px;">
                        <label>Suffix</label>
                        <select id="suffix" name="suffix">
                            <option value="">None</option>
                            <option value="Jr." <?php echo (isset($formData['suffix']) && $formData['suffix'] == 'Jr.') ? 'selected' : ''; ?>>Jr.</option>
                            <option value="Sr." <?php echo (isset($formData['suffix']) && $formData['suffix'] == 'Sr.') ? 'selected' : ''; ?>>Sr.</option>
                            <option value="II" <?php echo (isset($formData['suffix']) && $formData['suffix'] == 'II') ? 'selected' : ''; ?>>II</option>
                            <option value="III" <?php echo (isset($formData['suffix']) && $formData['suffix'] == 'III') ? 'selected' : ''; ?>>III</option>
                            <option value="IV" <?php echo (isset($formData['suffix']) && $formData['suffix'] == 'IV') ? 'selected' : ''; ?>>IV</option>
                        </select>
                    </div>

                    <!-- Email -->
                    <div class="form-group">
                        <label>Email Address <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <span class="input-icon">
                                <svg viewBox="0 0 24 24">
                                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                                    <polyline points="22,6 12,13 2,6"/>
                                </svg>
                            </span>
                            <input type="email" id="email" name="email" placeholder="you@example.com"
                                   value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <!-- Password with Strength Meter -->
                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <span class="input-icon">
                                <svg viewBox="0 0 24 24">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                                </svg>
                            </span>
                            <input type="password" id="password" name="password" placeholder="Min 8 characters" required>
                            <button type="button" class="toggle-password" id="togglePassword" aria-label="Toggle password visibility">
                                <svg id="eyeIcon" viewBox="0 0 24 24">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>

                        <!-- Strength Meter -->
                        <div class="password-strength" id="strengthMeter">
                            <div class="strength-bar">
                                <span class="bar-segment" id="seg1"></span>
                                <span class="bar-segment" id="seg2"></span>
                                <span class="bar-segment" id="seg3"></span>
                                <span class="bar-segment" id="seg4"></span>
                            </div>
                            <span class="strength-text" id="strengthText">Enter a password</span>
                        </div>

                        <!-- Requirements -->
                        <div class="password-requirements" id="requirements">
                            <span class="req" id="reqLength">
                                <span class="req-icon unmet">✕</span>
                                <span class="req-text unmet">8-16 characters</span>
                            </span>
                            <span class="req" id="reqUpper">
                                <span class="req-icon unmet">✕</span>
                                <span class="req-text unmet">Uppercase letter</span>
                            </span>
                            <span class="req" id="reqLower">
                                <span class="req-icon unmet">✕</span>
                                <span class="req-text unmet">Lowercase letter</span>
                            </span>
                            <span class="req" id="reqNumber">
                                <span class="req-icon unmet">✕</span>
                                <span class="req-text unmet">Number</span>
                            </span>
                            <span class="req" id="reqSpecial">
                                <span class="req-icon unmet">✕</span>
                                <span class="req-text unmet">Special character (!@#$%^&*)</span>
                            </span>
                        </div>
                    </div>

                    <!-- Gender + Birthday Row -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Gender <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <span class="input-icon">
                                    <svg viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="4"/>
                                        <path d="M12 2v4"/>
                                        <path d="M12 18v4"/>
                                        <path d="M4.93 4.93l2.83 2.83"/>
                                        <path d="M16.24 16.24l2.83 2.83"/>
                                        <path d="M2 12h4"/>
                                        <path d="M18 12h4"/>
                                        <path d="M4.93 19.07l2.83-2.83"/>
                                        <path d="M16.24 7.76l2.83-2.83"/>
                                    </svg>
                                </span>
                                <select id="gender" name="gender" required>
                                    <option value="">Select gender</option>
                                    <option value="Male" <?php echo (isset($formData['gender']) && $formData['gender'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo (isset($formData['gender']) && $formData['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo (isset($formData['gender']) && $formData['gender'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                    <option value="Prefer not to say" <?php echo (isset($formData['gender']) && $formData['gender'] == 'Prefer not to say') ? 'selected' : ''; ?>>Prefer not to say</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Birthday <span class="required">*</span></label>
                            <div class="birthday-group">
                                <div class="birthday-select">
                                    <select id="birthMonth" name="birthMonth" required>
                                        <option value="">Month</option>
                                        <option value="1" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '1') ? 'selected' : ''; ?>>January</option>
                                        <option value="2" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '2') ? 'selected' : ''; ?>>February</option>
                                        <option value="3" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '3') ? 'selected' : ''; ?>>March</option>
                                        <option value="4" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '4') ? 'selected' : ''; ?>>April</option>
                                        <option value="5" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '5') ? 'selected' : ''; ?>>May</option>
                                        <option value="6" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '6') ? 'selected' : ''; ?>>June</option>
                                        <option value="7" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '7') ? 'selected' : ''; ?>>July</option>
                                        <option value="8" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '8') ? 'selected' : ''; ?>>August</option>
                                        <option value="9" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '9') ? 'selected' : ''; ?>>September</option>
                                        <option value="10" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '10') ? 'selected' : ''; ?>>October</option>
                                        <option value="11" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '11') ? 'selected' : ''; ?>>November</option>
                                        <option value="12" <?php echo (isset($formData['birthMonth']) && $formData['birthMonth'] == '12') ? 'selected' : ''; ?>>December</option>
                                    </select>
                                </div>
                                <div class="birthday-select">
                                    <select id="birthDay" name="birthDay" required>
                                        <option value="">Day</option>
                                        <!-- Populated by JavaScript -->
                                    </select>
                                </div>
                                <div class="birthday-select">
                                    <select id="birthYear" name="birthYear" required>
                                        <option value="">Year</option>
                                        <!-- Populated by JavaScript -->
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Place of Birth -->
                    <div class="form-group">
                        <label>Place of Birth <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <span class="input-icon">
                                <svg viewBox="0 0 24 24">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
                                    <circle cx="12" cy="10" r="3"/>
                                </svg>
                            </span>
                            <input type="text" id="placeOfBirth" name="placeOfBirth" placeholder="City, Province, Country"
                                   value="<?php echo htmlspecialchars($formData['placeOfBirth'] ?? ''); ?>" required>
                        </div>
                    </div>

                    <!-- Region -->
                    <div class="form-group">
                        <label>Region <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <span class="input-icon">
                                <svg viewBox="0 0 24 24">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="2" y1="12" x2="22" y2="12"/>
                                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                                </svg>
                            </span>
                            <select id="region" name="region" required>
                                <option value="">Select your region</option>
                            </select>
                        </div>
                    </div>

                    <!-- City -->
                    <div class="form-group">
                        <label>City / Municipality <span class="required">*</span></label>
                        <div class="input-wrapper">
                            <span class="input-icon">
                                <svg viewBox="0 0 24 24">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/>
                                    <circle cx="12" cy="10" r="3"/>
                                </svg>
                            </span>
                            <select id="city" name="city" required disabled>
                                <option value="">Select a region first</option>
                            </select>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="../../index.php" class="btn-back">
                            <svg viewBox="0 0 24 24">
                                <line x1="19" y1="12" x2="5" y2="12"/>
                                <polyline points="12 19 5 12 12 5"/>
                            </svg>
                            Cancel
                        </a>
                        <button type="submit" class="btn btn-primary btn-large" id="submitBtn">
                            Create Account
                            <svg width="18" height="18" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"/>
                                <polyline points="12 5 19 12 12 19"/>
                            </svg>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Sign In Link -->
            <div class="signin-link">
                Already have an account? <a href="../../login.php">Sign In</a>
            </div>

        </div>
    </div>

    <!-- ===== MODAL ===== -->
    <div class="modal-overlay" id="modalOverlay">
        <div class="modal" id="modalContent">
            <!-- Dynamic content -->
        </div>
    </div>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        // =============================================
        // 1. PHILIPPINES REGIONS & CITIES DATA
        // =============================================
        const philippinesData = {
            "NCR - National Capital Region": [
                "Caloocan", "Las Piñas", "Makati", "Malabon", "Mandaluyong", "Manila",
                "Marikina", "Muntinlupa", "Navotas", "Parañaque", "Pasay", "Pasig",
                "Quezon City", "San Juan", "Taguig", "Valenzuela", "Pateros"
            ],
            "Region I - Ilocos Region": [
                "Dagupan", "Laoag", "San Carlos", "Urdaneta", "Vigan", "Alaminos",
                "Batac", "Candon", "San Fernando", "Bangui", "Paoay", "Pasuquin"
            ],
            "Region II - Cagayan Valley": [
                "Tuguegarao", "Santiago", "Cauayan", "Ilagan", "Bayombong", "Basco",
                "Aparri", "Gonzaga", "Sanchez-Mira", "Solana", "Tuao"
            ],
            "Region III - Central Luzon": [
                "Angeles", "San Fernando", "Tarlac", "Olongapo", "Balanga", "Mabalacat",
                "Malolos", "Meycauayan", "San Jose", "Cabanatuan", "Gapan", "Palayan",
                "San Jose Del Monte", "Capas", "Concepcion", "Guimba", "Moncada", "Paniqui"
            ],
            "Region IV-A - CALABARZON": [
                "Antipolo", "Bacoor", "Calamba", "Dasmariñas", "Imus", "Laguna",
                "Lucena", "San Pablo", "Santa Rosa", "Tanauan", "Tayabas", "Batangas",
                "Cavite", "Lipa", "Los Baños", "Biñan", "Cabuyao", "Carmona", "General Trias",
                "Silang", "Tagaytay", "Trece Martires"
            ],
            "Region IV-B - MIMAROPA": [
                "Calapan", "Puerto Princesa", "Roxas", "Boac", "Romblon", "San Jose",
                "Mamburao", "Sablayan", "Santa Cruz", "Victoria", "Gasan", "Mogpog"
            ],
            "Region V - Bicol Region": [
                "Legazpi", "Naga", "Tabaco", "Iriga", "Ligao", "Masbate",
                "Sorsogon", "Virac", "Pili", "Daet", "Labo", "San Jose",
                "Buhi", "Guinobatan", "Oas", "Polangui", "Gubat", "Matnog"
            ],
            "Region VI - Western Visayas": [
                "Iloilo", "Bacolod", "Roxas", "San Jose", "Kalibo", "Jordan",
                "Passi", "Sagay", "Silay", "Talay", "Bago", "Cadiz",
                "Escalante", "Himamaylan", "Kabankalan", "La Carlota", "Victorias"
            ],
            "Region VII - Central Visayas": [
                "Cebu", "Talisay", "Danao", "Lapu-Lapu", "Mandaue", "Bogo",
                "Carcar", "Toledo", "Dumaguete", "Bais", "Bayawan", "Canlaon",
                "Tagbilaran", "Carmen", "Lila", "Loay", "Loboc", "Sevilla"
            ],
            "Region VIII - Eastern Visayas": [
                "Tacloban", "Ormoc", "Baybay", "Catbalogan", "Calbayog", "Borongan",
                "Maasin", "Naval", "Allen", "Catarman", "Laoang", "Palapag",
                "Guiuan", "Hernani", "Llorente", "Mercedes", "Salcedo"
            ],
            "Region IX - Zamboanga Peninsula": [
                "Zamboanga", "Pagadian", "Isabela", "Dipolog", "Dapitan", "Molave",
                "Titay", "Aurora", "Labangan", "Mahayag", "Ramon Magsaysay"
            ],
            "Region X - Northern Mindanao": [
                "Cagayan de Oro", "Iligan", "Butuan", "Gingoog", "Malaybalay", "Valencia",
                "Ozamiz", "Oroquieta", "Tangub", "El Salvador", "Kapatagan", "Lala",
                "Tubod", "Manolo Fortich", "Libona", "Baungon", "Talakag"
            ],
            "Region XI - Davao Region": [
                "Davao", "Digos", "Tagum", "Panabo", "Mati", "Samal",
                "Malita", "Lupon", "Bansalan", "Hagonoy", "Magsaysay", "Padada",
                "Santa Cruz", "Sulop", "Asuncion", "Kapalong", "New Corella"
            ],
            "Region XII - SOCCSKSARGEN": [
                "General Santos", "Cotabato", "Koronadal", "Tacurong", "Kidapawan", "Midsayap",
                "Surallah", "Tupi", "Polomolok", "Alabel", "Mati", "M'lang"
            ],
            "Region XIII - Caraga": [
                "Butuan", "Surigao", "Tandag", "Bislig", "Bayugan", "Cabadbaran",
                "Lianga", "Barobo", "Hinatuan", "Carrascal", "Cantilan", "Madrid"
            ],
            "ARMM - Bangsamoro Autonomous Region": [
                "Cotabato", "Marawi", "Jolo", "Lamitan", "Bongao", "Isabela",
                "Mati", "Maluso", "Sumisip", "Tipo-Tipo", "Siasi", "Patikul"
            ],
            "Cordillera Administrative Region": [
                "Baguio", "Tabuk", "Bontoc", "Lagawe", "La Trinidad", "Itogon",
                "Mankayan", "Sagada", "Banaue", "Mayoyao", "Hungduan", "Lubuagan"
            ]
        };

        // =============================================
        // 2. POPULATE REGION DROPDOWN
        // =============================================
        const regionSelect = document.getElementById('region');
        const citySelect = document.getElementById('city');

        Object.keys(philippinesData).forEach(region => {
            const option = document.createElement('option');
            option.value = region;
            option.textContent = region;
            regionSelect.appendChild(option);
        });

        // Set selected region if form data exists
        <?php if (isset($formData['region']) && !empty($formData['region'])): ?>
        regionSelect.value = "<?php echo htmlspecialchars($formData['region']); ?>";
        // Trigger change to populate cities
        regionSelect.dispatchEvent(new Event('change'));
        <?php endif; ?>

        // =============================================
        // 3. REGION → CITY DYNAMIC UPDATE
        // =============================================
        regionSelect.addEventListener('change', function() {
            const selectedRegion = this.value;
            citySelect.innerHTML = '<option value="">Select your city</option>';
            
            if (selectedRegion && philippinesData[selectedRegion]) {
                citySelect.disabled = false;
                philippinesData[selectedRegion].sort().forEach(city => {
                    const option = document.createElement('option');
                    option.value = city;
                    option.textContent = city;
                    citySelect.appendChild(option);
                });
                
                // Set selected city if form data exists
                <?php if (isset($formData['city']) && !empty($formData['city'])): ?>
                citySelect.value = "<?php echo htmlspecialchars($formData['city']); ?>";
                <?php endif; ?>
            } else {
                citySelect.disabled = true;
                citySelect.innerHTML = '<option value="">Select a region first</option>';
            }
        });

        // =============================================
        // 4. BIRTHDAY DROPDOWNS - POPULATE DAYS & YEARS
        // =============================================
        const birthMonth = document.getElementById('birthMonth');
        const birthDay = document.getElementById('birthDay');
        const birthYear = document.getElementById('birthYear');

        // Populate days (1-31)
        for (let d = 1; d <= 31; d++) {
            const option = document.createElement('option');
            option.value = d;
            option.textContent = d;
            birthDay.appendChild(option);
        }

        // Populate years (1900 - current year)
        const currentYear = new Date().getFullYear();
        for (let y = currentYear; y >= 1900; y--) {
            const option = document.createElement('option');
            option.value = y;
            option.textContent = y;
            birthYear.appendChild(option);
        }

        // Set selected birthday values if form data exists
        <?php if (isset($formData['birthMonth']) && !empty($formData['birthMonth'])): ?>
        birthMonth.value = "<?php echo htmlspecialchars($formData['birthMonth']); ?>";
        <?php endif; ?>
        
        <?php if (isset($formData['birthDay']) && !empty($formData['birthDay'])): ?>
        birthDay.value = "<?php echo htmlspecialchars($formData['birthDay']); ?>";
        <?php endif; ?>
        
        <?php if (isset($formData['birthYear']) && !empty($formData['birthYear'])): ?>
        birthYear.value = "<?php echo htmlspecialchars($formData['birthYear']); ?>";
        <?php endif; ?>

        // =============================================
        // 5. PASSWORD STRENGTH METER
        // =============================================
        const passwordInput = document.getElementById('password');
        const seg1 = document.getElementById('seg1');
        const seg2 = document.getElementById('seg2');
        const seg3 = document.getElementById('seg3');
        const seg4 = document.getElementById('seg4');
        const strengthText = document.getElementById('strengthText');

        const reqLength = document.getElementById('reqLength');
        const reqUpper = document.getElementById('reqUpper');
        const reqLower = document.getElementById('reqLower');
        const reqNumber = document.getElementById('reqNumber');
        const reqSpecial = document.getElementById('reqSpecial');

        function checkPasswordStrength(password) {
            const checks = {
                length: password.length >= 8 && password.length <= 16,
                upper: /[A-Z]/.test(password),
                lower: /[a-z]/.test(password),
                number: /\d/.test(password),
                special: /[!@#$%^&*(),.?":{}|<>]/.test(password)
            };

            updateRequirement(reqLength, checks.length);
            updateRequirement(reqUpper, checks.upper);
            updateRequirement(reqLower, checks.lower);
            updateRequirement(reqNumber, checks.number);
            updateRequirement(reqSpecial, checks.special);

            const score = Object.values(checks).filter(Boolean).length;
            
            const segments = [seg1, seg2, seg3, seg4];
            segments.forEach((seg, index) => {
                seg.className = 'bar-segment';
                if (index < score) {
                    if (score <= 2) seg.classList.add('weak');
                    else if (score === 3) seg.classList.add('fair');
                    else if (score === 4) seg.classList.add('good');
                    else if (score === 5) seg.classList.add('strong');
                }
            });

            const strengthMap = {
                0: { text: 'Enter a password', class: '' },
                1: { text: 'Weak — add more variety', class: 'weak' },
                2: { text: 'Weak — add more variety', class: 'weak' },
                3: { text: 'Fair — almost there', class: 'fair' },
                4: { text: 'Good — strong password', class: 'good' },
                5: { text: 'Strong — excellent!', class: 'strong' }
            };

            const result = strengthMap[score] || strengthMap[0];
            strengthText.textContent = result.text;
            strengthText.className = 'strength-text ' + result.class;

            return checks.length && checks.upper && checks.lower && checks.number && checks.special;
        }

        function updateRequirement(element, met) {
            const icon = element.querySelector('.req-icon');
            const text = element.querySelector('.req-text');
            
            if (met) {
                icon.textContent = '✓';
                icon.className = 'req-icon met';
                text.className = 'req-text met';
            } else {
                icon.textContent = '✕';
                icon.className = 'req-icon unmet';
                text.className = 'req-text unmet';
            }
        }

        passwordInput.addEventListener('input', function() {
            checkPasswordStrength(this.value);
        });

        // =============================================
        // 6. PASSWORD TOGGLE VISIBILITY
        // =============================================
        const togglePassword = document.getElementById('togglePassword');
        const eyeIcon = document.getElementById('eyeIcon');
        let isPasswordVisible = false;

        togglePassword.addEventListener('click', function() {
            isPasswordVisible = !isPasswordVisible;
            passwordInput.setAttribute('type', isPasswordVisible ? 'text' : 'password');
            
            if (isPasswordVisible) {
                eyeIcon.innerHTML = `
                    <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94"/>
                    <path d="M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                `;
            } else {
                eyeIcon.innerHTML = `
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                `;
            }
        });

        // =============================================
        // 7. MODAL SYSTEM
        // =============================================
        const modalOverlay = document.getElementById('modalOverlay');
        const modalContent = document.getElementById('modalContent');

        function showModal(type, title, message, buttonText, buttonAction, errorList = null) {
            let iconSvg = '';
            let iconClass = '';
            let buttonClass = '';

            if (type === 'error') {
                iconSvg = `
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                `;
                iconClass = 'error';
                buttonClass = 'primary';
            } else if (type === 'success') {
                iconSvg = `
                    <svg viewBox="0 0 24 24">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                `;
                iconClass = 'success';
                buttonClass = 'success';
            }

            let errorHtml = '';
            if (errorList && errorList.length > 0) {
                errorHtml = `<ul class="error-list">`;
                errorList.forEach(err => {
                    errorHtml += `<li><span class="bullet">•</span> ${err}</li>`;
                });
                errorHtml += `</ul>`;
            }

            modalContent.innerHTML = `
                <div class="modal-icon ${iconClass}">${iconSvg}</div>
                <h2>${title}</h2>
                ${errorHtml}
                <p>${message}</p>
                <button class="btn-modal ${buttonClass}" id="modalBtn">${buttonText}</button>
            `;

            modalOverlay.classList.add('active');

            document.getElementById('modalBtn').addEventListener('click', function() {
                if (buttonAction) {
                    buttonAction();
                }
                modalOverlay.classList.remove('active');
            });
        }

        // Close modal on overlay click
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modalOverlay.classList.contains('active')) {
                modalOverlay.classList.remove('active');
            }
        });

        // =============================================
        // 8. CHECK FOR SERVER-SIDE ERRORS
        // =============================================
        <?php if (!empty($error)): ?>
        const errorMessages = "<?php echo htmlspecialchars($error); ?>".split('|');
        showModal(
            'error',
            'Registration Error',
            'Please fix the following issues to continue.',
            'Try Again',
            null,
            errorMessages
        );
        <?php endif; ?>

        // =============================================
        // 9. FORM VALIDATION WITH MODAL
        // =============================================
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const form = this;
            const inputs = form.querySelectorAll('input[required], select[required]');
            let isValid = true;
            let firstInvalid = null;
            const errorMessages = [];

            // Check required fields
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    input.style.borderColor = '#dc2626';
                    if (!firstInvalid) firstInvalid = input;
                    const label = input.closest('.form-group').querySelector('label');
                    const fieldName = label ? label.textContent.replace('*', '').trim() : 'Field';
                    errorMessages.push(fieldName + ' is required.');
                } else {
                    input.style.borderColor = '';
                }
            });

            // Check email format
            const emailInput = document.getElementById('email');
            if (emailInput.value.trim() && !isValidEmail(emailInput.value)) {
                isValid = false;
                emailInput.style.borderColor = '#dc2626';
                if (!firstInvalid) firstInvalid = emailInput;
                errorMessages.push('Please enter a valid email address.');
            }

            // Check password strength
            const password = passwordInput.value;
            const isStrong = checkPasswordStrength(password);
            if (!isStrong) {
                isValid = false;
                passwordInput.style.borderColor = '#dc2626';
                if (!firstInvalid) firstInvalid = passwordInput;
                errorMessages.push('Password must meet all requirements.');
            }

            // Check birthday (must be at least 18 years old)
            const month = parseInt(birthMonth.value);
            const day = parseInt(birthDay.value);
            const year = parseInt(birthYear.value);

            if (month && day && year) {
                const birthDate = new Date(year, month - 1, day);
                const today = new Date();
                let age = today.getFullYear() - birthDate.getFullYear();
                const m = today.getMonth() - birthDate.getMonth();
                if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }
                if (age < 18) {
                    isValid = false;
                    birthMonth.style.borderColor = '#dc2626';
                    birthDay.style.borderColor = '#dc2626';
                    birthYear.style.borderColor = '#dc2626';
                    if (!firstInvalid) firstInvalid = birthMonth;
                    errorMessages.push('You must be at least 18 years old.');
                }
            } else {
                isValid = false;
                birthMonth.style.borderColor = '#dc2626';
                birthDay.style.borderColor = '#dc2626';
                birthYear.style.borderColor = '#dc2626';
                if (!firstInvalid) firstInvalid = birthMonth;
                errorMessages.push('Please enter your complete birthday.');
            }

            // Check region selection
            if (!regionSelect.value) {
                isValid = false;
                regionSelect.style.borderColor = '#dc2626';
                if (!firstInvalid) firstInvalid = regionSelect;
                errorMessages.push('Please select your region.');
            }

            // Check city selection
            if (!citySelect.value) {
                isValid = false;
                citySelect.style.borderColor = '#dc2626';
                if (!firstInvalid) firstInvalid = citySelect;
                errorMessages.push('Please select your city.');
            }

            if (!isValid) {
                e.preventDefault();
                if (firstInvalid) {
                    firstInvalid.focus();
                }
                showModal(
                    'error',
                    'Please fix the following errors:',
                    'Review the fields highlighted in red.',
                    'Okay',
                    null,
                    errorMessages
                );
                return;
            }

            // If valid, show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span>Creating Account...</span> <span>⏳</span>';
        });

        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        // Clear error styling on input
        document.querySelectorAll('input, select').forEach(el => {
            el.addEventListener('input', function() {
                this.style.borderColor = '';
            });
            el.addEventListener('change', function() {
                this.style.borderColor = '';
            });
        });

        console.log('📝 Registration Page loaded successfully!');
    </script>

</body>
</html>