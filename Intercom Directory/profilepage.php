<?php
require_once 'conn.php';

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
$user_data = [];

// Fetch current user information
$sql = "SELECT u.*, 
               d.division_name, 
               dept.department_name,
               un.unit_name,
               o.office_name
        FROM users u
        LEFT JOIN divisions d ON u.division_id = d.division_id
        LEFT JOIN departments dept ON u.department_id = dept.department_id
        LEFT JOIN units un ON u.unit_id = un.unit_id
        LEFT JOIN offices o ON u.office_id = o.office_id
        WHERE u.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user_data = $result->fetch_assoc();
} else {
    $error = "User not found!";
    header('Location: logout.php');
    exit;
}

// Get active divisions for dropdown
$divisions = getDivisions($conn);

// Keep form field values for repopulation
$username = $user_data['username'];
$email = $user_data['email'];
$fullname = $user_data['full_name'];
$current_role_id = $user_data['role_id'];
$current_division_id = $user_data['division_id'];
$current_department_id = $user_data['department_id'];
$current_unit_id = $user_data['unit_id'];
$current_office_id = $user_data['office_id'];

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'update_profile') {
            // Update profile information
            $username = trim($_POST['username']);
            $email = trim($_POST['email']);
            $fullname = trim($_POST['fn']);
            
            // Validate email format
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Invalid email format!";
            }
            
            if (empty($error)) {
                // Check if username or email already exists (excluding current user)
                $check_sql = "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?";
                $check_stmt = mysqli_prepare($conn, $check_sql);
                mysqli_stmt_bind_param($check_stmt, "ssi", $username, $email, $user_id);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);
                
                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $error = "Username or email already exists!";
                } else {
                    $update_sql = "UPDATE users SET username = ?, email = ?, full_name = ? WHERE user_id = ?";
                    $update_stmt = mysqli_prepare($conn, $update_sql);
                    mysqli_stmt_bind_param($update_stmt, "sssi", $username, $email, $fullname, $user_id);
                    
                    if (mysqli_stmt_execute($update_stmt)) {
                        $_SESSION['username'] = $username; // Update session
                        $success = "Profile updated successfully!";
                        
                        // Refresh user data
                        $user_data['username'] = $username;
                        $user_data['email'] = $email;
                        $user_data['full_name'] = $fullname;
                    } else {
                        $error = "Failed to update profile: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($update_stmt);
                }
                mysqli_stmt_close($check_stmt);
            }
        } elseif ($action === 'change_password') {
            // Change password (for current user)
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            // Verify current password
            if (!password_verify($current_password, $user_data['password'])) {
                $error = "Current password is incorrect!";
            } elseif ($new_password !== $confirm_password) {
                $error = "New passwords do not match!";
            } elseif (strlen($new_password) < 8) {
                $error = "New password must be at least 8 characters long!";
            } else {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_sql = "UPDATE users SET password = ? WHERE user_id = ?";
                $update_stmt = mysqli_prepare($conn, $update_sql);
                mysqli_stmt_bind_param($update_stmt, "si", $password_hash, $user_id);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $success = "Password changed successfully!";
                } else {
                    $error = "Failed to change password!";
                }
                mysqli_stmt_close($update_stmt);
            }
        } elseif ($action === 'update_user' && isAdmin()) {
            // Admin update user
            $edit_user_id = (int)$_POST['edit_user_id'];
            $edit_username = trim($_POST['edit_username']);
            $edit_email = trim($_POST['edit_email']);
            $edit_fullname = trim($_POST['edit_fullname']);
            $edit_role_id = (int)$_POST['edit_role_id'];
            $edit_division_id = !empty($_POST['edit_division_id']) ? (int)$_POST['edit_division_id'] : NULL;
            $edit_department_id = !empty($_POST['edit_department_id']) ? (int)$_POST['edit_department_id'] : NULL;
            $edit_unit_id = !empty($_POST['edit_unit_id']) ? (int)$_POST['edit_unit_id'] : NULL;
            $edit_office_id = !empty($_POST['edit_office_id']) ? (int)$_POST['edit_office_id'] : NULL;
            
            // Validate email format
            if (!filter_var($edit_email, FILTER_VALIDATE_EMAIL)) {
                $error = "Invalid email format!";
            }
            
            if (empty($error)) {
                // Check if username or email already exists (excluding the user being edited)
                $check_sql = "SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?";
                $check_stmt = mysqli_prepare($conn, $check_sql);
                mysqli_stmt_bind_param($check_stmt, "ssi", $edit_username, $edit_email, $edit_user_id);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);
                
                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $error = "Username or email already exists!";
                } else {
                    $update_sql = "UPDATE users SET username = ?, email = ?, full_name = ?, role_id = ?, division_id = ?, department_id = ?, unit_id = ?, office_id = ? WHERE user_id = ?";
                    $update_stmt = mysqli_prepare($conn, $update_sql);
                    mysqli_stmt_bind_param($update_stmt, "sssiiiiii", $edit_username, $edit_email, $edit_fullname, $edit_role_id, $edit_division_id, $edit_department_id, $edit_unit_id, $edit_office_id, $edit_user_id);
                    
                    if (mysqli_stmt_execute($update_stmt)) {
                        $success = "User updated successfully!";
                        // Force immediate refresh
                        echo '<script>window.location.href = window.location.pathname + "?refresh=" + Date.now();</script>';
                        exit();
                    } else {
                        $error = "Failed to update user: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($update_stmt);
                }
                mysqli_stmt_close($check_stmt);
            }
        } elseif ($action === 'add_user' && isAdmin()) {
            // Admin add new user
            $edit_username = trim($_POST['edit_username']);
            $edit_email = trim($_POST['edit_email']);
            $edit_fullname = trim($_POST['edit_fullname']);
            $edit_role_id = (int)$_POST['edit_role_id'];
            $edit_division_id = !empty($_POST['edit_division_id']) ? (int)$_POST['edit_division_id'] : NULL;
            $edit_department_id = !empty($_POST['edit_department_id']) ? (int)$_POST['edit_department_id'] : NULL;
            $edit_unit_id = !empty($_POST['edit_unit_id']) ? (int)$_POST['edit_unit_id'] : NULL;
            $edit_office_id = !empty($_POST['edit_office_id']) ? (int)$_POST['edit_office_id'] : NULL;
            
            // Validate email format
            if (!filter_var($edit_email, FILTER_VALIDATE_EMAIL)) {
                $error = "Invalid email format!";
            }
            
            if (empty($error)) {
                // Check if username or email already exists
                $check_sql = "SELECT user_id FROM users WHERE username = ? OR email = ?";
                $check_stmt = mysqli_prepare($conn, $check_sql);
                mysqli_stmt_bind_param($check_stmt, "ss", $edit_username, $edit_email);
                mysqli_stmt_execute($check_stmt);
                mysqli_stmt_store_result($check_stmt);
                
                if (mysqli_stmt_num_rows($check_stmt) > 0) {
                    $error = "Username or email already exists!";
                } else {
                    // INSERT new user - set default password
                    $default_password = password_hash('Password123!', PASSWORD_DEFAULT);
                    $insert_sql = "INSERT INTO users (username, email, full_name, role_id, division_id, department_id, unit_id, office_id, password, status) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                    $insert_stmt = mysqli_prepare($conn, $insert_sql);
                    mysqli_stmt_bind_param($insert_stmt, "sssiiiiis", $edit_username, $edit_email, $edit_fullname, $edit_role_id, $edit_division_id, $edit_department_id, $edit_unit_id, $edit_office_id, $default_password);
                    
                    if (mysqli_stmt_execute($insert_stmt)) {
                        $success = "User added successfully! Default password: Password123!";
                        // Force immediate refresh
                        echo '<script>window.location.href = window.location.pathname + "?refresh=" + Date.now();</script>';
                        exit();
                    } else {
                        $error = "Failed to add user: " . mysqli_error($conn);
                        error_log("SQL Error: " . mysqli_error($conn));
                    }
                    mysqli_stmt_close($insert_stmt);
                }
                mysqli_stmt_close($check_stmt);
            }
        } elseif ($action === 'admin_change_password' && isAdmin()) {
            // Admin changes another user's password
            $target_user_id = (int)$_POST['target_user_id'];
            $admin_new_password = trim($_POST['admin_new_password']);
            $admin_confirm_password = trim($_POST['admin_confirm_password']);
            
            if ($admin_new_password !== $admin_confirm_password) {
                $error = "Passwords do not match!";
            } elseif (strlen($admin_new_password) < 8) {
                $error = "Password must be at least 8 characters long!";
            } else {
                $password_hash = password_hash($admin_new_password, PASSWORD_DEFAULT);
                $update_sql = "UPDATE users SET password = ? WHERE user_id = ?";
                $update_stmt = mysqli_prepare($conn, $update_sql);
                mysqli_stmt_bind_param($update_stmt, "si", $password_hash, $target_user_id);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $success = "Password changed successfully for user ID: " . $target_user_id;
                    // Force immediate refresh
                    echo '<script>window.location.href = window.location.pathname + "?refresh=" + Date.now();</script>';
                    exit();
                } else {
                    $error = "Failed to change password: " . mysqli_error($conn);
                }
                mysqli_stmt_close($update_stmt);
            }
        } elseif ($action === 'delete_user' && isAdmin()) {
            // Admin delete user (soft delete)
            $delete_user_id = (int)$_POST['delete_user_id'];
            
            if ($delete_user_id != $user_id) { // Prevent self-deletion
                $update_sql = "UPDATE users SET status = 'decommissioned' WHERE user_id = ?";
                $update_stmt = mysqli_prepare($conn, $update_sql);
                mysqli_stmt_bind_param($update_stmt, "i", $delete_user_id);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $success = "User decommissioned successfully!";
                    // Force immediate refresh
                    echo '<script>window.location.href = window.location.pathname + "?refresh=" + Date.now();</script>';
                    exit();
                } else {
                    $error = "Failed to deactivate user: " . mysqli_error($conn);
                }
                mysqli_stmt_close($update_stmt);
            } else {
                $error = "You cannot delete your own account!";
            }
        }
    }
}

// Get all users for admin panel
$all_users = [];
if (isAdmin()) {
    $users_sql = "SELECT u.*, 
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
    $users_result = $conn->query($users_sql);
    while ($row = $users_result->fetch_assoc()) {
        $all_users[] = $row;
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
        /* --- GENERAL --- */
        * { box-sizing: border-box; margin:0; padding:0; font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background-color: #edf4fc;
        }

        /* --- HEADER --- */
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

        /* --- MAIN CONTENT --- */
        .content {
            flex: 1;
            margin-top: 100px;
            padding: 20px;
        }

        /* --- PROFILE CONTAINER --- */
        .profile-container {
            display: flex;
            gap: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* --- PROFILE SIDEBAR --- */
        .profile-sidebar {
            width: 300px;
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            height: fit-content;
        }

        .profile-header {
            margin-bottom: 25px;
        }

        .profile-name {
            font-size: 24px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .profile-role {
            color: #2b6cb0;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 20px;
            padding: 5px 10px;
            background-color: #bee3f8;
            border-radius: 4px;
            display: inline-block;
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
        }

        .stat-value {
            color: #2d3748;
            font-weight: 500;
        }

        /* --- PROFILE CONTENT --- */
        .profile-content {
            flex: 1;
        }

        .profile-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .profile-card h3 {
            color: #2b6cb0;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }

        /* --- FORM STYLES --- */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #4a5568;
            font-weight: 500;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2b6cb0;
            box-shadow: 0 0 0 3px rgba(43,108,176,0.1);
        }

        .form-group input[readonly],
        .form-group input[disabled] {
            background-color: #f7fafc;
            color: #718096;
            cursor: not-allowed;
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
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }

        /* --- BUTTONS --- */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn i {
            font-size: 14px;
        }

        .btn-primary {
            background-color: #2b6cb0;
            color: white;
        }

        .btn-primary:hover {
            background-color: #1f4f8b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(43,108,176,0.3);
        }

        .btn-secondary {
            background-color: #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background-color: #cbd5e0;
        }

        .btn-danger {
            background-color: #f56565;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c53030;
        }

        .btn-success {
            background-color: #38a169;
            color: white;
        }

        .btn-success:hover {
            background-color: #2f855a;
        }

        .btn-warning {
            background-color: #ed8936;
            color: white;
        }

        .btn-warning:hover {
            background-color: #dd6b20;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 14px;
        }

        /* --- ALERTS --- */
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert.success {
            background-color: #c6f6d5;
            color: #276749;
            border: 1px solid #9ae6b4;
        }

        .alert.error {
            background-color: #fed7d7;
            color: #9b2c2c;
            border: 1px solid #feb2b2;
        }

        /* --- TAB NAVIGATION --- */
        .tab-nav {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 12px 24px;
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
            background-color: #2b6cb0;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* --- ADMIN PANEL STYLES --- */
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .admin-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .admin-stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* --- USER TABLE --- */
        .user-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .user-table th {
            background-color: #2b6cb0;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }

        .user-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
        }

        .user-table tr:hover {
            background-color: #f7fafc;
        }

        .user-table tr:last-child td {
            border-bottom: none;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-admin { background-color: #fed7d7; color: #9b2c2c; }
        .role-mcc { background-color: #bee3f8; color: #2c5282; }
        .role-division { background-color: #c6f6d5; color: #276749; }
        .role-department { background-color: #fefcbf; color: #744210; }
        .role-unit { background-color: #e9d8fd; color: #553c9a; }
        .role-office { background-color: #fed7e2; color: #97266d; }
        .role-staff { background-color: #e2e8f0; color: #4a5568; }

        .user-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        /* --- MODAL STYLES --- */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .modal-header h3 {
            margin: 0;
            color: #2b6cb0;
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
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        /* --- FOOTER --- */
        .footer {
            background-color: #07417f;
            color: #fff;
            text-align: center;
            padding: 18px 10px;
            font-size: 14px;
            margin-top: auto;
        }

        /* --- RESPONSIVE --- */
        @media (max-width: 992px) {
            .profile-container {
                flex-direction: column;
            }
            
            .profile-sidebar {
                width: 100%;
            }
            
            .admin-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                padding: 15px;
                text-align: center;
            }
            
            .header .logo span {
                font-size: 1.3rem;
            }
            
            .user-table {
                display: block;
                overflow-x: auto;
            }
            
            .admin-stats {
                grid-template-columns: 1fr;
            }
            
            .user-actions {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>
<body>

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
            <?php endif; ?>
            <li><a href="profilepage.php">Profile</a></li>
            <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
        <?php endif; ?>
    </ul>
</div>

<div class="content">
    <div class="profile-container">
        <!-- Profile Sidebar -->
        <div class="profile-sidebar">
            <div class="profile-header">
                <h2 class="profile-name"><?php echo htmlspecialchars($user_data['full_name']); ?></h2>
                <div class="profile-role"><?php echo $role_names[$user_data['role_id']] ?? 'User'; ?></div>
            </div>
            
            <div class="profile-stats">
                <div class="stat-row">
                    <span class="stat-label">Username:</span>
                    <span class="stat-value"><?php echo htmlspecialchars($user_data['username']); ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Email:</span>
                    <span class="stat-value"><?php echo htmlspecialchars($user_data['email']); ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Division:</span>
                    <span class="stat-value"><?php echo $user_data['division_name'] ?? 'N/A'; ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Department:</span>
                    <span class="stat-value"><?php echo $user_data['department_name'] ?? 'N/A'; ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Unit:</span>
                    <span class="stat-value"><?php echo $user_data['unit_name'] ?? 'N/A'; ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Office:</span>
                    <span class="stat-value"><?php echo $user_data['office_name'] ?? 'N/A'; ?></span>
                </div>
                <?php if (isAdmin()): ?>
                <div class="stat-row">
                    <span class="stat-label">Users Online:</span>
                    <span class="stat-value"><?php echo getOnlineAdmins($conn); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Profile Content -->
        <div class="profile-content">
            <?php if ($error): ?>
                <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <!-- Tab Navigation -->
            <div class="tab-nav">
                <button class="tab-btn active" onclick="openTab('profile')">Profile Info</button>
                <button class="tab-btn" onclick="openTab('password')">Change Password</button>
                <?php if (isAdmin()): ?>
                <button class="tab-btn" onclick="openTab('admin')">Admin Panel</button>
                <?php endif; ?>
            </div>

            <!-- Profile Info Tab -->
            <div id="profile-tab" class="tab-content active">
                <div class="profile-card">
                    <h3>Edit Profile Information</h3>
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
                            <label for="role_id">Role (Read-only)</label>
                            <input type="text" id="role_id" value="<?php echo $role_names[$current_role_id] ?? 'Unknown'; ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="division_name">Division (Read-only)</label>
                            <input type="text" id="division_name" value="<?php echo $user_data['division_name'] ?? 'N/A'; ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="department_name">Department (Read-only)</label>
                            <input type="text" id="department_name" value="<?php echo $user_data['department_name'] ?? 'N/A'; ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="unit_name">Unit (Read-only)</label>
                            <input type="text" id="unit_name" value="<?php echo $user_data['unit_name'] ?? 'N/A'; ?>" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="office_name">Office (Read-only)</label>
                            <input type="text" id="office_name" value="<?php echo $user_data['office_name'] ?? 'N/A'; ?>" readonly>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>
            </div>

            <!-- Change Password Tab -->
            <div id="password-tab" class="tab-content">
                <div class="profile-card">
                    <h3>Change Password</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group password-container">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required>
                            <button type="button" onclick="togglePassword('current_password')">Show</button>
                        </div>
                        
                        <div class="form-group password-container">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" required>
                            <button type="button" onclick="togglePassword('new_password')">Show</button>
                        </div>
                        
                        <div class="form-group password-container">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                            <button type="button" onclick="togglePassword('confirm_password')">Show</button>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </form>
                </div>
            </div>

            <!-- Admin Panel Tab (Only for admins) -->
            <?php if (isAdmin()): ?>
            <div id="admin-tab" class="tab-content">
                <div class="profile-card">
                    <div class="admin-header">
                        <h3>User Management</h3>
                        <button class="btn btn-success" onclick="openUserModal('add')">
                            <span>Add New User</span>
                        </button>
                    </div>
                    
                    <div class="admin-stats">
                        <div class="admin-stat-card">
                            <div class="admin-stat-value"><?php echo count($all_users); ?></div>
                            <div class="admin-stat-label">Total Users</div>
                        </div>
                        <div class="admin-stat-card">
                            <div class="admin-stat-value"><?php echo count(array_filter($all_users, fn($user) => $user['role_id'] == 1)); ?></div>
                            <div class="admin-stat-label">Admins</div>
                        </div>
                        <div class="admin-stat-card">
                            <div class="admin-stat-value"><?php echo getOnlineAdmins($conn); ?></div>
                            <div class="admin-stat-label">Online Now</div>
                        </div>
                    </div>

                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Division</th>
                                <th>Department</th>
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
                                    <?php 
                                    $role_class = '';
                                    switch($user['role_id']) {
                                        case 1: $role_class = 'role-admin'; break;
                                        case 2: $role_class = 'role-mcc'; break;
                                        case 3: $role_class = 'role-division'; break;
                                        case 4: $role_class = 'role-department'; break;
                                        case 5: $role_class = 'role-unit'; break;
                                        case 6: $role_class = 'role-office'; break;
                                        case 7: $role_class = 'role-staff'; break;
                                    }
                                    ?>
                                    <span class="role-badge <?php echo $role_class; ?>">
                                        <?php echo $role_names[$user['role_id']] ?? 'Unknown'; ?>
                                    </span>
                                </td>
                                <td><?php echo $user['division_name'] ?? 'N/A'; ?></td>
                                <td><?php echo $user['department_name'] ?? 'N/A'; ?></td>
                                <td>
                                    <div class="user-actions">
                                        <button class="btn btn-primary btn-sm" onclick="openUserModal('edit', <?php echo $user['user_id']; ?>)">Edit</button>
                                        <button class="btn btn-warning btn-sm" onclick="openPasswordModal(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">Password</button>
                                        <?php if ($user['user_id'] != $user_id): ?>
                                        <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">Delete</button>
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

<!-- User Modal (for add/edit) -->
<div class="modal" id="userModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Add/Edit User</h3>
            <button class="modal-close" onclick="closeUserModal()">&times;</button>
        </div>
        <form id="userForm" method="POST">
            <input type="hidden" name="action" id="formAction" value="add_user">
            <input type="hidden" name="edit_user_id" id="edit_user_id" value="">
            
            <div class="form-group">
                <label for="edit_username">Username *</label>
                <input type="text" id="edit_username" name="edit_username" required>
            </div>
            
            <div class="form-group">
                <label for="edit_email">Email Address *</label>
                <input type="email" id="edit_email" name="edit_email" required>
            </div>
            
            <div class="form-group">
                <label for="edit_fullname">Full Name *</label>
                <input type="text" id="edit_fullname" name="edit_fullname" required>
            </div>
            
            <div class="form-group">
                <label for="edit_role_id">Role *</label>
                <select id="edit_role_id" name="edit_role_id" required onchange="toggleOrgFields()">
                    <option value="">Select Role</option>
                    <?php foreach($role_names as $id => $name): ?>
                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="edit_division_id">Division</label>
                <select id="edit_division_id" name="edit_division_id" onchange="loadEditDepartments(this.value)">
                    <option value="">Select Division</option>
                    <?php foreach($divisions as $division): ?>
                        <option value="<?php echo $division['division_id']; ?>">
                            <?php echo htmlspecialchars($division['division_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="edit_department_id">Department</label>
                <select id="edit_department_id" name="edit_department_id" onchange="loadEditUnits(this.value)">
                    <option value="">Select Department</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="edit_unit_id">Unit</label>
                <select id="edit_unit_id" name="edit_unit_id" onchange="loadEditOffices(this.value)">
                    <option value="">Select Unit</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="edit_office_id">Office</label>
                <select id="edit_office_id" name="edit_office_id">
                    <option value="">Select Office</option>
                </select>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeUserModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save User</button>
            </div>
        </form>
    </div>
</div>

<!-- Password Change Modal (for admin to change user passwords) -->
<div class="modal" id="passwordModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="passwordModalTitle">Change User Password</h3>
            <button class="modal-close" onclick="closePasswordModal()">&times;</button>
        </div>
        <form id="passwordForm" method="POST">
            <input type="hidden" name="action" value="admin_change_password">
            <input type="hidden" name="target_user_id" id="target_user_id" value="">
            
            <div class="form-group">
                <label>Username:</label>
                <input type="text" id="password_username" readonly class="form-control" style="background-color: #f7fafc;">
            </div>
            
            <div class="form-group password-container">
                <label for="admin_new_password">New Password *</label>
                <input type="password" id="admin_new_password" name="admin_new_password" required>
                <button type="button" onclick="togglePassword('admin_new_password')">Show</button>
            </div>
            
            <div class="form-group password-container">
                <label for="admin_confirm_password">Confirm New Password *</label>
                <input type="password" id="admin_confirm_password" name="admin_confirm_password" required>
                <button type="button" onclick="togglePassword('admin_confirm_password')">Show</button>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePasswordModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Change Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Confirm Deletion</h3>
            <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <p>Are you sure you want to deactivate user: <strong id="deleteUserName"></strong>?</p>
        <p class="text-warning">This action cannot be undone.</p>
        <form id="deleteForm" method="POST">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="delete_user_id" id="delete_user_id" value="">
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Delete User</button>
            </div>
        </form>
    </div>
</div>

<div class="footer">
    © 2026 Intercom Directory. All rights reserved.<br>
    Developed by TNTS Programming Students JT.DP.RR
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// Tab functionality
function openTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById(tabName + '-tab').classList.add('active');
    
    // Set active tab button
    event.currentTarget.classList.add('active');
}

// Password toggle function
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const button = input.nextElementSibling;
    
    if (input.type === 'password') {
        input.type = 'text';
        button.textContent = 'Hide';
    } else {
        input.type = 'password';
        button.textContent = 'Show';
    }
}

// User modal functions
let currentAction = 'add';
let currentUserId = null;

function openUserModal(action, userId = null) {
    currentAction = action;
    currentUserId = userId;
    
    const modal = document.getElementById('userModal');
    const title = document.getElementById('modalTitle');
    const form = document.getElementById('userForm');
    const actionField = document.getElementById('formAction');
    const userIdField = document.getElementById('edit_user_id');
    
    if (action === 'add') {
        title.textContent = 'Add New User';
        actionField.value = 'add_user';
        userIdField.value = '';
        form.reset();
        toggleOrgFields();
        
        // Clear dependent dropdowns for new user
        document.getElementById('edit_department_id').innerHTML = '<option value="">Select Department</option>';
        document.getElementById('edit_unit_id').innerHTML = '<option value="">Select Unit</option>';
        document.getElementById('edit_office_id').innerHTML = '<option value="">Select Office</option>';
    } else if (action === 'edit' && userId) {
        title.textContent = 'Edit User';
        actionField.value = 'update_user';
        userIdField.value = userId;
        
        // Get user data from PHP variable (already loaded)
        const allUsers = <?php echo json_encode($all_users); ?>;
        const user = allUsers.find(u => parseInt(u.user_id) === parseInt(userId));
        
        if (user) {
            console.log('Found user:', user);
            document.getElementById('edit_username').value = user.username || '';
            document.getElementById('edit_email').value = user.email || '';
            document.getElementById('edit_fullname').value = user.full_name || '';
            document.getElementById('edit_role_id').value = user.role_id || '';
            document.getElementById('edit_division_id').value = user.division_id || '';
            
            // Load dependent dropdowns
            if (user.division_id) {
                // Use setTimeout to ensure the department load happens after the role is set
                setTimeout(() => {
                    loadEditDepartments(user.division_id, user.department_id);
                    
                    // Chain loading for unit and office
                    if (user.department_id) {
                        setTimeout(() => {
                            loadEditUnits(user.department_id, user.unit_id);
                            
                            if (user.unit_id) {
                                setTimeout(() => {
                                    loadEditOffices(user.unit_id, user.office_id);
                                }, 100);
                            }
                        }, 100);
                    }
                }, 100);
            }
            
            toggleOrgFields();
        } else {
            console.error('User not found in loaded data. User ID:', userId, 'All users:', allUsers);
            alert('User not found in loaded data');
        }
    }
    
    modal.classList.add('active');
}

function closeUserModal() {
    document.getElementById('userModal').classList.remove('active');
}

// Password modal functions
function openPasswordModal(userId, username) {
    document.getElementById('target_user_id').value = userId;
    document.getElementById('password_username').value = username;
    document.getElementById('passwordModalTitle').textContent = 'Change Password for: ' + username;
    document.getElementById('passwordModal').classList.add('active');
}

function closePasswordModal() {
    document.getElementById('passwordModal').classList.remove('active');
}

// Delete modal functions
function confirmDelete(userId, username) {
    document.getElementById('delete_user_id').value = userId;
    document.getElementById('deleteUserName').textContent = username;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

// Role-based field toggling for edit form
function toggleOrgFields() {
    const roleId = parseInt(document.getElementById('edit_role_id').value);
    const division = document.getElementById('edit_division_id');
    const department = document.getElementById('edit_department_id');
    const unit = document.getElementById('edit_unit_id');
    const office = document.getElementById('edit_office_id');
    
    [department, unit, office].forEach(field => {
        field.disabled = true;
        field.required = false;
        if (field.id !== 'edit_department_id') {
            field.innerHTML = '<option value="">Select ' + field.name.replace('edit_', '').replace('_', ' ').replace('id', '') + '</option>';
        }
    });

    division.disabled = true;
    division.required = false;
    
    if ([3, 4, 5, 6, 7].includes(roleId)) {
        division.disabled = false;
        division.required = true;
    }
    if ([4, 5, 6, 7].includes(roleId)) {
        department.disabled = false;
        department.required = true;
    }
    if ([5, 6, 7].includes(roleId)) {
        unit.disabled = false;
        unit.required = true;
    }
    if ([6, 7].includes(roleId)) {
        office.disabled = false;
        office.required = true;
    }
}

// AJAX functions for dependent dropdowns in edit form
function loadEditDepartments(divisionId, selectedId = null) {
    if (!divisionId) {
        document.getElementById('edit_department_id').innerHTML = '<option value="">Select Department</option>';
        document.getElementById('edit_unit_id').innerHTML = '<option value="">Select Unit</option>';
        document.getElementById('edit_office_id').innerHTML = '<option value="">Select Office</option>';
        return;
    }
    $.post('ajax_get_departments.php', {division_id: divisionId}, function(response) {
        $('#edit_department_id').html(response);
        if (selectedId) {
            $('#edit_department_id').val(selectedId);
            loadEditUnits(selectedId);
        }
        $('#edit_unit_id').html('<option value="">Select Unit</option>');
        $('#edit_office_id').html('<option value="">Select Office</option>');
    });
}

function loadEditUnits(departmentId, selectedId = null) {
    if (!departmentId) {
        document.getElementById('edit_unit_id').innerHTML = '<option value="">Select Unit</option>';
        document.getElementById('edit_office_id').innerHTML = '<option value="">Select Office</option>';
        return;
    }
    $.post('ajax_get_units.php', {department_id: departmentId}, function(response) {
        $('#edit_unit_id').html(response);
        if (selectedId) {
            $('#edit_unit_id').val(selectedId);
            loadEditOffices(selectedId);
        }
        $('#edit_office_id').html('<option value="">Select Office</option>');
    });
}

function loadEditOffices(unitId, selectedId = null) {
    if (!unitId) {
        document.getElementById('edit_office_id').innerHTML = '<option value="">Select Office</option>';
        return;
    }
    $.post('ajax_get_offices.php', {unit_id: unitId}, function(response) {
        $('#edit_office_id').html(response);
        if (selectedId) {
            $('#edit_office_id').val(selectedId);
        }
    });
}

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.display = 'none';
        }, 5000);
    });
    
    // Close modals when clicking outside
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    });
});
</script>
</body>
</html>