<?php
// app/face_functions.php - Face verification functions

define('FACE_SCRIPTS_PATH', dirname(__DIR__) . '/scripts/');
define('FACE_TEMP_PATH', dirname(__DIR__) . '/uploads/face_data/temp/');

function compareFaces($image1Path, $image2Path) {
    // Use the fixed Python script
    $scriptPath = FACE_SCRIPTS_PATH . 'face_compare_fixed.py';
    
    if (!file_exists($scriptPath)) {
        return ['success' => false, 'error' => 'Face comparison script not found'];
    }
    
    $command = escapeshellcmd("python " . $scriptPath . " " . 
                              escapeshellarg($image1Path) . " " . 
                              escapeshellarg($image2Path));
    
    $output = shell_exec($command);
    
    if ($output === null) {
        return ['success' => false, 'error' => 'Failed to execute face comparison'];
    }
    
    $result = json_decode($output, true);
    
    if (!$result) {
        return ['success' => false, 'error' => 'Invalid response from face comparison'];
    }
    
    return $result;
}