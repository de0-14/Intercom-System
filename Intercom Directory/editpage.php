<?php
require_once 'conn.php';
updateAllUsersActivity($conn);

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Add notification-related variables
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$is_admin = isAdmin();
$admin_notifications_count = 0;
$admin_chat_requests = [];

if($is_admin && $user_id) {
    $admin_notifications_count = getAdminUnreadChatsCount($conn, $user_id);
    $admin_chat_requests = getAdminChatRequests($conn, $user_id);
}

// Also add these for the header
$user_unread = 0;
if($user_id && !$is_admin) {
    $user_unread = getUnreadAdminMessageCount($conn, $user_id, false);
}

$error = '';
$success = '';
$edit_mode = false;
$edit_org_mode = '';
$current_item = null;
$current_org_item = null;

$active_section = isset($_GET['section']) ? $_GET['section'] : 'contacts';

// Handle delete contact number
if (isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];
    
    $delete_sql = "DELETE FROM numbers WHERE number_id = ?";
    $params = array($delete_id);
    $delete_stmt = sqlsrv_query($conn, $delete_sql, $params);
    
    if ($delete_stmt) {
        $success = "Contact number deleted successfully!";
    } else {
        $error = "Failed to delete contact number: " . print_r(sqlsrv_errors(), true);
    }
    sqlsrv_free_stmt($delete_stmt);
}

// Handle delete division
if (isset($_POST['delete_division'])) {
    $division_id = (int)$_POST['delete_division'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Check if division has departments
        $check_sql = "SELECT COUNT(*) as count FROM departments WHERE division_id = ?";
        $check_params = array($division_id);
        $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
        
        if ($check_stmt && sqlsrv_fetch($check_stmt)) {
            $count = sqlsrv_get_field($check_stmt, 0);
            
            if ($count > 0) {
                sqlsrv_free_stmt($check_stmt);
                throw new Exception("Cannot delete division. It contains departments.");
            }
        }
        sqlsrv_free_stmt($check_stmt);
        
        // Delete numbers associated with this division
        $delete_numbers_sql = "DELETE FROM numbers WHERE division_id = ?";
        $delete_numbers_params = array($division_id);
        $delete_numbers_stmt = sqlsrv_query($conn, $delete_numbers_sql, $delete_numbers_params);
        sqlsrv_free_stmt($delete_numbers_stmt);
        
        // Delete the division
        $delete_sql = "DELETE FROM divisions WHERE division_id = ?";
        $delete_params = array($division_id);
        $delete_stmt = sqlsrv_query($conn, $delete_sql, $delete_params);
        
        if ($delete_stmt) {
            sqlsrv_commit($conn);
            $success = "Division deleted successfully!";
        } else {
            throw new Exception("Failed to delete division: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_stmt);
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $error = $e->getMessage();
    }
}

// Handle delete department
if (isset($_POST['delete_department'])) {
    $department_id = (int)$_POST['delete_department'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Check if department has units
        $check_sql = "SELECT COUNT(*) as count FROM units WHERE department_id = ?";
        $check_params = array($department_id);
        $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
        
        if ($check_stmt && sqlsrv_fetch($check_stmt)) {
            $count = sqlsrv_get_field($check_stmt, 0);
            
            if ($count > 0) {
                sqlsrv_free_stmt($check_stmt);
                throw new Exception("Cannot delete department. It contains units.");
            }
        }
        sqlsrv_free_stmt($check_stmt);
        
        // Delete numbers associated with this department
        $delete_numbers_sql = "DELETE FROM numbers WHERE department_id = ?";
        $delete_numbers_params = array($department_id);
        $delete_numbers_stmt = sqlsrv_query($conn, $delete_numbers_sql, $delete_numbers_params);
        sqlsrv_free_stmt($delete_numbers_stmt);
        
        // Delete the department
        $delete_sql = "DELETE FROM departments WHERE department_id = ?";
        $delete_params = array($department_id);
        $delete_stmt = sqlsrv_query($conn, $delete_sql, $delete_params);
        
        if ($delete_stmt) {
            sqlsrv_commit($conn);
            $success = "Department deleted successfully!";
        } else {
            throw new Exception("Failed to delete department: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_stmt);
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $error = $e->getMessage();
    }
}

// Handle delete unit
if (isset($_POST['delete_unit'])) {
    $unit_id = (int)$_POST['delete_unit'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Check if unit has offices
        $check_sql = "SELECT COUNT(*) as count FROM offices WHERE unit_id = ?";
        $check_params = array($unit_id);
        $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
        
        if ($check_stmt && sqlsrv_fetch($check_stmt)) {
            $count = sqlsrv_get_field($check_stmt, 0);
            
            if ($count > 0) {
                sqlsrv_free_stmt($check_stmt);
                throw new Exception("Cannot delete unit. It contains offices.");
            }
        }
        sqlsrv_free_stmt($check_stmt);
        
        // Delete numbers associated with this unit
        $delete_numbers_sql = "DELETE FROM numbers WHERE unit_id = ?";
        $delete_numbers_params = array($unit_id);
        $delete_numbers_stmt = sqlsrv_query($conn, $delete_numbers_sql, $delete_numbers_params);
        sqlsrv_free_stmt($delete_numbers_stmt);
        
        // Delete the unit
        $delete_sql = "DELETE FROM units WHERE unit_id = ?";
        $delete_params = array($unit_id);
        $delete_stmt = sqlsrv_query($conn, $delete_sql, $delete_params);
        
        if ($delete_stmt) {
            sqlsrv_commit($conn);
            $success = "Unit deleted successfully!";
        } else {
            throw new Exception("Failed to delete unit: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_stmt);
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $error = $e->getMessage();
    }
}

// Handle delete office
if (isset($_POST['delete_office'])) {
    $office_id = (int)$_POST['delete_office'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Delete numbers associated with this office
        $delete_numbers_sql = "DELETE FROM numbers WHERE office_id = ?";
        $delete_numbers_params = array($office_id);
        $delete_numbers_stmt = sqlsrv_query($conn, $delete_numbers_sql, $delete_numbers_params);
        sqlsrv_free_stmt($delete_numbers_stmt);
        
        // Delete the office
        $delete_sql = "DELETE FROM offices WHERE office_id = ?";
        $delete_params = array($office_id);
        $delete_stmt = sqlsrv_query($conn, $delete_sql, $delete_params);
        
        if ($delete_stmt) {
            sqlsrv_commit($conn);
            $success = "Office deleted successfully!";
        } else {
            throw new Exception("Failed to delete office: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_stmt);
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $error = $e->getMessage();
    }
}

// Handle edit contact number (with status) - UPDATED FOR EMAIL
if (isset($_POST['save_edit'])) {
    $edit_id = (int)$_POST['edit_id'];
    $new_number = trim($_POST['new_number']);
    $new_email = trim($_POST['new_email'] ?? '');
    $new_description = trim($_POST['new_description']);
    $new_head = trim($_POST['new_head']);
    $new_status = trim($_POST['new_status']);
    $new_division_id = !empty($_POST['new_division_id']) ? (int)$_POST['new_division_id'] : NULL;
    $new_department_id = !empty($_POST['new_department_id']) ? (int)$_POST['new_department_id'] : NULL;
    $new_unit_id = !empty($_POST['new_unit_id']) ? (int)$_POST['new_unit_id'] : NULL;
    $new_office_id = !empty($_POST['new_office_id']) ? (int)$_POST['new_office_id'] : NULL;
    
    // Validate email if provided
    if (!empty($new_email) && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $update_sql = "UPDATE numbers SET numbers = ?, email = ?, description = ?, head = ?, status = ?, division_id = ?, department_id = ?, unit_id = ?, office_id = ? WHERE number_id = ?";
        $update_params = array($new_number, $new_email, $new_description, $new_head, $new_status, $new_division_id, $new_department_id, $new_unit_id, $new_office_id, $edit_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if ($update_stmt) {
            $success = "Contact number updated successfully!";
            $edit_mode = false;
            header("Location: editpage.php?section=$active_section&success=" . urlencode($success));
            exit;
        } else {
            $error = "Failed to update contact number: " . print_r(sqlsrv_errors(), true);
        }
        sqlsrv_free_stmt($update_stmt);
    }
}

// Handle update division
if (isset($_POST['update_division'])) {
    $division_id = (int)$_POST['division_id'];
    $division_name = trim($_POST['division_name']);
    $division_head = trim($_POST['division_head']);
    $division_status = $_POST['division_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Update the division
        $update_sql = "UPDATE divisions SET division_name = ?, status = ? WHERE division_id = ?";
        $update_params = array($division_name, $division_status, $division_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update division: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($update_stmt);
        
        // Also update the head in the associated numbers
        if (!empty($division_head)) {
            $update_head_sql = "UPDATE numbers SET head = ? WHERE division_id = ?";
            $update_head_params = array($division_head, $division_id);
            $update_head_stmt = sqlsrv_query($conn, $update_head_sql, $update_head_params);
            if (!$update_head_stmt) {
                throw new Exception("Failed to update division head: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($update_head_stmt);
        }
        
        // If decommissioning, cascade to all child organizations and their numbers
        if ($division_status === 'decommissioned') {
            // Update all numbers under this division
            $update_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE division_id = ?";
            $update_numbers_params = array($division_id);
            $update_numbers_stmt = sqlsrv_query($conn, $update_numbers_sql, $update_numbers_params);
            sqlsrv_free_stmt($update_numbers_stmt);
            
            // Update all departments under this division
            $update_depts_sql = "UPDATE departments SET status = 'decommissioned' WHERE division_id = ?";
            $update_depts_params = array($division_id);
            $update_depts_stmt = sqlsrv_query($conn, $update_depts_sql, $update_depts_params);
            sqlsrv_free_stmt($update_depts_stmt);
            
            // Get all departments under this division to cascade to units
            $dept_ids_sql = "SELECT department_id FROM departments WHERE division_id = ?";
            $dept_ids_params = array($division_id);
            $dept_ids_stmt = sqlsrv_query($conn, $dept_ids_sql, $dept_ids_params);
            
            $dept_ids = [];
            if ($dept_ids_stmt) {
                while ($dept = sqlsrv_fetch_array($dept_ids_stmt, SQLSRV_FETCH_ASSOC)) {
                    $dept_ids[] = $dept['department_id'];
                }
                sqlsrv_free_stmt($dept_ids_stmt);
            }
            
            if (!empty($dept_ids)) {
                // Update all units under these departments
                $placeholders = implode(',', array_fill(0, count($dept_ids), '?'));
                $update_units_sql = "UPDATE units SET status = 'decommissioned' WHERE department_id IN ($placeholders)";
                $update_units_stmt = sqlsrv_prepare($conn, $update_units_sql, $dept_ids);
                if (!$update_units_stmt || !sqlsrv_execute($update_units_stmt)) {
                    throw new Exception("Failed to update units: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($update_units_stmt);
                
                // Get all units under these departments to cascade to offices
                $unit_ids_sql = "SELECT unit_id FROM units WHERE department_id IN ($placeholders)";
                $unit_ids_stmt = sqlsrv_prepare($conn, $unit_ids_sql, $dept_ids);
                sqlsrv_execute($unit_ids_stmt);
                
                $unit_ids = [];
                while ($unit = sqlsrv_fetch_array($unit_ids_stmt, SQLSRV_FETCH_ASSOC)) {
                    $unit_ids[] = $unit['unit_id'];
                }
                sqlsrv_free_stmt($unit_ids_stmt);
                
                if (!empty($unit_ids)) {
                    // Update all offices under these units
                    $office_placeholders = implode(',', array_fill(0, count($unit_ids), '?'));
                    $update_offices_sql = "UPDATE offices SET status = 'decommissioned' WHERE unit_id IN ($office_placeholders)";
                    $update_offices_stmt = sqlsrv_prepare($conn, $update_offices_sql, $unit_ids);
                    if (!$update_offices_stmt || !sqlsrv_execute($update_offices_stmt)) {
                        throw new Exception("Failed to update offices: " . print_r(sqlsrv_errors(), true));
                    }
                    sqlsrv_free_stmt($update_offices_stmt);
                    
                    // Update all numbers under these offices
                    $update_office_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE office_id IN ($office_placeholders)";
                    $update_office_numbers_stmt = sqlsrv_prepare($conn, $update_office_numbers_sql, $unit_ids);
                    if (!$update_office_numbers_stmt || !sqlsrv_execute($update_office_numbers_stmt)) {
                        throw new Exception("Failed to update office numbers: " . print_r(sqlsrv_errors(), true));
                    }
                    sqlsrv_free_stmt($update_office_numbers_stmt);
                }
            }
        }
        
        sqlsrv_commit($conn);
        $success = "Division updated successfully!" . ($division_status === 'decommissioned' ? " All child organizations and their numbers have been decommissioned." : "");
        $edit_org_mode = '';
        header("Location: editpage.php?section=divisions&success=" . urlencode($success));
        exit;
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $error = $e->getMessage();
    }
}

// Handle update department
if (isset($_POST['update_department'])) {
    $department_id = (int)$_POST['department_id'];
    $department_name = trim($_POST['department_name']);
    $department_head = trim($_POST['department_head']);
    $division_id = (int)$_POST['division_id'];
    $department_status = $_POST['department_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        $update_sql = "UPDATE departments SET department_name = ?, division_id = ?, status = ? WHERE department_id = ?";
        $update_params = array($department_name, $division_id, $department_status, $department_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update department: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($update_stmt);
        
        if (!empty($department_head)) {
            $update_head_sql = "UPDATE numbers SET head = ? WHERE department_id = ?";
            $update_head_params = array($department_head, $department_id);
            $update_head_stmt = sqlsrv_query($conn, $update_head_sql, $update_head_params);
            if (!$update_head_stmt) {
                throw new Exception("Failed to update department head: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($update_head_stmt);
        }
        
        // If decommissioning, cascade to all child units, offices, and their numbers
        if ($department_status === 'decommissioned') {
            // Update all numbers under this department
            $update_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE department_id = ?";
            $update_numbers_params = array($department_id);
            $update_numbers_stmt = sqlsrv_query($conn, $update_numbers_sql, $update_numbers_params);
            sqlsrv_free_stmt($update_numbers_stmt);
            
            // Update all units under this department
            $update_units_sql = "UPDATE units SET status = 'decommissioned' WHERE department_id = ?";
            $update_units_params = array($department_id);
            $update_units_stmt = sqlsrv_query($conn, $update_units_sql, $update_units_params);
            sqlsrv_free_stmt($update_units_stmt);
            
            // Get all units under this department to cascade to offices
            $unit_ids_sql = "SELECT unit_id FROM units WHERE department_id = ?";
            $unit_ids_params = array($department_id);
            $unit_ids_stmt = sqlsrv_query($conn, $unit_ids_sql, $unit_ids_params);
            
            $unit_ids = [];
            if ($unit_ids_stmt) {
                while ($unit = sqlsrv_fetch_array($unit_ids_stmt, SQLSRV_FETCH_ASSOC)) {
                    $unit_ids[] = $unit['unit_id'];
                }
                sqlsrv_free_stmt($unit_ids_stmt);
            }
            
            if (!empty($unit_ids)) {
                // Update all offices under these units
                $placeholders = implode(',', array_fill(0, count($unit_ids), '?'));
                $update_offices_sql = "UPDATE offices SET status = 'decommissioned' WHERE unit_id IN ($placeholders)";
                $update_offices_stmt = sqlsrv_prepare($conn, $update_offices_sql, $unit_ids);
                if (!$update_offices_stmt || !sqlsrv_execute($update_offices_stmt)) {
                    throw new Exception("Failed to update offices: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($update_offices_stmt);
                
                // Update all numbers under these offices
                $update_office_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE office_id IN ($placeholders)";
                $update_office_numbers_stmt = sqlsrv_prepare($conn, $update_office_numbers_sql, $unit_ids);
                if (!$update_office_numbers_stmt || !sqlsrv_execute($update_office_numbers_stmt)) {
                    throw new Exception("Failed to update office numbers: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($update_office_numbers_stmt);
            }
        }
        
        sqlsrv_commit($conn);
        $success = "Department updated successfully!" . ($department_status === 'decommissioned' ? " All child units, offices, and their numbers have been decommissioned." : "");
        $edit_org_mode = '';
        header("Location: editpage.php?section=departments&success=" . urlencode($success));
        exit;
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $error = $e->getMessage();
    }
}

// Handle update unit
if (isset($_POST['update_unit'])) {
    $unit_id = (int)$_POST['unit_id'];
    $unit_name = trim($_POST['unit_name']);
    $unit_head = trim($_POST['unit_head']);
    $department_id = (int)$_POST['department_id'];
    $unit_status = $_POST['unit_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        $update_sql = "UPDATE units SET unit_name = ?, department_id = ?, status = ? WHERE unit_id = ?";
        $update_params = array($unit_name, $department_id, $unit_status, $unit_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update unit: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($update_stmt);
        
        if (!empty($unit_head)) {
            $update_head_sql = "UPDATE numbers SET head = ? WHERE unit_id = ?";
            $update_head_params = array($unit_head, $unit_id);
            $update_head_stmt = sqlsrv_query($conn, $update_head_sql, $update_head_params);
            if (!$update_head_stmt) {
                throw new Exception("Failed to update unit head: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($update_head_stmt);
        }
        
        // If decommissioning, cascade to all child offices and their numbers
        if ($unit_status === 'decommissioned') {
            // Update all numbers under this unit
            $update_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE unit_id = ?";
            $update_numbers_params = array($unit_id);
            $update_numbers_stmt = sqlsrv_query($conn, $update_numbers_sql, $update_numbers_params);
            sqlsrv_free_stmt($update_numbers_stmt);
            
            // Update all offices under this unit
            $update_offices_sql = "UPDATE offices SET status = 'decommissioned' WHERE unit_id = ?";
            $update_offices_params = array($unit_id);
            $update_offices_stmt = sqlsrv_query($conn, $update_offices_sql, $update_offices_params);
            sqlsrv_free_stmt($update_offices_stmt);
            
            // Update all numbers under offices in this unit
            $update_office_numbers_sql = "UPDATE numbers n SET n.status = 'decommissioned' FROM numbers n INNER JOIN offices o ON n.office_id = o.office_id WHERE o.unit_id = ?";
            $update_office_numbers_params = array($unit_id);
            $update_office_numbers_stmt = sqlsrv_query($conn, $update_office_numbers_sql, $update_office_numbers_params);
            if (!$update_office_numbers_stmt) {
                throw new Exception("Failed to update office numbers: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($update_office_numbers_stmt);
        }
        
        sqlsrv_commit($conn);
        $success = "Unit updated successfully!" . ($unit_status === 'decommissioned' ? " All child offices and their numbers have been decommissioned." : "");
        $edit_org_mode = '';
        header("Location: editpage.php?section=units&success=" . urlencode($success));
        exit;
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $error = $e->getMessage();
    }
}

// Handle update office
if (isset($_POST['update_office'])) {
    $office_id = (int)$_POST['office_id'];
    $office_name = trim($_POST['office_name']);
    $office_head = trim($_POST['office_head']);
    $unit_id = (int)$_POST['unit_id'];
    $office_status = $_POST['office_status'];
    
    $update_sql = "UPDATE offices SET office_name = ?, unit_id = ?, status = ? WHERE office_id = ?";
    $update_params = array($office_name, $unit_id, $office_status, $office_id);
    $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
    
    if ($update_stmt) {
        if (!empty($office_head)) {
            $update_head_sql = "UPDATE numbers SET head = ? WHERE office_id = ?";
            $update_head_params = array($office_head, $office_id);
            $update_head_stmt = sqlsrv_query($conn, $update_head_sql, $update_head_params);
            sqlsrv_free_stmt($update_head_stmt);
        }
        $success = "Office updated successfully!";
        $edit_org_mode = '';
        header("Location: editpage.php?section=offices&success=" . urlencode($success));
        exit;
    } else {
        $error = "Failed to update office: " . print_r(sqlsrv_errors(), true);
    }
    sqlsrv_free_stmt($update_stmt);
}

// Handle edit mode for contact number
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    
    $sql = "SELECT * FROM numbers WHERE number_id = ?";
    $params = array($edit_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $current_item = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $edit_mode = true;
    } else {
        $error = "Contact not found!";
    }
    sqlsrv_free_stmt($stmt);
}

// Handle edit division
if (isset($_GET['edit_division'])) {
    $division_id = (int)$_GET['edit_division'];
    
    $sql = "SELECT * FROM divisions WHERE division_id = ?";
    $params = array($division_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $current_org_item = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $edit_org_mode = 'division';
    } else {
        $error = "Division not found!";
    }
    sqlsrv_free_stmt($stmt);
}

// Handle edit department
if (isset($_GET['edit_department'])) {
    $department_id = (int)$_GET['edit_department'];
    
    $sql = "SELECT * FROM departments WHERE department_id = ?";
    $params = array($department_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $current_org_item = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $edit_org_mode = 'department';
    } else {
        $error = "Department not found!";
    }
    sqlsrv_free_stmt($stmt);
}

// Handle edit unit
if (isset($_GET['edit_unit'])) {
    $unit_id = (int)$_GET['edit_unit'];
    
    $sql = "SELECT * FROM units WHERE unit_id = ?";
    $params = array($unit_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $current_org_item = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $edit_org_mode = 'unit';
    } else {
        $error = "Unit not found!";
    }
    sqlsrv_free_stmt($stmt);
}

// Handle edit office
if (isset($_GET['edit_office'])) {
    $office_id = (int)$_GET['edit_office'];
    
    $sql = "SELECT * FROM offices WHERE office_id = ?";
    $params = array($office_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $current_org_item = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $edit_org_mode = 'office';
    } else {
        $error = "Office not found!";
    }
    sqlsrv_free_stmt($stmt);
}

// Fetch all contact numbers with organizational info - UPDATED FOR EMAIL
$query = "SELECT 
    n.numbers,
    n.email, 
    n.description,
    n.head,
    u.username as head_username,
    u.full_name as head_full_name,
    d.division_id, d.division_name,
    dp.department_id, dp.department_name,
    un.unit_id, un.unit_name,
    o.office_id, o.office_name,
    n.number_id,
    n.status
FROM numbers n
LEFT JOIN users u ON n.head_user_id = u.user_id
LEFT JOIN divisions d ON n.division_id = d.division_id AND d.status = 'active'
LEFT JOIN departments dp ON n.department_id = dp.department_id AND dp.status = 'active'
LEFT JOIN units un ON n.unit_id = un.unit_id AND un.status = 'active'
LEFT JOIN offices o ON n.office_id = o.office_id AND o.status = 'active'
WHERE n.status = 'active'
ORDER BY 
    d.division_name,
    dp.department_name,
    un.unit_name,
    o.office_name,
    n.numbers;";

$result = sqlsrv_query($conn, $query);
$numbers = [];
if ($result) {
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $numbers[] = $row;
    }
    sqlsrv_free_stmt($result);
}   

// Fetch all organizational units for dropdowns
$divisions_result = sqlsrv_query($conn, "SELECT dv.* FROM divisions dv ORDER BY division_name");
$divisions_list = [];
if ($divisions_result) {
    while ($row = sqlsrv_fetch_array($divisions_result, SQLSRV_FETCH_ASSOC)) {
        $divisions_list[] = $row;
    }
    sqlsrv_free_stmt($divisions_result);
}

// For divisions, get the most recent head from numbers
foreach ($divisions_list as &$division) {
    $head_sql = "SELECT TOP 1 head FROM numbers WHERE division_id = ? ORDER BY number_id DESC";
    $head_params = array($division['division_id']);
    $head_stmt = sqlsrv_query($conn, $head_sql, $head_params);
    if ($head_stmt && sqlsrv_has_rows($head_stmt)) {
        $head_row = sqlsrv_fetch_array($head_stmt, SQLSRV_FETCH_ASSOC);
        $division['head'] = $head_row['head'];
    } else {
        $division['head'] = 'Not assigned';
    }
    sqlsrv_free_stmt($head_stmt);
    unset($division);
}

$departments_result = sqlsrv_query($conn, "SELECT d.*, dv.division_name FROM departments d LEFT JOIN divisions dv ON d.division_id = dv.division_id ORDER BY d.department_name");
$departments_list = [];
if ($departments_result) {
    while ($row = sqlsrv_fetch_array($departments_result, SQLSRV_FETCH_ASSOC)) {
        $departments_list[] = $row;
    }
    sqlsrv_free_stmt($departments_result);
}

// For departments, get the most recent head from numbers
foreach ($departments_list as &$department) {
    $head_sql = "SELECT TOP 1 head FROM numbers WHERE department_id = ? ORDER BY number_id DESC";
    $head_params = array($department['department_id']);
    $head_stmt = sqlsrv_query($conn, $head_sql, $head_params);
    if ($head_stmt && sqlsrv_has_rows($head_stmt)) {
        $head_row = sqlsrv_fetch_array($head_stmt, SQLSRV_FETCH_ASSOC);
        $department['head'] = $head_row['head'];
    } else {
        $department['head'] = 'Not assigned';
    }
    sqlsrv_free_stmt($head_stmt);
    unset($department);
}

$units_result = sqlsrv_query($conn, "SELECT u.*, d.department_name, dv.division_name FROM units u LEFT JOIN departments d ON u.department_id = d.department_id LEFT JOIN divisions dv ON d.division_id = dv.division_id ORDER BY u.unit_name");
$units_list = [];
if ($units_result) {
    while ($row = sqlsrv_fetch_array($units_result, SQLSRV_FETCH_ASSOC)) {
        $units_list[] = $row;
    }
    sqlsrv_free_stmt($units_result);
}

// For units, get the most recent head from numbers
foreach ($units_list as &$unit) {
    $head_sql = "SELECT TOP 1 head FROM numbers WHERE unit_id = ? ORDER BY number_id DESC";
    $head_params = array($unit['unit_id']);
    $head_stmt = sqlsrv_query($conn, $head_sql, $head_params);
    if ($head_stmt && sqlsrv_has_rows($head_stmt)) {
        $head_row = sqlsrv_fetch_array($head_stmt, SQLSRV_FETCH_ASSOC);
        $unit['head'] = $head_row['head'];
    } else {
        $unit['head'] = 'Not assigned';
    }
    sqlsrv_free_stmt($head_stmt);
    unset($unit);
}

$offices_result = sqlsrv_query($conn, "SELECT o.*, u.unit_name, d.department_name, dv.division_name FROM offices o LEFT JOIN units u ON o.unit_id = u.unit_id LEFT JOIN departments d ON u.department_id = d.department_id LEFT JOIN divisions dv ON d.division_id = dv.division_id ORDER BY o.office_name");
$offices_list = [];
if ($offices_result) {
    while ($row = sqlsrv_fetch_array($offices_result, SQLSRV_FETCH_ASSOC)) {
        $offices_list[] = $row;
    }
    sqlsrv_free_stmt($offices_result);
}

// For offices, get the most recent head from numbers
foreach ($offices_list as &$office) {
    $head_sql = "SELECT TOP 1 head FROM numbers WHERE office_id = ? ORDER BY number_id DESC";
    $head_params = array($office['office_id']);
    $head_stmt = sqlsrv_query($conn, $head_sql, $head_params);
    if ($head_stmt && sqlsrv_has_rows($head_stmt)) {
        $head_row = sqlsrv_fetch_array($head_stmt, SQLSRV_FETCH_ASSOC);
        $office['head'] = $head_row['head'];
    } else {
        $office['head'] = 'Not assigned';
    }
    sqlsrv_free_stmt($head_stmt);
    unset($office);
}   

// Fetch for dropdowns in forms
$all_divisions = getDivisions($conn, false);
$all_departments = getDepartments($conn, false);
$all_units = getUnits($conn, false);

$offices_result2 = sqlsrv_query($conn, "SELECT * FROM offices ORDER BY office_name");
$offices = [];
if ($offices_result2) {
    while ($row = sqlsrv_fetch_array($offices_result2, SQLSRV_FETCH_ASSOC)) {
        $offices[] = $row;
    }
    sqlsrv_free_stmt($offices_result2);
}

// Fetch all users' full names for dropdown
$head_names_result = sqlsrv_query($conn, "SELECT user_id, full_name FROM users WHERE status = 'active' ORDER BY full_name");
$head_names = [];
if ($head_names_result) {
    while ($row = sqlsrv_fetch_array($head_names_result, SQLSRV_FETCH_ASSOC)) {
        $head_names[] = $row;
    }
    sqlsrv_free_stmt($head_names_result);
}

if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}

$division_heads = getUsersByRoleIds($conn, [3]); // Only Division Heads (role_id 3)
$department_heads = getUsersByRoleIds($conn, [3, 4]); // Division Heads and Department Heads
$unit_heads = getUsersByRoleIds($conn, [3, 4, 5]); // Division, Department, and Unit Heads
$office_heads = getUsersByRoleIds($conn, [3, 4, 5, 6]); // All Heads (Division, Department, Unit, Office)

// Also get all heads for the contact numbers form (original functionality)
$all_heads = getUsersByRoleIds($conn, [3, 4, 5, 6]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit/Remove Contacts</title>
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

        /* ============ ACTIVE STATE STYLING - MATCHING PROFILEPAGE.PHP ============ */
        ul.nav li a.active {
            background-color: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
        }

        ul.nav li a.active:hover {
            background-color: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
        }
        /* ============ END ACTIVE STATE STYLING ============ */

        /* --- MAIN CONTENT --- */
        .content {
            flex: 1;
            margin-top: 100px; /* leave space for fixed header */
            padding: 20px;
        }

        /* --- EDIT CONTAINER --- */
        .edit-container {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
            padding: 25px;
        }

        .edit-container h2 {
            color: #2b6cb0;
            margin-bottom: 25px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 15px;
            text-align: center;
        }

        /* --- SECTION BUTTONS --- */
        .section-buttons {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .edit-section-btn {
            padding: 12px 24px;
            border: none;
            background-color: #e2e8f0;
            color: #4a5568;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            font-size: 14px;
        }

        .edit-section-btn:hover {
            background-color: #cbd5e0;
        }

        .edit-section-btn.active {
            background-color: #2b6cb0;
            color: white;
        }

        /* --- SECTION STYLES --- */
        .edit-section {
            display: none;
        }

        .edit-section.active {
            display: block;
        }

        .section-container {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .section-container h3 {
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* --- MESSAGES --- */
        .error-message {
            background-color: #fed7d7;
            color: #9b2c2c;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #feb2b2;
        }

        .success-message {
            background-color: #c6f6d5;
            color: #276749;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #9ae6b4;
        }

        /* --- FORMS --- */
        .edit-form, .org-edit-form, .add-form {
            background-color: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .edit-form h3, .org-edit-form h3, .add-form h3 {
            color: #2d3748;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a5568;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        input[type="text"], select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        input[type="text"]:focus, select:focus {
            outline: none;
            border-color: #2b6cb0;
            box-shadow: 0 0 0 3px rgba(43,108,176,0.1);
        }

        /* --- BUTTONS --- */
        .btn-save {
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 10px 20px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .btn-save:hover {
            background-color: #218838;
        }

        .btn-cancel {
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 10px 20px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: background-color 0.2s;
        }

        .btn-cancel:hover {
            background-color: #5a6268;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .edit-btn, .delete-btn {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }

        .edit-btn {
            background-color: #2b6cb0;
            color: white;
            border: none;
        }

        .edit-btn:hover {
            background-color: #1e4e8c;
        }

        .delete-btn {
            background-color: #e53e3e;
            color: white;
            border: none;
        }

        .delete-btn:hover {
            background-color: #c53030;
        }

        /* --- TABLES --- */
        .contacts-table, .org-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .contacts-table th, .org-table th {
            background-color: #2b6cb0;
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }

        .contacts-table td, .org-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
        }

        .contacts-table tr:hover, .org-table tr:hover {
            background-color: #f7fafc;
        }

        /* --- STATUS BADGES --- */
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-active { background-color: #c6f6d5; color: #276749; border: 1px solid #9ae6b4; }
        .status-decommissioned { background-color: #fed7d7; color: #9b2c2c; border: 1px solid #feb2b2; }
        .status-inactive { background-color: #e2e8f0; color: #4a5568; border: 1px solid #cbd5e0; }

        .org-badge {
            display: inline-block;
            padding: 2px 8px;
            background-color: #bee3f8;
            color: #2c5282;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-right: 8px;
        }

        /* --- SEARCH FILTER --- */
        .search-filter {
            margin-bottom: 20px;
        }

        .search-filter input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
        }

        .search-filter input:focus {
            outline: none;
            border-color: #2b6cb0;
            box-shadow: 0 0 0 3px rgba(43,108,176,0.1);
        }

        /* --- NO ITEMS --- */
        .no-items {
            text-align: center;
            padding: 40px;
            color: #718096;
            font-style: italic;
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
        @media (max-width: 768px) {
            .header { 
                flex-direction: column; 
                padding: 15px; 
                text-align: center; 
            }
            
            .header .logo span { 
                font-size: 1.3rem; 
            }
            
            .section-buttons {
                flex-direction: column;
            }
            
            .form-row {
                flex-direction: column;
                gap: 10px;
            }
            
            .contacts-table, .org-table { 
                display: block; 
                overflow-x: auto; 
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .org-action-buttons {
                flex-direction: column;
                gap: 5px;
            }
        }

        /* --- ORG ACTION BUTTONS --- */
        .org-action-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        /* --- HEAD & ORGANIZATION COLUMN --- */
        .head-org-column {
            vertical-align: top;
        }

        .head-org-container {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .head-info, .org-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .info-label {
            font-size: 11px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .info-label::before {
            content: "•";
            color: #2b6cb0;
            font-size: 16px;
        }

        .head-value {
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
            padding-left: 16px;
        }

        .org-content {
            display: flex;
            align-items: center;
            gap: 8px;
            padding-left: 16px;
        }

        .org-type-badge {
            display: inline-block;
            padding: 3px 8px;
            background-color: #ebf8ff;
            color: #2c5282;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid #bee3f8;
            min-width: 60px;
            text-align: center;
        }

        .org-name {
            font-weight: 500;
            color: #4a5568;
            font-size: 14px;
        }

        .org-not-assigned {
            color: #a0aec0;
            font-style: italic;
            font-size: 13px;
            padding-left: 16px;
        }

        /* --- EMAIL CELL --- */
        .email-cell {
            font-size: 13px;
            color: #4a5568;
            word-break: break-all;
        }
        
        /* --- DROPDOWN WITH INPUT --- */
        .dropdown-with-input {
            position: relative;
            width: 100%;
        }
        
        .dropdown-with-input select {
            width: 100%;
            padding-right: 40px;
        }
        
        .dropdown-with-input .input-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
            font-size: 12px;
            padding: 2px 6px;
            border-radius: 3px;
        }
        
        .dropdown-with-input .input-toggle:hover {
            background-color: #f0f0f0;
        }
        
        .custom-input {
            margin-top: 8px;
        }
        
        /* --- NOTIFICATION BUTTON STYLES --- */
        .admin-notification-container {
            position: relative;
            display: inline-block;
            margin-left: 10px;
        }

        .admin-notification-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #e53e3e, #c53030);
            color: white;
            border: 3px solid white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 20px;
            box-shadow: 0 3px 10px rgba(229, 62, 62, 0.3);
            transition: all 0.3s;
            position: relative;
        }

        .admin-notification-btn:hover {
            background: linear-gradient(135deg, #c53030, #9b2c2c);
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(229, 62, 62, 0.4);
        }

        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            margin-top: 15px;
            padding: 0;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s;
        }

        .admin-notification-container:hover .notification-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notification-header {
            padding: 15px;
            background: #2b6cb0;
            color: white;
            border-radius: 8px 8px 0 0;
        }

        .notification-header h4 {
            margin: 0;
            color: white;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notification-list {
            max-height: 400px;
            overflow-y: auto;
            padding: 10px;
        }

        .notification-item {
            display: flex;
            align-items: center;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 8px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
        }

        .notification-item:hover {
            background-color: #f7fafc;
            border-color: #cbd5e0;
        }

        .notification-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #2b6cb0;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 12px;
            font-size: 16px;
        }

        .notification-info {
            flex: 1;
        }

        .notification-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 3px;
            font-size: 14px;
        }

        .notification-meta {
            font-size: 12px;
            color: #718096;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notification-time {
            color: #a0aec0;
        }

        .message-count-badge {
            background-color: #e53e3e;
            color: white;
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
            font-weight: bold;
        }

        .no-notifications {
            text-align: center;
            padding: 30px 20px;
            color: #a0aec0;
            font-style: italic;
        }

        .notification-footer {
            padding: 12px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
        }

        .view-all-btn {
            display: inline-block;
            padding: 8px 16px;
            background-color: #2b6cb0;
            color: white;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: background-color 0.2s;
        }

        .view-all-btn:hover {
            background-color: #1f4f8b;
        }

        .notification-bell-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #e53e3e;
            color: white;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 10px;
            min-width: 24px;
            text-align: center;
            font-weight: bold;
            border: 2px solid white;
            animation: pulse 1.5s infinite;
        }

        .notification-indicator {
            position: relative;
        }

        .nav-notification-badge {
            background-color: #e53e3e;
            color: white;
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
            margin-left: 5px;
            animation: pulse 2s infinite;
            display: inline-block;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
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
                <!-- ============ ADDED ACTIVE CLASS TO EDIT PAGE BUTTON ============ -->
                <li><a href="editpage.php" class="active">Edit page</a></li>
                <li>
                    <a href="adminpanel.php" class="notification-indicator">
                        Operator Panel 
                        <?php if ($admin_notifications_count > 0): ?>
                            <span class="nav-notification-badge"><?php echo $admin_notifications_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php else: ?>
                <li><a href="adminchat.php">Chat with an Operator <?php echo $user_unread > 0 ? "($user_unread)" : ""; ?></a></li>
            <?php endif; ?>
            <li><a href="profilepage.php">Profile</a></li>
            <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
        <?php endif; ?>
    </ul>
    
    <?php if($is_admin && $user_id): ?>
    <div class="admin-notification-container">
        <button class="admin-notification-btn" id="adminNotificationBtn">
            🔔
            <?php if($admin_notifications_count > 0): ?>
                <span class="notification-bell-badge"><?php echo $admin_notifications_count; ?></span>
            <?php endif; ?>
        </button>
        <div class="notification-dropdown" id="notificationDropdown">
            <div class="notification-header">
                <h4>📨 New Chat Requests (<?php echo $admin_notifications_count; ?>)</h4>
            </div>
            <div class="notification-list">
                <?php if(!empty($admin_chat_requests)): ?>
                    <?php foreach($admin_chat_requests as $request): 
                        $chat_id = getAdminChatWithUser($conn, $user_id, $request['user_id']);
                        $chat_link = $chat_id ? "adminpanel.php?chat_id=$chat_id" : "adminpanel.php?start_chat=" . $request['user_id'];
                    ?>
                    <a href="<?php echo $chat_link; ?>" class="notification-item">
                        <div class="notification-avatar">
                            <?php echo strtoupper(substr($request['full_name'], 0, 1)); ?>
                        </div>
                        <div class="notification-info">
                            <div class="notification-name"><?php echo htmlspecialchars($request['full_name']); ?></div>
                            <div class="notification-meta">
                                <span class="notification-time"><?php echo time_ago($request['first_message_time']); ?></span>
                                <?php if($request['message_count'] > 1): ?>
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

<div class="content">
    <div class="edit-container">
        <h2>Edit/Remove Contacts & Organizational Units</h2>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Centered Section Buttons -->
        <div class="section-buttons">
            <button class="edit-section-btn <?php echo $active_section == 'contacts' ? 'active' : ''; ?>" onclick="showSection('contacts')">Contact Numbers</button>
            <button class="edit-section-btn <?php echo $active_section == 'divisions' ? 'active' : ''; ?>" onclick="showSection('divisions')">Divisions</button>
            <button class="edit-section-btn <?php echo $active_section == 'departments' ? 'active' : ''; ?>" onclick="showSection('departments')">Departments</button>
            <button class="edit-section-btn <?php echo $active_section == 'units' ? 'active' : ''; ?>" onclick="showSection('units')">Units</button>
            <button class="edit-section-btn <?php echo $active_section == 'offices' ? 'active' : ''; ?>" onclick="showSection('offices')">Offices</button>
        </div>
        
        <!-- Edit Contact Number Form - UPDATED FOR EMAIL -->
    <?php if ($edit_mode && $current_item): ?>
        <div class="edit-form">
            <h3>Edit Contact #<?php echo htmlspecialchars($current_item['numbers']); ?></h3>
            <form method="POST">
                <input type="hidden" name="edit_id" value="<?php echo $current_item['number_id']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="new_number">Phone Number(s)</label>
                        <input type="text" id="new_number" name="new_number" 
                               value="<?php echo htmlspecialchars($current_item['numbers']); ?>"
                               placeholder="e.g., 123-4567, 987-6543 (use commas for multiple)">
                    </div>
                    
                    <div class="form-group">
                        <label for="new_email">Email Address</label>
                        <input type="text" id="new_email" name="new_email" 
                               value="<?php echo htmlspecialchars($current_item['email'] ?? ''); ?>"
                               placeholder="e.g., department@hospital.com">
                    </div>
                    
                    <div class="form-group">
                        <label for="new_description">Description *</label>
                        <input type="text" id="new_description" name="new_description" 
                               value="<?php echo htmlspecialchars($current_item['description']); ?>" required 
                               placeholder="e.g., Main line, Emergency line, etc.">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="new_status">Status *</label>
                        <select id="new_status" name="new_status" required>
                            <option value="active" <?php echo (isset($current_item['status']) && $current_item['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="decommissioned" <?php echo (isset($current_item['status']) && $current_item['status'] == 'decommissioned') ? 'selected' : ''; ?>>Decommissioned</option>
                        </select>
                    </div>
                    
                    <!-- Replace the new_head dropdown in the contact number edit form with: -->
                    <div class="form-group">
                        <label for="new_head">Head Name *</label>
                        <select id="new_head" name="new_head" required>
                            <option value="">Select Head</option>
                            <?php foreach ($all_heads as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['full_name']); ?>"
                                    <?php echo $current_item['head'] == $user['full_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                    <?php 
                                    if ($user['role_id'] == 3) echo '(Division Head)';
                                    elseif ($user['role_id'] == 4) echo '(Department Head)';
                                    elseif ($user['role_id'] == 5) echo '(Unit Head)';
                                    elseif ($user['role_id'] == 6) echo '(Office Head)';
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <small style="color: #666;">Note: At least one contact method is required (phone number OR email address)</small>
                </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_division_id">Division</label>
                            <select id="new_division_id" name="new_division_id">
                                <option value="">Select Division</option>
                                <?php foreach ($all_divisions as $division): ?>
                                    <option value="<?php echo $division['division_id']; ?>"
                                        <?php echo $current_item['division_id'] == $division['division_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($division['division_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_department_id">Department</label>
                            <select id="new_department_id" name="new_department_id">
                                <option value="">Select Department</option>
                                <?php foreach ($all_departments as $department): ?>
                                    <option value="<?php echo $department['department_id']; ?>"
                                        <?php echo $current_item['department_id'] == $department['department_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($department['department_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_unit_id">Unit</label>
                            <select id="new_unit_id" name="new_unit_id">
                                <option value="">Select Unit</option>
                                <?php foreach ($all_units as $unit): ?>
                                    <option value="<?php echo $unit['unit_id']; ?>"
                                        <?php echo $current_item['unit_id'] == $unit['unit_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($unit['unit_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_office_id">Office</label>
                            <select id="new_office_id" name="new_office_id">
                                <option value="">Select Office</option>
                                <?php foreach ($offices as $office): ?>
                                    <option value="<?php echo $office['office_id']; ?>"
                                        <?php echo $current_item['office_id'] == $office['office_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($office['office_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <small style="color: #666;">Note: Only one organization type can be selected</small>
                    </div>
                    
                     <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" name="save_edit" class="btn-save">Save Changes</button>
                    <a href="?section=<?php echo $active_section; ?>&cancel=1" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
    <?php endif; ?>
        <!-- Organizational Edit Forms -->
        <?php if ($edit_org_mode == 'division' && isset($current_org_item)): ?>
            <div class="org-edit-form">
                <h3>Edit Division: <?php echo htmlspecialchars($current_org_item['division_name']); ?></h3>
                <form method="POST">
                    <input type="hidden" name="division_id" value="<?php echo $current_org_item['division_id']; ?>">

                    <div class="form-group">
                        <label for="division_name">Division Name *</label>
                        <input type="text" id="division_name" name="division_name" 
                               value="<?php echo htmlspecialchars($current_org_item['division_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="division_head">Division Head *</label>
                        <div class="dropdown-with-input">
                            <select id="division_head" name="division_head" required onchange="handleHeadSelection(this, 'division_custom_input')">
                                <option value="">Select Division Head</option>
                                <?php 
                                $current_head = $current_org_item['head'] ?? '';
                                $has_existing_option = false;
                                foreach ($division_heads as $head): 
                                    if ($head['full_name'] === $current_head) {
                                        $has_existing_option = true;
                                    }
                                ?>
                                    <option value="<?php echo htmlspecialchars($head['full_name']); ?>"
                                        <?php echo $current_head === $head['full_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($head['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="division_status">Status *</label>
                        <select id="division_status" name="division_status" required onchange="showDecommissionWarning(this, 'division')">
                            <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                        </select>
                    </div>
                    
                    <div id="decommissionWarning" style="display: none; background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin-top: 10px; color: #856404;">
                        <strong>Warning:</strong> Decommissioning this organization will also decommission all child organizations and their contact numbers.
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" name="update_division" class="btn-save">Update Division</button>
                        <a href="?section=divisions&cancel=1" class="btn-cancel">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        
        <?php if ($edit_org_mode == 'department' && isset($current_org_item)): ?>
            <div class="org-edit-form">
                <h3>Edit Department: <?php echo htmlspecialchars($current_org_item['department_name']); ?></h3>
                <form method="POST">
                    <input type="hidden" name="department_id" value="<?php echo $current_org_item['department_id']; ?>">
                    
                    <div class="form-group">
                        <label for="department_name">Department Name *</label>
                        <input type="text" id="department_name" name="department_name" 
                               value="<?php echo htmlspecialchars($current_org_item['department_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="department_head">Department Head *</label>
                        <div class="dropdown-with-input">
                            <select id="department_head" name="department_head" required onchange="handleHeadSelection(this, 'department_custom_input')">
                                <option value="">Select Department Head</option>
                                <?php 
                                $current_head = $current_org_item['head'] ?? '';
                                $has_existing_option = false;
                                foreach ($department_heads as $head): 
                                    if ($head['full_name'] === $current_head) {
                                        $has_existing_option = true;
                                    }
                                ?>
                                    <option value="<?php echo htmlspecialchars($head['full_name']); ?>"
                                        <?php echo $current_head === $head['full_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($head['full_name']); ?>
                                        <?php echo $head['role_id'] == 3 ? '(Division Head)' : '(Department Head)'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="division_id">Division *</label>
                        <select id="division_id" name="division_id" required>
                            <option value="">Select Division</option>
                            <?php foreach ($all_divisions as $division): ?>
                                <option value="<?php echo $division['division_id']; ?>"
                                    <?php echo $current_org_item['division_id'] == $division['division_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($division['division_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="department_status">Status *</label>
                        <select id="department_status" name="department_status" required onchange="showDecommissionWarning(this, 'department')">
                            <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                        </select>
                    </div>

                    <div id="decommissionWarning" style="display: none; background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin-top: 10px; color: #856404;">
                        <strong>Warning:</strong> Decommissioning this organization will also decommission all child organizations and their contact numbers.
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" name="update_department" class="btn-save">Update Department</button>
                        <a href="?section=departments&cancel=1" class="btn-cancel">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        
        <?php if ($edit_org_mode == 'unit' && isset($current_org_item)): ?>
            <div class="org-edit-form">
                <h3>Edit Unit: <?php echo htmlspecialchars($current_org_item['unit_name']); ?></h3>
                <form method="POST">
                    <input type="hidden" name="unit_id" value="<?php echo $current_org_item['unit_id']; ?>">
                    
                    <div class="form-group">
                        <label for="unit_name">Unit Name *</label>
                        <input type="text" id="unit_name" name="unit_name" 
                               value="<?php echo htmlspecialchars($current_org_item['unit_name']); ?>" required>
                    </div>
                    
                    <!-- Replace the unit head dropdown section with: -->
                    <div class="form-group">
                        <label for="unit_head">Unit Head *</label>
                        <div class="dropdown-with-input">
                            <select id="unit_head" name="unit_head" required onchange="handleHeadSelection(this, 'unit_custom_input')">
                                <option value="">Select Unit Head</option>
                                <?php 
                                $current_head = $current_org_item['head'] ?? '';
                                $has_existing_option = false;
                                foreach ($unit_heads as $head): 
                                    if ($head['full_name'] === $current_head) {
                                        $has_existing_option = true;
                                    }
                                ?>
                                    <option value="<?php echo htmlspecialchars($head['full_name']); ?>"
                                        <?php echo $current_head === $head['full_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($head['full_name']); ?>
                                        <?php 
                                        if ($head['role_id'] == 3) echo '(Division Head)';
                                        elseif ($head['role_id'] == 4) echo '(Department Head)';
                                        elseif ($head['role_id'] == 5) echo '(Unit Head)';
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="department_id">Department *</label>
                        <select id="department_id" name="department_id" required>
                            <option value="">Select Department</option>
                            <?php foreach ($all_departments as $department): ?>
                                <option value="<?php echo $department['department_id']; ?>"
                                    <?php echo $current_org_item['department_id'] == $department['department_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($department['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="unit_status">Status *</label>
                        <select id="unit_status" name="unit_status" required onchange="showDecommissionWarning(this, 'unit')">
                            <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                        </select>
                    </div>

                    <div id="decommissionWarning" style="display: none; background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin-top: 10px; color: #856404;">
                        <strong>Warning:</strong> Decommissioning this organization will also decommission all child organizations and their contact numbers.
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" name="update_unit" class="btn-save">Update Unit</button>
                        <a href="?section=units&cancel=1" class="btn-cancel">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        
        <?php if ($edit_org_mode == 'office' && isset($current_org_item)): ?>
            <div class="org-edit-form">
                <h3>Edit Office: <?php echo htmlspecialchars($current_org_item['office_name']); ?></h3>
                <form method="POST">
                    <input type="hidden" name="office_id" value="<?php echo $current_org_item['office_id']; ?>">
                    
                    <div class="form-group">
                        <label for="office_name">Office Name *</label>
                        <input type="text" id="office_name" name="office_name" 
                               value="<?php echo htmlspecialchars($current_org_item['office_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="office_head">Office Head *</label>
                        <div class="dropdown-with-input">
                            <select id="office_head" name="office_head" required onchange="handleHeadSelection(this, 'office_custom_input')">
                                <option value="">Select Office Head</option>
                                <?php 
                                $current_head = $current_org_item['head'] ?? '';
                                $has_existing_option = false;
                                foreach ($office_heads as $head): 
                                    if ($head['full_name'] === $current_head) {
                                        $has_existing_option = true;
                                    }
                                ?>
                                    <option value="<?php echo htmlspecialchars($head['full_name']); ?>"
                                        <?php echo $current_head === $head['full_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($head['full_name']); ?>
                                        <?php 
                                        if ($head['role_id'] == 3) echo '(Division Head)';
                                        elseif ($head['role_id'] == 4) echo '(Department Head)';
                                        elseif ($head['role_id'] == 5) echo '(Unit Head)';
                                        elseif ($head['role_id'] == 6) echo '(Office Head)';
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="unit_id">Unit *</label>
                        <select id="unit_id" name="unit_id" required>
                            <option value="">Select Unit</option>
                            <?php foreach ($all_units as $unit): ?>
                                <option value="<?php echo $unit['unit_id']; ?>"
                                    <?php echo $current_org_item['unit_id'] == $unit['unit_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($unit['unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="office_status">Status *</label>
                        <select id="office_status" name="office_status" required onchange="showDecommissionWarning(this, 'office')">
                            <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                        </select>
                    </div>

                    <div id="decommissionWarning" style="display: none; background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin-top: 10px; color: #856404;">
                        <strong>Warning:</strong> Decommissioning this organization will also decommission all child organizations and their contact numbers.
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" name="update_office" class="btn-save">Update Office</button>
                        <a href="?section=offices&cancel=1" class="btn-cancel">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Contact Numbers Table - UPDATED FOR EMAIL -->
    <div class="edit-section <?php echo $active_section == 'contacts' ? 'active' : ''; ?>" id="contacts-section">
        <div class="section-container">
            <h3>Contact Numbers</h3>
            <div class="search-filter">
                <input type="text" id="searchContacts" placeholder="Search by phone, email, description, head, or status..." onkeyup="filterTable('contactsTable', 'searchContacts')">
            </div>
            
            <?php if (count($numbers) > 0): ?>
                <table class="contacts-table" id="contactsTable">
                    <thead>
                        <tr>
                            <th>Phone Number(s)</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Email Address</th>
                            <th>Head</th>
                            <th>Organization</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($numbers as $number): ?>
                            <tr>
                                <td>
                                    <?php 
                                    $phoneDisplay = htmlspecialchars($number['numbers']);
                                    echo $phoneDisplay === 'N/A' ? '<span style="color: #999; font-style: italic;">N/A</span>' : $phoneDisplay;
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($number['description']); ?></td>
                                <td>
                                    <?php 
                                    $statusClass = 'status-inactive';
                                    $statusText = 'Unknown';
                                    if (isset($number['status'])) {
                                        if ($number['status'] === 'active') {
                                            $statusClass = 'status-active';
                                            $statusText = 'Active';
                                        } elseif ($number['status'] === 'decommissioned') {
                                            $statusClass = 'status-decommissioned';
                                            $statusText = 'Decommissioned';
                                        }
                                    }
                                    ?>  
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                                <td class="email-cell">
                                    <?php 
                                    $email = $number['email'] ?? '';
                                    if (!empty($email)) {
                                        echo '<a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a>';
                                    } else {
                                        echo '<span style="color: #999; font-style: italic;">No email</span>';
                                    }
                                    ?>
                                </td>
                                <td style="font-weight: 600; color: #2d3748;">
                                    <?php echo htmlspecialchars($number['head']); ?>
                                </td>
                                <td class="head-org-column">
                                    <div class="head-org-container">
                                            <?php 
                                            // Determine organization type
                                            $org_type = 'Unknown';
                                            $org_name = '';
                                            
                                            if (!empty($number['office_id']) && !empty($number['office_name'])) {
                                                $org_type = 'Office';
                                                $org_name = $number['office_name'];
                                            } elseif (!empty($number['unit_id']) && !empty($number['unit_name'])) {
                                                $org_type = 'Unit';
                                                $org_name = $number['unit_name'];
                                            } elseif (!empty($number['department_id']) && !empty($number['department_name'])) {
                                                $org_type = 'Department';
                                                $org_name = $number['department_name'];
                                            } elseif (!empty($number['division_id']) && !empty($number['division_name'])) {
                                                $org_type = 'Division';
                                                $org_name = $number['division_name'];
                                            }
                                            
                                            if ($org_type !== 'Unknown' && !empty($org_name)): ?>
                                                <!-- Direct Organization -->
                                                <div class="org-info">
                                                    <div class="info-label"><?php echo $org_type; ?></div>
                                                    <div class="org-content">
                                                        <span class="org-type-badge"><?php echo $org_type; ?></span>
                                                        <span class="org-name"><?php echo htmlspecialchars($org_name); ?></span>
                                                    </div>
                                                </div>
                                                
                                                <!-- Show parent hierarchy -->
                                                <?php if ($org_type === 'Office' && !empty($number['unit_name'])): ?>
                                                    <div style="font-size: 11px; color: #718096; margin-left: 16px;">
                                                        ← <?php echo htmlspecialchars($number['unit_name']); ?> (Unit)
                                                        <?php if (!empty($number['department_name'])): ?>
                                                            <br>← <?php echo htmlspecialchars($number['department_name']); ?> (Department)
                                                        <?php endif; ?>
                                                        <?php if (!empty($number['division_name'])): ?>
                                                            <br>← <?php echo htmlspecialchars($number['division_name']); ?> (Division)
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif ($org_type === 'Unit' && !empty($number['department_name'])): ?>
                                                    <div style="font-size: 11px; color: #718096; margin-left: 16px;">
                                                        ← <?php echo htmlspecialchars($number['department_name']); ?> (Department)
                                                        <?php if (!empty($number['division_name'])): ?>
                                                            <br>← <?php echo htmlspecialchars($number['division_name']); ?> (Division)
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif ($org_type === 'Department' && !empty($number['division_name'])): ?>
                                                    <div style="font-size: 11px; color: #718096; margin-left: 16px;">
                                                        ← <?php echo htmlspecialchars($number['division_name']); ?> (Division)
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <div class="org-not-assigned">Not assigned to any organization</div>
                                            <?php endif; ?>
                                        </div>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?section=contacts&edit=<?php echo $number['number_id']; ?>" class="edit-btn">Edit</a>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="delete_id" value="<?php echo $number['number_id']; ?>">
                                            <button type="submit" class="delete-btn" 
                                                    onclick="return confirm('Delete this contact?')">
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-items">
                    No contact numbers found. 
                </div>
            <?php endif; ?>
        </div>
    </div>
        
        <!-- Divisions Section -->
        <div class="edit-section <?php echo $active_section == 'divisions' ? 'active' : ''; ?>" id="divisions-section">
            <div class="section-container">
                <h3>Divisions</h3>
                <div class="search-filter">
                    <input type="text" id="searchDivisions" placeholder="Search by division name..." onkeyup="filterTable('divisionsTable', 'searchDivisions')">
                </div>
                
                <?php if (count($divisions_list) > 0): ?>
                    <table class="org-table" id="divisionsTable">
                        <thead>
                            <tr>
                                <th>Division Name</th>
                                <th>Division Head</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($divisions_list as $division): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($division['division_name']); ?></td>
                                    <td><?php echo isset($division['head']) ? htmlspecialchars($division['head']) : 'Not assigned'; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $division['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                            <?php echo ucfirst($division['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="org-action-buttons">
                                            <a href="?section=divisions&edit_division=<?php echo $division['division_id']; ?>" class="edit-btn">Edit</a>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="delete_division" value="<?php echo $division['division_id']; ?>">
                                                <button type="submit" class="delete-btn" 
                                                        onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($division['division_name']); ?>? This will also delete all associated contact numbers AND all DEPARTMENTS, UNITS, AND OFFICES.')">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-items">
                        No divisions found. <a href="createpage.php">Create your first division</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Departments Section -->
        <div class="edit-section <?php echo $active_section == 'departments' ? 'active' : ''; ?>" id="departments-section">
            <div class="section-container">
                <h3>Departments</h3>
                <div class="search-filter">
                    <input type="text" id="searchDepartments" placeholder="Search by department name..." onkeyup="filterTable('departmentsTable', 'searchDepartments')">
                </div>
                
                <?php if (count($departments_list) > 0): ?>
                    <table class="org-table" id="departmentsTable">
                        <thead>
                            <tr>
                                <th>Department Name</th>
                                <th>Department Head</th>
                                <th>Division</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($departments_list as $department): ?>
                                <tr data-department-id="<?php echo $department['department_id']; ?>" data-division-name="<?php echo htmlspecialchars($department['division_name']); ?>">
                                    <td><?php echo htmlspecialchars($department['department_name']); ?></td>
                                    <td><?php echo isset($department['head']) ? htmlspecialchars($department['head']) : 'Not assigned'; ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($department['division_name']); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $department['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                            <?php echo ucfirst($department['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="org-action-buttons">
                                            <a href="?section=departments&edit_department=<?php echo $department['department_id']; ?>" class="edit-btn">Edit</a>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="delete_department" value="<?php echo $department['department_id']; ?>">
                                                <button type="submit" class="delete-btn" 
                                                        onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($department['department_name']); ?>? This will also delete all associated contact numbers.')">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-items">
                        No departments found. <a href="create_department.php">Create your first department</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Units Section -->
        <div class="edit-section <?php echo $active_section == 'units' ? 'active' : ''; ?>" id="units-section">
            <div class="section-container">
                <h3>Units</h3>
                <div class="search-filter">
                    <input type="text" id="searchUnits" placeholder="Search by unit name..." onkeyup="filterTable('unitsTable', 'searchUnits')">
                </div>
                
                <?php if (count($units_list) > 0): ?>
                    <table class="org-table" id="unitsTable">
                        <thead>
                            <tr>
                                <th>Unit Name</th>
                                <th>Unit Head</th>
                                <th>Department</th>
                                <th>Division</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($units_list as $unit): ?>
                                <tr data-unit-id="<?php echo $unit['unit_id']; ?>" data-department-name="<?php echo htmlspecialchars($unit['department_name']); ?>">
                                    <td><?php echo htmlspecialchars($unit['unit_name']); ?></td>
                                    <td><?php echo isset($unit['head']) ? htmlspecialchars($unit['head']) : 'Not assigned'; ?></td>
                                    <td><?php echo htmlspecialchars($unit['department_name']); ?></td>
                                    <td>
                                        <div class="parent-info"><?php echo htmlspecialchars($unit['division_name']); ?></div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $unit['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                            <?php echo ucfirst($unit['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="org-action-buttons">
                                            <a href="?section=units&edit_unit=<?php echo $unit['unit_id']; ?>" class="edit-btn">Edit</a>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="delete_unit" value="<?php echo $unit['unit_id']; ?>">
                                                <button type="submit" class="delete-btn" 
                                                        onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($unit['unit_name']); ?>? This will also delete all associated contact numbers.')">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-items">
                        No units found. <a href="create_unit.php">Create your first unit</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Offices Section -->
        <div class="edit-section <?php echo $active_section == 'offices' ? 'active' : ''; ?>" id="offices-section">
            <div class="section-container">
                <h3>Offices</h3>
                <div class="search-filter">
                    <input type="text" id="searchOffices" placeholder="Search by office name..." onkeyup="filterTable('officesTable', 'searchOffices')">
                </div>
                
                <?php if (count($offices_list) > 0): ?>
                    <table class="org-table" id="officesTable">
                        <thead>
                            <tr>
                                <th>Office Name</th>
                                <th>Office Head</th>
                                <th>Unit</th>
                                <th>Department</th>
                                <th>Division</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($offices_list as $office): ?>
                                <tr data-office-id="<?php echo $office['office_id']; ?>" data-unit-name="<?php echo htmlspecialchars($office['unit_name']); ?>">
                                    <td><?php echo htmlspecialchars($office['office_name']); ?></td>
                                    <td><?php echo isset($office['head']) ? htmlspecialchars($office['head']) : 'Not assigned'; ?></td>
                                    <td><?php echo htmlspecialchars($office['unit_name']); ?></td>
                                    <td><?php echo htmlspecialchars($office['department_name']); ?></td>
                                    <td>
                                        <div class="parent-info"><?php echo htmlspecialchars($office['division_name']); ?></div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $office['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                            <?php echo ucfirst($office['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="org-action-buttons">
                                            <a href="?section=offices&edit_office=<?php echo $office['office_id']; ?>" class="edit-btn">Edit</a>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="delete_office" value="<?php echo $office['office_id']; ?>">
                                                <button type="submit" class="delete-btn" 
                                                        onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($office['office_name']); ?>? This will also delete all associated contact numbers.')">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-items">
                        No offices found. <a href="create_office.php">Create your first office</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="footer">
    © 2026 Intercom Directory. All rights reserved.<br>
    Developed by TNTS Programming Students JT.DP.RR
</div>

<script>
    function showSection(section) {
        window.location.href = `editpage.php?section=${section}`;
    }
    
    function filterTable(tableId, inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        
        const filter = input.value.toLowerCase();
        const table = document.getElementById(tableId);
        
        if (!table) return;
        
        const rows = table.getElementsByTagName('tr');
        
        for (let i = 1; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            let found = false;
            
            for (let j = 0; j < cells.length - 1; j++) {
                if (cells[j]) {
                    const text = cells[j].textContent || cells[j].innerText;
                    if (text.toLowerCase().indexOf(filter) > -1) {
                        found = true;
                        break;
                    }
                }
            }
            
            rows[i].style.display = found ? '' : 'none';
        }
    }
    
    function handleHeadSelection(select, customInputId) {
        const customInputDiv = document.getElementById(customInputId);
        if (select.value === '__custom__') {
            customInputDiv.style.display = 'block';
            const customInput = customInputDiv.querySelector('input[type="text"]');
            if (customInput) {
                customInput.focus();
            }
        } else {
            customInputDiv.style.display = 'none';
        }
    }
    
    function toggleCustomInput(selectId, customInputId) {
        const select = document.getElementById(selectId);
        const customInputDiv = document.getElementById(customInputId);
        const customInput = customInputDiv.querySelector('input[type="text"]');
        
        if (customInputDiv.style.display === 'none') {
            // Show custom input and select the custom option
            select.value = '__custom__';
            customInputDiv.style.display = 'block';
            if (customInput) {
                customInput.focus();
            }
        } else {
            // Hide custom input and reset to empty
            customInputDiv.style.display = 'none';
            select.value = '';
        }
    }
    
    function updateHeadSelect(input, selectId) {
        const select = document.getElementById(selectId);
        // Update the custom option text to show the current input
        const customOption = select.querySelector('option[value="__custom__"]');
        if (customOption) {
            customOption.textContent = input.value ? `Custom: ${input.value}` : 'Enter custom name...';
        }
        // Ensure the custom option is selected
        if (select.value !== '__custom__') {
            select.value = '__custom__';
        }
    }
    
    function showDecommissionWarning(select, orgType) {
        const warningDiv = document.getElementById('decommissionWarning');
        if (select.value === 'decommissioned') {
            warningDiv.style.display = 'block';
            // Customize message based on org type
            if (orgType === 'division') {
                warningDiv.innerHTML = '<strong>Warning:</strong> Decommissioning this division will also decommission all departments, units, offices, and their contact numbers under it.';
            } else if (orgType === 'department') {
                warningDiv.innerHTML = '<strong>Warning:</strong> Decommissioning this department will also decommission all units, offices, and their contact numbers under it.';
            } else if (orgType === 'unit') {
                warningDiv.innerHTML = '<strong>Warning:</strong> Decommissioning this unit will also decommission all offices and their contact numbers under it.';
            } else if (orgType === 'office') {
                warningDiv.innerHTML = '<strong>Warning:</strong> Decommissioning this office will also decommission all contact numbers under it.';
            }
        } else {
            warningDiv.style.display = 'none';
        }
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded');
        
        // Validate email in other forms
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                // For edit and add forms
                if (form.querySelector('input[name="new_email"]') || form.querySelector('input[name="new_number"]')) {
                    const emailInput = form.querySelector('input[name="new_email"]');
                    const phoneInput = form.querySelector('input[name="new_number"]');
                    
                    const emailValue = emailInput ? emailInput.value.trim() : '';
                    const phoneValue = phoneInput ? phoneInput.value.trim() : '';
                    
                    // Check if at least one contact method is provided
                    if (emailValue === '' && (phoneValue === '' || phoneValue === 'N/A')) {
                        e.preventDefault();
                        alert('Please provide at least one contact method (phone number or email address).');
                        return false;
                    }
                    
                    // Validate email format if provided
                    if (emailValue !== '' && !isValidEmail(emailValue)) {
                        e.preventDefault();
                        alert('Please enter a valid email address.');
                        emailInput.focus();
                        return false;
                    }
                }
            });
        });
        
        // Handle form submission for custom head names
        const orgForms = document.querySelectorAll('.org-edit-form form');
        orgForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                // Check if there are custom head inputs
                const customInputs = form.querySelectorAll('input[name$="_custom"]');
                customInputs.forEach(input => {
                    const selectName = input.name.replace('_custom', '');
                    const select = form.querySelector(`select[name="${selectName}"]`);
                    if (select && select.value === '__custom__' && input.value.trim()) {
                        // Update the select's value to the custom input
                        select.value = input.value.trim();
                    }
                });
            });
        });
        
        // Notification button functionality
        const notificationBtn = document.getElementById('adminNotificationBtn');
        const notificationDropdown = document.getElementById('notificationDropdown');
        
        if(notificationBtn && notificationDropdown) {
            notificationBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if(notificationDropdown.style.opacity === '1') {
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
                if(notificationBtn && notificationDropdown) {
                    if(!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                        notificationDropdown.style.opacity = '0';
                        notificationDropdown.style.visibility = 'hidden';
                        notificationDropdown.style.transform = 'translateY(-10px)';
                    }
                }
            });
        }
        
        // Clear other organization selections when one is selected
        const divisionSelect = document.getElementById('new_division_id');
        const departmentSelect = document.getElementById('new_department_id');
        const unitSelect = document.getElementById('new_unit_id');
        const officeSelect = document.getElementById('new_office_id');
        
        if (divisionSelect) {
            function clearOtherSelections(selected) {
                const selects = [divisionSelect, departmentSelect, unitSelect, officeSelect];
                selects.forEach(select => {
                    if (select && select !== selected && select.value) {
                        select.value = '';
                    }
                });
            }
            
            if (divisionSelect) divisionSelect.addEventListener('change', function() { if (this.value) clearOtherSelections(this); });
            if (departmentSelect) departmentSelect.addEventListener('change', function() { if (this.value) clearOtherSelections(this); });
            if (unitSelect) unitSelect.addEventListener('change', function() { if (this.value) clearOtherSelections(this); });
            if (officeSelect) officeSelect.addEventListener('change', function() { if (this.value) clearOtherSelections(this); });
        }
        
        // Auto-refresh page every 60 seconds for notifications
        setTimeout(() => {
            console.log('Auto-refreshing page...');
            location.reload();
        }, 60000);
    });
    
    function isValidEmail(email) {
        const re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
        return re.test(String(email).toLowerCase());
    }
</script>
</body>
</html>