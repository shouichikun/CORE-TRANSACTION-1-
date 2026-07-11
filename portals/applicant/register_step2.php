<?php
// portals/applicant/register_step2.php - Area of Interest
session_start();

// Include configuration file
require_once '../../app/config.php';

// Check if user is logged in and has completed step 1
if (!isset($_SESSION['user_id']) || !isset($_SESSION['applicant_id'])) {
    // Redirect to step 1 if not logged in
    header('Location: register.php');
    exit;
}

// Get applicant ID from session
$applicantId = $_SESSION['applicant_id'];
$userId = $_SESSION['user_id'];

// Initialize variables
$error = '';
$success = false;
$selectedInterests = [];

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get selected interests
    $selectedInterests = $_POST['interests'] ?? [];
    
    // Validate: at least 2 interests required
    if (count($selectedInterests) < 2) {
        $error = 'Please select at least 2 areas of interest.';
    } else {
        // Save interests to database
        $saved = saveApplicantInterests($applicantId, $selectedInterests);
        
        if ($saved) {
            // Log the activity
            logActivity($userId, 'Registration Complete', 'applicant', $applicantId, 'User completed registration with ' . count($selectedInterests) . ' interests');
            
            // Set success flag
            $_SESSION['registration_complete'] = true;
            $_SESSION['interests'] = $selectedInterests;
            
            // Set success to true (show modal)
            $success = true;
        } else {
            $error = 'Failed to save your interests. Please try again.';
        }
    }
}

// If there's an error, store it for display
if (!empty($error)) {
    $_SESSION['register_error'] = $error;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Area of Interest - ISMERS</title>
    
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

        .btn-success {
            background: #22c55e;
            color: white;
        }

        .btn-success:hover {
            background: #16a34a;
            transform: translateY(-2px);
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

        /* ===== STEP INDICATOR (2 STEPS) ===== */
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

        /* ===== INTEREST GRID ===== */
        .interest-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 24px;
        }

        .interest-option {
            position: relative;
            cursor: pointer;
        }

        .interest-option input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .interest-option .interest-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border: 2px solid var(--gray-border);
            border-radius: 12px;
            background: var(--gray-light);
            transition: var(--transition);
            cursor: pointer;
        }

        .interest-option .interest-card:hover {
            border-color: var(--primary-light);
            background: rgba(74, 144, 217, 0.04);
        }

        .interest-option input:checked + .interest-card {
            border-color: var(--primary-light);
            background: rgba(74, 144, 217, 0.08);
            box-shadow: 0 0 0 4px rgba(74, 144, 217, 0.1);
        }

        .interest-option .interest-card .interest-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .interest-option .interest-card .interest-icon svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .interest-option .interest-card .interest-icon.blue {
            background: rgba(74, 144, 217, 0.12);
            color: var(--primary-light);
        }

        .interest-option .interest-card .interest-icon.green {
            background: rgba(34, 197, 94, 0.12);
            color: #16a34a;
        }

        .interest-option .interest-card .interest-icon.purple {
            background: rgba(124, 58, 237, 0.12);
            color: #7c3aed;
        }

        .interest-option .interest-card .interest-icon.orange {
            background: rgba(245, 158, 11, 0.12);
            color: #d97706;
        }

        .interest-option .interest-card .interest-icon.pink {
            background: rgba(219, 39, 119, 0.12);
            color: #db2777;
        }

        .interest-option .interest-card .interest-icon.teal {
            background: rgba(8, 145, 178, 0.12);
            color: #0891b2;
        }

        .interest-option .interest-card .interest-icon.indigo {
            background: rgba(79, 70, 229, 0.12);
            color: #4f46e5;
        }

        .interest-option .interest-card .interest-icon.red {
            background: rgba(220, 38, 38, 0.12);
            color: #dc2626;
        }

        .interest-option .interest-card .interest-info {
            flex: 1;
        }

        .interest-option .interest-card .interest-info h4 {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .interest-option .interest-card .interest-info p {
            font-size: 12px;
            color: var(--text-gray);
            margin-top: 1px;
        }

        .interest-option .interest-card .check-indicator {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 2px solid var(--gray-border);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            flex-shrink: 0;
        }

        .interest-option input:checked + .interest-card .check-indicator {
            background: var(--primary-gradient);
            border-color: var(--primary-light);
            color: white;
        }

        .interest-option input:checked + .interest-card .check-indicator::after {
            content: '✓';
            font-size: 12px;
            font-weight: 700;
        }

        /* ===== SELECTED COUNT ===== */
        .selection-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: var(--gray-light);
            border-radius: 10px;
            margin-bottom: 24px;
            border: 1px solid var(--gray-border);
        }

        .selection-info .count {
            font-size: 14px;
            color: var(--text-gray);
        }

        .selection-info .count strong {
            color: var(--primary-dark);
        }

        .selection-info .min-selection {
            font-size: 13px;
            color: var(--text-gray);
        }

        .selection-info .min-selection .required-badge {
            display: inline-block;
            background: #fef3c7;
            color: #d97706;
            padding: 2px 10px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 11px;
        }

        /* ===== FORM ACTIONS ===== */
        .form-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
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
            width: 72px;
            height: 72px;
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
            width: 36px;
            height: 36px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .modal h2 {
            font-size: 24px;
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
            padding: 14px 48px;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 10px;
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

        .modal .btn-modal .btn-icon {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
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

            .interest-grid {
                grid-template-columns: 1fr;
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
                flex-direction: column-reverse;
                gap: 12px;
            }

            .form-actions .btn-back {
                order: 2;
            }

            .selection-info {
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }

            .modal {
                padding: 32px 24px;
            }
        }

        @media (max-width: 480px) {
            .register-card {
                padding: 24px 16px 20px;
            }

            .step-indicator .step-line {
                width: 30px;
            }

            .step-indicator .step-circle {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }

            .interest-option .interest-card {
                padding: 12px 14px;
            }

            .modal .btn-modal {
                padding: 12px 32px;
                font-size: 14px;
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
                <h1>Select Your Interests</h1>
                <p>Choose the areas you're passionate about</p>
            </div>

            <!-- Step Indicator (2 Steps) -->
            <div class="step-indicator">
                <!-- Step 1: User Details -->
                <div class="step-circle-wrapper">
                    <div class="step-circle done" id="step1Circle">
                        <span class="number">1</span>
                        <span class="checkmark">
                            <svg width="18" height="18" viewBox="0 0 24 24" stroke="white" stroke-width="3" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        </span>
                    </div>
                    <span class="step-label">User Details</span>
                </div>

                <div class="step-line done" id="line1"></div>

                <!-- Step 2: Area of Interest -->
                <div class="step-circle-wrapper">
                    <div class="step-circle active" id="step2Circle">
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

            <!-- ===== STEP 2: AREA OF INTEREST ===== -->
            <div class="form-section" id="step2">
                <div class="section-title">What are you interested in?</div>
                <div class="section-subtitle">Select at least 2 areas that match your skills and passion.</div>

                <form id="interestForm" method="POST" action="">

                    <!-- Selection Info -->
                    <div class="selection-info">
                        <span class="count">
                            Selected: <strong id="selectedCount">0</strong> of minimum 2
                        </span>
                        <span class="min-selection">
                            <span class="required-badge">Required</span> Choose at least 2
                        </span>
                    </div>

                    <!-- Interest Grid -->
                    <div class="interest-grid" id="interestGrid">

                        <!-- Software Development -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="Software Development" data-group="tech">
                            <span class="interest-card">
                                <span class="interest-icon blue">
                                    <svg viewBox="0 0 24 24">
                                        <polyline points="16 18 22 12 16 6"/>
                                        <polyline points="8 6 2 12 8 18"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>Software Development</h4>
                                    <p>Build apps, websites & systems</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                        <!-- Data Science & Analytics -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="Data Science & Analytics" data-group="tech">
                            <span class="interest-card">
                                <span class="interest-icon purple">
                                    <svg viewBox="0 0 24 24">
                                        <line x1="18" y1="20" x2="18" y2="10"/>
                                        <line x1="12" y1="20" x2="12" y2="4"/>
                                        <line x1="6" y1="20" x2="6" y2="14"/>
                                        <rect x="2" y="2" width="20" height="20" rx="2" ry="2"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>Data Science & Analytics</h4>
                                    <p>Analyze data & generate insights</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                        <!-- UI/UX Design -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="UI/UX Design" data-group="design">
                            <span class="interest-card">
                                <span class="interest-icon pink">
                                    <svg viewBox="0 0 24 24">
                                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                        <line x1="8" y1="21" x2="16" y2="21"/>
                                        <line x1="12" y1="17" x2="12" y2="21"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>UI/UX Design</h4>
                                    <p>Design user-friendly interfaces</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                        <!-- Graphic Design -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="Graphic Design" data-group="design">
                            <span class="interest-card">
                                <span class="interest-icon orange">
                                    <svg viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="10"/>
                                        <circle cx="12" cy="12" r="6"/>
                                        <circle cx="12" cy="12" r="2"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>Graphic Design</h4>
                                    <p>Create visual content & branding</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                        <!-- Marketing & Sales -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="Marketing & Sales" data-group="business">
                            <span class="interest-card">
                                <span class="interest-icon green">
                                    <svg viewBox="0 0 24 24">
                                        <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
                                        <polyline points="17 6 23 6 23 12"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>Marketing & Sales</h4>
                                    <p>Drive growth & customer engagement</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                        <!-- Finance & Accounting -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="Finance & Accounting" data-group="business">
                            <span class="interest-card">
                                <span class="interest-icon teal">
                                    <svg viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 6v6l4 2"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>Finance & Accounting</h4>
                                    <p>Manage finances & budgets</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                        <!-- Human Resources -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="Human Resources" data-group="business">
                            <span class="interest-card">
                                <span class="interest-icon indigo">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                        <circle cx="9" cy="7" r="4"/>
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>Human Resources</h4>
                                    <p>Talent acquisition & management</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                        <!-- Customer Service -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="Customer Service" data-group="support">
                            <span class="interest-card">
                                <span class="interest-icon red">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>Customer Service</h4>
                                    <p>Support & client relations</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                        <!-- Healthcare -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="Healthcare" data-group="health">
                            <span class="interest-card">
                                <span class="interest-icon blue">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M22 12h-4l-3 9-4-18-3 9H2"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>Healthcare</h4>
                                    <p>Medical & health services</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                        <!-- Education -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="Education & Training" data-group="education">
                            <span class="interest-card">
                                <span class="interest-icon orange">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>Education & Training</h4>
                                    <p>Teach & develop training programs</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                        <!-- Engineering -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="Engineering" data-group="engineering">
                            <span class="interest-card">
                                <span class="interest-icon purple">
                                    <svg viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="3"/>
                                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>Engineering</h4>
                                    <p>Design & build solutions</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                        <!-- Legal -->
                        <label class="interest-option">
                            <input type="checkbox" name="interests[]" value="Legal" data-group="legal">
                            <span class="interest-card">
                                <span class="interest-icon indigo">
                                    <svg viewBox="0 0 24 24">
                                        <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                                        <path d="M2 17l10 5 10-5"/>
                                        <path d="M2 12l10 5 10-5"/>
                                    </svg>
                                </span>
                                <span class="interest-info">
                                    <h4>Legal</h4>
                                    <p>Legal advice & compliance</p>
                                </span>
                                <span class="check-indicator"></span>
                            </span>
                        </label>

                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <a href="register.php" class="btn-back">
                            <svg viewBox="0 0 24 24">
                                <line x1="19" y1="12" x2="5" y2="12"/>
                                <polyline points="12 19 5 12 12 5"/>
                            </svg>
                            Back
                        </a>
                        <button type="submit" class="btn btn-primary btn-large" id="submitBtn">
                            Complete Registration
                            <svg width="18" height="18" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"/>
                                <polyline points="12 5 19 12 12 19"/>
                            </svg>
                        </button>
                    </div>

                </form>
            </div>

        </div>
    </div>

    <!-- ===== SUCCESS MODAL ===== -->
    <div class="modal-overlay" id="successModal">
        <div class="modal" id="successModalContent">
            <div class="modal-icon success">
                <svg viewBox="0 0 24 24">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <h2>Registration Complete!</h2>
            <p>
                Your account has been successfully created. You can now log in to access your dashboard 
                and start exploring opportunities.
            </p>
            <a href="../../login.php" class="btn-modal success">
                <svg class="btn-icon" viewBox="0 0 24 24">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                    <polyline points="10 17 15 12 10 7"/>
                    <line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
                Go to Login
            </a>
        </div>
    </div>

    <!-- ===== ERROR MODAL ===== -->
    <div class="modal-overlay" id="errorModal">
        <div class="modal" id="errorModalContent">
            <!-- Dynamic content -->
        </div>
    </div>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        // =============================================
        // 1. SUCCESS MODAL
        // =============================================
        <?php if ($success): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const successModal = document.getElementById('successModal');
            successModal.classList.add('active');
        });
        <?php endif; ?>

        // =============================================
        // 2. ERROR MODAL
        // =============================================
        function showErrorModal(title, message, errorList = null) {
            const modalOverlay = document.getElementById('errorModal');
            const modalContent = document.getElementById('errorModalContent');

            let errorHtml = '';
            if (errorList && errorList.length > 0) {
                errorHtml = `<ul class="error-list">`;
                errorList.forEach(err => {
                    errorHtml += `<li><span class="bullet">•</span> ${err}</li>`;
                });
                errorHtml += `</ul>`;
            }

            modalContent.innerHTML = `
                <div class="modal-icon error">
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <h2>${title}</h2>
                ${errorHtml}
                <p>${message}</p>
                <button class="btn-modal primary" id="errorModalBtn">Try Again</button>
            `;

            modalOverlay.classList.add('active');

            document.getElementById('errorModalBtn').addEventListener('click', function() {
                modalOverlay.classList.remove('active');
            });
        }

        // Close modals on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });

        // =============================================
        // 3. INTEREST SELECTION TRACKING
        // =============================================
        const checkboxes = document.querySelectorAll('input[name="interests[]"]');
        const selectedCount = document.getElementById('selectedCount');
        const submitBtn = document.getElementById('submitBtn');

        function updateSelectionCount() {
            const checked = document.querySelectorAll('input[name="interests[]"]:checked');
            const count = checked.length;
            selectedCount.textContent = count;

            // Update UI feedback
            const infoBox = document.querySelector('.selection-info');
            if (count >= 2) {
                infoBox.style.borderColor = '#22c55e';
                infoBox.style.background = 'rgba(34, 197, 94, 0.05)';
            } else {
                infoBox.style.borderColor = 'var(--gray-border)';
                infoBox.style.background = 'var(--gray-light)';
            }

            // Update submit button state
            if (count >= 2) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
            } else {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                submitBtn.style.cursor = 'not-allowed';
            }
        }

        // Add event listeners to all checkboxes
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectionCount);
        });

        // =============================================
        // 4. INITIAL STATE
        // =============================================
        updateSelectionCount();

        // =============================================
        // 5. CHECK FOR SERVER-SIDE ERRORS
        // =============================================
        <?php if (!empty($error)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showErrorModal(
                'Selection Error',
                '<?php echo htmlspecialchars($error); ?>',
                ['<?php echo htmlspecialchars($error); ?>']
            );
        });
        <?php endif; ?>

        // =============================================
        // 6. FORM VALIDATION WITH MODAL
        // =============================================
        document.getElementById('interestForm').addEventListener('submit', function(e) {
            const checked = document.querySelectorAll('input[name="interests[]"]:checked');
            
            if (checked.length < 2) {
                e.preventDefault();
                showErrorModal(
                    'Selection Required',
                    'Please select at least 2 areas of interest to complete your registration.',
                    ['You must select at least 2 interests.']
                );
                return;
            }

            // If valid, show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span>Saving...</span> <span>⏳</span>';
        });

        // =============================================
        // 7. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const active = document.activeElement;
                if (active && active.closest('.interest-option')) {
                    const checkbox = active.querySelector('input[type="checkbox"]');
                    if (checkbox) {
                        checkbox.checked = !checkbox.checked;
                        updateSelectionCount();
                    }
                }
            }
        });

        console.log('📝 ISMERS Area of Interest loaded successfully!');
    </script>

</body>
</html>