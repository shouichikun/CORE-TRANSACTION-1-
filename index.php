<?php
// index.php - Minimalist ERP Landing Page (Merged)
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>ERP · Business Growth</title>

    <!-- Inter font -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet" />

    <style>
        /* ============================================================
                   RESET & BASE (minimalist, flat, light gray)
                   ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg: #f4f4f4;
            --blue: #3b6bee;
            --blue-dark: #2a56d0;
            --text-dark: #0b0b0b;
            --text-muted: #2b2b2b;
            --border-light: #e6e6e6;
            --radius: 16px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 8px 30px rgba(0, 0, 0, 0.06);
            --transition: all 0.2s ease;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text-dark);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        /* ============================================================
                   STICKY HEADER (minimalist, no glassmorphism)
                   ============================================================ */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: var(--bg);
            padding: 1rem 0;
            border-bottom: 1px solid var(--border-light);
            transition: var(--transition);
        }

        .navbar.scrolled {
            box-shadow: var(--shadow-sm);
        }

        .navbar .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.8rem;
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.5rem;
            font-weight: 700;
            color: #111;
            text-decoration: none;
            letter-spacing: -0.02em;
        }

        .nav-brand .brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: var(--blue);
            color: white;
            border-radius: 10px;
            font-weight: 800;
            font-size: 1.1rem;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 2rem;
            list-style: none;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .nav-links a {
            text-decoration: none;
            color: #1f1f1f;
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
            background: var(--blue);
            transition: var(--transition);
        }

        .nav-links a:hover {
            color: var(--blue);
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .btn {
            padding: 0.6rem 1.5rem;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-outline {
            background: transparent;
            color: #1f1f1f;
            border: 1.5px solid #1f1f1f;
        }

        .btn-outline:hover {
            background: #1f1f1f;
            color: white;
            transform: translateY(-1px);
        }

        .btn-primary {
            background: var(--blue);
            color: white;
            box-shadow: 0 4px 14px rgba(59, 107, 238, 0.3);
        }

        .btn-primary:hover {
            background: var(--blue-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(59, 107, 238, 0.35);
        }

        .btn-large {
            padding: 0.9rem 2.2rem;
            font-size: 1rem;
        }

        /* Hamburger (mobile) */
        .hamburger {
            display: none;
            flex-direction: column;
            gap: 5px;
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
        }

        .hamburger span {
            width: 26px;
            height: 3px;
            background: #1f1f1f;
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

        /* ============================================================
                   HERO (split layout)
                   ============================================================ */
        .hero {
            padding: 3rem 0 4rem 0;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            align-items: center;
        }

        .hero-content {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .hero-title {
            font-size: 4.2rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1.1;
            color: var(--text-dark);
            margin-bottom: 1.5rem;
        }

        .hero-title .highlight {
            color: var(--blue);
        }

        .hero-subtitle {
            font-size: 1.2rem;
            font-weight: 400;
            color: var(--text-muted);
            max-width: 90%;
            margin-bottom: 2.5rem;
            line-height: 1.6;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }

        /* ============================================================
                   ILLUSTRATION (right side)
                   ============================================================ */
        .hero-illustration {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .illustration-wrapper {
            width: 100%;
            max-width: 520px;
            background: #ebebeb;
            border-radius: 24px;
            padding: 1.5rem 1rem;
            box-shadow: var(--shadow-sm);
        }

        .illustration-svg {
            display: block;
            width: 100%;
            height: auto;
        }

        /* ============================================================
                   SOCIAL PROOF
                   ============================================================ */
        .social-proof {
            padding: 3.5rem 0 4rem 0;
            border-top: 1px solid var(--border-light);
            background: var(--bg);
        }

        .social-proof .container {
            text-align: center;
        }

        .proof-text {
            font-size: 1.1rem;
            font-weight: 500;
            color: #1f1f1f;
            margin-bottom: 2rem;
        }

        .logo-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 2.5rem 3.5rem;
        }

        .logo-placeholder {
            font-size: 0.9rem;
            font-weight: 600;
            color: #4a4a4a;
            text-transform: uppercase;
            background: #e6e6e6;
            padding: 0.5rem 1.5rem;
            border-radius: 40px;
            border: 1px solid #dcdcdc;
            min-width: 100px;
            text-align: center;
            filter: grayscale(0.9);
            opacity: 0.8;
            letter-spacing: 0.04em;
        }

        /* ============================================================
                   CTA SECTION (blue, flat)
                   ============================================================ */
        .cta-section {
            padding: 5rem 2rem;
            background: var(--blue);
            color: white;
            text-align: center;
        }

        .cta-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .cta-section h2 {
            font-size: 2.6rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            margin-bottom: 1rem;
        }

        .cta-section p {
            font-size: 1.2rem;
            opacity: 0.85;
            margin-bottom: 2rem;
        }

        .cta-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-white {
            background: white;
            color: var(--blue);
        }

        .btn-white:hover {
            background: #f0f4ff;
            transform: translateY(-2px);
        }

        .btn-ghost {
            background: rgba(255, 255, 255, 0.12);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.25);
        }

        .btn-ghost:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        /* ============================================================
                   FOOTER
                   ============================================================ */
        .footer {
            background: #0b0b0b;
            color: rgba(255, 255, 255, 0.7);
            padding: 3rem 2rem 2rem;
        }

        .footer-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 2.5rem;
        }

        .footer-brand h3 {
            color: white;
            font-size: 1.4rem;
            margin-bottom: 0.5rem;
        }

        .footer-brand p {
            font-size: 0.9rem;
            line-height: 1.7;
            max-width: 300px;
            opacity: 0.7;
        }

        .footer-col h4 {
            color: white;
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }

        .footer-col a {
            display: block;
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.5);
            padding: 4px 0;
            transition: var(--transition);
            text-decoration: none;
        }

        .footer-col a:hover {
            color: white;
            padding-left: 6px;
        }

        .footer-bottom {
            max-width: 1280px;
            margin: 2rem auto 0;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            text-align: center;
            font-size: 0.85rem;
            opacity: 0.5;
        }

        /* ============================================================
                   RESPONSIVE
                   ============================================================ */
        @media (max-width: 1024px) {
            .hero-title {
                font-size: 3.4rem;
            }
        }

        @media (max-width: 860px) {
            .hero-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            .hero-title {
                font-size: 3rem;
            }
            .hero-subtitle {
                max-width: 100%;
            }
            .illustration-wrapper {
                max-width: 100%;
            }
            .footer-container {
                grid-template-columns: 1fr 1fr;
                gap: 2rem;
            }
        }

        @media (max-width: 768px) {
            .navbar .container {
                flex-wrap: wrap;
            }

            .nav-links {
                display: none;
                flex-direction: column;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: var(--bg);
                padding: 1.5rem 2rem;
                gap: 1.2rem;
                border-bottom: 1px solid var(--border-light);
                box-shadow: var(--shadow-md);
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
                padding: 2rem 0 3rem;
            }
            .hero-title {
                font-size: 2.4rem;
            }
            .hero-subtitle {
                font-size: 1rem;
            }

            .cta-section h2 {
                font-size: 2rem;
            }
            .cta-section p {
                font-size: 1rem;
            }

            .footer-container {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
        }

        @media (max-width: 480px) {
            .hero-title {
                font-size: 2rem;
            }
            .btn-large {
                padding: 0.7rem 1.5rem;
                font-size: 0.9rem;
            }
            .container {
                padding: 0 1rem;
            }
        }
    </style>
</head>
<body>  

    <!-- ============================================================
    STICKY NAVBAR (minimalist, no "Lando")
    ============================================================ -->
    <nav class="navbar" id="navbar">
        <div class="container">
            <a href="#" class="nav-brand">

                <span>company name</span>
            </a>

            <ul class="nav-links" id="navLinks">
                <li><a href="#features">Features</a></li>
                <li><a href="#about">About</a></li>
                <li><a href="#contact">Contact</a></li>
            </ul>

            <div class="nav-actions">
                <a href="login.php" class="btn btn-outline">Log in</a>
                <a href="portals/applicant/register.php" class="btn btn-primary">Sign up</a>
                <button class="hamburger" id="hamburger" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </nav>

    <!-- ============================================================
    HERO SECTION (split layout)
    ============================================================ -->
    <section class="hero" id="features">
        <div class="container hero-grid">
            <!-- left side -->
            <div class="hero-content">
                <h1 class="hero-title">
                    few words on the company<br />
                    <span class="highlight">Business Growth</span>
                </h1>
                <p class="hero-subtitle">
                   anything na ilalagay here for the clients and stuff para ma hook sila 
                </p>
                <div class="hero-actions">
                    <a href="portals/applicant/register.php" class="btn btn-primary btn-large">
                        <svg width="18" height="18" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14" />
                            <path d="M5 12h14" />
                        </svg>
                        Start Free Trial
                    </a>
                    <a href="#about" class="btn btn-outline btn-large">See how it works</a>
                </div>
            </div>

            <!-- right side: isometric illustration -->
            <div class="hero-illustration">
                <div class="illustration-wrapper">
                    <svg class="illustration-svg" viewBox="0 0 500 380" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect width="500" height="380" rx="18" fill="#E6ECF5" />
                        <!-- isometric floor -->
                        <polygon points="70,320 250,370 430,320 250,270" fill="#D2DCE8" stroke="#B0C0D0" stroke-width="1.5" />
                        <polygon points="70,320 250,270 250,200 70,250" fill="#C8D4E2" stroke="#B0C0D0" stroke-width="1.5" />
                        <polygon points="430,320 250,270 250,200 430,250" fill="#BDCBDB" stroke="#B0C0D0" stroke-width="1.5" />
                        <!-- desk / interface -->
                        <rect x="180" y="210" width="140" height="80" rx="6" fill="#3B6BEE" fill-opacity="0.15" stroke="#3B6BEE" stroke-width="1.8" stroke-dasharray="4 3" />
                        <rect x="190" y="220" width="50" height="30" rx="4" fill="#3B6BEE" fill-opacity="0.25" />
                        <rect x="250" y="220" width="60" height="30" rx="4" fill="#3B6BEE" fill-opacity="0.2" />
                        <circle cx="230" cy="270" r="12" fill="#3B6BEE" fill-opacity="0.3" stroke="#3B6BEE" stroke-width="1.5" />
                        <circle cx="280" cy="270" r="10" fill="#3B6BEE" fill-opacity="0.25" stroke="#3B6BEE" stroke-width="1.5" />
                        <!-- person -->
                        <circle cx="360" cy="200" r="16" fill="#5A7FB5" />
                        <rect x="348" y="215" width="24" height="40" rx="6" fill="#4A6A9E" />
                        <rect x="340" y="235" width="10" height="30" rx="4" fill="#3B5A85" />
                        <rect x="370" y="235" width="10" height="30" rx="4" fill="#3B5A85" />
                        <rect x="350" y="255" width="10" height="22" rx="4" fill="#2D4A73" />
                        <rect x="365" y="255" width="10" height="22" rx="4" fill="#2D4A73" />
                        <!-- charts -->
                        <rect x="90" y="130" width="40" height="60" rx="4" fill="#3B6BEE" fill-opacity="0.25" stroke="#3B6BEE" stroke-width="1.5" />
                        <rect x="140" y="100" width="40" height="90" rx="4" fill="#3B6BEE" fill-opacity="0.2" stroke="#3B6BEE" stroke-width="1.5" />
                        <rect x="190" y="90" width="40" height="100" rx="4" fill="#3B6BEE" fill-opacity="0.3" stroke="#3B6BEE" stroke-width="1.5" />
                        <!-- gears -->
                        <circle cx="110" cy="220" r="24" stroke="#3B6BEE" stroke-width="3" fill="none" stroke-dasharray="8 6" />
                        <circle cx="110" cy="220" r="12" fill="#3B6BEE" fill-opacity="0.2" stroke="#3B6BEE" stroke-width="2" />
                        <circle cx="400" cy="150" r="30" stroke="#3B6BEE" stroke-width="3" fill="none" stroke-dasharray="10 8" />
                        <circle cx="400" cy="150" r="16" fill="#3B6BEE" fill-opacity="0.15" stroke="#3B6BEE" stroke-width="2" />
                        <circle cx="420" cy="280" r="18" stroke="#5A7FB5" stroke-width="2.5" fill="none" stroke-dasharray="6 5" />
                        <!-- nodes -->
                        <circle cx="70" cy="180" r="8" fill="#3B6BEE" fill-opacity="0.4" />
                        <circle cx="440" cy="190" r="8" fill="#3B6BEE" fill-opacity="0.3" />
                        <line x1="70" y1="180" x2="110" y2="220" stroke="#3B6BEE" stroke-width="1.5" stroke-dasharray="4 4" />
                        <line x1="440" y1="190" x2="400" y2="150" stroke="#3B6BEE" stroke-width="1.5" stroke-dasharray="4 4" />
                        <rect x="300" y="300" width="80" height="20" rx="6" fill="#3B6BEE" fill-opacity="0.2" stroke="#3B6BEE" stroke-width="1" />
                        <rect x="310" y="305" width="30" height="10" rx="3" fill="#3B6BEE" fill-opacity="0.35" />
                        <circle cx="160" cy="300" r="6" fill="#3B6BEE" fill-opacity="0.4" />
                        <circle cx="190" cy="310" r="6" fill="#3B6BEE" fill-opacity="0.3" />
                        <circle cx="220" cy="300" r="6" fill="#3B6BEE" fill-opacity="0.5" />
                    </svg>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
    SOCIAL PROOF
    ============================================================ -->
    <section class="social-proof" id="about">
        <div class="container">
            <p class="proof-text">Trusted by individuals and teams</p>
            <div class="logo-row">
                <span class="logo-placeholder">Company</span>
            </div>
        </div>
    </section>

    <!-- ============================================================
    CTA SECTION (from old design, adapted)
    ============================================================ -->
    <section class="cta-section" id="contact">
        <div class="cta-container">
            <h2>Ready to be recruited?</h2>
            <p>
                Join our platform today and take the first step towards a brighter future. Our streamlined process ensures that you can focus on what matters most — your career growth.
            </p>
            <div class="cta-actions">
                <a href="portals/applicant/register.php" class="btn btn-white btn-large">
                    <svg width="18" height="18" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
                    Start Now — It's Free
                </a>
                <a href="#" class="btn btn-ghost btn-large">
                    <svg width="18" height="18" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                        <line x1="16" y1="2" x2="16" y2="6" />
                        <line x1="8" y1="2" x2="8" y2="6" />
                        <line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                    Book a Demo
                </a>
            </div>
        </div>
    </section>

    <!-- ============================================================
    FOOTER (from old design, adapted)
    ============================================================ -->
    <footer class="footer" id="footer">
        <div class="footer-container">
            <div class="footer-brand">
                <h3>company name</h3>
                <p>
                    apply na nga 
                </p>
            </div>
          
            <div class="footer-col">
                <h4>Company</h4>
                <a href="#">About</a>
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

    <!-- ============================================================
    JAVASCRIPT
    ============================================================ -->
    <script>
        // =============================================
        // 1. NAVBAR SCROLL EFFECT
        // =============================================
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', function() {
            if (window.scrollY > 40) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // =============================================
        // 2. MOBILE HAMBURGER
        // =============================================
        const hamburger = document.getElementById('hamburger');
        const navLinks = document.getElementById('navLinks');

        hamburger.addEventListener('click', function() {
            hamburger.classList.toggle('active');
            navLinks.classList.toggle('open');
        });

        document.querySelectorAll('.nav-links a').forEach(function(link) {
            link.addEventListener('click', function() {
                hamburger.classList.remove('active');
                navLinks.classList.remove('open');
            });
        });

        // =============================================
        // 3. SMOOTH SCROLL
        // =============================================
        document.querySelectorAll('a[href^="#"]').forEach(function(anchor) {
            anchor.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href === '#') return;
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        // =============================================
        // 4. ESC TO CLOSE MENU
        // =============================================
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hamburger.classList.remove('active');
                navLinks.classList.remove('open');
            }
        });

        console.log('Minimalist ERP Landing Page loaded.');
    </script>

</body>
</html>
