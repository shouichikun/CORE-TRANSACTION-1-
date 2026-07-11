<?php
// index.php - ISMERS Landing Page (Professional Version)
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Name</title>
    
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
            overflow-x: hidden;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* ===== SCROLLBAR ===== */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: var(--gray-light);
        }
        ::-webkit-scrollbar-thumb {
            background: var(--primary-light);
            border-radius: 10px;
        }

        /* ===== HEADER / NAVBAR ===== */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 16px 40px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(26, 58, 92, 0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
        }

        .navbar.scrolled {
            background: rgba(255, 255, 255, 0.96);
            box-shadow: var(--shadow-sm);
            padding: 12px 40px;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 24px;
            font-weight: 700;
            color: var(--primary-blue);
        }

        .nav-brand .brand-icon {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 32px;
            list-style: none;
        }

        .nav-links a {
            font-size: 15px;
            font-weight: 500;
            color: var(--text-gray);
            transition: var(--transition);
            position: relative;
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary-gradient);
            transition: var(--transition);
        }

        .nav-links a:hover {
            color: var(--primary-blue);
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn {
            padding: 10px 28px;
            border: none;
            border-radius: 50px;
            font-size: 15px;
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
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(26, 58, 92, 0.2);
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
            padding: 16px 40px;
            font-size: 18px;
        }

        .btn-white {
            background: white;
            color: var(--primary-blue);
            box-shadow: var(--shadow-sm);
        }

        .btn-white:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-ghost {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(4px);
        }

        .btn-ghost:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }

        /* Mobile Hamburger */
        .hamburger {
            display: none;
            flex-direction: column;
            gap: 5px;
            cursor: pointer;
            padding: 4px;
            background: none;
            border: none;
        }

        .hamburger span {
            width: 28px;
            height: 3px;
            background: var(--primary-blue);
            border-radius: 3px;
            transition: var(--transition);
        }

        .hamburger.active span:nth-child(1) {
            transform: rotate(45deg) translate(5px, 6px);
        }
        .hamburger.active span:nth-child(2) {
            opacity: 0;
        }
        .hamburger.active span:nth-child(3) {
            transform: rotate(-45deg) translate(5px, -6px);
        }

        /* ===== HERO SECTION ===== */
        .hero {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 120px 40px 80px;
            background: linear-gradient(160deg, #f0f5ff 0%, #ffffff 50%, #f8faff 100%);
            position: relative;
            overflow: hidden;
            text-align: center;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(74, 144, 217, 0.06) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(26, 58, 92, 0.04) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-container {
            max-width: 820px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .hero-content {
            animation: fadeInUp 0.8s ease-out;
        }

        .hero-title {
            font-size: 56px;
            font-weight: 800;
            line-height: 1.12;
            color: var(--primary-dark);
            margin-bottom: 20px;
        }

        .hero-title .highlight {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-subtitle {
            font-size: 20px;
            color: var(--text-gray);
            line-height: 1.7;
            max-width: 600px;
            margin: 0 auto 32px;
        }

        .hero-actions {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* ===== CTA SECTION ===== */
        .cta-section {
            padding: 80px 40px;
            background: var(--primary-gradient);
            position: relative;
            overflow: hidden;
        }

        .cta-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 50%;
        }

        .cta-container {
            max-width: 800px;
            margin: 0 auto;
            text-align: center;
            position: relative;
            z-index: 1;
        }

        .cta-section h2 {
            font-size: 38px;
            font-weight: 800;
            color: white;
            margin-bottom: 16px;
        }

        .cta-section p {
            font-size: 18px;
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 32px;
        }

        .cta-actions {
            display: flex;
            gap: 16px;
            justify-content: center;
            flex-wrap: wrap;
        }

        /* ===== FOOTER ===== */
        .footer {
            background: var(--primary-dark);
            color: rgba(255, 255, 255, 0.7);
            padding: 48px 40px 32px;
        }

        .footer-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 40px;
        }

        .footer-brand h3 {
            color: white;
            font-size: 22px;
            margin-bottom: 12px;
        }

        .footer-brand p {
            font-size: 14px;
            line-height: 1.8;
            max-width: 320px;
        }

        .footer-col h4 {
            color: white;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .footer-col a {
            display: block;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.6);
            padding: 4px 0;
            transition: var(--transition);
        }

        .footer-col a:hover {
            color: white;
            padding-left: 6px;
        }

        .footer-bottom {
            max-width: 1280px;
            margin: 32px auto 0;
            padding-top: 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            text-align: center;
            font-size: 14px;
        }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1024px) {
            .hero-title {
                font-size: 44px;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 12px 20px;
            }

            .nav-links {
                display: none;
                flex-direction: column;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: white;
                padding: 20px 24px;
                gap: 16px;
                box-shadow: var(--shadow-md);
                border-bottom: 1px solid var(--gray-border);
            }

            .nav-links.open {
                display: flex;
            }

            .hamburger {
                display: flex;
            }

            .nav-actions .btn-outline {
                display: none;
            }

            .hero {
                padding: 100px 20px 60px;
            }

            .hero-title {
                font-size: 34px;
            }

            .hero-subtitle {
                font-size: 17px;
            }

            .cta-section {
                padding: 60px 20px;
            }

            .cta-section h2 {
                font-size: 30px;
            }

            .footer-container {
                grid-template-columns: 1fr;
                gap: 24px;
            }

            .footer {
                padding: 40px 20px 24px;
            }
        }

        @media (max-width: 480px) {
            .hero-title {
                font-size: 28px;
            }

            .btn {
                padding: 10px 20px;
                font-size: 14px;
            }

            .btn-large {
                padding: 14px 28px;
                font-size: 16px;
            }
        }
    </style>
</head>
<body>

    <!-- ===== NAVBAR ===== -->
    <nav class="navbar" id="navbar">
        <a href="#" class="nav-brand">
            <span class="brand-icon">I</span>
            company name 
        </a>

        <ul class="nav-links" id="navLinks">
            <li><a href="#cta">Features</a></li>
            <li><a href="#cta">About</a></li>
            <li><a href="#footer">Contact</a></li>
        </ul>

        <div class="nav-actions">
            <a href="login.php" class="btn btn-outline">Sign In</a>
            <a href="portals/applicant/register.php" class="btn btn-primary">Get Started</a>
            <button class="hamburger" id="hamburger" aria-label="Toggle menu">
                <span></span>
                <span></span>
                <span></span>
            </button>
        </div>
    </nav>

    <!-- ===== HERO SECTION ===== -->
     <!-- ilagay ang mga kemerut nila  -->
    <section class="hero">
        <div class="hero-container">
            <div class="hero-content">
                <!-- dito ko ilalagay yung mga title or lines na gusto nila ipalagay -->
                <h1 class="hero-title">
                    LINE ONE.<br>
                    <span class="highlight">LINE 2.</span>
                    <br>LINE 3.
                </h1>
                <p class="hero-subtitle">
                   lalagyan ng mga ano nila pangpasiklab at possible lagyan din ng mga slideshows if provided nila
                   pang palakas sa landing page nila .
                </p>
                <div class="hero-actions">
                    <a href="portals/applicant/register.php" class="btn btn-primary btn-large">
                        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14"/>
                            <path d="M5 12h14"/>
                        </svg>
                        Get Started
                    </a>
                    <a href="#cta" class="btn btn-white btn-large">
                        Learn More
                        <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"/>
                            <polyline points="12 5 19 12 12 19"/>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- ===== CTA SECTION ===== -->
    <section class="cta-section" id="cta">
        <div class="cta-container">
            <h2>Ready to Transform Your Hiring Process?</h2>
            <p>
                Experience the future of recruitment with "Name of Company". 
            </p>
            <div class="cta-actions">
                <a href="portals/applicant/register.php" class="btn btn-white btn-large">
                    <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14"/>
                        <path d="M5 12h14"/>
                    </svg>
                    Start Now — It's Free
                </a>
                <a href="#" class="btn btn-ghost btn-large">
                    <svg width="20" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    Book a Demo
                </a>
            </div>
        </div>
    </section>

    <!-- ===== FOOTER ===== -->
    <footer class="footer" id="footer">
        <div class="footer-container">
            <div class="footer-brand">
                <h3>Name of Company</h3>
                <p>
                 Join now and get employed .
                </p>
            </div>
            <div class="footer-col">
                <h4>Platform</h4>
                <a href="#">Features</a>
                <a href="#">Pricing</a>
                <a href="#">Security</a>
                <a href="#">API Docs</a>
            </div>
            <div class="footer-col">
                <h4>Company</h4>
                <a href="#">About</a>
                <a href="#">Careers</a>
                <a href="#">Blog</a>
                <a href="#">Contact</a>
            </div>
            <div class="footer-col">
                <h4>Support</h4>
                <a href="#">Help Center</a>
                <a href="#">Privacy Policy</a>
                <a href="#">Terms of Service</a>
                <a href="#">Status</a>
            </div>
        </div>
        <div class="footer-bottom">
            &copy; 2026 company name. All rights reserved.
        </div>
    </footer>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        // =============================================
        // 1. NAVBAR SCROLL EFFECT
        // =============================================
        const navbar = document.getElementById('navbar');

        window.addEventListener('scroll', function() {
            if (window.scrollY > 60) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // =============================================
        // 2. MOBILE HAMBURGER MENU
        // =============================================
        const hamburger = document.getElementById('hamburger');
        const navLinks = document.getElementById('navLinks');

        hamburger.addEventListener('click', function() {
            hamburger.classList.toggle('active');
            navLinks.classList.toggle('open');
        });

        // Close menu when clicking a link (mobile)
        document.querySelectorAll('.nav-links a').forEach(link => {
            link.addEventListener('click', function() {
                hamburger.classList.remove('active');
                navLinks.classList.remove('open');
            });
        });

        // =============================================
        // 3. SMOOTH SCROLL FOR ANCHOR LINKS
        // =============================================
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href === '#') return;
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // =============================================
        // 4. KEYBOARD ACCESSIBILITY
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hamburger.classList.remove('active');
                navLinks.classList.remove('open');
            }
        });

        console.log('ISMERS Landing Page loaded successfully.');
    </script>

</body>
</html>