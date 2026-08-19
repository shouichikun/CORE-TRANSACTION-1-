<?php
// /CT1/api/biometric_verify.php - Face Verification API
session_start();
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../app/config.php';

// Get the input data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Log the request for debugging
error_log("Face Verification Request: " . $input);

$action = $data['action'] ?? '';

if ($action === 'enroll') {
    $userId = isset($data['user_id']) ? intval($data['user_id']) : 0;
    $descriptor = $data['descriptor'] ?? null;
    $snapshot = $data['snapshot'] ?? null;
    
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
        global $conn;
        
        // Check if user already has a face enrollment
        $checkSql = "SELECT id FROM face_verification WHERE user_id = ?";
        $checkStmt = mysqli_prepare($conn, $checkSql);
        mysqli_stmt_bind_param($checkStmt, "i", $userId);
        mysqli_stmt_execute($checkStmt);
        $checkResult = mysqli_stmt_get_result($checkStmt);
        $existing = mysqli_fetch_assoc($checkResult);
        mysqli_stmt_close($checkStmt);
        
        // Convert descriptor to JSON
        $descriptorJson = json_encode($descriptor);
        
        if ($existing) {
            // Update existing
            $sql = "UPDATE face_verification SET 
                    face_descriptor = ?,
                    snapshot = ?,
                    updated_at = NOW()
                    WHERE user_id = ?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ssi", $descriptorJson, $snapshot, $userId);
            $result = mysqli_stmt_execute($stmt);
            $affected = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            
            if ($result) {
                // Update applicants table
                $updateSql = "UPDATE applicants SET face_verified = 1, face_verified_at = NOW() WHERE user_id = ?";
                $updateStmt = mysqli_prepare($conn, $updateSql);
                mysqli_stmt_bind_param($updateStmt, "i", $userId);
                mysqli_stmt_execute($updateStmt);
                mysqli_stmt_close($updateStmt);
                
                error_log("Face enrollment updated for user: $userId");
                echo json_encode(['success' => true, 'message' => 'Face updated successfully', 'action' => 'updated']);
            } else {
                error_log("Failed to update face: " . mysqli_error($conn));
                echo json_encode(['success' => false, 'error' => 'Failed to update face data']);
            }
        } else {
            // Create new
            $sql = "INSERT INTO face_verification (user_id, face_descriptor, snapshot, created_at) 
                    VALUES (?, ?, ?, NOW())";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "iss", $userId, $descriptorJson, $snapshot);
            $result = mysqli_stmt_execute($stmt);
            $id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
            
            if ($result) {
                // Update applicants table
                $updateSql = "UPDATE applicants SET face_verified = 1, face_verified_at = NOW() WHERE user_id = ?";
                $updateStmt = mysqli_prepare($conn, $updateSql);
                mysqli_stmt_bind_param($updateStmt, "i", $userId);
                mysqli_stmt_execute($updateStmt);
                mysqli_stmt_close($updateStmt);
                
                // Log activity
                logActivity($userId, 'Face Enrolled', 'face_verification', $id, 'Face biometric enrolled for applicant');
                
                error_log("Face enrollment created for user: $userId, ID: $id");
                echo json_encode(['success' => true, 'message' => 'Face enrolled successfully', 'action' => 'created']);
            } else {
                error_log("Failed to insert face: " . mysqli_error($conn));
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
    
    $sql = "SELECT id, face_descriptor FROM face_verification WHERE user_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $faceData = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($faceData) {
        echo json_encode([
            'success' => true, 
            'has_face' => true,
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

echo json_encode(['success' => false, 'error' => 'Invalid action']);