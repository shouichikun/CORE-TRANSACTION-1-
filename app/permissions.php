<?php
// app/permissions.php - Permission Helper Functions
// Test: This will cause an error if the file loads
error_log("permissions.php loaded successfully");
/**
 * Get all permissions for a user
 * 
 * @param int $userId The user ID
 * @return array Array of permission keys
 */
function getUserPermissions($userId) {
    $user = getUserById($userId);
    if (!$user) return [];
    
    // Admin gets all permissions
    if ($user['role'] === 'admin') {
        $permissionGroups = getPermissionGroups();
        $allPermissions = [];
        foreach ($permissionGroups as $group) {
            $allPermissions = array_merge($allPermissions, array_keys($group['permissions']));
        }
        return $allPermissions;
    }
    
    // Get role permissions from database
    $role = getRecord("SELECT permissions FROM roles WHERE role_name = ?", [$user['role']], "s");
    if (!$role) return [];
    
    $permissions = json_decode($role['permissions'], true) ?: [];
    return $permissions;
}

/**
 * Get permission groups definition
 * 
 * @return array Permission groups
 */
function getPermissionGroups() {
    return [
        'dashboard' => [
            'label' => 'Dashboard',
            'icon' => 'dashboard',
            'permissions' => [
                'view_dashboard' => 'View Dashboard',
                'view_stats' => 'View Statistics',
            ]
        ],
        'users' => [
            'label' => 'User Management',
            'icon' => 'people',
            'permissions' => [
                'view_users' => 'View Users',
                'create_users' => 'Create Users',
                'edit_users' => 'Edit Users',
                'delete_users' => 'Delete Users',
                'manage_roles' => 'Manage Roles',
            ]
        ],
        'clients' => [
            'label' => 'Client Management',
            'icon' => 'business',
            'permissions' => [
                'view_clients' => 'View Clients',
                'create_clients' => 'Create Clients',
                'edit_clients' => 'Edit Clients',
                'delete_clients' => 'Delete Clients',
            ]
        ],
        'employees' => [
            'label' => 'Employee Management',
            'icon' => 'badge',
            'permissions' => [
                'view_employees' => 'View Employees',
                'create_employees' => 'Create Employees',
                'edit_employees' => 'Edit Employees',
                'delete_employees' => 'Delete Employees',
            ]
        ],
        'jobs' => [
            'label' => 'Job Management',
            'icon' => 'work',
            'permissions' => [
                'view_jobs' => 'View Jobs',
                'create_jobs' => 'Create Jobs',
                'edit_jobs' => 'Edit Jobs',
                'delete_jobs' => 'Delete Jobs',
                'manage_applications' => 'Manage Applications',
            ]
        ],
        'applications' => [
            'label' => 'Applications',
            'icon' => 'description',
            'permissions' => [
                'view_applications' => 'View Applications',
                'review_applications' => 'Review Applications',
                'schedule_interviews' => 'Schedule Interviews',
            ]
        ],
        'agencies' => [
            'label' => 'Agency Management',
            'icon' => 'apartment',
            'permissions' => [
                'view_agencies' => 'View Agencies',
                'create_agencies' => 'Create Agencies',
                'edit_agencies' => 'Edit Agencies',
                'delete_agencies' => 'Delete Agencies',
            ]
        ],
        'settings' => [
            'label' => 'System Settings',
            'icon' => 'settings',
            'permissions' => [
                'view_settings' => 'View Settings',
                'edit_settings' => 'Edit Settings',
                'manage_biometric' => 'Manage Biometric',
                'view_logs' => 'View Audit Logs',
            ]
        ],
        'profile' => [
            'label' => 'Profile',
            'icon' => 'person',
            'permissions' => [
                'view_profile' => 'View Profile',
                'edit_profile' => 'Edit Profile',
                'change_password' => 'Change Password',
            ]
        ],
        // ✅ ADD THIS - Reports permission group
        'reports' => [
            'label' => 'Reports & Analytics',
            'icon' => 'analytics',
            'permissions' => [
                'view_reports' => 'View Reports',
                'export_reports' => 'Export Reports',
                'schedule_reports' => 'Schedule Reports',
            ]
        ]
    ];
}

/**
 * Check if a user has a specific permission
 * 
 * @param int $userId The user ID
 * @param string $permission The permission key (e.g., 'view_users')
 * @return bool True if user has permission
 */
function hasPermission($userId, $permission) {
    if (empty($permission)) return false;
    
    $user = getUserById($userId);
    if (!$user) return false;
    
    // Admin always has all permissions
    if ($user['role'] === 'admin') return true;
    
    // Get role permissions
    $role = getRecord("SELECT permissions FROM roles WHERE role_name = ?", [$user['role']], "s");
    if (!$role) return false;
    
    $permissions = json_decode($role['permissions'], true) ?: [];
    return in_array($permission, $permissions);
}

/**
 * Check if a user has any of the given permissions
 * 
 * @param int $userId The user ID
 * @param array $permissions Array of permission keys
 * @return bool True if user has any of the permissions
 */
function hasAnyPermission($userId, $permissions) {
    foreach ($permissions as $permission) {
        if (hasPermission($userId, $permission)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if a user has all of the given permissions
 * 
 * @param int $userId The user ID
 * @param array $permissions Array of permission keys
 * @return bool True if user has all permissions
 */
function hasAllPermissions($userId, $permissions) {
    foreach ($permissions as $permission) {
        if (!hasPermission($userId, $permission)) {
            return false;
        }
    }
    return true;
}

/**
 * Require a permission - redirects to dashboard if not granted
 * 
 * @param int $userId The user ID
 * @param string $permission The permission key
 * @param string $redirectUrl URL to redirect to if no permission
 */
function requirePermission($userId, $permission, $redirectUrl = 'dashboard.php') {
    if (!hasPermission($userId, $permission)) {
        header('Location: ' . $redirectUrl);
        exit;
    }
}

/**
 * Require any of the given permissions
 * 
 * @param int $userId The user ID
 * @param array $permissions Array of permission keys
 * @param string $redirectUrl URL to redirect to if no permission
 */
function requireAnyPermission($userId, $permissions, $redirectUrl = 'dashboard.php') {
    if (!hasAnyPermission($userId, $permissions)) {
        header('Location: ' . $redirectUrl);
        exit;
    }
}