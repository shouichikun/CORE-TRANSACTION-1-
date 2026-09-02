// CT1/session_guard.js
(function() {
    // Configuration
    const SESSION_TIMEOUT = 420000; // 7 minutes in milliseconds (7 * 60 * 1000)

    let timeoutId = null;
    let modalShown = false;

    // =============================================
    // 1. INJECT CSS & MODAL HTML AUTOMATICALLY
    // =============================================
    const style = document.createElement('style');
    style.innerHTML = `
        .session-modal-overlay {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.6); backdrop-filter: blur(8px);
            z-index: 99999; display: none; justify-content: center; align-items: center; padding: 1rem;
        }
        .session-modal-overlay.active { display: flex; }
        .session-modal-box {
            background: white; border-radius: 1.5rem; max-width: 440px; width: 100%;
            padding: 2rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); text-align: center;
            animation: sessionSlideUp 0.3s ease;
        }
        @keyframes sessionSlideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .session-modal-icon { font-size: 3.5rem; margin-bottom: 0.5rem; color: #dc2626; }
        .session-modal-title { font-size: 1.25rem; font-weight: 700; margin-bottom: 0.5rem; color: #1b1b24; }
        .session-modal-text { color: #464555; font-size: 0.875rem; margin-bottom: 1.5rem; }
        .session-modal-btns { display: flex; gap: 0.75rem; justify-content: center; }
        .session-btn-danger {
            padding: 0.625rem 1.5rem; background: #dc2626; color: white; border: none;
            border-radius: 0.75rem; font-weight: 600; font-size: 0.875rem; cursor: pointer;
            transition: all 0.15s; width: 100%;
        }
        .session-btn-danger:hover { background: #b91c1c; }
    `;
    document.head.appendChild(style);

    // Inject Modal HTML (Uses SVG for a clean red alert icon)
    const modalHTML = `
        <div class="session-modal-overlay" id="sessionGuardModal">
            <div class="session-modal-box">
                <div class="session-modal-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                        <line x1="12" y1="9" x2="12" y2="13"></line>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                </div>
                <h2 class="session-modal-title">Session Expired</h2>
                <p class="session-modal-text">
                    You have been inactive for too long. Please log in again to continue.
                </p>
                <div class="session-modal-btns">
                    <button class="session-btn-danger" onclick="window.location.href='/logout.php'">Logout</button>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHTML);

    // =============================================
    // 2. CORE LOGIC
    // =============================================
    function startTimer() {
        clearTimeout(timeoutId);
        modalShown = false;
        
        timeoutId = setTimeout(() => {
            // Show the modal
            document.getElementById('sessionGuardModal').classList.add('active');
            modalShown = true;
            
            // Auto-redirect to logout if they do nothing
            setTimeout(() => {
                window.location.href = 'logout.php';
            }, 10000);
        }, SESSION_TIMEOUT);
    }

    // =============================================
    // 3. TRACK USER ACTIVITY
    // =============================================
    const activityEvents = ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'];
    activityEvents.forEach(event => {
        document.addEventListener(event, () => {
            // If modal is shown, DO NOT reset. They MUST logout.
            if (!modalShown) {
                startTimer();
            }
        });
    });

    // =============================================
    // 4. START INITIAL TIMER
    // =============================================
    startTimer();
})();
