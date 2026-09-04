<?php
// api/biometric_verify.php - Face Verification API
// FIXED: Added duplicate face detection and PostgreSQL support

// Start session for user authentication
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set JSON header
header('Content-Type: application/json');

require_once '../app/config.php';
require_once '../app/includes/functions.php';

// Helper function to calculate Euclidean distance between two face descriptors
function calculateFaceDistance($descriptor1, $descriptor2) {
    if (count($descriptor1) !== count($descriptor2)) {
        return PHP_FLOAT_MAX;
    }
    
    $sum = 0;
    for ($i = 0; $i < count($descriptor1); $i++) {
        $diff = $descriptor1[$i] - $descriptor2[$i];
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

// Helper function to check if face already exists in database
function checkDuplicateFace($descriptor, $currentUserId = null, $threshold = 0.6) {
    // Fetch all face encodings from face_verification table
    $sql = "SELECT id, user_id, face_encoding FROM face_verification WHERE is_active = 1";
    if ($currentUserId) {
        $sql .= " AND user_id != $currentUserId";
    }
    
    $existingFaces = getRecords($sql);
    
    if (!$existingFaces || count($existingFaces) === 0) {
        return false; // No existing faces to compare
    }
    
    $threshold = 0.6; // Lower threshold = stricter matching
    
    foreach ($existingFaces as $face) {
        $existingDescriptor = json_decode($face['face_encoding'], true);
        if (!$existingDescriptor || !is_array($existingDescriptor)) {
            continue;
        }
        
        try {
            $distance = calculateFaceDistance($descriptor, $existingDescriptor);
            error_log("Face distance: $distance for user_id: " . $face['user_id']);
            
            if ($distance < $threshold) {
                return [
                    'duplicate' => true,
                    'existing_user_id' => $face['user_id'],
                    'distance' => $distance
                ];
            }
        } catch (Exception $e) {
            error_log("Error calculating face distance: " . $e->getMessage());
            continue;
        }
    }
    
    return false;
}

// Get the input data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Log the request for debugging
error_log("Face Verification Request: " . $input);

$action = $data['action'] ?? '';
$redirect = $data['redirect'] ?? 'dashboard.php';

if ($action === 'enroll') {
    $userId = isset($data['user_id']) ? intval($data['user_id']) : 0;
    $descriptor = $data['descriptor'] ?? null;
    $snapshot = $data['snapshot'] ?? null;
    $checkDuplicate = $data['check_duplicate'] ?? true;
    
    // Validate input
    if (!$userId) {
        echo json_encode(['success' => false, 'error' => 'User ID is required']);
        exit;
    }
    
    if (!$descriptor || !is_array($descriptor) || count($descriptor) < 10) {
        error_log("Invalid descriptor: " . json_encode($descriptor));
        echo json_encode(['success' => false, 'error' => 'Invalid face descriptor data']);
        exit;
    }
    
    try {
        // ✅ CHECK FOR DUPLICATE FACE
        if ($checkDuplicate) {
            $duplicateCheck = checkDuplicateFace($descriptor, $userId, 0.6);
            
            if ($duplicateCheck && $duplicateCheck['duplicate']) {
                error_log("DUPLICATE FACE DETECTED: User $userId tried to register a face already used by user " . $duplicateCheck['existing_user_id']);
                echo json_encode([
                    'success' => false, 
                    'error' => 'This face is already registered to another user. Please contact support.',
                    'duplicate' => true,
                    'existing_user_id' => $duplicateCheck['existing_user_id']
                ]);
                exit;
            }
        }
        
        // Check if user already has a face enrollment
        $existing = getRecord("SELECT id FROM face_verification WHERE user_id = $1", [$userId]);
        
        // Convert descriptor to JSON
        $descriptorJson = json_encode($descriptor);
        
        if ($existing) {
            // Update existing
            $result = updateRecord("
                UPDATE face_verification SET 
                    face_encoding = $1,
                    image_path = $2,
                    expressions = $3,
                    liveness_score = $4,
                    is_active = 1,
                    updated_at = NOW()
                WHERE user_id = $5
            ", [
                $descriptorJson,
                $snapshot,
                '{"neutral": 0.99, "happy": 0.01}', // Default expressions
                0.95, // Default liveness score
                $userId
            ]);
            
            if ($result) {
                // Update applicants table
                updateRecord("
                    UPDATE applicants SET face_verified = 1, face_verified_at = NOW() 
                    WHERE user_id = $1
                ", [$userId]);
                
                error_log("Face enrollment updated for user: $userId");
                echo json_encode([
                    'success' => true, 
                    'message' => 'Face updated successfully', 
                    'action' => 'updated'
                ]);
            } else {
                error_log("Failed to update face for user: $userId");
                echo json_encode(['success' => false, 'error' => 'Failed to update face data']);
            }
        } else {
            // Create new
            $faceId = insertRecord("
                INSERT INTO face_verification (
                    user_id, 
                    face_encoding, 
                    image_path, 
                    expressions, 
                    liveness_score, 
                    is_active, 
                    created_at, 
                    updated_at
                ) VALUES ($1, $2, $3, $4, $5, 1, NOW(), NOW())
                RETURNING id
            ", [
                $userId,
                $descriptorJson,
                $snapshot,
                '{"neutral": 0.99, "happy": 0.01}', // Default expressions
                0.95 // Default liveness score
            ]);
            
            if ($faceId) {
                // Update applicants table
                updateRecord("
                    UPDATE applicants SET face_verified = 1, face_verified_at = NOW() 
                    WHERE user_id = $1
                ", [$userId]);
                
                error_log("Face enrollment created for user: $userId, ID: $faceId");
                echo json_encode([
                    'success' => true, 
                    'message' => 'Face enrolled successfully', 
                    'action' => 'created',
                    'face_id' => $faceId
                ]);
            } else {
                error_log("Failed to insert face for user: $userId");
                echo json_encode(['success' => false, 'error' => 'Failed to save face data']);
            }
        }
        
    } catch (Exception $e) {
        error_log("Face enrollment error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
    
    exit;
}

// Handle verification (check if user has face data)
if ($action === 'check') {
    $userId = isset($data['user_id']) ? intval($data['user_id']) : 0;
    
    if (!$userId) {
        echo json_encode(['success' => false, 'error' => 'User ID is required']);
        exit;
    }
    
    $faceData = getRecord("
        SELECT id, face_encoding, is_active 
        FROM face_verification 
        WHERE user_id = $1
    ", [$userId]);
    
    if ($faceData) {
        echo json_encode([
            'success' => true, 
            'has_face' => true,
            'is_active' => $faceData['is_active'] == 1,
            'message' => 'Face verification found'
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'has_face' => false,
            'message' => 'No face verification found'
        ]);
    }
    exit;
}

// Handle verify (verify a face against stored descriptors)
if ($action === 'verify') {
    $userId = isset($data['user_id']) ? intval($data['user_id']) : 0;
    $descriptor = $data['descriptor'] ?? null;
    
    if (!$userId) {
        echo json_encode(['success' => false, 'error' => 'User ID is required']);
        exit;
    }
    
    if (!$descriptor || !is_array($descriptor) || count($descriptor) < 10) {
        echo json_encode(['success' => false, 'error' => 'Invalid face descriptor data']);
        exit;
    }
    
    // Get the user's stored face
    $storedFace = getRecord("
        SELECT id, user_id, face_encoding 
        FROM face_verification 
        WHERE user_id = $1 AND is_active = 1
    ", [$userId]);
    
    if (!$storedFace) {
        echo json_encode([
            'success' => false, 
            'verified' => false,
            'error' => 'No face registered for this user'
        ]);
        exit;
    }
    
    $storedDescriptor = json_decode($storedFace['face_encoding'], true);
    if (!$storedDescriptor || !is_array($storedDescriptor)) {
        echo json_encode([
            'success' => false, 
            'verified' => false,
            'error' => 'Invalid stored face data'
        ]);
        exit;
    }
    
    $distance = calculateFaceDistance($descriptor, $storedDescriptor);
    $threshold = 0.6;
    $verified = $distance < $threshold;
    
    error_log("Face verification for user $userId: distance=$distance, threshold=$threshold, verified=" . ($verified ? 'true' : 'false'));
    
    echo json_encode([
        'success' => true,
        'verified' => $verified,
        'distance' => $distance,
        'threshold' => $threshold,
        'message' => $verified ? 'Face matched successfully' : 'Face does not match'
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
