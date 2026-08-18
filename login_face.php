<?php
// login_face.php - Face Authentication Login (Inside CT1 folder)
session_start();

require_once 'app/config.php';

// ✅ FIXED: Files are inside /CT1/ folder
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'applicant';
    $redirects = [
        'admin' => '/CT1/portals/admin/dashboard.php',
        'hr_manager' => '/CT1/portals/hr/dashboard.php',
        'recruiter' => '/CT1/portals/hr/dashboard.php',
        'client' => '/CT1/portals/client/dashboard.php',
        'applicant' => '/CT1/portals/applicant/dashboard.php',
        'employee' => '/CT1/portals/employee/dashboard.php',
        'supervisor' => '/CT1/portals/supervisor/dashboard.php'
    ];
    header('Location: ' . ($redirects[$role] ?? '/CT1/index.php'));
    exit;
}

$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Login - ISMERS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">

    <style>
        /* =============================================
        MATERIAL 3 DESIGN SYSTEM - FACE LOGIN
        ============================================= */
        :root {
            --bg-surface: #ffffff;
            --bg-surface-low: #f5f3ff;
            --text-on-surface: #1b1b24;
            --text-on-surface-variant: #6b7280;
            --primary: #4f46e5;
            --primary-light: #818cf8;
            --slate-200: #e2e8f0;
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            --radius-2xl: 1.5rem;
            --radius-xl: 1rem;
            --radius: 0.5rem;
            --success-color: #22c55e;
            --error-color: #dc2626;
            --warning-color: #f59e0b;
            --info-color: #3b82f6;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #f5f3ff;
            color: var(--text-on-surface);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }

        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            min-height: 100vh;
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
            padding: 1rem;
        }

        .login-card {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-xl);
            padding: 2rem;
            max-width: 480px;
            width: 100%;
            position: relative;
        }

        .login-card .login-logo {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .login-card .login-logo .logo-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 4rem;
            height: 4rem;
            border-radius: 1rem;
            background: var(--primary);
            color: white;
            font-size: 2rem;
        }

        .login-card .login-logo h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-top: 0.5rem;
        }

        .login-card .login-logo p {
            color: var(--text-on-surface-variant);
            font-size: 0.875rem;
        }

        .face-video-wrapper {
            position: relative;
            background: #1a1a2e;
            border-radius: var(--radius-xl);
            overflow: hidden;
            margin-bottom: 1rem;
            aspect-ratio: 4 / 3;
            min-height: 280px;
        }

        .face-video-wrapper video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transform: scaleX(-1); /* Mirror for natural selfie view */
        }

        .face-video-wrapper canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 2;
            transform: scaleX(-1); /* Mirror to match video */
        }

        .face-video-wrapper .face-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
            z-index: 1;
        }

        .face-video-wrapper .face-overlay .face-guide {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 200px;
            height: 200px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
        }

        .face-video-wrapper .face-overlay .face-guide .guide-dot {
            position: absolute;
            width: 8px;
            height: 8px;
            background: rgba(255, 255, 255, 0.6);
            border-radius: 50%;
        }
        .face-video-wrapper .face-overlay .face-guide .guide-dot.tl { top: -4px; left: -4px; }
        .face-video-wrapper .face-overlay .face-guide .guide-dot.tr { top: -4px; right: -4px; }
        .face-video-wrapper .face-overlay .face-guide .guide-dot.bl { bottom: -4px; left: -4px; }
        .face-video-wrapper .face-overlay .face-guide .guide-dot.br { bottom: -4px; right: -4px; }

        .face-video-wrapper .face-overlay .face-status {
            position: absolute;
            bottom: 1rem;
            left: 50%;
            transform: translateX(-50%);
            padding: 0.5rem 1.25rem;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.875rem;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            color: white;
            display: none;
            white-space: nowrap;
            z-index: 3;
        }

        .face-video-wrapper .face-overlay .face-status.show { display: block; animation: slideUp 0.3s ease; }
        .face-video-wrapper .face-overlay .face-status.success { background: rgba(34, 197, 94, 0.9); }
        .face-video-wrapper .face-overlay .face-status.failed { background: rgba(220, 38, 38, 0.9); }
        .face-video-wrapper .face-overlay .face-status.scanning { background: rgba(79, 70, 229, 0.9); }
        .face-video-wrapper .face-overlay .face-status.warning { background: rgba(245, 158, 11, 0.9); }

        @keyframes slideUp {
            from { opacity: 0; transform: translateX(-50%) translateY(10px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        .face-status-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            border-radius: var(--radius);
            font-size: 0.875rem;
            background: var(--bg-surface-low);
            margin-bottom: 1rem;
        }

        .face-status-indicator .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        .face-status-indicator .status-dot.idle { background: #9ca3af; }
        .face-status-indicator .status-dot.loading { background: var(--warning-color); animation: pulse 1s infinite; }
        .face-status-indicator .status-dot.success { background: var(--success-color); }
        .face-status-indicator .status-dot.failed { background: var(--error-color); }
        .face-status-indicator .status-dot.warning { background: var(--warning-color); }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.3); opacity: 0.6; }
        }

        .face-controls {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .face-controls .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .face-controls .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }

        .face-controls .btn-primary {
            background: var(--primary);
            color: white;
        }
        .face-controls .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.3);
        }

        .face-controls .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        .face-controls .btn-outline:hover:not(:disabled) {
            background: var(--primary);
            color: white;
        }

        .face-controls .btn .material-symbols-outlined {
            font-size: 1.125rem;
        }

        .alternative-login {
            text-align: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--slate-200);
        }

        .alternative-login a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        .alternative-login a:hover { text-decoration: underline; }

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
        .toast.success { background: var(--success-color); }
        .toast.error { background: var(--error-color); }
        .toast.info { background: var(--primary); }
        .toast.warning { background: var(--warning-color); }

        /* =============================================
           OBSTRUCTION MODAL
        ============================================= */
        .obstruction-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            padding: 1rem;
            animation: fadeIn 0.3s ease;
        }
        .obstruction-modal.active {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .obstruction-modal-content {
            background: var(--bg-surface);
            border-radius: var(--radius-2xl);
            max-width: 440px;
            width: 100%;
            padding: 2rem;
            box-shadow: var(--shadow-xl);
            animation: modalSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            max-height: 90vh;
            overflow-y: auto;
        }

        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .obstruction-modal-content .modal-icon {
            width: 4rem;
            height: 4rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2.5rem;
        }

        .obstruction-modal-content .modal-icon.warning {
            background: #fef3c7;
            color: #d97706;
        }

        .obstruction-modal-content .modal-icon.error {
            background: #fecaca;
            color: #dc2626;
        }

        .obstruction-modal-content .modal-icon.info {
            background: #dbeafe;
            color: #3b82f6;
        }

        .obstruction-modal-content .modal-icon.success {
            background: #d1fae5;
            color: #059669;
        }

        .obstruction-modal-content h2 {
            font-size: 1.25rem;
            font-weight: 700;
            text-align: center;
            margin-bottom: 0.5rem;
        }

        .obstruction-modal-content p {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            text-align: center;
            margin-bottom: 0.5rem;
            line-height: 1.7;
        }

        .obstruction-modal-content .modal-issues {
            margin: 1rem 0;
            padding: 0.75rem 1rem;
            background: var(--bg-surface-low);
            border-radius: var(--radius);
            border-left: 4px solid var(--warning-color);
        }

        .obstruction-modal-content .modal-issues .issue-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0;
            font-size: 0.8125rem;
            color: var(--text-on-surface);
        }

        .obstruction-modal-content .modal-issues .issue-item .material-symbols-outlined {
            font-size: 1rem;
            color: var(--warning-color);
        }

        .obstruction-modal-content .modal-tips {
            margin: 1rem 0;
            padding: 0.75rem 1rem;
            background: #eff6ff;
            border-radius: var(--radius);
            border-left: 4px solid var(--info-color);
        }

        .obstruction-modal-content .modal-tips .tip-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0;
            font-size: 0.8125rem;
            color: var(--text-on-surface);
        }

        .obstruction-modal-content .modal-tips .tip-item .material-symbols-outlined {
            font-size: 1rem;
            color: var(--info-color);
        }

        .obstruction-modal-content .modal-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.25rem;
            flex-wrap: wrap;
        }

        .obstruction-modal-content .modal-actions .btn {
            flex: 1;
            justify-content: center;
            min-width: 100px;
        }

        .obstruction-modal-content .modal-actions .btn-retry {
            background: var(--primary);
            color: white;
        }
        .obstruction-modal-content .modal-actions .btn-retry:hover {
            background: #4338ca;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.3);
        }

        .obstruction-modal-content .modal-actions .btn-password {
            background: transparent;
            color: var(--primary);
            border: 2px solid var(--primary);
        }
        .obstruction-modal-content .modal-actions .btn-password:hover {
            background: var(--primary);
            color: white;
        }

        .debug-toggle {
            text-align: center;
            margin-top: 0.5rem;
        }
        .debug-toggle button {
            background: none;
            border: none;
            color: var(--text-on-surface-variant);
            font-size: 0.75rem;
            cursor: pointer;
            text-decoration: underline;
        }

        .debug-panel {
            margin-top: 0.5rem;
            padding: 0.75rem;
            background: #1a1a2e;
            border-radius: var(--radius);
            font-family: monospace;
            font-size: 0.7rem;
            color: #00ff41;
            max-height: 120px;
            overflow-y: auto;
            display: none;
        }
        .debug-panel.active { display: block; }
        .debug-panel .debug-line { padding: 0.1rem 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .debug-panel .debug-line .time { color: #6b7280; margin-right: 0.5rem; }

        /* =============================================
        MODERN OPTIMISTIC PROGRESS MODAL
        ============================================= */
        .progress-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 15, 30, 0.75);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            z-index: 9998;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
            animation: fadeIn 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .progress-modal.active {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; backdrop-filter: blur(0px); }
            to { opacity: 1; backdrop-filter: blur(20px); }
        }

        .progress-modal-content {
            background: linear-gradient(145deg, #ffffff, #f8f7ff);
            border-radius: 2rem;
            max-width: 400px;
            width: 100%;
            padding: 2.5rem 2rem 2rem;
            box-shadow: 0 40px 80px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.1);
            animation: modalSlideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .progress-modal-content::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 30% 20%, rgba(79, 70, 229, 0.04), transparent 60%);
            pointer-events: none;
        }

        .progress-modal-content::after {
            content: '';
            position: absolute;
            top: -60%;
            left: -60%;
            width: 220%;
            height: 220%;
            background: radial-gradient(circle at 70% 80%, rgba(79, 70, 229, 0.03), transparent 50%);
            pointer-events: none;
            animation: glowPulse 4s ease-in-out infinite;
        }

        @keyframes glowPulse {
            0%, 100% { opacity: 0.5; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.1); }
        }

        .progress-modal-content > * {
            position: relative;
            z-index: 1;
        }

        /* Icon with floating animation */
        .progress-icon-wrapper {
            position: relative;
            width: 88px;
            height: 88px;
            margin: 0 auto 1.25rem;
        }

        .progress-icon-wrapper .ring {
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            border: 2px solid transparent;
            animation: ringSpin 3s linear infinite;
        }

        .progress-icon-wrapper .ring:nth-child(1) {
            border-top-color: rgba(79, 70, 229, 0.3);
            border-right-color: rgba(79, 70, 229, 0.1);
            animation-duration: 3s;
        }

        .progress-icon-wrapper .ring:nth-child(2) {
            inset: -12px;
            border-bottom-color: rgba(79, 70, 229, 0.15);
            border-left-color: rgba(79, 70, 229, 0.05);
            animation-duration: 4s;
            animation-direction: reverse;
        }

        @keyframes ringSpin {
            to { transform: rotate(360deg); }
        }

        .progress-icon {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.75rem;
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            color: var(--primary);
            transition: all 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            z-index: 2;
            box-shadow: 0 8px 32px rgba(79, 70, 229, 0.15);
        }

        .progress-icon .material-symbols-outlined {
            transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .progress-icon.scanning {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #2563eb;
            box-shadow: 0 8px 32px rgba(37, 99, 235, 0.25);
            animation: iconPulse 2s ease-in-out infinite;
        }

        .progress-icon.matching {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #d97706;
            box-shadow: 0 8px 32px rgba(217, 119, 6, 0.25);
            animation: iconPulse 1.8s ease-in-out infinite;
        }

        .progress-icon.done {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #059669;
            box-shadow: 0 8px 32px rgba(5, 150, 105, 0.3);
        }

        .progress-icon.error {
            background: linear-gradient(135deg, #fecaca, #fca5a5);
            color: #dc2626;
            box-shadow: 0 8px 32px rgba(220, 38, 38, 0.25);
            animation: iconShake 0.5s ease;
        }

        @keyframes iconPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        @keyframes iconShake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-12px) rotate(-3deg); }
            40% { transform: translateX(12px) rotate(3deg); }
            60% { transform: translateX(-8px) rotate(-2deg); }
            80% { transform: translateX(8px) rotate(2deg); }
        }

        .progress-title {
            font-size: 1.35rem;
            font-weight: 800;
            margin-bottom: 0.35rem;
            color: var(--text-on-surface);
            letter-spacing: -0.3px;
            transition: all 0.3s ease;
        }

        .progress-subtitle {
            font-size: 0.875rem;
            color: var(--text-on-surface-variant);
            margin-bottom: 1.75rem;
            min-height: 1.5rem;
            transition: all 0.3s ease;
            font-weight: 450;
        }

        /* Modern Step Indicators */
        .step-indicators {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin: 0 0 1.5rem;
            padding: 0 0.5rem;
        }

        .step-indicators .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            flex: 1;
            position: relative;
        }

        .step-indicators .step .step-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2.5px solid #e5e7eb;
            background: #ffffff;
            transition: all 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            z-index: 2;
        }

        .step-indicators .step .step-dot.active {
            border-color: var(--primary);
            background: var(--primary);
            transform: scale(1.15);
            box-shadow: 0 0 24px rgba(79, 70, 229, 0.35);
            animation: dotPulse 1.8s ease-in-out infinite;
        }

        @keyframes dotPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.35); }
            50% { box-shadow: 0 0 0 10px rgba(79, 70, 229, 0); }
        }

        .step-indicators .step .step-dot.done {
            border-color: var(--success-color);
            background: var(--success-color);
            transform: scale(1);
            box-shadow: 0 0 20px rgba(34, 197, 94, 0.2);
        }

        .step-indicators .step .step-dot.error {
            border-color: var(--error-color);
            background: var(--error-color);
            transform: scale(1);
            box-shadow: 0 0 20px rgba(220, 38, 38, 0.2);
        }

        .step-indicators .step .step-label {
            font-size: 0.6rem;
            font-weight: 600;
            color: var(--text-on-surface-variant);
            text-align: center;
            opacity: 0.4;
            transition: all 0.3s ease;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .step-indicators .step .step-label.active {
            opacity: 1;
            color: var(--primary);
            font-weight: 700;
        }

        .step-indicators .step .step-label.done {
            opacity: 0.8;
            color: var(--success-color);
        }

        .step-indicators .step .step-label.error {
            opacity: 1;
            color: var(--error-color);
        }

        .step-indicators .step .step-line {
            position: absolute;
            top: 7px;
            left: calc(50% + 16px);
            right: calc(-50% + 16px);
            height: 2.5px;
            background: #e5e7eb;
            z-index: 1;
            transition: all 0.6s ease;
            border-radius: 2px;
        }

        .step-indicators .step .step-line.done {
            background: var(--success-color);
        }

        .step-indicators .step:last-child .step-line {
            display: none;
        }

        /* Tips section */
        .progress-tips {
            font-size: 0.75rem;
            color: var(--text-on-surface-variant);
            padding: 0.75rem 1rem;
            background: linear-gradient(135deg, #f8f7ff, #f0eeff);
            border-radius: 1rem;
            margin: 0 0 1.25rem;
            min-height: 2.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: 1px solid rgba(79, 70, 229, 0.06);
        }

        .progress-tips .tip-icon {
            font-size: 1.125rem;
            flex-shrink: 0;
        }

        .progress-tips .tip-text {
            font-weight: 450;
        }

        /* Actions */
        .progress-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            margin-top: 0.25rem;
        }

        .progress-actions .btn {
            flex: 1;
            justify-content: center;
            padding: 0.7rem 1.5rem;
            border-radius: 1rem;
            font-weight: 600;
            font-size: 0.875rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            font-family: inherit;
            min-width: 100px;
            position: relative;
            overflow: hidden;
        }

        .progress-actions .btn::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.2), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .progress-actions .btn:hover::after {
            opacity: 1;
        }

        .progress-actions .btn-outline {
            background: transparent;
            color: var(--text-on-surface-variant);
            border: 2px solid #e5e7eb;
        }
        .progress-actions .btn-outline:hover {
            background: #f8f7ff;
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(79, 70, 229, 0.12);
        }

        .progress-actions .btn-error {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            color: white;
            box-shadow: 0 4px 16px rgba(220, 38, 38, 0.25);
        }
        .progress-actions .btn-error:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(220, 38, 38, 0.35);
        }

        .progress-actions .btn-hidden {
            display: none;
        }

        @media (max-width: 480px) {
            .login-card { padding: 1.25rem; }
            .obstruction-modal-content { padding: 1.5rem; margin: 0.5rem; }
            .obstruction-modal-content .modal-actions { flex-direction: column; }
            .obstruction-modal-content .modal-actions .btn { width: 100%; }
            .face-video-wrapper { min-height: 200px; }
            .progress-modal-content { padding: 1.75rem 1.25rem; margin: 0.5rem; }
            .step-indicators .step .step-label { font-size: 0.5rem; }
            .step-indicators .step .step-dot { width: 12px; height: 12px; }
            .step-indicators .step .step-line { top: 6px; left: calc(50% + 14px); right: calc(-50% + 14px); height: 2px; }
            .progress-icon-wrapper { width: 72px; height: 72px; }
            .progress-icon { width: 72px; height: 72px; font-size: 2.25rem; }
            .progress-title { font-size: 1.125rem; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <span class="logo-icon">I</span>
                <h1>Face Login</h1>
                <p>Sign in using facial recognition</p>
            </div>

            <div class="face-video-wrapper" id="faceWrapper">
                <video id="video" autoplay muted playsinline></video>
                <canvas id="canvas"></canvas>
                <div class="face-overlay">
                    <div class="face-guide">
                        <span class="guide-dot tl"></span>
                        <span class="guide-dot tr"></span>
                        <span class="guide-dot bl"></span>
                        <span class="guide-dot br"></span>
                    </div>
                    <div class="face-status" id="faceStatus">Position your face in the circle</div>
                </div>
            </div>

            <div class="face-status-indicator">
                <span class="status-dot loading" id="statusDot"></span>
                <span class="status-text" id="statusText">Loading face models...</span>
            </div>

            <div class="face-controls">
                <button class="btn btn-primary" id="scanBtn" onclick="scanAndLogin()" disabled>
                    <span class="material-symbols-outlined" id="scanIcon">auto_awesome</span>
                    <span id="scanLabel">Scan & Login</span>
                </button>
                <button class="btn btn-outline" onclick="stopCamera()">
                    <span class="material-symbols-outlined">stop</span>
                    Stop
                </button>
            </div>

            <div class="debug-toggle">
                <button onclick="toggleDebug()">🔧 Debug Log</button>
            </div>
            <div class="debug-panel" id="debugPanel"></div>

            <div class="alternative-login">
                <p>
                    <span class="material-symbols-outlined" style="font-size:1rem; vertical-align:middle;">lock</span>
                    <a href="login.php<?php echo isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : ''; ?>">Sign in with password</a>
                </p>
            </div>
        </div>
    </div>

    <!-- =============================================
    OBSTRUCTION MODAL
    ============================================= -->
    <div class="obstruction-modal" id="obstructionModal">
        <div class="obstruction-modal-content">
            <div class="modal-icon warning" id="modalIcon">
                <span class="material-symbols-outlined">warning</span>
            </div>
            <h2 id="modalTitle">Face Obstruction Detected</h2>
            <p id="modalDescription">We couldn't clearly see your face. Please remove any obstructions and try again.</p>

            <div class="modal-issues" id="modalIssues">
                <div class="issue-item">
                    <span class="material-symbols-outlined">error</span>
                    <span id="issueText">Your face appears to be partially covered or obstructed.</span>
                </div>
            </div>

            <div class="modal-tips">
                <div class="tip-item">
                    <span class="material-symbols-outlined">lightbulb</span>
                    <span><strong>Tips:</strong> Remove any face mask, glasses, or objects covering your face. Ensure good lighting and face the camera directly.</span>
                </div>
                <div class="tip-item" style="margin-top:0.25rem;">
                    <span class="material-symbols-outlined">info</span>
                    <span>Make sure your entire face is visible within the circle guide.</span>
                </div>
            </div>

            <div class="modal-actions">
                <button class="btn btn-retry" onclick="closeObstructionModal(true)">
                    <span class="material-symbols-outlined">refresh</span>
                    Try Again
                </button>
                <button class="btn btn-password" onclick="redirectToPasswordLogin()">
                    <span class="material-symbols-outlined">lock</span>
                    Use Password
                </button>
            </div>
        </div>
    </div>

    <!-- =============================================
    MODERN OPTIMISTIC PROGRESS MODAL
    ============================================= -->
    <div class="progress-modal" id="progressModal">
        <div class="progress-modal-content">
            <div class="progress-icon-wrapper">
                <div class="ring"></div>
                <div class="ring"></div>
                <div class="progress-icon scanning" id="progressIcon">
                    <span class="material-symbols-outlined" id="progressIconSymbol">scan</span>
                </div>
            </div>

            <h3 class="progress-title" id="progressTitle">Scanning Face</h3>
            <p class="progress-subtitle" id="progressSubtitle">Please position your face in the circle...</p>

            <!-- Step Indicators -->
            <div class="step-indicators" id="stepIndicators">
                <div class="step" data-step="0">
                    <div class="step-dot" id="dot0"></div>
                    <span class="step-label" id="label0">Scan</span>
                    <div class="step-line" id="line0"></div>
                </div>
                <div class="step" data-step="1">
                    <div class="step-dot" id="dot1"></div>
                    <span class="step-label" id="label1">Match</span>
                    <div class="step-line" id="line1"></div>
                </div>
                <div class="step" data-step="2">
                    <div class="step-dot" id="dot2"></div>
                    <span class="step-label" id="label2">Done</span>
                </div>
            </div>

            <div class="progress-tips" id="progressTips">
                <span class="tip-icon">💡</span>
                <span class="tip-text" id="tipText">Keep your face centered and well-lit</span>
            </div>

            <div class="progress-actions" id="progressActions">
                <button class="btn btn-outline" onclick="closeProgressModal(true)" id="progressCancelBtn">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>

    <script>
        // =============================================
        // STATE - CORRECT PATHS FOR CT1 FOLDER
        // =============================================
        let video = null;
        let canvas = null;
        let isInitialized = false;
        let isScanning = false;
        let isLoggedIn = false;
        let storedDescriptors = [];
        let currentStream = null;
        let debugLogs = [];
        let obstructionModalShown = false;
        let progressModalOpen = false;
        let progressTimeout = null;
        let currentStep = 0;
        let totalSteps = 3;
        let faceAuthInstance = null;

        // ✅ API Base URL - with /CT1/ prefix
        const API_BASE = '/CT1/api/face/';

        // ✅ Role redirects - with /CT1/ prefix
        const ROLE_REDIRECTS = {
            'admin': '/CT1/portals/admin/dashboard.php',
            'hr_manager': '/CT1/portals/hr/dashboard.php',
            'recruiter': '/CT1/portals/hr/dashboard.php',
            'client': '/CT1/portals/client/dashboard.php',
            'applicant': '/CT1/portals/applicant/dashboard.php',
            'employee': '/CT1/portals/employee/dashboard.php',
            'supervisor': '/CT1/portals/supervisor/dashboard.php'
        };

        // =============================================
        // PROGRESS MODAL CONTROLS - 3 STEPS
        // =============================================
        const stepConfigs = [
            { 
                icon: 'scan', 
                title: 'Scanning Face', 
                subtitle: 'Looking for your face...', 
                tip: 'Keep your face centered and well-lit', 
                iconClass: 'scanning',
                tipIcon: '🔍'
            },
            { 
                icon: 'compare', 
                title: 'Matching Identity', 
                subtitle: 'Comparing with stored data...', 
                tip: 'This may take a moment', 
                iconClass: 'matching',
                tipIcon: '🔄'
            },
            { 
                icon: 'check_circle', 
                title: 'Complete!', 
                subtitle: 'Redirecting to your dashboard...', 
                tip: 'Welcome back! 🎉', 
                iconClass: 'done',
                tipIcon: '🎉'
            }
        ];

        function showProgressModal(step = 0) {
            const modal = document.getElementById('progressModal');
            modal.classList.add('active');
            progressModalOpen = true;
            document.body.style.overflow = 'hidden';
            updateProgressStep(step);
        }

        function updateProgressStep(step) {
            currentStep = Math.min(step, totalSteps - 1);
            const config = stepConfigs[currentStep] || stepConfigs[0];

            // Update icon with smooth transition
            const icon = document.getElementById('progressIcon');
            const symbol = document.getElementById('progressIconSymbol');
            
            // Remove all classes and add new ones with animation
            icon.className = 'progress-icon';
            // Trigger reflow for animation
            void icon.offsetWidth;
            icon.classList.add(config.iconClass);
            symbol.textContent = config.icon;

            // Update text with fade effect
            const title = document.getElementById('progressTitle');
            const subtitle = document.getElementById('progressSubtitle');
            const tipText = document.getElementById('tipText');
            const tipIcon = document.querySelector('.progress-tips .tip-icon');
            
            title.textContent = config.title;
            subtitle.textContent = config.subtitle;
            tipText.textContent = config.tip;
            if (tipIcon) tipIcon.textContent = config.tipIcon || '💡';

            // Update step indicators
            for (let i = 0; i < totalSteps; i++) {
                const dot = document.getElementById('dot' + i);
                const label = document.getElementById('label' + i);
                const line = document.getElementById('line' + i);
                
                dot.className = 'step-dot';
                label.className = 'step-label';
                if (line) line.className = 'step-line';

                if (i < currentStep) {
                    dot.classList.add('done');
                    label.classList.add('done');
                    if (line) line.classList.add('done');
                } else if (i === currentStep) {
                    dot.classList.add('active');
                    label.classList.add('active');
                }
            }

            // Update actions
            const actions = document.getElementById('progressActions');
            if (currentStep === totalSteps - 1) {
                actions.className = 'progress-actions btn-hidden';
                actions.innerHTML = '';
            } else {
                actions.className = 'progress-actions';
                actions.innerHTML = `
                    <button class="btn btn-outline" onclick="closeProgressModal(true)">
                        Cancel
                    </button>
                `;
            }
        }

        function closeProgressModal(cancelled = false) {
            const modal = document.getElementById('progressModal');
            modal.classList.remove('active');
            progressModalOpen = false;
            document.body.style.overflow = '';
            
            if (progressTimeout) {
                clearTimeout(progressTimeout);
                progressTimeout = null;
            }
            
            if (cancelled) {
                isScanning = false;
                resetButton();
                updateStatus('Ready - Position your face', 'idle');
                showToast('Login cancelled', 'info');
            }
        }

        function animateProgressToStep(targetStep, delay = 300) {
            return new Promise((resolve) => {
                if (progressTimeout) clearTimeout(progressTimeout);
                progressTimeout = setTimeout(() => {
                    updateProgressStep(targetStep);
                    resolve();
                }, delay);
            });
        }

        // =============================================
        // DEBUG
        // =============================================
        function debugLog(message, type = 'info') {
            const time = new Date().toLocaleTimeString();
            debugLogs.push({ time, message, type });
            console.log(`[FaceAuth] ${message}`);

            const panel = document.getElementById('debugPanel');
            if (panel && panel.classList.contains('active')) {
                const line = document.createElement('div');
                line.className = 'debug-line';
                const color = type === 'error' ? '#dc2626' : type === 'success' ? '#22c55e' : '#00ff41';
                line.innerHTML = `<span class="time">[${time}]</span><span style="color:${color}">${message}</span>`;
                panel.appendChild(line);
                panel.scrollTop = panel.scrollHeight;
            }
        }

        function toggleDebug() {
            const panel = document.getElementById('debugPanel');
            if (!panel) return;
            panel.classList.toggle('active');
            if (panel.classList.contains('active')) {
                panel.innerHTML = '';
                debugLogs.forEach(log => {
                    const line = document.createElement('div');
                    line.className = 'debug-line';
                    const color = log.type === 'error' ? '#dc2626' : log.type === 'success' ? '#22c55e' : '#00ff41';
                    line.innerHTML = `<span class="time">[${log.time}]</span><span style="color:${color}">${log.message}</span>`;
                    panel.appendChild(line);
                });
                panel.scrollTop = panel.scrollHeight;
            }
        }

        function updateStatus(text, type = 'loading') {
            const dot = document.getElementById('statusDot');
            const textEl = document.getElementById('statusText');
            if (dot) dot.className = 'status-dot ' + type;
            if (textEl) textEl.textContent = text;
            debugLog(text, type === 'loading' ? 'info' : type);
        }

        function showToast(message, type) {
            type = type || 'info';
            const existingToast = document.querySelector('.toast');
            if (existingToast) existingToast.remove();

            const toast = document.createElement('div');
            toast.className = 'toast ' + type;
            const iconMap = { 'success': 'check_circle', 'error': 'error', 'info': 'info', 'warning': 'warning' };
            toast.innerHTML = `<span class="material-symbols-outlined">${iconMap[type] || 'info'}</span> ${message}`;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(20px)';
                toast.style.transition = 'all 0.4s ease';
                setTimeout(() => toast.remove(), 400);
            }, 3500);
        }

        function resetButton() {
            const scanBtn = document.getElementById('scanBtn');
            scanBtn.innerHTML = `
                <span class="material-symbols-outlined" id="scanIcon">auto_awesome</span>
                <span id="scanLabel">Scan & Login</span>
            `;
            scanBtn.disabled = false;
        }

        // =============================================
        // OBSTRUCTION MODAL
        // =============================================
        function showObstructionModal(reason, details = null) {
            const modal = document.getElementById('obstructionModal');
            const title = document.getElementById('modalTitle');
            const description = document.getElementById('modalDescription');
            const issueText = document.getElementById('issueText');
            const icon = document.getElementById('modalIcon');

            if (reason === 'no_face') {
                title.textContent = 'No Face Detected';
                description.textContent = 'We couldn\'t detect a face. Please face the camera directly.';
                issueText.textContent = 'No face was detected in the camera frame.';
                icon.className = 'modal-icon error';
                icon.innerHTML = '<span class="material-symbols-outlined">face_4</span>';
            } else if (reason === 'mask') {
                title.textContent = 'Face Mask Detected';
                description.textContent = 'For security reasons, please remove your face mask for authentication.';
                issueText.textContent = 'A face mask is covering your mouth and lower face.';
                icon.className = 'modal-icon warning';
                icon.innerHTML = '<span class="material-symbols-outlined">mask</span>';
            } else if (reason === 'hand') {
                title.textContent = 'Hand Obstruction Detected';
                description.textContent = 'Your hand is covering part of your face. Please remove it.';
                issueText.textContent = 'A hand or object is covering your face.';
                icon.className = 'modal-icon warning';
                icon.innerHTML = '<span class="material-symbols-outlined">pan_tool</span>';
            } else if (reason === 'glasses') {
                title.textContent = 'Glasses Obstruction';
                description.textContent = 'Your glasses are causing glare or covering your eyes. Please remove them.';
                issueText.textContent = 'Glasses are obstructing clear view of your eyes.';
                icon.className = 'modal-icon info';
                icon.innerHTML = '<span class="material-symbols-outlined">glasses</span>';
            } else if (reason === 'low_visibility') {
                title.textContent = 'Poor Lighting or Positioning';
                description.textContent = 'Your face is not clearly visible. Please ensure good lighting.';
                issueText.textContent = 'Face is not clearly visible due to lighting or positioning.';
                icon.className = 'modal-icon info';
                icon.innerHTML = '<span class="material-symbols-outlined">light_mode</span>';
            } else if (reason === 'multiple_faces') {
                title.textContent = 'Multiple Faces Detected';
                description.textContent = 'Please ensure only one face is visible for authentication.';
                issueText.textContent = 'Multiple faces detected in the frame.';
                icon.className = 'modal-icon error';
                icon.innerHTML = '<span class="material-symbols-outlined">group</span>';
            } else {
                title.textContent = 'Face Obstruction Detected';
                description.textContent = 'We couldn\'t clearly see your face. Please remove any obstructions.';
                issueText.textContent = reason || 'Your face appears to be partially covered.';
                icon.className = 'modal-icon warning';
                icon.innerHTML = '<span class="material-symbols-outlined">warning</span>';
            }

            if (details && details.issues) {
                const issuesDiv = document.getElementById('modalIssues');
                issuesDiv.innerHTML = '';
                details.issues.forEach(issue => {
                    const item = document.createElement('div');
                    item.className = 'issue-item';
                    item.innerHTML = `<span class="material-symbols-outlined">error</span><span>${issue}</span>`;
                    issuesDiv.appendChild(item);
                });
            }

            obstructionModalShown = true;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeObstructionModal(retry = false) {
            const modal = document.getElementById('obstructionModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';

            if (retry) {
                obstructionModalShown = false;
                resetButton();
                updateStatus('Ready to scan', 'idle');
                if (faceAuthInstance) {
                    faceAuthInstance.isScanning = false;
                }
            }
        }

        function redirectToPasswordLogin() {
            const redirect = new URLSearchParams(window.location.search).get('redirect') || '';
            window.location.href = 'login.php' + (redirect ? '?redirect=' + encodeURIComponent(redirect) : '');
        }

        // =============================================
        // FACE AUTH INITIALIZATION
        // =============================================
        async function initFaceAuth() {
            try {
                video = document.getElementById('video');
                canvas = document.getElementById('canvas');
                
                const modelPath = '/CT1/public/js';
                    
                debugLog('Loading face models from: ' + modelPath);

                await faceapi.nets.tinyFaceDetector.loadFromUri(modelPath);
                await faceapi.nets.faceLandmark68Net.loadFromUri(modelPath);
                await faceapi.nets.faceRecognitionNet.loadFromUri(modelPath);
                await faceapi.nets.faceExpressionNet.loadFromUri(modelPath);

                debugLog('Face models loaded successfully', 'success');

                await startCamera();
                detectLoop();

                isInitialized = true;
                document.getElementById('scanBtn').disabled = false;
                updateStatus('Ready - Position your face', 'idle');

                await loadStoredDescriptors();

                debugLog('Face authentication initialized', 'success');

            } catch (error) {
                debugLog('Failed to initialize: ' + error.message, 'error');
                updateStatus('Error: ' + error.message, 'failed');
                showToast('Face authentication failed to initialize. Please refresh and try again.', 'error');
                console.error('Full error:', error);
            }
        }

        // =============================================
        // CAMERA - NON-MIRRORED
        // =============================================
        async function startCamera() {
            try {
                if (currentStream) {
                    currentStream.getTracks().forEach(track => track.stop());
                }

                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        width: { ideal: 640 },
                        height: { ideal: 480 },
                        facingMode: 'environment' // Use environment/non-mirrored mode
                    },
                    audio: false
                });

                currentStream = stream;
                video.srcObject = stream;
                await video.play();

                const rect = video.getBoundingClientRect();
                canvas.width = rect.width || 640;
                canvas.height = rect.height || 480;
                canvas.style.width = '100%';
                canvas.style.height = '100%';

                debugLog('Camera started successfully', 'success');

            } catch (error) {
                debugLog('Camera error: ' + error.message, 'error');
                if (error.name === 'NotAllowedError') {
                    throw new Error('Camera access denied. Please allow camera permissions.');
                } else if (error.name === 'NotFoundError') {
                    throw new Error('No camera found. Please connect a camera.');
                } else {
                    throw new Error('Could not access camera. Please check your camera.');
                }
            }
        }

        function stopCamera() {
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }
            if (video) {
                video.srcObject = null;
            }
            if (canvas) {
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);
            }
            updateStatus('Camera stopped', 'idle');
            debugLog('Camera stopped');
        }

        // =============================================
        // FACE DETECTION LOOP
        // =============================================
        let detectionLoopRunning = false;

        async function detectLoop() {
            if (detectionLoopRunning) return;
            detectionLoopRunning = true;

            while (isInitialized) {
                try {
                    await detectFace();
                    await sleep(100);
                } catch (error) {
                    // Silent fail for loop
                }
            }
            detectionLoopRunning = false;
        }

        async function detectFace() {
            if (!video || video.paused || !video.videoWidth) return null;

            const options = new faceapi.TinyFaceDetectorOptions({
                inputSize: 224,
                scoreThreshold: 0.6
            });

            try {
                const detections = await faceapi.detectAllFaces(video, options)
                    .withFaceLandmarks()
                    .withFaceExpressions()
                    .withFaceDescriptors();

                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                if (detections && detections.length > 0) {
                    const sorted = detections.sort((a, b) => {
                        const aArea = a.detection.box.width * a.detection.box.height;
                        const bArea = b.detection.box.width * b.detection.box.height;
                        return bArea - aArea;
                    });

                    const detection = sorted[0];
                    const obstructionCheck = checkObstructions(detection);

                    if (obstructionCheck.hasObstruction) {
                        drawObstructionWarning(detection, obstructionCheck.reason);
                        updateStatus('⚠️ ' + obstructionCheck.reason, 'warning');
                        
                        if (!obstructionModalShown && !isScanning) {
                            const reason = getObstructionReason(obstructionCheck.reason);
                            showObstructionModal(reason, { 
                                issues: [obstructionCheck.reason] 
                            });
                        }
                        
                        return null;
                    } else {
                        drawDetection(detection);
                        
                        if (!isScanning) {
                            const visibility = Math.round((obstructionCheck.visibilityScore || 1) * 100);
                            updateStatus(`Face detected (${visibility}% visible)`, 'idle');
                        }
                        
                        return detection;
                    }
                } else {
                    if (!isScanning) {
                        updateStatus('No face detected', 'idle');
                    }
                    return null;
                }

            } catch (error) {
                return null;
            }
        }

        // =============================================
        // OBSTRUCTION DETECTION
        // =============================================
        function checkObstructions(detection) {
            if (!detection || !detection.landmarks) {
                return { hasObstruction: true, reason: 'No face landmarks detected' };
            }

            const landmarks = detection.landmarks;
            const positions = landmarks.positions;

            const faceParts = detectFaceParts(positions);
            const visibilityScore = calculateFaceVisibility(faceParts);
            const hasObstruction = visibilityScore < 0.7;

            let reason = '';
            if (hasObstruction) {
                if (!faceParts.eyesVisible) reason = 'Eyes are covered or not visible';
                else if (!faceParts.noseVisible) reason = 'Nose is covered or not visible';
                else if (!faceParts.mouthVisible) reason = 'Mouth is covered (possibly wearing a mask)';
                else if (!faceParts.foreheadVisible) reason = 'Forehead is covered';
                else reason = 'Face is partially obstructed';
            }

            return {
                hasObstruction: hasObstruction,
                reason: reason,
                visibilityScore: visibilityScore,
                faceParts: faceParts
            };
        }

        function detectFaceParts(positions) {
            if (!positions || positions.length < 68) {
                return {
                    eyesVisible: false,
                    noseVisible: false,
                    mouthVisible: false,
                    foreheadVisible: false,
                    cheeksVisible: false
                };
            }

            const leftEye = positions.slice(36, 42);
            const rightEye = positions.slice(42, 48);
            const nose = positions.slice(27, 35);
            const mouth = positions.slice(48, 67);
            const jaw = positions.slice(0, 17);

            const eyeVariance = calculatePointVariance([...leftEye, ...rightEye]);
            const noseVariance = calculatePointVariance(nose);
            const mouthVariance = calculatePointVariance(mouth);
            const jawVariance = calculatePointVariance(jaw);

            const visibilityThreshold = 2.5;

            return {
                eyesVisible: eyeVariance > visibilityThreshold && leftEye.length > 5 && rightEye.length > 5,
                noseVisible: noseVariance > 1.5 && nose.length > 7,
                mouthVisible: mouthVariance > 2.0 && mouth.length > 18,
                foreheadVisible: checkForeheadVisibility(positions),
                cheeksVisible: checkCheeksVisibility(positions)
            };
        }

        function calculatePointVariance(points) {
            if (!points || points.length < 2) return 0;

            const centerX = points.reduce((sum, p) => sum + p.x, 0) / points.length;
            const centerY = points.reduce((sum, p) => sum + p.y, 0) / points.length;

            let variance = 0;
            for (const p of points) {
                const dx = p.x - centerX;
                const dy = p.y - centerY;
                variance += dx * dx + dy * dy;
            }

            return variance / points.length;
        }

        function checkForeheadVisibility(positions) {
            if (!positions || positions.length < 30) return false;

            const jawTop = positions.slice(0, 9);
            const leftEyebrow = positions.slice(17, 22);
            const rightEyebrow = positions.slice(22, 27);

            if (jawTop.length < 9 || leftEyebrow.length < 5 || rightEyebrow.length < 5) return false;

            const jawWidth = Math.abs(jawTop[0].x - jawTop[8].x);
            const browWidth = Math.abs(leftEyebrow[0].x - rightEyebrow[4].x);

            if (browWidth < jawWidth * 0.5) return false;

            const browPoints = [...leftEyebrow, ...rightEyebrow];
            const browVariance = calculatePointVariance(browPoints);

            return browVariance > 1.0;
        }

        function checkCheeksVisibility(positions) {
            if (!positions || positions.length < 30) return false;

            const jaw = positions.slice(0, 17);
            if (jaw.length < 17) return false;

            const jawVariance = calculatePointVariance(jaw);
            return jawVariance > 3.0;
        }

        function calculateFaceVisibility(faceParts) {
            let score = 0;
            let total = 0;

            const weights = {
                eyesVisible: 0.35,
                noseVisible: 0.20,
                mouthVisible: 0.25,
                foreheadVisible: 0.10,
                cheeksVisible: 0.10
            };

            for (const [key, weight] of Object.entries(weights)) {
                total += weight;
                if (faceParts[key]) {
                    score += weight;
                }
            }

            return score / total;
        }

        function getObstructionReason(reason) {
            if (reason.includes('mask') || reason.includes('Mouth')) return 'mask';
            if (reason.includes('Eyes') || reason.includes('eyes')) return 'glasses';
            if (reason.includes('hand') || reason.includes('covered')) return 'hand';
            if (reason.includes('lighting') || reason.includes('visible')) return 'low_visibility';
            if (reason.includes('landmarks')) return 'no_face';
            return 'low_visibility';
        }

        // =============================================
        // DRAWING FUNCTIONS
        // =============================================
        function drawDetection(detection) {
            if (!canvas) return;

            const ctx = canvas.getContext('2d');
            const dims = faceapi.matchDimensions(canvas, video, true);

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            if (!detection) return;

            const resized = faceapi.resizeResults(detection, dims);
            const box = resized.detection.box;

            ctx.strokeStyle = '#22c55e';
            ctx.lineWidth = 3;
            ctx.strokeRect(box.x, box.y, box.width, box.height);

            faceapi.draw.drawFaceLandmarks(canvas, resized);

            if (resized.expressions) {
                const expressions = resized.expressions;
                const topExpression = Object.keys(expressions).reduce((a, b) => 
                    expressions[a] > expressions[b] ? a : b
                );

                ctx.fillStyle = 'rgba(79, 70, 229, 0.8)';
                ctx.font = 'bold 14px Inter, sans-serif';
                ctx.fillText(
                    `${topExpression}: ${Math.round(expressions[topExpression] * 100)}%`,
                    box.x,
                    box.y - 10
                );
            }

            const obstructionCheck = checkObstructions(detection);
            const visibility = obstructionCheck.visibilityScore || 1;
            const visibilityPercent = Math.round(visibility * 100);
            
            ctx.fillStyle = visibility > 0.7 ? 'rgba(34, 197, 94, 0.9)' : 'rgba(220, 38, 38, 0.9)';
            ctx.font = 'bold 12px Inter, sans-serif';
            ctx.fillText(
                `👁 ${visibilityPercent}% visible`,
                box.x + box.width - 120,
                box.y - 10
            );

            ctx.fillStyle = 'rgba(34, 197, 94, 0.8)';
            ctx.font = 'bold 14px Inter, sans-serif';
            ctx.fillText('✅ Face Clear', box.x + box.width - 100, box.y + box.height + 25);
        }

        function drawObstructionWarning(detection, reason) {
            if (!canvas) return;

            const ctx = canvas.getContext('2d');
            const dims = faceapi.matchDimensions(canvas, video, true);

            ctx.clearRect(0, 0, canvas.width, canvas.height);

            if (!detection) return;

            const resized = faceapi.resizeResults(detection, dims);
            const box = resized.detection.box;

            ctx.strokeStyle = '#dc2626';
            ctx.lineWidth = 4;
            ctx.setLineDash([8, 8]);
            ctx.strokeRect(box.x, box.y, box.width, box.height);
            ctx.setLineDash([]);

            const overlayGradient = ctx.createLinearGradient(0, box.y - 30, 0, box.y + 10);
            overlayGradient.addColorStop(0, 'rgba(220, 38, 38, 0.9)');
            overlayGradient.addColorStop(1, 'rgba(220, 38, 38, 0)');
            ctx.fillStyle = overlayGradient;
            ctx.fillRect(box.x, box.y - 30, box.width, 40);

            ctx.fillStyle = '#ffffff';
            ctx.font = 'bold 14px Inter, sans-serif';
            ctx.fillText('⚠️ ' + reason, box.x + 10, box.y - 10);

            ctx.fillStyle = 'rgba(220, 38, 38, 0.8)';
            ctx.font = 'bold 14px Inter, sans-serif';
            ctx.fillText('🚫 Face Obstructed', box.x + box.width - 150, box.y + box.height + 25);

            if (resized.landmarks) {
                const pts = resized.landmarks.positions;
                ctx.fillStyle = 'rgba(255, 255, 255, 0.3)';
                for (const p of pts) {
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, 2, 0, 2 * Math.PI);
                    ctx.fill();
                }
            }
        }

        // =============================================
        // LOAD STORED DESCRIPTORS
        // =============================================
        async function loadStoredDescriptors() {
            try {
                const url = '/CT1/api/face/get_descriptors.php';
                debugLog('Fetching descriptors from: ' + url, 'info');
                
                const response = await fetch(url);
                
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ' - ' + response.statusText);
                }
                
                const data = await response.json();
                debugLog('API Response: ' + JSON.stringify(data).substring(0, 300), 'info');
                
                if (data.success && data.descriptors) {
                    storedDescriptors = data.descriptors;
                    debugLog(`✅ Loaded ${storedDescriptors.length} stored face descriptors`, 'success');
                } else {
                    debugLog('⚠️ No stored face descriptors found: ' + (data.error || 'Unknown error'), 'warning');
                    storedDescriptors = [];
                }
            } catch (error) {
                debugLog('❌ Failed to load descriptors: ' + error.message, 'error');
                storedDescriptors = [];
            }
        }

        // =============================================
        // SCAN AND LOGIN - FAST 3 STEP PROGRESS
        // =============================================
        async function scanAndLogin() {
            if (isScanning || !isInitialized) return;
            
            debugLog('Stored descriptors count: ' + storedDescriptors.length, 'info');
            
            if (storedDescriptors.length === 0) {
                showToast('No registered faces found. Please contact your administrator.', 'warning');
                debugLog('⚠️ No stored descriptors available - trying to reload', 'error');
                await loadStoredDescriptors();
                if (storedDescriptors.length === 0) {
                    return;
                }
            }

            isScanning = true;
            
            // Show interactive progress modal - Step 1: Scanning
            showProgressModal(0);
            updateStatus('Scanning face...', 'scanning');

            try {
                // STEP 1: SCANNING - Looking for face (fast)
                await animateProgressToStep(0, 200);
                
                let detection = null;
                let attempts = 0;
                const maxAttempts = 15; // Reduced from 30 for speed
                let faceFound = false;

                while (attempts < maxAttempts) {
                    await sleep(100); // Reduced from 150ms
                    detection = await detectFace();

                    if (detection) {
                        const obstructionCheck = checkObstructions(detection);
                        if (!obstructionCheck.hasObstruction) {
                            faceFound = true;
                            break;
                        } else {
                            if (!obstructionModalShown) {
                                const reason = getObstructionReason(obstructionCheck.reason);
                                showObstructionModal(reason, { issues: [obstructionCheck.reason] });
                            }
                            detection = null;
                        }
                    }
                    attempts++;

                    // Update subtitle with progress (faster updates)
                    if (attempts % 2 === 0) {
                        const progress = Math.min(Math.floor(attempts / 2), 8);
                        document.getElementById('progressSubtitle').textContent = 
                            `Looking for your face... (${progress}/8)`;
                    }
                }

                if (!detection || !faceFound) {
                    document.getElementById('progressSubtitle').textContent = 'No face detected. Please try again.';
                    document.getElementById('tipText').textContent = 'Make sure your face is visible and well-lit';
                    
                    // Show error state
                    const icon = document.getElementById('progressIcon');
                    const symbol = document.getElementById('progressIconSymbol');
                    icon.className = 'progress-icon error';
                    symbol.textContent = 'error';
                    
                    document.getElementById('progressTitle').textContent = 'Scan Failed';
                    document.getElementById('progressActions').innerHTML = `
                        <button class="btn btn-error" onclick="closeProgressModal(true)">
                            <span class="material-symbols-outlined">refresh</span>
                            Try Again
                        </button>
                    `;
                    
                    showToast('No clear face detected. Please remove obstructions and try again.', 'error');
                    isScanning = false;
                    return;
                }

                // STEP 2: MATCHING - Compare face (fast)
                await animateProgressToStep(1, 200);
                document.getElementById('progressSubtitle').textContent = 'Comparing with registered faces...';
                document.getElementById('tipText').textContent = 'Searching for a match';

                const descriptor = detection.descriptor;
                const currentDescriptor = new Float32Array(descriptor);

                let bestMatch = null;
                let bestScore = 0;

                for (const stored of storedDescriptors) {
                    try {
                        const storedDescriptor = new Float32Array(stored.descriptor);
                        const distance = faceapi.euclideanDistance(currentDescriptor, storedDescriptor);
                        const similarity = 1 - distance;

                        if (similarity > bestScore) {
                            bestScore = similarity;
                            bestMatch = stored;
                        }
                    } catch (e) {
                        debugLog('Error comparing descriptors: ' + e.message, 'error');
                    }
                }

                const matchScore = Math.round(bestScore * 100);
                const isMatch = bestScore > 0.6;

                if (isMatch && bestMatch) {
                    debugLog(`Match found! Score: ${matchScore}%`, 'success');
                    updateStatus(`✅ Match! ${matchScore}%`, 'success');
                    
                    // STEP 3: DONE - Authenticating (fast)
                    await animateProgressToStep(2, 200);
                    document.getElementById('progressSubtitle').textContent = `Match found! (${matchScore}% confidence)`;
                    document.getElementById('tipText').textContent = '🎉 Welcome back!';
                    
                    await performLogin(bestMatch.user_id, matchScore);
                } else {
                    debugLog(`No match. Score: ${matchScore}%`, 'error');
                    updateStatus(`❌ No match (${matchScore}%)`, 'failed');
                    
                    // Show error state
                    const icon = document.getElementById('progressIcon');
                    const symbol = document.getElementById('progressIconSymbol');
                    icon.className = 'progress-icon error';
                    symbol.textContent = 'error';
                    
                    document.getElementById('progressTitle').textContent = 'No Match Found';
                    document.getElementById('progressSubtitle').textContent = `Confidence: ${matchScore}% - Please try again`;
                    document.getElementById('tipText').textContent = 'Make sure you are registered and your face is clearly visible';
                    
                    document.getElementById('progressActions').innerHTML = `
                        <button class="btn btn-error" onclick="closeProgressModal(true)">
                            <span class="material-symbols-outlined">refresh</span>
                            Try Again
                        </button>
                    `;
                    
                    showToast('Face not recognized. Please try again.', 'error');
                    showObstructionModal('no_face', {
                        issues: ['Face not recognized. Please ensure you are registered.']
                    });
                    isScanning = false;
                }

            } catch (error) {
                debugLog('Scan error: ' + error.message, 'error');
                showToast('Scan failed. Please try again.', 'error');
                closeProgressModal(true);
            } finally {
                if (!isLoggedIn) {
                    isScanning = false;
                }
            }
        }

        // =============================================
        // PERFORM LOGIN - AUTO REDIRECT
        // =============================================
        async function performLogin(userId, matchScore) {
            try {
                const response = await fetch('/CT1/api/face/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        match_score: matchScore,
                        liveness_score: 0.8
                    })
                });

                if (!response.ok) {
                    const text = await response.text();
                    console.error('Server response:', text);
                    throw new Error('Server returned ' + response.status);
                }

                const data = await response.json();

                if (data.success) {
                    isLoggedIn = true;
                    showToast('✅ Login successful!', 'success');
                    debugLog('Login successful for user: ' + userId, 'success');

                    // Already on Step 3 (Done) - just update subtitle
                    document.getElementById('progressSubtitle').textContent = 'Redirecting to your dashboard...';
                    document.getElementById('tipText').textContent = '🎉 You\'re all set!';
                    
                    // Get redirect path
                    let redirectPath = data.redirect || '';
                    
                    if (!redirectPath) {
                        const role = data.user?.role || 'applicant';
                        redirectPath = ROLE_REDIRECTS[role] || '/CT1/index.php';
                    }
                    
                    if (!redirectPath.startsWith('/CT1/')) {
                        redirectPath = redirectPath.replace(/^\//, '');
                        redirectPath = '/CT1/' + redirectPath;
                    }
                    
                    debugLog('Redirecting to: ' + redirectPath, 'success');
                    
                    // Auto redirect after short delay
                    setTimeout(() => {
                        window.location.replace(redirectPath);
                    }, 800);

                } else {
                    showToast(data.error || 'Login failed. Please try again.', 'error');
                    debugLog('Login failed: ' + data.error, 'error');
                    
                    // Show error in modal
                    const icon = document.getElementById('progressIcon');
                    const symbol = document.getElementById('progressIconSymbol');
                    icon.className = 'progress-icon error';
                    symbol.textContent = 'error';
                    
                    document.getElementById('progressTitle').textContent = 'Login Failed';
                    document.getElementById('progressSubtitle').textContent = data.error || 'Please try again';
                    document.getElementById('tipText').textContent = 'Check your connection and try again';
                    
                    document.getElementById('progressActions').innerHTML = `
                        <button class="btn btn-error" onclick="closeProgressModal(true)">
                            <span class="material-symbols-outlined">refresh</span>
                            Try Again
                        </button>
                    `;
                    
                    resetButton();
                    updateStatus('Ready - Position your face', 'idle');
                    isScanning = false;
                }

            } catch (error) {
                debugLog('Login error: ' + error.message, 'error');
                showToast('Login failed. Please try again.', 'error');
                console.error('Full login error:', error);
                
                // Show error in modal
                const icon = document.getElementById('progressIcon');
                const symbol = document.getElementById('progressIconSymbol');
                icon.className = 'progress-icon error';
                symbol.textContent = 'error';
                
                document.getElementById('progressTitle').textContent = 'Connection Error';
                document.getElementById('progressSubtitle').textContent = 'Failed to connect to server';
                document.getElementById('tipText').textContent = 'Please check your internet connection';
                
                document.getElementById('progressActions').innerHTML = `
                    <button class="btn btn-error" onclick="closeProgressModal(true)">
                        <span class="material-symbols-outlined">refresh</span>
                        Try Again
                    </button>
                `;
                
                resetButton();
                updateStatus('Ready - Position your face', 'idle');
                isScanning = false;
            }
        }

        // =============================================
        // UTILITIES
        // =============================================
        function sleep(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        }

        // =============================================
        // INIT
        // =============================================
        document.addEventListener('DOMContentLoaded', () => {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                document.getElementById('statusText').textContent = 'Camera not supported';
                showToast('Your browser does not support camera access.', 'error');
                return;
            }

            initFaceAuth();
        });

        window.addEventListener('beforeunload', () => {
            stopCamera();
        });

        window.addEventListener('error', (event) => {
            debugLog('Unhandled error: ' + event.message, 'error');
        });
    </script>
</body>
</html>