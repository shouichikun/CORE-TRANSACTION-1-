// public/js/face-auth.js
// Main face authentication handler with face-api.js
// Enhanced with obstruction detection (face mask, hand covering, etc.)

class FaceAuth {
    constructor(options = {}) {
        this.options = {
            // FIXED: Models are in /public/js/ not /public/js/face-models
            modelPath: '/public/js',
            minConfidence: 0.6,
            matchThreshold: 0.6,
            livenessThreshold: 0.7,
            // NEW: Obstruction detection settings
            obstructionThreshold: 0.3, // Max allowed obstruction area
            minFaceVisibility: 0.7,    // Minimum face visibility score
            requireBothEyes: true,      // Must detect both eyes
            requireNose: true,          // Must detect nose
            requireMouth: true,         // Must detect mouth
            ...options
        };
        
        this.video = null;
        this.canvas = null;
        this.overlay = null;
        this.isInitialized = false;
        this.isScanning = false;
        this.faceDetections = [];
        this.currentDescriptor = null;
        this.onDetect = null;
        this.onMatch = null;
        this.onError = null;
        
        // Bind methods
        this.init = this.init.bind(this);
        this.startCamera = this.startCamera.bind(this);
        this.stopCamera = this.stopCamera.bind(this);
        this.scanFace = this.scanFace.bind(this);
        this.enrollFace = this.enrollFace.bind(this);
        this.verifyFace = this.verifyFace.bind(this);
        this.detectLiveness = this.detectLiveness.bind(this);
        this.takeSnapshot = this.takeSnapshot.bind(this);
        this.checkObstructions = this.checkObstructions.bind(this);
        this.calculateFaceVisibility = this.calculateFaceVisibility.bind(this);
        this.detectFaceParts = this.detectFaceParts.bind(this);
    }

    // =============================================
    // 1. INITIALIZATION
    // =============================================
    
    async init(videoElement, canvasElement, overlayElement) {
        this.video = videoElement;
        this.canvas = canvasElement;
        this.overlay = overlayElement;
        
        try {
            // Load face-api.js models
            await this.loadModels();
            
            // Setup video
            await this.setupVideo();
            
            // Setup canvas
            this.setupCanvas();
            
            this.isInitialized = true;
            
            // Start detection loop
            this.detectLoop();
            
            this.updateStatus('Ready', 'idle');
            
            return true;
        } catch (error) {
            console.error('FaceAuth init error:', error);
            this.handleError('Failed to initialize face authentication.');
            return false;
        }
    }

    async loadModels() {
        const modelPath = this.options.modelPath;
        
        console.log('📡 Loading face models from:', modelPath);
        
        try {
            await faceapi.nets.tinyFaceDetector.loadFromUri(modelPath);
            await faceapi.nets.faceLandmark68Net.loadFromUri(modelPath);
            await faceapi.nets.faceRecognitionNet.loadFromUri(modelPath);
            await faceapi.nets.faceExpressionNet.loadFromUri(modelPath);
            
            console.log('✅ Face models loaded successfully');
            this.updateStatus('Models loaded', 'idle');
        } catch (error) {
            console.error('❌ Failed to load face models:', error);
            throw new Error('Could not load face recognition models. Please check the model files.');
        }
    }

    async setupVideo() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                    facingMode: 'user'
                },
                audio: false
            });
            
            this.video.srcObject = stream;
            await this.video.play();
            
            console.log('✅ Camera started');
            this.updateStatus('Camera ready', 'idle');
        } catch (error) {
            console.error('❌ Camera error:', error);
            if (error.name === 'NotAllowedError') {
                throw new Error('Camera access denied. Please allow camera permissions.');
            } else if (error.name === 'NotFoundError') {
                throw new Error('No camera found. Please connect a camera.');
            } else {
                throw new Error('Could not access camera. Please check your camera.');
            }
        }
    }

    setupCanvas() {
        const rect = this.video.getBoundingClientRect();
        this.canvas.width = rect.width || 640;
        this.canvas.height = rect.height || 480;
        
        // Position canvas over video
        this.canvas.style.position = 'absolute';
        this.canvas.style.top = '0';
        this.canvas.style.left = '0';
        this.canvas.style.width = '100%';
        this.canvas.style.height = '100%';
        this.canvas.style.pointerEvents = 'none';
        this.canvas.style.zIndex = '2';
        
        // Ensure video container has relative positioning
        const wrapper = this.video.parentElement;
        if (wrapper) {
            wrapper.style.position = 'relative';
            wrapper.style.display = 'flex';
            wrapper.style.alignItems = 'center';
            wrapper.style.justifyContent = 'center';
        }
    }

    // =============================================
    // 2. DETECTION LOOP WITH OBSTRUCTION CHECK
    // =============================================
    
    async detectLoop() {
        if (!this.isInitialized) return;
        
        try {
            const detection = await this.detectFace();
            
            if (detection) {
                // Check for obstructions
                const obstructionCheck = this.checkObstructions(detection);
                
                if (obstructionCheck.hasObstruction) {
                    // Face is obstructed - don't allow scanning
                    this.drawObstructionWarning(detection, obstructionCheck.reason);
                    this.updateStatus('⚠️ ' + obstructionCheck.reason, 'failed');
                    this.faceDetections = [];
                    this.currentDescriptor = null;
                } else {
                    // Face is clear and visible
                    this.faceDetections = detection;
                    this.currentDescriptor = detection.descriptor;
                    this.drawDetection(detection);
                    
                    if (!this.isScanning) {
                        this.updateStatus('Face detected ✓', 'idle');
                    }
                }
            } else {
                this.faceDetections = [];
                this.currentDescriptor = null;
                this.clearCanvas();
                if (!this.isScanning) {
                    this.updateStatus('No face detected', 'idle');
                }
            }
        } catch (error) {
            // Silent fail for loop
        }
        
        requestAnimationFrame(() => this.detectLoop());
    }

    // =============================================
    // 3. FACE DETECTION WITH OBSTRUCTION CHECK
    // =============================================
    
    async detectFace() {
        if (!this.video || this.video.paused) return null;
        
        const options = new faceapi.TinyFaceDetectorOptions({
            inputSize: 224,
            scoreThreshold: this.options.minConfidence
        });
        
        try {
            const detections = await faceapi.detectAllFaces(this.video, options)
                .withFaceLandmarks()
                .withFaceExpressions()
                .withFaceDescriptors();
            
            if (detections && detections.length > 0) {
                // Get the largest face (best quality)
                const sorted = detections.sort((a, b) => {
                    const aArea = a.detection.box.width * a.detection.box.height;
                    const bArea = b.detection.box.width * b.detection.box.height;
                    return bArea - aArea;
                });
                
                return sorted[0];
            }
        } catch (error) {
            // Silently handle detection errors
        }
        
        return null;
    }

    // =============================================
    // 4. OBSTRUCTION DETECTION (FACE MASK, HAND, ETC.)
    // =============================================
    
    /**
     * Check if the face is obstructed by mask, hand, or other objects
     */
    checkObstructions(detection) {
        if (!detection || !detection.landmarks) {
            return { hasObstruction: true, reason: 'No face landmarks detected' };
        }
        
        const landmarks = detection.landmarks;
        const positions = landmarks.positions;
        
        // Check for face parts visibility
        const faceParts = this.detectFaceParts(positions);
        
        // Calculate visibility score
        const visibilityScore = this.calculateFaceVisibility(faceParts);
        
        // Check if obstruction exceeds threshold
        const hasObstruction = visibilityScore < this.options.minFaceVisibility;
        
        // Determine reason for obstruction
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

    /**
     * Detect individual face parts visibility
     */
    detectFaceParts(positions) {
        if (!positions || positions.length < 68) {
            return {
                eyesVisible: false,
                noseVisible: false,
                mouthVisible: false,
                foreheadVisible: false,
                cheeksVisible: false
            };
        }
        
        // Face landmark indices (68-point model)
        // Left eye: 36-41, Right eye: 42-47
        // Nose: 27-35
        // Mouth: 48-67
        // Jaw: 0-16
        
        const leftEye = positions.slice(36, 42);
        const rightEye = positions.slice(42, 48);
        const nose = positions.slice(27, 35);
        const mouth = positions.slice(48, 67);
        const jaw = positions.slice(0, 17);
        
        // Calculate variance to detect occlusion
        // If points are too close together, they might be obstructed
        
        const eyeVariance = this.calculatePointVariance([...leftEye, ...rightEye]);
        const noseVariance = this.calculatePointVariance(nose);
        const mouthVariance = this.calculatePointVariance(mouth);
        const jawVariance = this.calculatePointVariance(jaw);
        
        // Threshold for visibility (higher variance = more visible)
        const visibilityThreshold = 2.5;
        
        return {
            eyesVisible: eyeVariance > visibilityThreshold && leftEye.length > 5 && rightEye.length > 5,
            noseVisible: noseVariance > 1.5 && nose.length > 7,
            mouthVisible: mouthVariance > 2.0 && mouth.length > 18,
            foreheadVisible: this.checkForeheadVisibility(positions),
            cheeksVisible: this.checkCheeksVisibility(positions)
        };
    }

    /**
     * Calculate variance of points (indicates how spread out they are)
     */
    calculatePointVariance(points) {
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

    /**
     * Check forehead visibility using jaw and eyebrow positions
     */
    checkForeheadVisibility(positions) {
        if (!positions || positions.length < 30) return false;
        
        // Use jaw top points (0-8) and eyebrows (17-26)
        const jawTop = positions.slice(0, 9);
        const leftEyebrow = positions.slice(17, 22);
        const rightEyebrow = positions.slice(22, 27);
        
        if (jawTop.length < 9 || leftEyebrow.length < 5 || rightEyebrow.length < 5) return false;
        
        // Calculate jaw width
        const jawWidth = Math.abs(jawTop[0].x - jawTop[8].x);
        const browWidth = Math.abs(leftEyebrow[0].x - rightEyebrow[4].x);
        
        // If brow width is significantly smaller than jaw width, forehead might be covered
        if (browWidth < jawWidth * 0.5) return false;
        
        // Check eyebrow variance
        const browPoints = [...leftEyebrow, ...rightEyebrow];
        const browVariance = this.calculatePointVariance(browPoints);
        
        return browVariance > 1.0;
    }

    /**
     * Check cheeks visibility
     */
    checkCheeksVisibility(positions) {
        if (!positions || positions.length < 30) return false;
        
        // Use jaw points (0-16) for cheek area
        const jaw = positions.slice(0, 17);
        if (jaw.length < 17) return false;
        
        // Check if jaw points have enough spread
        const jawVariance = this.calculatePointVariance(jaw);
        
        return jawVariance > 3.0;
    }

    /**
     * Calculate overall face visibility score
     */
    calculateFaceVisibility(faceParts) {
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

    // =============================================
    // 5. DRAWING WITH OBSTRUCTION WARNING
    // =============================================
    
    drawDetection(detection) {
        if (!this.canvas) return;
        
        const context = this.canvas.getContext('2d');
        const dims = faceapi.matchDimensions(this.canvas, this.video, true);
        
        context.clearRect(0, 0, this.canvas.width, this.canvas.height);
        
        if (!detection) return;
        
        // Resize detection to match canvas
        const resized = faceapi.resizeResults(detection, dims);
        
        // Check obstruction before drawing
        const obstructionCheck = this.checkObstructions(detection);
        
        if (obstructionCheck.hasObstruction) {
            // Draw obstruction warning
            this.drawObstructionWarning(resized, obstructionCheck.reason);
            return;
        }
        
        // Draw detection box (green = clear)
        const box = resized.detection.box;
        context.strokeStyle = '#22c55e';
        context.lineWidth = 3;
        context.strokeRect(box.x, box.y, box.width, box.height);
        
        // Draw landmarks
        faceapi.draw.drawFaceLandmarks(this.canvas, resized);
        
        // Draw expressions
        if (resized.expressions) {
            const expressions = resized.expressions;
            const topExpression = Object.keys(expressions).reduce((a, b) => 
                expressions[a] > expressions[b] ? a : b
            );
            
            context.fillStyle = 'rgba(79, 70, 229, 0.8)';
            context.font = 'bold 14px Inter, sans-serif';
            context.fillText(
                `${topExpression}: ${Math.round(expressions[topExpression] * 100)}%`,
                box.x,
                box.y - 10
            );
        }
        
        // Draw visibility score
        const visibility = obstructionCheck.visibilityScore || 1;
        const visibilityPercent = Math.round(visibility * 100);
        context.fillStyle = visibility > 0.7 ? 'rgba(34, 197, 94, 0.9)' : 'rgba(220, 38, 38, 0.9)';
        context.font = 'bold 12px Inter, sans-serif';
        context.fillText(
            `👁 ${visibilityPercent}% visible`,
            box.x + box.width - 120,
            box.y - 10
        );
        
        // Draw "Clear" indicator
        context.fillStyle = 'rgba(34, 197, 94, 0.8)';
        context.font = 'bold 14px Inter, sans-serif';
        context.fillText('✅ Face Clear', box.x + box.width - 100, box.y + box.height + 25);
    }

    drawObstructionWarning(detection, reason) {
        if (!this.canvas) return;
        
        const context = this.canvas.getContext('2d');
        const dims = faceapi.matchDimensions(this.canvas, this.video, true);
        
        context.clearRect(0, 0, this.canvas.width, this.canvas.height);
        
        if (!detection) return;
        
        const resized = faceapi.resizeResults(detection, dims);
        const box = resized.detection.box;
        
        // Draw red warning box
        context.strokeStyle = '#dc2626';
        context.lineWidth = 4;
        context.setLineDash([8, 8]);
        context.strokeRect(box.x, box.y, box.width, box.height);
        context.setLineDash([]);
        
        // Draw warning message
        const overlayGradient = context.createLinearGradient(0, box.y - 30, 0, box.y + 10);
        overlayGradient.addColorStop(0, 'rgba(220, 38, 38, 0.9)');
        overlayGradient.addColorStop(1, 'rgba(220, 38, 38, 0)');
        context.fillStyle = overlayGradient;
        context.fillRect(box.x, box.y - 30, box.width, 40);
        
        // Warning text
        context.fillStyle = '#ffffff';
        context.font = 'bold 14px Inter, sans-serif';
        context.fillText('⚠️ ' + reason, box.x + 10, box.y - 10);
        
        // Draw "Obstructed" indicator at bottom
        context.fillStyle = 'rgba(220, 38, 38, 0.8)';
        context.font = 'bold 14px Inter, sans-serif';
        context.fillText('🚫 Face Obstructed', box.x + box.width - 150, box.y + box.height + 25);
        
        // Draw face landmarks faintly for reference
        if (resized.landmarks) {
            const pts = resized.landmarks.positions;
            context.fillStyle = 'rgba(255, 255, 255, 0.3)';
            for (const p of pts) {
                context.beginPath();
                context.arc(p.x, p.y, 2, 0, 2 * Math.PI);
                context.fill();
            }
        }
    }

    clearCanvas() {
        if (!this.canvas) return;
        const context = this.canvas.getContext('2d');
        context.clearRect(0, 0, this.canvas.width, this.canvas.height);
    }

    // =============================================
    // 6. LIVENESS DETECTION (Enhanced)
    // =============================================
    
    calculateLivenessScore(detection) {
        if (!detection || !detection.expressions) return 0;
        
        // First check obstructions - if obstructed, liveness is 0
        const obstructionCheck = this.checkObstructions(detection);
        if (obstructionCheck.hasObstruction) {
            return 0;
        }
        
        const exp = detection.expressions;
        let score = 0;
        
        // Check for natural expressions (less likely in photos)
        const naturalExpressions = ['neutral', 'happy', 'surprised', 'sad'];
        const maxNatural = Math.max(...naturalExpressions.map(e => exp[e] || 0));
        
        // Check for micro-expressions (blinks, subtle movements)
        // This is a simplified version - in production, use temporal analysis
        
        score += maxNatural * 0.5;
        
        // Look for specific expression indicators
        if (exp.happy > 0.3) score += 0.2;
        if (exp.surprised > 0.2) score += 0.2;
        if (exp.neutral > 0.3) score += 0.1;
        
        // Bonus for variety
        const expressionVariance = Object.keys(exp).reduce((sum, key) => {
            return sum + Math.abs(exp[key] - 0.2);
        }, 0);
        score += Math.min(expressionVariance / 2, 0.3);
        
        // Bonus for face parts visibility (ensures real face)
        const faceParts = this.detectFaceParts(detection.landmarks.positions);
        if (faceParts.eyesVisible) score += 0.1;
        if (faceParts.noseVisible) score += 0.05;
        if (faceParts.mouthVisible) score += 0.05;
        
        return Math.min(score, 1);
    }

    // =============================================
    // 7. FACE SCANNING (Enhanced with obstruction check)
    // =============================================
    
    async scanFace() {
        if (this.isScanning) return null;
        
        this.isScanning = true;
        this.updateStatus('Scanning face...', 'scanning');
        
        const wrapper = this.video.parentElement;
        if (wrapper) wrapper.classList.add('scanning');
        
        try {
            // Wait for a good detection with clear face
            let detection = null;
            let attempts = 0;
            const maxAttempts = 20; // Increased for better detection
            
            while (attempts < maxAttempts) {
                await this.delay(100);
                detection = await this.detectFace();
                
                if (detection) {
                    // Check for obstructions
                    const obstructionCheck = this.checkObstructions(detection);
                    
                    if (!obstructionCheck.hasObstruction) {
                        // Face is clear - proceed
                        break;
                    } else {
                        // Face is obstructed - show warning and continue
                        this.drawObstructionWarning(detection, obstructionCheck.reason);
                        this.updateStatus('⚠️ ' + obstructionCheck.reason, 'failed');
                        // Reset detection and try again
                        detection = null;
                    }
                }
                
                attempts++;
                
                // Update status every few attempts
                if (attempts % 3 === 0) {
                    this.updateStatus(`Looking for clear face... (${attempts}/${maxAttempts})`, 'scanning');
                }
            }
            
            if (!detection) {
                this.handleError('No clear face detected. Please remove any obstruction and try again.');
                return null;
            }
            
            // Double-check obstruction one more time
            const obstructionCheck = this.checkObstructions(detection);
            if (obstructionCheck.hasObstruction) {
                this.handleError('Face is obstructed: ' + obstructionCheck.reason);
                return null;
            }
            
            // Check liveness
            const livenessScore = this.calculateLivenessScore(detection);
            const isLive = livenessScore > this.options.livenessThreshold;
            
            if (!isLive) {
                this.handleError('Spoof detected! Please use your real face.');
                this.updateStatus('⚠️ Spoof detected', 'failed');
                return null;
            }
            
            // Get face descriptor
            const descriptor = detection.descriptor;
            this.currentDescriptor = descriptor;
            
            this.updateStatus('Face captured ✓', 'success');
            
            // Take snapshot
            const snapshot = await this.takeSnapshot();
            
            return {
                descriptor: descriptor,
                snapshot: snapshot,
                detection: detection,
                expressions: detection.expressions,
                landmarks: detection.landmarks,
                livenessScore: livenessScore,
                visibilityScore: obstructionCheck.visibilityScore || 1,
                faceParts: obstructionCheck.faceParts || {}
            };
            
        } catch (error) {
            console.error('Face scan error:', error);
            this.handleError('Face scan failed. Please try again.');
            return null;
        } finally {
            this.isScanning = false;
            if (wrapper) wrapper.classList.remove('scanning');
        }
    }

    // =============================================
    // 8. ENROLLMENT (Enhanced)
    // =============================================
    
    async enrollFace(userId, userName = '') {
        const scanResult = await this.scanFace();
        
        if (!scanResult) return null;
        
        // Prepare enrollment data with obstruction info
        const enrollmentData = {
            user_id: userId,
            user_name: userName,
            descriptor: Array.from(scanResult.descriptor),
            expressions: scanResult.expressions,
            snapshot: scanResult.snapshot,
            liveness_score: scanResult.livenessScore,
            visibility_score: scanResult.visibilityScore || 1,
            face_parts: scanResult.faceParts || {},
            enrolled_at: new Date().toISOString(),
            provider: 'face-api.js',
            has_obstruction: false
        };
        
        // Send to server
        try {
            const response = await fetch('/api/face/enroll.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(enrollmentData)
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.updateStatus('✅ Face enrolled successfully!', 'success');
                return result;
            } else {
                this.handleError(result.error || 'Enrollment failed.');
                return null;
            }
        } catch (error) {
            console.error('Enrollment error:', error);
            this.handleError('Enrollment failed. Please try again.');
            return null;
        }
    }

    // =============================================
    // 9. VERIFICATION (Enhanced with obstruction check)
    // =============================================
    
    async verifyFace(storedDescriptors) {
        const scanResult = await this.scanFace();
        
        if (!scanResult) return null;
        
        if (!storedDescriptors || storedDescriptors.length === 0) {
            this.handleError('No stored face data found.');
            return null;
        }
        
        // Compare face with stored descriptors
        let bestMatch = null;
        let bestScore = 0;
        
        const currentDescriptor = new Float32Array(scanResult.descriptor);
        
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
                console.warn('Error comparing with stored descriptor:', e);
            }
        }
        
        // Check if match meets threshold
        const matchScore = Math.round(bestScore * 100);
        const isMatch = bestScore > this.options.matchThreshold;
        
        const result = {
            match: isMatch,
            score: matchScore,
            level: this.getMatchLevel(matchScore),
            user: bestMatch,
            livenessScore: scanResult.livenessScore,
            visibilityScore: scanResult.visibilityScore || 1,
            expressions: scanResult.expressions,
            snapshot: scanResult.snapshot,
            faceParts: scanResult.faceParts || {},
            obstructionChecked: true
        };
        
        // Update status
        if (isMatch) {
            this.updateStatus(`✅ Match! ${matchScore}%`, 'success');
        } else {
            this.updateStatus(`❌ No match (${matchScore}%)`, 'failed');
        }
        
        return result;
    }

    getMatchLevel(score) {
        if (score >= 80) return 'high';
        if (score >= 60) return 'medium';
        return 'low';
    }

    // =============================================
    // 10. CAMERA CONTROLS
    // =============================================
    
    async startCamera() {
        if (this.video && this.video.srcObject) {
            return;
        }
        
        await this.setupVideo();
        this.updateStatus('Camera started', 'idle');
    }

    stopCamera() {
        if (this.video && this.video.srcObject) {
            const tracks = this.video.srcObject.getTracks();
            tracks.forEach(track => track.stop());
            this.video.srcObject = null;
        }
        
        this.clearCanvas();
        this.updateStatus('Camera stopped', 'idle');
    }

    // =============================================
    // 11. SNAPSHOT
    // =============================================
    
    takeSnapshot() {
        return new Promise((resolve) => {
            const canvas = document.createElement('canvas');
            canvas.width = this.video.videoWidth || 640;
            canvas.height = this.video.videoHeight || 480;
            
            const context = canvas.getContext('2d');
            context.drawImage(this.video, 0, 0, canvas.width, canvas.height);
            
            // Draw face box if available
            if (this.faceDetections) {
                const detection = this.faceDetections;
                const box = detection.detection.box;
                
                // Check if face is clear (green box) or obstructed (red box)
                const obstructionCheck = this.checkObstructions(detection);
                context.strokeStyle = obstructionCheck.hasObstruction ? '#dc2626' : '#22c55e';
                context.lineWidth = 3;
                context.strokeRect(box.x, box.y, box.width, box.height);
                
                // Add obstruction warning if needed
                if (obstructionCheck.hasObstruction) {
                    context.fillStyle = 'rgba(220, 38, 38, 0.7)';
                    context.font = 'bold 20px Inter, sans-serif';
                    context.fillText('⚠️ OBSTRUCTED', box.x + 10, box.y - 15);
                }
            }
            
            resolve(canvas.toDataURL('image/jpeg', 0.9));
        });
    }

    // =============================================
    // 12. UI HELPERS
    // =============================================
    
    updateStatus(text, type = 'idle') {
        const statusElement = this.overlay?.querySelector('.face-status');
        const indicator = document.querySelector('.face-status-indicator');
        
        if (statusElement) {
            statusElement.textContent = text;
            statusElement.className = 'face-status show ' + type;
        }
        
        if (indicator) {
            const dot = indicator.querySelector('.status-dot');
            const textEl = indicator.querySelector('.status-text');
            
            if (dot) {
                dot.className = 'status-dot ' + type;
            }
            if (textEl) {
                textEl.textContent = text;
                textEl.className = 'status-text ' + type;
            }
        }
    }

    handleError(message) {
        console.error('FaceAuth error:', message);
        this.updateStatus('❌ ' + message, 'failed');
        
        if (this.onError) {
            this.onError(message);
        }
        
        if (typeof showToast === 'function') {
            showToast(message, 'error');
        }
    }

    // =============================================
    // 13. UTILITIES
    // =============================================
    
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    // =============================================
    // 14. DESTROY
    // =============================================
    
    destroy() {
        this.stopCamera();
        this.isInitialized = false;
        this.faceDetections = [];
        this.currentDescriptor = null;
        
        if (this.canvas && this.canvas.parentNode) {
            this.canvas.parentNode.removeChild(this.canvas);
        }
    }
}

// =============================================
// EXPORT
// =============================================
if (typeof module !== 'undefined' && module.exports) {
    module.exports = FaceAuth;
}