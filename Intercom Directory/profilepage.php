<?php
require_once 'conn.php';
require_once 'admin_archive_functions.php';

// Redirect to login if not logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Update user activity
updateAllUsersActivity($conn);

$error = "";
$success = "";
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Get current user data
$user_id = $_SESSION['user_id'];
$is_admin = isAdmin();
$user_data = [];

// Role names mapping
$role_names = [
    1 => 'Admin',
    2 => 'MCC',
    3 => 'Division Head',
    4 => 'Department Head',
    5 => 'Unit Head',
    6 => 'Office Head',
    7 => 'Staff'
];

// Fetch current user information - ALWAYS from database, never from POST
$sql = "SELECT u.* FROM users u WHERE u.user_id = ?";
$params = array($user_id);
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt && sqlsrv_has_rows($stmt)) {
    $user_data = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

    // Fetch user's organizational units from numbers table
    $units_sql = "SELECT 
                    n.*,
                    d.division_name,
                    dept.department_name,
                    un.unit_name,
                    o.office_name
                  FROM numbers n
                  LEFT JOIN divisions d ON n.division_id = d.division_id
                  LEFT JOIN departments dept ON n.department_id = dept.department_id
                  LEFT JOIN units un ON n.unit_id = un.unit_id
                  LEFT JOIN offices o ON n.office_id = o.office_id
                  WHERE n.head_user_id = ?";
    
    $units_params = array($user_id);
    $units_stmt = sqlsrv_query($conn, $units_sql, $units_params);

    $user_data['units'] = [];
    if ($units_stmt) {
        while ($unit = sqlsrv_fetch_array($units_stmt, SQLSRV_FETCH_ASSOC)) {
            $user_data['units'][] = $unit;
        }
        sqlsrv_free_stmt($units_stmt);
    }
} else {
    $error = "User not found!";
    header('Location: logout.php');
    exit;
}

if ($stmt) sqlsrv_free_stmt($stmt);

// Get active divisions for dropdown
$divisions = getDivisions($conn);

// ============================================
// CRITICAL FIX: ALWAYS use database values for profile form
// Never use POST data to repopulate these fields
// ============================================
$username = $user_data['username'];
$email = $user_data['email'];
$fullname = $user_data['full_name'];
$current_role_id = $user_data['role_id'];
$current_division_id = !empty($user_data['units'][0]['division_id']) ? $user_data['units'][0]['division_id'] : '';
$current_department_id = !empty($user_data['units'][0]['department_id']) ? $user_data['units'][0]['department_id'] : '';
$current_unit_id = !empty($user_data['units'][0]['unit_id']) ? $user_data['units'][0]['unit_id'] : '';
$current_office_id = !empty($user_data['units'][0]['office_id']) ? $user_data['units'][0]['office_id'] : '';

// ============================================
// Handle POST requests
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'update_profile') {
            // Update profile information
            $new_username = trim($_POST['username']);
            $new_email = trim($_POST['email']);
            $new_fullname = trim($_POST['fn']);

            if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $error = "Invalid email format!";
            }

            if (empty($error)) {
                $check_sql = "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?";
                $check_params = array($new_username, $new_email, $user_id);
                $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
                
                if ($check_stmt && sqlsrv_has_rows($check_stmt)) {
                    $error = "Username or email already exists!";
                } else {
                    $update_sql = "UPDATE users SET username = ?, email = ?, full_name = ? WHERE user_id = ?";
                    $update_params = array($new_username, $new_email, $new_fullname, $user_id);
                    $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);

                    if ($update_stmt) {
                        $_SESSION['username'] = $new_username;
                        $success = "Profile updated successfully!";
                        
                        // Refresh user data from database
                        $refresh_sql = "SELECT * FROM users WHERE user_id = ?";
                        $refresh_params = array($user_id);
                        $refresh_stmt = sqlsrv_query($conn, $refresh_sql, $refresh_params);
                        if ($refresh_stmt && $refresh_row = sqlsrv_fetch_array($refresh_stmt, SQLSRV_FETCH_ASSOC)) {
                            $user_data = $refresh_row;
                            $username = $user_data['username'];
                            $email = $user_data['email'];
                            $fullname = $user_data['full_name'];
                            $current_role_id = $user_data['role_id'];
                        }
                        if ($refresh_stmt) sqlsrv_free_stmt($refresh_stmt);
                    } else {
                        $error = "Failed to update profile: " . print_r(sqlsrv_errors(), true);
                    }
                    if ($update_stmt) sqlsrv_free_stmt($update_stmt);
                }
                if ($check_stmt) sqlsrv_free_stmt($check_stmt);
            }
        } 
        // ============================================
        // Change Password functionality
        // ============================================
        elseif ($action === 'change_password') {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            // Validation
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error = "All password fields are required!";
            } elseif (strlen($new_password) < 8) {
                $error = "New password must be at least 8 characters long!";
            } elseif ($new_password !== $confirm_password) {
                $error = "New password and confirm password do not match!";
            } else {
                // Verify current password
                $check_sql = "SELECT password FROM users WHERE user_id = ?";
                $check_params = array($user_id);
                $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
                
                if ($check_stmt && $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC)) {
                    $stored_password = $row['password'];
                    $password_valid = false;
                    
                    // Check if password is hashed or plain text
                    if (strlen($stored_password) == 60 && password_verify($current_password, $stored_password)) {
                        $password_valid = true;
                    } elseif ($current_password === $stored_password) {
                        $password_valid = true;
                    }
                    
                    if ($password_valid) {
                        // Hash the new password
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        
                        $update_sql = "UPDATE users SET password = ? WHERE user_id = ?";
                        $update_params = array($hashed_password, $user_id);
                        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
                        
                        if ($update_stmt) {
                            $success = "Password changed successfully!";
                        } else {
                            $error = "Failed to update password: " . print_r(sqlsrv_errors(), true);
                        }
                        if ($update_stmt) sqlsrv_free_stmt($update_stmt);
                    } else {
                        $error = "Current password is incorrect!";
                    }
                } else {
                    $error = "Unable to verify current password!";
                }
                if ($check_stmt) sqlsrv_free_stmt($check_stmt);
            }
        }
        // ============================================
        // Admin Add User functionality - FIXED: NEVER modify profile form variables
        // ============================================
        elseif ($action === 'add_user' && isAdmin()) {
            $new_username = trim($_POST['edit_username']);
            $new_email = trim($_POST['edit_email']);
            $new_fullname = trim($_POST['edit_fullname']);
            $role_id = intval($_POST['edit_role_id']);
            $password = $_POST['add_password'];
            $confirm_password = $_POST['confirm_password'];
            
            $division_id = !empty($_POST['division_id']) ? intval($_POST['division_id']) : null;
            $department_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
            $unit_id = !empty($_POST['unit_id']) ? intval($_POST['unit_id']) : null;
            $office_id = !empty($_POST['office_id']) ? intval($_POST['office_id']) : null;
            
            // Validation
            $errors = [];
            
            if (empty($new_username)) $errors[] = "Username is required";
            if (empty($new_email)) $errors[] = "Email is required";
            if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
            if (empty($new_fullname)) $errors[] = "Full name is required";
            if (empty($role_id)) $errors[] = "Role is required";
            if (empty($password)) $errors[] = "Password is required";
            if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters";
            if ($password !== $confirm_password) $errors[] = "Passwords do not match";
            
            // Check if role is head (3-6) - should not have org unit assignment in users table
            if ($role_id >= 3 && $role_id <= 6) {
                // Clear any org assignments - heads are assigned via numbers table
                $division_id = null;
                $department_id = null;
                $unit_id = null;
                $office_id = null;
            }
            
            if (empty($errors)) {
                // Check if username or email already exists
                $check_sql = "SELECT user_id FROM users WHERE username = ? OR email = ?";
                $check_params = array($new_username, $new_email);
                $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
                
                if ($check_stmt && sqlsrv_has_rows($check_stmt)) {
                    $error = "Username or email already exists!";
                } else {
                    // Hash the password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Insert new user without created_at
                    $insert_sql = "INSERT INTO users (username, password, email, full_name, role_id, 
                                    division_id, department_id, unit_id, office_id, status) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                    
                    $insert_params = array($new_username, $hashed_password, $new_email, $new_fullname, $role_id, 
                                          $division_id, $department_id, $unit_id, $office_id);
                    
                    $insert_stmt = sqlsrv_query($conn, $insert_sql, $insert_params);
                    
                    if ($insert_stmt) {
                        $success = "User added successfully!";
                        
                        // CRITICAL FIX: DO NOT TOUCH the profile form variables
                        // They should remain as the current logged-in user's data
                        // No code here should modify $username, $email, $fullname, etc.
                        
                    } else {
                        $error = "Failed to add user: " . print_r(sqlsrv_errors(), true);
                    }
                    if ($insert_stmt) sqlsrv_free_stmt($insert_stmt);
                }
                if ($check_stmt) sqlsrv_free_stmt($check_stmt);
            } else {
                $error = implode("<br>", $errors);
            }
        }
        // ============================================
        // Admin Update User functionality
        // ============================================
        elseif ($action === 'update_user' && isAdmin()) {
            $edit_user_id = intval($_POST['edit_user_id']);
            $new_username = trim($_POST['edit_username']);
            $new_email = trim($_POST['edit_email']);
            $new_fullname = trim($_POST['edit_fullname']);
            $role_id = intval($_POST['edit_role_id']);
            $new_password = isset($_POST['edit_password']) ? trim($_POST['edit_password']) : '';
            $confirm_password = isset($_POST['edit_confirm_password']) ? trim($_POST['edit_confirm_password']) : '';
            
            $division_id = !empty($_POST['division_id']) ? intval($_POST['division_id']) : null;
            $department_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
            $unit_id = !empty($_POST['unit_id']) ? intval($_POST['unit_id']) : null;
            $office_id = !empty($_POST['office_id']) ? intval($_POST['office_id']) : null;
            
            // Validation
            $errors = [];
            
            if (empty($edit_user_id)) $errors[] = "User ID is missing";
            if (empty($new_username)) $errors[] = "Username is required";
            if (empty($new_email)) $errors[] = "Email is required";
            if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
            if (empty($new_fullname)) $errors[] = "Full name is required";
            if (empty($role_id)) $errors[] = "Role is required";
            
            // Password validation if changing
            if (!empty($new_password)) {
                if (strlen($new_password) < 8) {
                    $errors[] = "Password must be at least 8 characters";
                }
                if ($new_password !== $confirm_password) {
                    $errors[] = "Passwords do not match";
                }
            }
            
            // Check if role is head (3-6) - should not have org unit assignment in users table
            if ($role_id >= 3 && $role_id <= 6) {
                // Clear any org assignments - heads are assigned via numbers table
                $division_id = null;
                $department_id = null;
                $unit_id = null;
                $office_id = null;
            }
            
            if (empty($errors)) {
                // Check if username or email already exists for another user
                $check_sql = "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?";
                $check_params = array($new_username, $new_email, $edit_user_id);
                $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
                
                if ($check_stmt && sqlsrv_has_rows($check_stmt)) {
                    $error = "Username or email already exists for another user!";
                } else {
                    // Build update query
                    if (!empty($new_password)) {
                        // Update with new password
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $update_sql = "UPDATE users SET 
                                        username = ?, 
                                        email = ?, 
                                        full_name = ?, 
                                        role_id = ?,
                                        password = ?,
                                        division_id = ?,
                                        department_id = ?,
                                        unit_id = ?,
                                        office_id = ?
                                      WHERE user_id = ?";
                        $update_params = array($new_username, $new_email, $new_fullname, $role_id, $hashed_password,
                                              $division_id, $department_id, $unit_id, $office_id, $edit_user_id);
                    } else {
                        // Update without changing password
                        $update_sql = "UPDATE users SET 
                                        username = ?, 
                                        email = ?, 
                                        full_name = ?, 
                                        role_id = ?,
                                        division_id = ?,
                                        department_id = ?,
                                        unit_id = ?,
                                        office_id = ?
                                      WHERE user_id = ?";
                        $update_params = array($new_username, $new_email, $new_fullname, $role_id,
                                              $division_id, $department_id, $unit_id, $office_id, $edit_user_id);
                    }
                    
                    $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
                    
                    if ($update_stmt) {
                        $success = "User updated successfully!";
                        
                        // If the updated user is the current user, refresh from database
                        if ($edit_user_id == $user_id) {
                            $_SESSION['username'] = $new_username;
                            
                            // Refresh user data from database
                            $refresh_sql = "SELECT * FROM users WHERE user_id = ?";
                            $refresh_params = array($user_id);
                            $refresh_stmt = sqlsrv_query($conn, $refresh_sql, $refresh_params);
                            if ($refresh_stmt && $refresh_row = sqlsrv_fetch_array($refresh_stmt, SQLSRV_FETCH_ASSOC)) {
                                $user_data = $refresh_row;
                                $username = $user_data['username'];
                                $email = $user_data['email'];
                                $fullname = $user_data['full_name'];
                                $current_role_id = $user_data['role_id'];
                            }
                            if ($refresh_stmt) sqlsrv_free_stmt($refresh_stmt);
                        }
                    } else {
                        $error = "Failed to update user: " . print_r(sqlsrv_errors(), true);
                    }
                    if ($update_stmt) sqlsrv_free_stmt($update_stmt);
                }
                if ($check_stmt) sqlsrv_free_stmt($check_stmt);
            } else {
                $error = implode("<br>", $errors);
            }
        }
        // ============================================
        // Admin Delete User functionality
        // ============================================
        elseif ($action === 'delete_user' && isAdmin()) {
            $delete_user_id = intval($_POST['delete_user_id']);
            
            // Don't allow deleting yourself
            if ($delete_user_id == $user_id) {
                $error = "You cannot deactivate your own account!";
            } else {
                // Check if user exists
                $check_sql = "SELECT user_id FROM users WHERE user_id = ?";
                $check_params = array($delete_user_id);
                $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
                
                if ($check_stmt && sqlsrv_has_rows($check_stmt)) {
                    // Soft delete - set status to inactive
                    $update_sql = "UPDATE users SET status = 'inactive' WHERE user_id = ?";
                    $update_params = array($delete_user_id);
                    $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
                    
                    if ($update_stmt) {
                        $success = "User deactivated successfully!";
                    } else {
                        $error = "Failed to deactivate user: " . print_r(sqlsrv_errors(), true);
                    }
                    if ($update_stmt) sqlsrv_free_stmt($update_stmt);
                } else {
                    $error = "User not found!";
                }
                if ($check_stmt) sqlsrv_free_stmt($check_stmt);
            }
        }
        // ============================================
        // Admin Direct Password Change functionality
        // ============================================
        elseif ($action === 'admin_change_password' && isAdmin()) {
            $target_user_id = intval($_POST['target_user_id']);
            $new_password = $_POST['admin_new_password'];
            $confirm_password = $_POST['admin_confirm_password'];
            
            if (empty($new_password) || empty($confirm_password)) {
                $error = "Password fields are required!";
            } elseif (strlen($new_password) < 8) {
                $error = "Password must be at least 8 characters long!";
            } elseif ($new_password !== $confirm_password) {
                $error = "Passwords do not match!";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                $update_sql = "UPDATE users SET password = ? WHERE user_id = ?";
                $update_params = array($hashed_password, $target_user_id);
                $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
                
                if ($update_stmt) {
                    $success = "Password changed successfully for user ID: " . $target_user_id;
                } else {
                    $error = "Failed to update password: " . print_r(sqlsrv_errors(), true);
                }
                if ($update_stmt) sqlsrv_free_stmt($update_stmt);
            }
        }
    }
}

// Get all users for admin panel
$all_users = [];
if (isAdmin()) {
    $users_sql = "SELECT 
                    u.user_id,
                    u.username,
                    u.email,
                    u.full_name,
                    u.role_id,
                    u.status,
                    u.division_id,
                    u.department_id,
                    u.unit_id,
                    u.office_id,
                    d.division_name,
                    dept.department_name,
                    un.unit_name,
                    o.office_name
                FROM users u
                LEFT JOIN divisions d ON u.division_id = d.division_id
                LEFT JOIN departments dept ON u.department_id = dept.department_id
                LEFT JOIN units un ON u.unit_id = un.unit_id
                LEFT JOIN offices o ON u.office_id = o.office_id
                WHERE u.status = 'active' OR u.status IS NULL 
                ORDER BY u.role_id, u.username";
    $users_result = sqlsrv_query($conn, $users_sql);

    if ($users_result) {
        while ($user = sqlsrv_fetch_array($users_result, SQLSRV_FETCH_ASSOC)) {
            // Get units this user heads (for role 3-6)
            if ($user['role_id'] >= 3 && $user['role_id'] <= 6) {
                $units_sql = "SELECT 
                                n.division_id,
                                n.department_id,
                                n.unit_id,
                                n.office_id,
                                d.division_name, 
                                dept.department_name,
                                un.unit_name,
                                o.office_name
                            FROM numbers n
                            LEFT JOIN divisions d ON n.division_id = d.division_id
                            LEFT JOIN departments dept ON n.department_id = dept.department_id
                            LEFT JOIN units un ON n.unit_id = un.unit_id
                            LEFT JOIN offices o ON n.office_id = o.office_id
                            WHERE n.head_user_id = ?";

                $units_params = array($user['user_id']);
                $units_stmt = sqlsrv_query($conn, $units_sql, $units_params);

                $user['units'] = [];
                if ($units_stmt) {
                    while ($unit = sqlsrv_fetch_array($units_stmt, SQLSRV_FETCH_ASSOC)) {
                        $user['units'][] = $unit;
                    }
                    sqlsrv_free_stmt($units_stmt);
                }
            } else {
                $user['units'] = [];
            }

            $all_users[] = $user;
        }
        sqlsrv_free_stmt($users_result);
    }
}

// Get notification data
$onlineAdminCount = function_exists('getOnlineAdmins') ? getOnlineAdmins($conn) : 0;
$unread_count = 0;
$admin_notifications_count = 0;
$admin_chat_requests = [];
$user_chats = [];

if (function_exists('getUnreadAdminMessageCount')) {
    $unread_count = getUnreadAdminMessageCount($conn, $user_id, $is_admin);
}

if($is_admin && $user_id) {
    if (function_exists('getAdminUnreadChatsCount')) {
        $admin_notifications_count = getAdminUnreadChatsCount($conn, $user_id);
    }
    if (function_exists('getAdminChatRequests')) {
        $admin_chat_requests = getAdminChatRequests($conn, $user_id);
    }
} else {
    if (function_exists('getUserChatsWithAllAdmins')) {
        $user_chats = getUserChatsWithAllAdmins($conn, $user_id);
    }
}

$head_contacts = [];
$total_head_unread = 0;

if($user_id) {
    if (function_exists('getHeadContacts')) {
        $head_contacts = getHeadContacts($conn, $user_id);
    }
    if (function_exists('getHeadUnreadCount')) {
        $total_head_unread = getHeadUnreadCount($conn, $user_id);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile - DRMC Intercom</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* EXACT SAME STYLES FROM HOMEPAGE.PHP */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: #edf4fc;
        }

        .header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            background-color: #07417f;
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            border-bottom: 3px solid #2b6cb0;
        }

        .header .logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header .logo img {
            width: 55px;
            height: 55px;
            object-fit: contain;
        }

        .header .logo span {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        ul.nav {
            display: flex;
            list-style: none;
            gap: 8px;
        }

        ul.nav li a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 10px 18px;
            font-weight: 600;
            border-radius: 6px;
            transition: all 0.2s;
        }

        ul.nav li a:hover {
            background-color: rgba(255,255,255,0.2);
        }

        ul.nav li a.active {
            background-color: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
        }

        /* NOTIFICATION SYSTEM - Like homepage.php */
        .notification-indicator { position: relative; }
        .nav-notification-badge { background-color: #e53e3e; color: white; font-size: 11px; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; margin-left: 5px; animation: pulse 2s infinite; display: none; }
        .admin-notification-container { position: relative; display: inline-block; margin-left: 10px; }
        .admin-notification-btn { width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, #e53e3e, #c53030); color: white; border: 3px solid white; cursor: pointer; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 20px; box-shadow: 0 3px 10px rgba(229, 62, 62, 0.3); transition: all 0.3s; position: relative; }
        .admin-notification-btn:hover { background: linear-gradient(135deg, #c53030, #9b2c2c); transform: scale(1.05); box-shadow: 0 5px 15px rgba(229, 62, 62, 0.4); }
        .notification-bell-badge { position: absolute; top: -5px; right: -5px; background-color: #e53e3e; color: white; font-size: 12px; padding: 3px 8px; border-radius: 10px; min-width: 24px; text-align: center; font-weight: bold; border: 2px solid white; animation: pulse 1.5s infinite; display: none; }
        .notification-dropdown { position: absolute; top: 100%; right: 0; width: 350px; background: white; border-radius: 8px; box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15); margin-top: 15px; padding: 0; z-index: 1000; opacity: 0; visibility: hidden; transform: translateY(-10px); transition: all 0.3s; }
        .admin-notification-container:hover .notification-dropdown { opacity: 1; visibility: visible; transform: translateY(0); }
        .notification-header { padding: 15px; background: #2b6cb0; color: white; border-radius: 8px 8px 0 0; }
        .notification-header h4 { margin: 0; color: white; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .notification-list { max-height: 400px; overflow-y: auto; padding: 10px; }
        .notification-item { display: flex; align-items: center; padding: 12px; border-radius: 8px; margin-bottom: 8px; border: 1px solid #e2e8f0; transition: all 0.2s; text-decoration: none; color: inherit; }
        .notification-item:hover { background-color: #f7fafc; border-color: #cbd5e0; }
        .notification-avatar { width: 40px; height: 40px; border-radius: 50%; background: #2b6cb0; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 12px; font-size: 16px; }
        .notification-info { flex: 1; }
        .notification-name { font-weight: 600; color: #2d3748; margin-bottom: 3px; font-size: 14px; }
        .notification-meta { font-size: 12px; color: #718096; display: flex; align-items: center; gap: 8px; }
        .notification-time { color: #a0aec0; }
        .message-count-badge { background-color: #e53e3e; color: white; font-size: 11px; padding: 2px 6px; border-radius: 10px; min-width: 20px; text-align: center; font-weight: bold; }
        .no-notifications { text-align: center; padding: 30px 20px; color: #a0aec0; font-style: italic; }
        .notification-footer { padding: 12px; border-top: 1px solid #e2e8f0; text-align: center; }
        .view-all-btn { display: inline-block; padding: 8px 16px; background-color: #2b6cb0; color: white; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 500; transition: background-color 0.2s; }
        .view-all-btn:hover { background-color: #1f4f8b; }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .content {
            flex: 1;
            margin-top: 100px;
            padding: 20px;
        }

        .footer {
            background-color: #07417f;
            color: #fff;
            text-align: center;
            padding: 18px 10px;
            font-size: 14px;
            margin-top: auto;
        }

        /* Toast notification styles */
        .notification-toast-container {
            position: fixed;
            top: 120px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 350px;
        }
        .notification-toast {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            border-left: 4px solid #e53e3e;
            animation: slideIn 0.3s ease-out;
            cursor: pointer;
            transition: transform 0.2s;
            border: 1px solid #e2e8f0;
        }
        .notification-toast:hover {
            transform: translateX(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        }
        .notification-toast-title {
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
            font-size: 14px;
        }
        .notification-toast-message {
            color: #4a5568;
            font-size: 13px;
        }
        .notification-toast-time {
            font-size: 11px;
            color: #a0aec0;
            margin-top: 5px;
            text-align: right;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Profile page specific styles - preserved from original */
        .profile-container {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
        }

        .profile-sidebar {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            height: fit-content;
        }

        .profile-name {
            font-size: 24px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .profile-role {
            display: inline-block;
            background: #bee3f8;
            color: #2b6cb0;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
        }

        .profile-stats {
            border-top: 1px solid #e2e8f0;
            padding-top: 20px;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f7fafc;
        }

        .stat-label {
            color: #718096;
            font-size: 14px;
        }

        .stat-value {
            color: #2d3748;
            font-weight: 500;
            font-size: 14px;
            text-align: right;
        }

        .profile-content {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .tab-nav {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 30px;
            gap: 10px;
        }

        .tab-btn {
            padding: 12px 25px;
            background: none;
            border: none;
            font-size: 16px;
            font-weight: 600;
            color: #718096;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
        }

        .tab-btn:hover {
            color: #2b6cb0;
        }

        .tab-btn.active {
            color: #2b6cb0;
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #2b6cb0;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #4a5568;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 15px;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2b6cb0;
            box-shadow: 0 0 0 3px rgba(43,108,176,0.1);
        }

        .form-group input[readonly] {
            background: #f7fafc;
            color: #718096;
        }

        .password-container {
            position: relative;
        }

        .password-container button {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #2b6cb0;
            font-weight: 600;
            cursor: pointer;
            padding: 5px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #2b6cb0;
            color: white;
        }

        .btn-primary:hover {
            background: #1f4f8b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(43,108,176,0.3);
        }

        .btn-success {
            background: #38a169;
            color: white;
        }

        .btn-success:hover {
            background: #2f855a;
        }

        .btn-danger {
            background: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background: #c53030;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert.success {
            background: #c6f6d5;
            color: #276749;
            border: 1px solid #9ae6b4;
        }

        .alert.error {
            background: #fed7d7;
            color: #9b2c2c;
            border: 1px solid #feb2b2;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .admin-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .admin-stat-card {
            background: #f7fafc;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }

        .admin-stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #2b6cb0;
            margin-bottom: 5px;
        }

        .admin-stat-label {
            color: #718096;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .user-table th {
            background: #2b6cb0;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
        }

        .user-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        .user-table tr:hover {
            background: #f7fafc;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .role-admin { background: #fed7d7; color: #9b2c2c; }
        .role-mcc { background: #bee3f8; color: #2c5282; }
        .role-division { background: #c6f6d5; color: #276749; }
        .role-department { background: #fefcbf; color: #744210; }
        .role-unit { background: #e9d8fd; color: #553c9a; }
        .role-office { background: #fed7e2; color: #97266d; }
        .role-staff { background: #e2e8f0; color: #4a5568; }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex !important;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 30px;
            position: relative;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }

        .modal-header h3 {
            color: #2b6cb0;
            margin: 0;
            font-size: 20px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #718096;
        }

        .modal-close:hover {
            color: #2d3748;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .org-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .org-section h4 {
            color: #2b6cb0;
            margin-bottom: 15px;
            font-size: 16px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .password-strength {
            margin-top: 5px;
            font-size: 12px;
        }
        
        .weak {
            color: #e53e3e;
        }
        
        .medium {
            color: #dd6b20;
        }
        
        .strong {
            color: #38a169;
        }

        @media (max-width: 992px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
            .profile-sidebar {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
            .user-table {
                display: block;
                overflow-x: auto;
            }
            .header {
                flex-direction: column;
                padding: 15px;
                text-align: center;
            }
            .header .logo span {
                font-size: 1.3rem;
            }
            ul.nav {
                flex-wrap: wrap;
                justify-content: center;
            }
            .notification-dropdown {
                width: 280px;
                right: -50px;
            }
        }

        @media (max-width: 480px) {
            .notification-dropdown {
                width: 250px;
                right: -30px;
            }
        }
    </style>
</head>
<body>
    <!-- EXACT SAME HEADER FROM HOMEPAGE.PHP -->
    <div class="header">
        <div class="logo">
            <img src="hospitalLogo.png" alt="Hospital Logo">
            <span>DAVAO REGIONAL MEDICAL CENTER</span>
        </div>
        <ul class="nav">
            <li><a href="homepage.php">Homepage</a></li>
            <?php if (isLoggedIn()): ?>
                <?php if (isAdmin()): ?>
                    <li><a href="createpage.php">Create page</a></li>
                    <li><a href="editpage.php">Edit page</a></li>
                    <li class="notification-indicator">
                        <a href="adminpanel.php" id="operatorPanelLink">
                            Operator Panel 
                            <span class="nav-notification-badge" id="operatorPanelBadge" style="display: none;">0</span>
                        </a>
                    </li>
                <?php else: ?>
                    <li><a href="adminchat.php" id="adminChatNavLink">
                        Chat with an Operator 
                        <span class="nav-notification-badge" id="adminChatNavBadge" style="display: none;">0</span>
                    </a></li>
                <?php endif; ?>
                <li><a href="profilepage.php" class="active">Profile</a></li>
                <li><a href="logout.php">Logout (<?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>)</a></li>
            <?php else: ?>
                <li><a href="login.php">Login</a></li>
            <?php endif; ?>
        </ul>
        
        <?php if($is_admin && $user_id): ?>
        <div class="admin-notification-container">
            <button class="admin-notification-btn" id="adminNotificationBtn">
                🔔
                <span class="notification-bell-badge" id="notificationBellBadge" style="display: none;">0</span>
            </button>
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <h4>📨 New Chat Requests (<span id="adminNotificationCount">0</span>)</h4>
                </div>
                <div class="notification-list" id="adminNotificationList">
                    <?php if(!empty($admin_chat_requests)): ?>
                        <?php foreach($admin_chat_requests as $request): 
                            $chat_id = function_exists('getAdminChatWithUser') ? getAdminChatWithUser($conn, $user_id, $request['user_id']) : null;
                            $chat_link = $chat_id ? "adminpanel.php?chat_id=$chat_id" : "adminpanel.php?start_chat=" . $request['user_id'];
                        ?>
                        <a href="<?php echo $chat_link; ?>" class="notification-item">
                            <div class="notification-avatar">
                                <?php echo strtoupper(substr($request['full_name'], 0, 1)); ?>
                            </div>
                            <div class="notification-info">
                                <div class="notification-name"><?php echo htmlspecialchars($request['full_name']); ?></div>
                                <div class="notification-meta">
                                    <span class="notification-time"><?php echo function_exists('time_ago') ? time_ago($request['first_message_time']) : 'Just now'; ?></span>
                                    <?php if(($request['message_count'] ?? 1) > 1): ?>
                                        <span class="message-count-badge"><?php echo $request['message_count']; ?> messages</span>
                                    <?php else: ?>
                                        <span class="message-count-badge">New message</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-notifications">
                            No new chat requests
                        </div>
                    <?php endif; ?>
                </div>
                <div class="notification-footer">
                    <a href="adminpanel.php" class="view-all-btn">View All Chats</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Notification Toast Container -->
    <div id="notificationToastContainer" class="notification-toast-container"></div>

    <!-- MAIN CONTENT -->
    <div class="content">
        <div class="profile-container">
            <!-- PROFILE SIDEBAR - Always shows current logged in user from database -->
            <div class="profile-sidebar">
                <div class="profile-header">
                    <h2 class="profile-name"><?php echo htmlspecialchars($user_data['full_name'] ?? ''); ?></h2>
                    <div class="profile-role"><?php echo $role_names[$user_data['role_id']] ?? 'User'; ?></div>
                </div>
                <div class="profile-stats">
                    <div class="stat-row">
                        <span class="stat-label">Username:</span>
                        <span class="stat-value"><?php echo htmlspecialchars($user_data['username'] ?? ''); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Email:</span>
                        <span class="stat-value"><?php echo htmlspecialchars($user_data['email'] ?? ''); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label">Organizations Headed:</span>
                        <span class="stat-value">
                            <?php if (!empty($user_data['units'])): ?>
                                <?php foreach ($user_data['units'] as $unit): ?>
                                    <?php
                                    if (!empty($unit['division_name'])) {
                                        echo "<div>Division: " . htmlspecialchars($unit['division_name']) . "</div>";
                                    } elseif (!empty($unit['department_name'])) {
                                        echo "<div>Department: " . htmlspecialchars($unit['department_name']) . "</div>";
                                    } elseif (!empty($unit['unit_name'])) {
                                        echo "<div>Unit: " . htmlspecialchars($unit['unit_name']) . "</div>";
                                    } elseif (!empty($unit['office_name'])) {
                                        echo "<div>Office: " . htmlspecialchars($unit['office_name']) . "</div>";
                                    }
                                    ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if (isAdmin()): ?>
                    <div class="stat-row">
                        <span class="stat-label">Users Online:</span>
                        <span class="stat-value">
                            <?php echo $onlineAdminCount; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- PROFILE CONTENT -->
            <div class="profile-content">
                <?php if ($error): ?>
                    <div class="alert error"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert success"><?php echo $success; ?></div>
                <?php endif; ?>

                <!-- TAB NAVIGATION -->
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="switchTab('profile-tab', this)">Profile Info</button>
                    <button class="tab-btn" onclick="switchTab('password-tab', this)">Change Password</button>
                    <?php if (isAdmin()): ?>
                    <button class="tab-btn" onclick="switchTab('admin-tab', this)">Operator Panel</button>
                    <?php endif; ?>
                </div>

                <!-- PROFILE TAB - Always shows current logged in user's data from database -->
                <div id="profile-tab" class="tab-content active">
                    <h3 style="color: #2b6cb0; margin-bottom: 25px;">Edit Profile Information</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-group">
                            <label for="fn">Full Name</label>
                            <input type="text" id="fn" name="fn" value="<?php echo htmlspecialchars($fullname); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="role_id">Role</label>
                            <input type="text" id="role_id" value="<?php echo $role_names[$current_role_id] ?? 'Unknown'; ?>" readonly>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>

                <!-- PASSWORD TAB -->
                <div id="password-tab" class="tab-content">
                    <h3 style="color: #2b6cb0; margin-bottom: 25px;">Change Password</h3>
                    <form method="POST" onsubmit="return validatePasswordForm()">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group password-container">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required>
                            <button type="button" onclick="togglePassword('current_password', this)">Show</button>
                        </div>
                        
                        <div class="form-group password-container">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" required onkeyup="checkPasswordStrength()">
                            <button type="button" onclick="togglePassword('new_password', this)">Show</button>
                            <div id="password-strength" class="password-strength"></div>
                        </div>
                        
                        <div class="form-group password-container">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required onkeyup="checkPasswordMatch()">
                            <button type="button" onclick="togglePassword('confirm_password', this)">Show</button>
                            <div id="password-match" class="password-strength"></div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </form>
                </div>

                <!-- ADMIN TAB -->
                <?php if (isAdmin()): ?>
                <div id="admin-tab" class="tab-content">
                    <div class="admin-header">
                        <h3 style="color: #2b6cb0;">User Management</h3>
                        <button type="button" class="btn btn-success" onclick="openUserModal('add')">
                            Add New User
                        </button>
                    </div>
                    
                    <div class="admin-stats">
                        <div class="admin-stat-card">
                            <div class="admin-stat-value"><?php echo count($all_users); ?></div>
                            <div class="admin-stat-label">Total Active Users</div>
                        </div>
                        <div class="admin-stat-card">
                            <div class="admin-stat-value">
                                <?php 
                                $admin_count = 0;
                                foreach ($all_users as $u) {
                                    if ($u['role_id'] == 1) $admin_count++;
                                }
                                echo $admin_count;
                                ?>
                            </div>
                            <div class="admin-stat-label">Admins</div>
                        </div>
                        <div class="admin-stat-card">
                            <div class="admin-stat-value">
                                <?php echo $onlineAdminCount; ?>
                            </div>
                            <div class="admin-stat-label">Online Now</div>
                        </div>
                    </div>
                    
                    <div style="overflow-x: auto;">
                        <table class="user-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Organization</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_users as $user): ?>
                                <tr>
                                    <td><?php echo $user['user_id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="role-badge role-<?php 
                                            echo $user['role_id'] == 1 ? 'admin' : 
                                                ($user['role_id'] == 2 ? 'mcc' : 
                                                ($user['role_id'] == 3 ? 'division' : 
                                                ($user['role_id'] == 4 ? 'department' : 
                                                ($user['role_id'] == 5 ? 'unit' : 
                                                ($user['role_id'] == 6 ? 'office' : 'staff'))))); ?>">
                                            <?php echo $role_names[$user['role_id']] ?? 'Unknown'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $orgs = [];
                                        
                                        if ($user['role_id'] >= 3 && $user['role_id'] <= 6 && !empty($user['units'])) {
                                            foreach ($user['units'] as $unit) {
                                                if (!empty($unit['division_name'])) {
                                                    $orgs[] = "Div: " . $unit['division_name'];
                                                }
                                                if (!empty($unit['department_name'])) {
                                                    $orgs[] = "Dept: " . $unit['department_name'];
                                                }
                                                if (!empty($unit['unit_name'])) {
                                                    $orgs[] = "Unit: " . $unit['unit_name'];
                                                }
                                                if (!empty($unit['office_name'])) {
                                                    $orgs[] = "Office: " . $unit['office_name'];
                                                }
                                            }
                                        } else {
                                            if (!empty($user['division_name'])) $orgs[] = "Div: " . $user['division_name'];
                                            if (!empty($user['department_name'])) $orgs[] = "Dept: " . $user['department_name'];
                                            if (!empty($user['unit_name'])) $orgs[] = "Unit: " . $user['unit_name'];
                                            if (!empty($user['office_name'])) $orgs[] = "Office: " . $user['office_name'];
                                        }
                                        
                                        echo !empty($orgs) ? implode('<br>', array_slice($orgs, 0, 2)) : '<em>Not assigned</em>';
                                        if (count($orgs) > 2) echo '<br><small>+' . (count($orgs) - 2) . ' more</small>';
                                        ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <button class="btn btn-primary btn-sm" onclick="openUserModal('edit', <?php echo $user['user_id']; ?>)">Edit</button>
                                            <button class="btn btn-secondary btn-sm" onclick="openPasswordModal(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">Reset Pass</button>
                                            <?php if ($user['user_id'] != $user_id): ?>
                                            <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">Deactivate</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="footer">
        © 2026 Intercom Directory. All rights reserved.<br>
        Developed by TNTS Programming Students JT.DP.RR
    </div>

    <!-- USER MODAL (Add/Edit) -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New User</h3>
                <button type="button" class="modal-close" onclick="closeUserModal()">&times;</button>
            </div>
            <form id="userForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="add_user">
                <input type="hidden" name="edit_user_id" id="edit_user_id" value="">
                
                <div class="grid-2">
                    <div class="form-group">
                        <label for="edit_username">Username *</label>
                        <input type="text" id="edit_username" name="edit_username" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_email">Email Address *</label>
                        <input type="email" id="edit_email" name="edit_email" class="form-input" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_fullname">Full Name *</label>
                    <input type="text" id="edit_fullname" name="edit_fullname" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_role_id">Role *</label>
                    <select id="edit_role_id" name="edit_role_id" class="form-input" required onchange="handleRoleChange()">
                        <option value="">Select Role</option>
                        <?php foreach ($role_names as $id => $name): ?>
                            <option value="<?php echo $id; ?>"><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="orgSection" class="org-section">
                    <h4>Assign to Organizational Unit (Optional - for Staff only)</h4>
                    <div class="grid-2">
                        <div class="form-group">
                            <label for="modal_division">Division</label>
                            <select id="modal_division" name="division_id" class="form-input" onchange="loadDepartments(this.value)">
                                <option value="">Select Division</option>
                                <?php foreach($divisions as $division): ?>
                                    <option value="<?php echo $division['division_id']; ?>">
                                        <?php echo htmlspecialchars($division['division_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="modal_department">Department</label>
                            <select id="modal_department" name="department_id" class="form-input" onchange="loadUnits(this.value)">
                                <option value="">Select Department</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid-2" style="margin-top: 15px;">
                        <div class="form-group">
                            <label for="modal_unit">Unit</label>
                            <select id="modal_unit" name="unit_id" class="form-input" onchange="loadOffices(this.value)">
                                <option value="">Select Unit</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="modal_office">Office</label>
                            <select id="modal_office" name="office_id" class="form-input">
                                <option value="">Select Office</option>
                            </select>
                        </div>
                    </div>
                    <p style="color: #718096; font-size: 12px; margin-top: 10px;">
                        <em>Note: For Division Head, Department Head, Unit Head, and Office Head roles, assign organizational units in the "Numbers" section.</em>
                    </p>
                </div>
                
                <div id="addPasswordFields">
                    <div class="grid-2">
                        <div class="form-group password-container">
                            <label for="add_password">Password *</label>
                            <input type="password" id="add_password" name="add_password" class="form-input" required onkeyup="checkAddPasswordStrength()">
                            <button type="button" onclick="toggleModalPassword('add_password', this)">Show</button>
                            <div id="add-password-strength" class="password-strength"></div>
                        </div>
                        <div class="form-group password-container">
                            <label for="add_confirm_password">Confirm Password *</label>
                            <input type="password" id="add_confirm_password" name="confirm_password" class="form-input" required onkeyup="checkAddPasswordMatch()">
                            <button type="button" onclick="toggleModalPassword('add_confirm_password', this)">Show</button>
                            <div id="add-password-match" class="password-strength"></div>
                        </div>
                    </div>
                </div>
                
                <div id="editPasswordFields" style="display: none;">
                    <div class="grid-2">
                        <div class="form-group password-container">
                            <label for="edit_password">New Password (Leave blank to keep current)</label>
                            <input type="password" id="edit_password" name="edit_password" class="form-input" onkeyup="checkEditPasswordStrength()">
                            <button type="button" onclick="toggleModalPassword('edit_password', this)">Show</button>
                            <div id="edit-password-strength" class="password-strength"></div>
                        </div>
                        <div class="form-group password-container">
                            <label for="edit_confirm_password">Confirm New Password</label>
                            <input type="password" id="edit_confirm_password" name="edit_confirm_password" class="form-input" onkeyup="checkEditPasswordMatch()">
                            <button type="button" onclick="toggleModalPassword('edit_confirm_password', this)">Show</button>
                            <div id="edit-password-match" class="password-strength"></div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeUserModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save User</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- PASSWORD RESET MODAL -->
    <div class="modal" id="passwordModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Reset User Password</h3>
                <button type="button" class="modal-close" onclick="closePasswordModal()">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateAdminPasswordForm()">
                <input type="hidden" name="action" value="admin_change_password">
                <input type="hidden" name="target_user_id" id="target_user_id" value="">
                
                <p style="margin-bottom: 15px;">Resetting password for: <strong id="target_username"></strong></p>
                
                <div class="form-group password-container">
                    <label for="admin_new_password">New Password *</label>
                    <input type="password" id="admin_new_password" name="admin_new_password" class="form-input" required onkeyup="checkAdminPasswordStrength()">
                    <button type="button" onclick="toggleModalPassword('admin_new_password', this)">Show</button>
                    <div id="admin-password-strength" class="password-strength"></div>
                </div>
                
                <div class="form-group password-container">
                    <label for="admin_confirm_password">Confirm New Password *</label>
                    <input type="password" id="admin_confirm_password" name="admin_confirm_password" class="form-input" required onkeyup="checkAdminPasswordMatch()">
                    <button type="button" onclick="toggleModalPassword('admin_confirm_password', this)">Show</button>
                    <div id="admin-password-match" class="password-strength"></div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closePasswordModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal" id="deleteModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3>Confirm Deactivation</h3>
                <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <p style="margin-bottom: 10px;">Are you sure you want to deactivate user: <strong id="deleteUserName"></strong>?</p>
            <p style="color: #e53e3e;">This action cannot be undone. The user will no longer be able to log in.</p>
            <form id="deleteForm" method="POST">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="delete_user_id" id="delete_user_id" value="">
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Deactivate User</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ===========================================
        // DYNAMIC NOTIFICATION SYSTEM - Like homepage.php
        // ===========================================
        
        let userId = <?php echo $user_id ?: 'null'; ?>;
        let isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
        let notificationCheckInterval;
        let shownToastIds = new Set();
        
        function startNotificationChecking() {
            if (notificationCheckInterval) {
                clearInterval(notificationCheckInterval);
            }
            checkNotifications();
            notificationCheckInterval = setInterval(checkNotifications, 3000);
        }
        
        function checkNotifications() {
            if (!userId) return;
            
            fetch('check_notifications.php?t=' + Date.now())
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateAllNotifications(data);
                        checkForNewToasts(data);
                    }
                })
                .catch(error => console.error('Notification check error:', error));
        }
        
        function updateAllNotifications(data) {
            const allNotifications = data.notifications || [];
            
            const adminRequests = allNotifications.filter(n => n.type === 'admin_chat_request');
            const adminMessages = allNotifications.filter(n => n.type === 'admin_chat_message');
            const adminReplies = allNotifications.filter(n => n.type === 'admin_reply');
            
            if (isAdmin) {
                // Operator Panel badge - NEW CHAT REQUESTS only
                const operatorPanelCount = adminRequests.length;
                updateOperatorPanelBadge(operatorPanelCount);
                
                // Bell badge - ALL admin notifications
                const totalAdminNotifications = adminRequests.length + adminMessages.length;
                updateBellBadge(totalAdminNotifications);
                
                // Admin notification dropdown
                updateAdminNotificationDropdown([...adminRequests, ...adminMessages]);
            } else {
                // Admin chat badge for regular users
                const adminReplyCount = adminReplies.reduce((sum, n) => sum + (n.unread_count || 0), 0);
                updateAdminChatBadge(adminReplyCount);
            }
        }
        
        function updateOperatorPanelBadge(count) {
            const badge = document.getElementById('operatorPanelBadge');
            if (!badge) return;
            
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }
        
        function updateBellBadge(totalUnread) {
            const bellBadge = document.getElementById('notificationBellBadge');
            if (bellBadge) {
                if (totalUnread > 0) {
                    bellBadge.textContent = totalUnread;
                    bellBadge.style.display = 'inline-block';
                } else {
                    bellBadge.style.display = 'none';
                }
            }
            
            const adminCountSpan = document.getElementById('adminNotificationCount');
            if (adminCountSpan) {
                adminCountSpan.textContent = totalUnread;
            }
        }
        
        function updateAdminNotificationDropdown(notifications) {
            const notificationList = document.getElementById('adminNotificationList');
            if (!notificationList) return;
            
            if (notifications.length === 0) {
                notificationList.innerHTML = '<div class="no-notifications">No new messages</div>';
                return;
            }
            
            let html = '';
            const uniqueNotifs = new Map();
            
            notifications.forEach(notif => {
                const key = notif.type === 'admin_chat_request' 
                    ? `req_${notif.user_id}` 
                    : `msg_${notif.chat_id}`;
                
                if (!uniqueNotifs.has(key)) {
                    uniqueNotifs.set(key, notif);
                }
            });
            
            uniqueNotifs.forEach(notif => {
                let chatLink = '';
                let displayName = '';
                let timeStr = '';
                let badgeText = '';
                
                if (notif.type === 'admin_chat_request') {
                    chatLink = notif.chat_id
                        ? `adminpanel.php?chat_id=${notif.chat_id}`
                        : `adminpanel.php?start_chat=${notif.user_id}`;
                    displayName = notif.full_name || 'User';
                    timeStr = timeAgoFromString(notif.first_message_time);
                    badgeText = notif.message_count > 1 ? `${notif.message_count} messages` : 'New message';
                } else {
                    chatLink = `adminpanel.php?chat_id=${notif.chat_id}`;
                    displayName = notif.full_name || 'User';
                    timeStr = timeAgoFromString(notif.last_message_time);
                    badgeText = notif.unread_count > 1 ? `${notif.unread_count} messages` : 'New message';
                }
                
                html += `
                    <a href="${chatLink}" class="notification-item">
                        <div class="notification-avatar">${escapeHtml(displayName.charAt(0).toUpperCase())}</div>
                        <div class="notification-info">
                            <div class="notification-name">${escapeHtml(displayName)}</div>
                            <div class="notification-meta">
                                <span class="notification-time">${timeStr}</span>
                                <span class="message-count-badge">${badgeText}</span>
                            </div>
                        </div>
                    </a>
                `;
            });
            
            notificationList.innerHTML = html;
        }
        
        function updateAdminChatBadge(count) {
            const adminChatBadge = document.getElementById('adminChatNavBadge');
            if (adminChatBadge) {
                if (count > 0) {
                    adminChatBadge.textContent = count;
                    adminChatBadge.style.display = 'inline-block';
                } else {
                    adminChatBadge.style.display = 'none';
                }
            }
        }
        
        function checkForNewToasts(data) {
            const allNotifications = data.notifications || [];
            
            let relevantNotifications;
            if (isAdmin) {
                relevantNotifications = allNotifications.filter(n => 
                    n.type === 'admin_chat_request' || n.type === 'admin_chat_message'
                );
            } else {
                relevantNotifications = allNotifications.filter(n => 
                    n.type === 'admin_reply'
                );
            }
            
            relevantNotifications.forEach(notif => {
                let notifId;
                if (notif.type === 'admin_chat_request') {
                    notifId = `toast_req_${notif.user_id}_${notif.first_message_time}`;
                } else if (notif.type === 'admin_chat_message') {
                    notifId = `toast_msg_${notif.chat_id}_${notif.last_message_time}`;
                } else {
                    notifId = `toast_reply_${notif.chat_id}_${notif.last_message_time}`;
                }
                
                if (!shownToastIds.has(notifId)) {
                    shownToastIds.add(notifId);
                    showNotificationToast(notif);
                    
                    if (shownToastIds.size > 100) {
                        const iterator = shownToastIds.values();
                        shownToastIds.delete(iterator.next().value);
                    }
                }
            });
        }
        
        function showNotificationToast(notif) {
            const container = document.getElementById('notificationToastContainer');
            if (!container) return;
            
            const toasts = container.children;
            if (toasts.length >= 3) {
                toasts[0].remove();
            }
            
            const toast = document.createElement('div');
            toast.className = 'notification-toast';
            
            let title = '';
            let message = '';
            let link = '';
            
            if (notif.type === 'admin_chat_request') {
                title = `📨 New chat request from ${escapeHtml(notif.full_name || 'User')}`;
                message = notif.message_count > 1 ? `${notif.message_count} messages` : 'New message';
                link = notif.chat_id ? `adminpanel.php?chat_id=${notif.chat_id}` : `adminpanel.php?start_chat=${notif.user_id}`;
            } else if (notif.type === 'admin_chat_message') {
                title = `💬 New message from ${escapeHtml(notif.full_name || 'User')}`;
                message = notif.unread_count > 1 ? `${notif.unread_count} messages` : 'New message';
                link = `adminpanel.php?chat_id=${notif.chat_id}`;
            } else if (notif.type === 'admin_reply') {
                title = `👤 Reply from Admin ${escapeHtml(notif.admin_name || '')}`;
                message = notif.unread_count > 1 ? `${notif.unread_count} messages` : 'New message';
                link = `adminchat.php?chat_id=${notif.chat_id}`;
            }
            
            toast.innerHTML = `
                <div class="notification-toast-title">${title}</div>
                <div class="notification-toast-message">${message}</div>
                <div class="notification-toast-time">Just now</div>
            `;
            
            toast.addEventListener('click', () => {
                window.location.href = link;
            });
            
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideIn 0.3s reverse';
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }
        
        function timeAgoFromString(dateString) {
            if (!dateString) return 'recently';
            try {
                const date = new Date(dateString);
                const now = new Date();
                const seconds = Math.floor((now - date) / 1000);
                
                if (seconds < 60) return 'just now';
                if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
                if (seconds < 86400) return Math.floor(seconds / 3600) + ' hours ago';
                return Math.floor(seconds / 86400) + ' days ago';
            } catch (e) {
                return 'recently';
            }
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ============ NOTIFICATION DROPDOWN FUNCTIONALITY ============
        document.addEventListener('DOMContentLoaded', function() {
            const notificationBtn = document.getElementById('adminNotificationBtn');
            const notificationDropdown = document.getElementById('notificationDropdown');
            
            if(notificationBtn && notificationDropdown) {
                notificationBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if(notificationDropdown.style.visibility === 'visible') {
                        notificationDropdown.style.opacity = '0';
                        notificationDropdown.style.visibility = 'hidden';
                        notificationDropdown.style.transform = 'translateY(-10px)';
                    } else {
                        notificationDropdown.style.opacity = '1';
                        notificationDropdown.style.visibility = 'visible';
                        notificationDropdown.style.transform = 'translateY(0)';
                    }
                });
                
                // Close dropdown when clicking outside
                document.addEventListener('click', function(e) {
                    if(!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                        notificationDropdown.style.opacity = '0';
                        notificationDropdown.style.visibility = 'hidden';
                        notificationDropdown.style.transform = 'translateY(-10px)';
                    }
                });
            }

            // Start notification checking
            if (userId) {
                startNotificationChecking();
            }
        });

        // ============ EXISTING FUNCTIONS ============

        function togglePassword(inputId, button) {
            var input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                button.textContent = 'Hide';
            } else {
                input.type = 'password';
                button.textContent = 'Show';
            }
        }

        function toggleModalPassword(inputId, button) {
            var input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                button.textContent = 'Hide';
            } else {
                input.type = 'password';
                button.textContent = 'Show';
            }
        }

        function checkPasswordStrength() {
            var password = document.getElementById('new_password').value;
            var strengthDiv = document.getElementById('password-strength');
            
            if (password.length === 0) {
                strengthDiv.innerHTML = '';
                return;
            }
            
            var strength = 0;
            
            if (password.length >= 8) strength += 1;
            if (password.match(/[a-z]+/)) strength += 1;
            if (password.match(/[A-Z]+/)) strength += 1;
            if (password.match(/[0-9]+/)) strength += 1;
            if (password.match(/[$@#&!]+/)) strength += 1;
            
            var strengthText = '';
            var strengthClass = '';
            
            if (strength < 3) {
                strengthText = 'Weak';
                strengthClass = 'weak';
            } else if (strength < 5) {
                strengthText = 'Medium';
                strengthClass = 'medium';
            } else {
                strengthText = 'Strong';
                strengthClass = 'strong';
            }
            
            strengthDiv.innerHTML = 'Password strength: <span class="' + strengthClass + '">' + strengthText + '</span>';
        }

        function checkPasswordMatch() {
            var password = document.getElementById('new_password').value;
            var confirm = document.getElementById('confirm_password').value;
            var matchDiv = document.getElementById('password-match');
            
            if (confirm.length === 0) {
                matchDiv.innerHTML = '';
                return;
            }
            
            if (password === confirm) {
                matchDiv.innerHTML = '<span class="strong">✓ Passwords match</span>';
            } else {
                matchDiv.innerHTML = '<span class="weak">✗ Passwords do not match</span>';
            }
        }

        function checkAddPasswordStrength() {
            var password = document.getElementById('add_password').value;
            var strengthDiv = document.getElementById('add-password-strength');
            if (!strengthDiv) return;
            if (password.length === 0) {
                strengthDiv.innerHTML = '';
                return;
            }
            var strength = 0;
            if (password.length >= 8) strength += 1;
            if (password.match(/[a-z]+/)) strength += 1;
            if (password.match(/[A-Z]+/)) strength += 1;
            if (password.match(/[0-9]+/)) strength += 1;
            if (password.match(/[$@#&!]+/)) strength += 1;
            var strengthText = '';
            var strengthClass = '';
            if (strength < 3) {
                strengthText = 'Weak';
                strengthClass = 'weak';
            } else if (strength < 5) {
                strengthText = 'Medium';
                strengthClass = 'medium';
            } else {
                strengthText = 'Strong';
                strengthClass = 'strong';
            }
            strengthDiv.innerHTML = 'Password strength: <span class="' + strengthClass + '">' + strengthText + '</span>';
        }

        function checkAddPasswordMatch() {
            var password = document.getElementById('add_password').value;
            var confirm = document.getElementById('add_confirm_password').value;
            var matchDiv = document.getElementById('add-password-match');
            if (!matchDiv) return;
            if (confirm.length === 0) {
                matchDiv.innerHTML = '';
                return;
            }
            if (password === confirm) {
                matchDiv.innerHTML = '<span class="strong">✓ Passwords match</span>';
            } else {
                matchDiv.innerHTML = '<span class="weak">✗ Passwords do not match</span>';
            }
        }

        function checkEditPasswordStrength() {
            var password = document.getElementById('edit_password').value;
            var strengthDiv = document.getElementById('edit-password-strength');
            if (!strengthDiv) return;
            if (password.length === 0) {
                strengthDiv.innerHTML = '';
                return;
            }
            var strength = 0;
            if (password.length >= 8) strength += 1;
            if (password.match(/[a-z]+/)) strength += 1;
            if (password.match(/[A-Z]+/)) strength += 1;
            if (password.match(/[0-9]+/)) strength += 1;
            if (password.match(/[$@#&!]+/)) strength += 1;
            var strengthText = '';
            var strengthClass = '';
            if (strength < 3) {
                strengthText = 'Weak';
                strengthClass = 'weak';
            } else if (strength < 5) {
                strengthText = 'Medium';
                strengthClass = 'medium';
            } else {
                strengthText = 'Strong';
                strengthClass = 'strong';
            }
            strengthDiv.innerHTML = 'Password strength: <span class="' + strengthClass + '">' + strengthText + '</span>';
        }

        function checkEditPasswordMatch() {
            var password = document.getElementById('edit_password').value;
            var confirm = document.getElementById('edit_confirm_password').value;
            var matchDiv = document.getElementById('edit-password-match');
            if (!matchDiv) return;
            if (password.length === 0 && confirm.length === 0) {
                matchDiv.innerHTML = '';
                return;
            }
            if (password === confirm) {
                matchDiv.innerHTML = '<span class="strong">✓ Passwords match</span>';
            } else {
                matchDiv.innerHTML = '<span class="weak">✗ Passwords do not match</span>';
            }
        }

        function checkAdminPasswordStrength() {
            var password = document.getElementById('admin_new_password').value;
            var strengthDiv = document.getElementById('admin-password-strength');
            if (!strengthDiv) return;
            if (password.length === 0) {
                strengthDiv.innerHTML = '';
                return;
            }
            var strength = 0;
            if (password.length >= 8) strength += 1;
            if (password.match(/[a-z]+/)) strength += 1;
            if (password.match(/[A-Z]+/)) strength += 1;
            if (password.match(/[0-9]+/)) strength += 1;
            if (password.match(/[$@#&!]+/)) strength += 1;
            var strengthText = '';
            var strengthClass = '';
            if (strength < 3) {
                strengthText = 'Weak';
                strengthClass = 'weak';
            } else if (strength < 5) {
                strengthText = 'Medium';
                strengthClass = 'medium';
            } else {
                strengthText = 'Strong';
                strengthClass = 'strong';
            }
            strengthDiv.innerHTML = 'Password strength: <span class="' + strengthClass + '">' + strengthText + '</span>';
        }

        function checkAdminPasswordMatch() {
            var password = document.getElementById('admin_new_password').value;
            var confirm = document.getElementById('admin_confirm_password').value;
            var matchDiv = document.getElementById('admin-password-match');
            if (!matchDiv) return;
            if (confirm.length === 0) {
                matchDiv.innerHTML = '';
                return;
            }
            if (password === confirm) {
                matchDiv.innerHTML = '<span class="strong">✓ Passwords match</span>';
            } else {
                matchDiv.innerHTML = '<span class="weak">✗ Passwords do not match</span>';
            }
        }

        function validatePasswordForm() {
            var current = document.getElementById('current_password').value;
            var newPass = document.getElementById('new_password').value;
            var confirm = document.getElementById('confirm_password').value;
            if (!current || !newPass || !confirm) {
                alert("All password fields are required!");
                return false;
            }
            if (newPass.length < 8) {
                alert("New password must be at least 8 characters long!");
                return false;
            }
            if (newPass !== confirm) {
                alert("New password and confirm password do not match!");
                return false;
            }
            return true;
        }

        function validateAdminPasswordForm() {
            var newPass = document.getElementById('admin_new_password').value;
            var confirm = document.getElementById('admin_confirm_password').value;
            if (!newPass || !confirm) {
                alert("All password fields are required!");
                return false;
            }
            if (newPass.length < 8) {
                alert("Password must be at least 8 characters long!");
                return false;
            }
            if (newPass !== confirm) {
                alert("Passwords do not match!");
                return false;
            }
            return true;
        }

        function switchTab(tabId, btn) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(button => {
                button.classList.remove('active');
            });
            document.getElementById(tabId).classList.add('active');
            btn.classList.add('active');
        }

        function handleRoleChange() {
            var roleSelect = document.getElementById('edit_role_id');
            var orgSection = document.getElementById('orgSection');
            if (!roleSelect || !orgSection) return;
            var roleId = parseInt(roleSelect.value);
            if (roleId === 3 || roleId === 4 || roleId === 5 || roleId === 6) {
                orgSection.style.display = 'none';
                var division = document.getElementById('modal_division');
                var dept = document.getElementById('modal_department');
                var unit = document.getElementById('modal_unit');
                var office = document.getElementById('modal_office');
                if (division) division.value = '';
                if (dept) dept.innerHTML = '<option value="">Select Department</option>';
                if (unit) unit.innerHTML = '<option value="">Select Unit</option>';
                if (office) office.innerHTML = '<option value="">Select Office</option>';
            } else {
                orgSection.style.display = 'block';
            }
        }

        function loadDepartments(divisionId) {
            var deptSelect = document.getElementById('modal_department');
            var unitSelect = document.getElementById('modal_unit');
            var officeSelect = document.getElementById('modal_office');
            if (!deptSelect) return;
            if (!divisionId) {
                deptSelect.innerHTML = '<option value="">Select Department</option>';
                if (unitSelect) unitSelect.innerHTML = '<option value="">Select Unit</option>';
                if (officeSelect) officeSelect.innerHTML = '<option value="">Select Office</option>';
                return;
            }
            deptSelect.innerHTML = '<option value="">Loading...</option>';
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'ajax_get_departments.php?division_id=' + divisionId, true);
            xhr.onload = function() {
                if (this.status === 200) {
                    deptSelect.innerHTML = this.responseText;
                } else {
                    deptSelect.innerHTML = '<option value="">Error loading departments</option>';
                }
                if (unitSelect) unitSelect.innerHTML = '<option value="">Select Unit</option>';
                if (officeSelect) officeSelect.innerHTML = '<option value="">Select Office</option>';
            };
            xhr.send();
        }

        function loadUnits(departmentId) {
            var unitSelect = document.getElementById('modal_unit');
            var officeSelect = document.getElementById('modal_office');
            if (!unitSelect) return;
            if (!departmentId) {
                unitSelect.innerHTML = '<option value="">Select Unit</option>';
                if (officeSelect) officeSelect.innerHTML = '<option value="">Select Office</option>';
                return;
            }
            unitSelect.innerHTML = '<option value="">Loading...</option>';
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'ajax_get_units.php?department_id=' + departmentId, true);
            xhr.onload = function() {
                if (this.status === 200) {
                    unitSelect.innerHTML = this.responseText;
                } else {
                    unitSelect.innerHTML = '<option value="">Error loading units</option>';
                }
                if (officeSelect) officeSelect.innerHTML = '<option value="">Select Office</option>';
            };
            xhr.send();
        }

        function loadOffices(unitId) {
            var officeSelect = document.getElementById('modal_office');
            if (!officeSelect) return;
            if (!unitId) {
                officeSelect.innerHTML = '<option value="">Select Office</option>';
                return;
            }
            officeSelect.innerHTML = '<option value="">Loading...</option>';
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'ajax_get_offices.php?unit_id=' + unitId, true);
            xhr.onload = function() {
                if (this.status === 200) {
                    officeSelect.innerHTML = this.responseText;
                } else {
                    officeSelect.innerHTML = '<option value="">Error loading offices</option>';
                }
            };
            xhr.send();
        }

        function fetchUserData(userId) {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'ajax_get_user.php?user_id=' + userId, true);
            xhr.onload = function() {
                if (this.status === 200) {
                    try {
                        var response = JSON.parse(this.responseText);
                        if (response.success) {
                            var user = response.user;
                            document.getElementById('edit_username').value = user.username || '';
                            document.getElementById('edit_email').value = user.email || '';
                            document.getElementById('edit_fullname').value = user.full_name || '';
                            var roleSelect = document.getElementById('edit_role_id');
                            roleSelect.value = user.role_id || '';
                            if (document.getElementById('edit_password')) {
                                document.getElementById('edit_password').value = '';
                            }
                            if (document.getElementById('edit_confirm_password')) {
                                document.getElementById('edit_confirm_password').value = '';
                            }
                            handleRoleChange();
                            if (user.role_id < 3 || user.role_id > 6) {
                                if (user.division_id) {
                                    var divisionSelect = document.getElementById('modal_division');
                                    if (divisionSelect) {
                                        divisionSelect.value = user.division_id;
                                        if (user.department_id) {
                                            loadDepartments(user.division_id);
                                            setTimeout(function() {
                                                var deptSelect = document.getElementById('modal_department');
                                                if (deptSelect) {
                                                    deptSelect.value = user.department_id;
                                                    if (user.unit_id) {
                                                        loadUnits(user.department_id);
                                                        setTimeout(function() {
                                                            var unitSelect = document.getElementById('modal_unit');
                                                            if (unitSelect) {
                                                                unitSelect.value = user.unit_id;
                                                                if (user.office_id) {
                                                                    loadOffices(user.unit_id);
                                                                    setTimeout(function() {
                                                                        var officeSelect = document.getElementById('modal_office');
                                                                        if (officeSelect) {
                                                                            officeSelect.value = user.office_id;
                                                                        }
                                                                    }, 300);
                                                                }
                                                            }
                                                        }, 300);
                                                    }
                                                }
                                            }, 300);
                                        }
                                    }
                                }
                            }
                        } else {
                            alert('Error loading user data: ' + (response.message || 'Unknown error'));
                        }
                    } catch (e) {
                        console.error('Error parsing user data:', e);
                        alert('Error parsing user data');
                    }
                } else {
                    alert('Error loading user data: HTTP ' + this.status);
                }
            };
            xhr.send();
        }

        function openUserModal(action, userId = null) {
            var modal = document.getElementById('userModal');
            if (!modal) return;
            var title = document.getElementById('modalTitle');
            var actionField = document.getElementById('formAction');
            var userIdField = document.getElementById('edit_user_id');
            var addPassFields = document.getElementById('addPasswordFields');
            var editPassFields = document.getElementById('editPasswordFields');
            if (action === 'add') {
                title.textContent = 'Add New User';
                actionField.value = 'add_user';
                userIdField.value = '';
                document.getElementById('userForm').reset();
                addPassFields.style.display = 'block';
                editPassFields.style.display = 'none';
                if (document.getElementById('add_password')) {
                    document.getElementById('add_password').required = true;
                }
                if (document.getElementById('add_confirm_password')) {
                    document.getElementById('add_confirm_password').required = true;
                }
                if (document.getElementById('edit_password')) {
                    document.getElementById('edit_password').required = false;
                }
                if (document.getElementById('edit_confirm_password')) {
                    document.getElementById('edit_confirm_password').required = false;
                }
                var division = document.getElementById('modal_division');
                var dept = document.getElementById('modal_department');
                var unit = document.getElementById('modal_unit');
                var office = document.getElementById('modal_office');
                if (division) division.value = '';
                if (dept) dept.innerHTML = '<option value="">Select Department</option>';
                if (unit) unit.innerHTML = '<option value="">Select Unit</option>';
                if (office) office.innerHTML = '<option value="">Select Office</option>';
                var roleSelect = document.getElementById('edit_role_id');
                if (roleSelect) {
                    roleSelect.value = '7';
                    handleRoleChange();
                }
                var strengthDiv = document.getElementById('add-password-strength');
                if (strengthDiv) strengthDiv.innerHTML = '';
                var matchDiv = document.getElementById('add-password-match');
                if (matchDiv) matchDiv.innerHTML = '';
            } else if (action === 'edit' && userId) {
                title.textContent = 'Edit User';
                actionField.value = 'update_user';
                userIdField.value = userId;
                addPassFields.style.display = 'none';
                editPassFields.style.display = 'block';
                if (document.getElementById('add_password')) {
                    document.getElementById('add_password').required = false;
                }
                if (document.getElementById('add_confirm_password')) {
                    document.getElementById('add_confirm_password').required = false;
                }
                if (document.getElementById('edit_password')) {
                    document.getElementById('edit_password').required = false;
                }
                if (document.getElementById('edit_confirm_password')) {
                    document.getElementById('edit_confirm_password').required = false;
                }
                var division = document.getElementById('modal_division');
                var dept = document.getElementById('modal_department');
                var unit = document.getElementById('modal_unit');
                var office = document.getElementById('modal_office');
                if (division) division.value = '';
                if (dept) dept.innerHTML = '<option value="">Select Department</option>';
                if (unit) unit.innerHTML = '<option value="">Select Unit</option>';
                if (office) office.innerHTML = '<option value="">Select Office</option>';
                fetchUserData(userId);
            }
            modal.classList.add('active');
        }

        function closeUserModal() {
            var modal = document.getElementById('userModal');
            if (modal) {
                modal.classList.remove('active');
            }
            document.getElementById('userForm').reset();
        }

        function openPasswordModal(userId, username) {
            document.getElementById('target_user_id').value = userId;
            document.getElementById('target_username').textContent = username;
            document.getElementById('passwordModal').classList.add('active');
            document.getElementById('admin_new_password').value = '';
            document.getElementById('admin_confirm_password').value = '';
            var strengthDiv = document.getElementById('admin-password-strength');
            if (strengthDiv) strengthDiv.innerHTML = '';
            var matchDiv = document.getElementById('admin-password-match');
            if (matchDiv) matchDiv.innerHTML = '';
        }

        function closePasswordModal() {
            document.getElementById('passwordModal').classList.remove('active');
        }

        function confirmDelete(userId, username) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('deleteUserName').textContent = username;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }

        function validateUserForm() {
            var action = document.getElementById('formAction').value;
            var username = document.getElementById('edit_username').value.trim();
            var email = document.getElementById('edit_email').value.trim();
            var fullname = document.getElementById('edit_fullname').value.trim();
            var roleId = parseInt(document.getElementById('edit_role_id').value);
            if (!username) {
                alert("Username is required!");
                return false;
            }
            if (!email) {
                alert("Email is required!");
                return false;
            }
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                alert("Please enter a valid email address!");
                return false;
            }
            if (!fullname) {
                alert("Full Name is required!");
                return false;
            }
            if (!roleId) {
                alert("Role is required!");
                return false;
            }
            if (action === 'add_user') {
                var password = document.getElementById('add_password').value;
                var confirmPass = document.getElementById('add_confirm_password').value;
                if (!password) {
                    alert("Password is required!");
                    return false;
                }
                if (password.length < 8) {
                    alert("Password must be at least 8 characters!");
                    return false;
                }
                if (password !== confirmPass) {
                    alert("Passwords do not match!");
                    return false;
                }
            }
            if (action === 'update_user') {
                var password = document.getElementById('edit_password').value;
                var confirmPass = document.getElementById('edit_confirm_password').value;
                if (password || confirmPass) {
                    if (password.length < 8) {
                        alert("Password must be at least 8 characters!");
                        return false;
                    }
                    if (password !== confirmPass) {
                        alert("Passwords do not match!");
                        return false;
                    }
                }
            }
            return true;
        }

        document.addEventListener('DOMContentLoaded', function() {
            var userForm = document.getElementById('userForm');
            if (userForm) {
                userForm.addEventListener('submit', function(e) {
                    if (!validateUserForm()) {
                        e.preventDefault();
                        return false;
                    }
                });
            }
            var roleSelect = document.getElementById('edit_role_id');
            if (roleSelect) {
                roleSelect.addEventListener('change', handleRoleChange);
                handleRoleChange();
            }
            var modals = document.querySelectorAll('.modal');
            modals.forEach(function(modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                        if (this.id === 'userModal') {
                            document.getElementById('userForm').reset();
                        }
                    }
                });
            });
            var alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.display = 'none';
                }, 5000);
            });
            var newPass = document.getElementById('new_password');
            if (newPass) {
                newPass.addEventListener('keyup', checkPasswordStrength);
            }
            var confirmPass = document.getElementById('confirm_password');
            if (confirmPass) {
                confirmPass.addEventListener('keyup', checkPasswordMatch);
            }
            var addPass = document.getElementById('add_password');
            if (addPass) {
                addPass.addEventListener('keyup', checkAddPasswordStrength);
            }
            var addConfirm = document.getElementById('add_confirm_password');
            if (addConfirm) {
                addConfirm.addEventListener('keyup', checkAddPasswordMatch);
            }
            var editPass = document.getElementById('edit_password');
            if (editPass) {
                editPass.addEventListener('keyup', checkEditPasswordStrength);
            }
            var editConfirm = document.getElementById('edit_confirm_password');
            if (editConfirm) {
                editConfirm.addEventListener('keyup', checkEditPasswordMatch);
            }
            var adminPass = document.getElementById('admin_new_password');
            if (adminPass) {
                adminPass.addEventListener('keyup', checkAdminPasswordStrength);
            }
            var adminConfirm = document.getElementById('admin_confirm_password');
            if (adminConfirm) {
                adminConfirm.addEventListener('keyup', checkAdminPasswordMatch);
            }

            // Make sure toast container exists
            if (!document.getElementById('notificationToastContainer')) {
                const toastContainer = document.createElement('div');
                toastContainer.id = 'notificationToastContainer';
                toastContainer.className = 'notification-toast-container';
                document.body.appendChild(toastContainer);
            }
        });
    </script>
</body>
</html>