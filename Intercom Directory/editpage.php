<?php
require_once 'conn.php';
updateAllUsersActivity($conn);

// After setting $_SESSION['edit_success'] or $_SESSION['edit_error'], add:
error_log("Session message set: " . ($_SESSION['edit_success'] ?? $_SESSION['edit_error'] ?? 'none'));
// Clear session messages on fresh page load


$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['section'])) {
    unset($_SESSION['edit_error']);
    unset($_SESSION['edit_success']);
}

// Check for session messages
if (isset($_SESSION['edit_error'])) {
    $error = $_SESSION['edit_error'];
    unset($_SESSION['edit_error']);
}
if (isset($_SESSION['edit_success'])) {
    $success = $_SESSION['edit_success'];
    unset($_SESSION['edit_success']);
}

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


$edit_mode = false;
$edit_org_mode = '';
$current_item = null;
$current_org_item = null;

$active_section = isset($_GET['section']) ? $_GET['section'] : 'contacts';

// Handle delete operations
if (isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];
    
    $delete_sql = "DELETE FROM numbers WHERE number_id = ?";
    $params = array($delete_id);
    $delete_stmt = sqlsrv_query($conn, $delete_sql, $params);
    
    if ($delete_stmt) {
        $_SESSION['edit_success'] = "Contact number deleted successfully!";
    } else {
        $_SESSION['edit_error'] = "Failed to delete contact number: " . print_r(sqlsrv_errors(), true);
    }
    sqlsrv_free_stmt($delete_stmt);
    header("Location: editpage.php?section=contacts");
    exit;
}

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
            $_SESSION['edit_success'] = "Division deleted successfully!";
        } else {
            throw new Exception("Failed to delete division: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_stmt);
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=divisions");
    exit;
}

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
            $_SESSION['edit_success'] = "Department deleted successfully!";
        } else {
            throw new Exception("Failed to delete department: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_stmt);
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=departments");
    exit;
}

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
            $_SESSION['edit_success'] = "Unit deleted successfully!";
        } else {
            throw new Exception("Failed to delete unit: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_stmt);
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=units");
    exit;
}

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
            $_SESSION['edit_success'] = "Office deleted successfully!";
        } else {
            throw new Exception("Failed to delete office: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_stmt);
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=offices");
    exit;
}

// Quick status change for contact numbers
if (isset($_POST['quick_status_change'])) {
    $number_id = (int)$_POST['number_id'];
    $new_status = $_POST['new_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Get current number info
        $current_sql = "SELECT * FROM numbers WHERE number_id = ?";
        $current_params = array($number_id);
        $current_stmt = sqlsrv_query($conn, $current_sql, $current_params);
        $current_number = sqlsrv_fetch_array($current_stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($current_stmt);
        
        // If trying to reactivate, check parent organization
        if ($new_status === 'active') {
            // Check division
            if (!empty($current_number['division_id'])) {
                $check_sql = "SELECT status FROM divisions WHERE division_id = ?";
                $check_params = array($current_number['division_id']);
                $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
                if ($check_stmt && sqlsrv_has_rows($check_stmt)) {
                    $org_row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
                    if ($org_row['status'] !== 'active') {
                        throw new Exception("Cannot reactivate - parent division is decommissioned");
                    }
                }
                sqlsrv_free_stmt($check_stmt);
            }
            
            // Check department
            if (!empty($current_number['department_id'])) {
                $check_sql = "SELECT status FROM departments WHERE department_id = ?";
                $check_params = array($current_number['department_id']);
                $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
                if ($check_stmt && sqlsrv_has_rows($check_stmt)) {
                    $org_row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
                    if ($org_row['status'] !== 'active') {
                        throw new Exception("Cannot reactivate - parent department is decommissioned");
                    }
                }
                sqlsrv_free_stmt($check_stmt);
            }
            
            // Check unit
            if (!empty($current_number['unit_id'])) {
                $check_sql = "SELECT status FROM units WHERE unit_id = ?";
                $check_params = array($current_number['unit_id']);
                $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
                if ($check_stmt && sqlsrv_has_rows($check_stmt)) {
                    $org_row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
                    if ($org_row['status'] !== 'active') {
                        throw new Exception("Cannot reactivate - parent unit is decommissioned");
                    }
                }
                sqlsrv_free_stmt($check_stmt);
            }
            
            // Check office
            if (!empty($current_number['office_id'])) {
                $check_sql = "SELECT status FROM offices WHERE office_id = ?";
                $check_params = array($current_number['office_id']);
                $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
                if ($check_stmt && sqlsrv_has_rows($check_stmt)) {
                    $org_row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
                    if ($org_row['status'] !== 'active') {
                        throw new Exception("Cannot reactivate - parent office is decommissioned");
                    }
                }
                sqlsrv_free_stmt($check_stmt);
            }
        }
        
        // Update the number status
        $update_sql = "UPDATE numbers SET status = ? WHERE number_id = ?";
        $update_params = array($new_status, $number_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update status");
        }
        sqlsrv_free_stmt($update_stmt);
        
        // If decommissioning, also decommission parent organization
        if ($new_status === 'decommissioned') {
            if (!empty($current_number['division_id'])) {
                $org_sql = "UPDATE divisions SET status = 'decommissioned' WHERE division_id = ?";
                $org_params = array($current_number['division_id']);
                sqlsrv_query($conn, $org_sql, $org_params);
            }
            if (!empty($current_number['department_id'])) {
                $org_sql = "UPDATE departments SET status = 'decommissioned' WHERE department_id = ?";
                $org_params = array($current_number['department_id']);
                sqlsrv_query($conn, $org_sql, $org_params);
            }
            if (!empty($current_number['unit_id'])) {
                $org_sql = "UPDATE units SET status = 'decommissioned' WHERE unit_id = ?";
                $org_params = array($current_number['unit_id']);
                sqlsrv_query($conn, $org_sql, $org_params);
            }
            if (!empty($current_number['office_id'])) {
                $org_sql = "UPDATE offices SET status = 'decommissioned' WHERE office_id = ?";
                $org_params = array($current_number['office_id']);
                sqlsrv_query($conn, $org_sql, $org_params);
            }
        }
        
        sqlsrv_commit($conn);
        $_SESSION['edit_success'] = "Contact number " . ($new_status === 'active' ? "reactivated" : "decommissioned") . " successfully!";
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=contacts");
    exit;
}

// Quick status change for divisions
if (isset($_POST['quick_status_change_division'])) {
    $division_id = (int)$_POST['division_id'];
    $new_status = $_POST['new_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Update division status
        $update_sql = "UPDATE divisions SET status = ? WHERE division_id = ?";
        $update_params = array($new_status, $division_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update division status");
        }
        sqlsrv_free_stmt($update_stmt);
        
        // If decommissioning, cascade to all children
        if ($new_status === 'decommissioned') {
            // 1. Update numbers directly under this division
            $update_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE division_id = ?";
            $update_numbers_params = array($division_id);
            sqlsrv_query($conn, $update_numbers_sql, $update_numbers_params);
            
            // 2. Update departments under this division
            $update_depts_sql = "UPDATE departments SET status = 'decommissioned' WHERE division_id = ?";
            $update_depts_params = array($division_id);
            sqlsrv_query($conn, $update_depts_sql, $update_depts_params);
            
            // 3. Get all departments under this division
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
                // 4. Update numbers under these departments
                $placeholders = implode(',', array_fill(0, count($dept_ids), '?'));
                $update_dept_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE department_id IN ($placeholders)";
                $update_dept_numbers_stmt = sqlsrv_prepare($conn, $update_dept_numbers_sql, $dept_ids);
                sqlsrv_execute($update_dept_numbers_stmt);
                sqlsrv_free_stmt($update_dept_numbers_stmt);
                
                // 5. Update units under these departments
                $update_units_sql = "UPDATE units SET status = 'decommissioned' WHERE department_id IN ($placeholders)";
                $update_units_stmt = sqlsrv_prepare($conn, $update_units_sql, $dept_ids);
                sqlsrv_execute($update_units_stmt);
                sqlsrv_free_stmt($update_units_stmt);
                
                // 6. Get all units under these departments
                $unit_ids_sql = "SELECT unit_id FROM units WHERE department_id IN ($placeholders)";
                $unit_ids_stmt = sqlsrv_prepare($conn, $unit_ids_sql, $dept_ids);
                sqlsrv_execute($unit_ids_stmt);
                
                $unit_ids = [];
                while ($unit = sqlsrv_fetch_array($unit_ids_stmt, SQLSRV_FETCH_ASSOC)) {
                    $unit_ids[] = $unit['unit_id'];
                }
                sqlsrv_free_stmt($unit_ids_stmt);
                
                if (!empty($unit_ids)) {
                    // 7. Update numbers under these units
                    $unit_placeholders = implode(',', array_fill(0, count($unit_ids), '?'));
                    $update_unit_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE unit_id IN ($unit_placeholders)";
                    $update_unit_numbers_stmt = sqlsrv_prepare($conn, $update_unit_numbers_sql, $unit_ids);
                    sqlsrv_execute($update_unit_numbers_stmt);
                    sqlsrv_free_stmt($update_unit_numbers_stmt);
                    
                    // 8. Update offices under these units
                    $update_offices_sql = "UPDATE offices SET status = 'decommissioned' WHERE unit_id IN ($unit_placeholders)";
                    $update_offices_stmt = sqlsrv_prepare($conn, $update_offices_sql, $unit_ids);
                    sqlsrv_execute($update_offices_stmt);
                    sqlsrv_free_stmt($update_offices_stmt);
                    
                    // 9. Get all offices under these units
                    $office_ids_sql = "SELECT office_id FROM offices WHERE unit_id IN ($unit_placeholders)";
                    $office_ids_stmt = sqlsrv_prepare($conn, $office_ids_sql, $unit_ids);
                    sqlsrv_execute($office_ids_stmt);
                    
                    $office_ids = [];
                    while ($office = sqlsrv_fetch_array($office_ids_stmt, SQLSRV_FETCH_ASSOC)) {
                        $office_ids[] = $office['office_id'];
                    }
                    sqlsrv_free_stmt($office_ids_stmt);
                    
                    if (!empty($office_ids)) {
                        // 10. Update numbers under these offices
                        $office_placeholders = implode(',', array_fill(0, count($office_ids), '?'));
                        $update_office_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE office_id IN ($office_placeholders)";
                        $update_office_numbers_stmt = sqlsrv_prepare($conn, $update_office_numbers_sql, $office_ids);
                        sqlsrv_execute($update_office_numbers_stmt);
                        sqlsrv_free_stmt($update_office_numbers_stmt);
                    }
                }
            }
        }
        
        sqlsrv_commit($conn);
        $_SESSION['edit_success'] = "Division " . ($new_status === 'active' ? "reactivated" : "decommissioned") . " successfully! " . 
                                    ($new_status === 'decommissioned' ? "All child organizations and numbers have been decommissioned." : "");
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=divisions");
    exit;
}

// Quick status change for departments
if (isset($_POST['quick_status_change_department'])) {
    $department_id = (int)$_POST['department_id'];
    $new_status = $_POST['new_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Get the department's division_id first
        $dept_sql = "SELECT division_id FROM departments WHERE department_id = ?";
        $dept_params = array($department_id);
        $dept_stmt = sqlsrv_query($conn, $dept_sql, $dept_params);
        $division_id = null;
        if ($dept_stmt && $row = sqlsrv_fetch_array($dept_stmt, SQLSRV_FETCH_ASSOC)) {
            $division_id = $row['division_id'];
        }
        sqlsrv_free_stmt($dept_stmt);
        
        // If trying to reactivate, check if parent division is active
        if ($new_status === 'active' && $division_id) {
            $check_sql = "SELECT status FROM divisions WHERE division_id = ?";
            $check_params = array($division_id);
            $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
            if ($check_stmt && $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC)) {
                if ($row['status'] !== 'active') {
                    throw new Exception("Cannot reactivate department - parent division is decommissioned");
                }
            }
            sqlsrv_free_stmt($check_stmt);
        }
        
        // Update department status
        $update_sql = "UPDATE departments SET status = ? WHERE department_id = ?";
        $update_params = array($new_status, $department_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update department status");
        }
        sqlsrv_free_stmt($update_stmt);
        
        // If decommissioning, cascade to all children
        if ($new_status === 'decommissioned') {
            // Update numbers directly under this department
            $update_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE department_id = ?";
            $update_numbers_params = array($department_id);
            $update_numbers_stmt = sqlsrv_query($conn, $update_numbers_sql, $update_numbers_params);
            sqlsrv_free_stmt($update_numbers_stmt);
            
            // Update units under this department
            $update_units_sql = "UPDATE units SET status = 'decommissioned' WHERE department_id = ?";
            $update_units_params = array($department_id);
            $update_units_stmt = sqlsrv_query($conn, $update_units_sql, $update_units_params);
            sqlsrv_free_stmt($update_units_stmt);
            
            // Get all units under this department
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
                // Update offices under these units
                $placeholders = implode(',', array_fill(0, count($unit_ids), '?'));
                $update_offices_sql = "UPDATE offices SET status = 'decommissioned' WHERE unit_id IN ($placeholders)";
                $update_offices_stmt = sqlsrv_prepare($conn, $update_offices_sql, $unit_ids);
                sqlsrv_execute($update_offices_stmt);
                sqlsrv_free_stmt($update_offices_stmt);
                
                // Get all offices under these units
                $office_ids_sql = "SELECT office_id FROM offices WHERE unit_id IN ($placeholders)";
                $office_ids_stmt = sqlsrv_prepare($conn, $office_ids_sql, $unit_ids);
                sqlsrv_execute($office_ids_stmt);
                
                $office_ids = [];
                while ($office = sqlsrv_fetch_array($office_ids_stmt, SQLSRV_FETCH_ASSOC)) {
                    $office_ids[] = $office['office_id'];
                }
                sqlsrv_free_stmt($office_ids_stmt);
                
                if (!empty($office_ids)) {
                    // Update numbers under these offices
                    $office_placeholders = implode(',', array_fill(0, count($office_ids), '?'));
                    $update_office_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE office_id IN ($office_placeholders)";
                    $update_office_numbers_stmt = sqlsrv_prepare($conn, $update_office_numbers_sql, $office_ids);
                    sqlsrv_execute($update_office_numbers_stmt);
                    sqlsrv_free_stmt($update_office_numbers_stmt);
                }
            }
        }
        
        sqlsrv_commit($conn);
        $_SESSION['edit_success'] = "Department " . ($new_status === 'active' ? "reactivated" : "decommissioned") . " successfully! " .
                                    ($new_status === 'decommissioned' ? "All child organizations and numbers have been decommissioned." : "");
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=departments");
    exit;
}

// Quick status change for units
if (isset($_POST['quick_status_change_unit'])) {
    $unit_id = (int)$_POST['unit_id'];
    $new_status = $_POST['new_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Get the unit's department_id first
        $unit_sql = "SELECT department_id FROM units WHERE unit_id = ?";
        $unit_params = array($unit_id);
        $unit_stmt = sqlsrv_query($conn, $unit_sql, $unit_params);
        $department_id = null;
        if ($unit_stmt && $row = sqlsrv_fetch_array($unit_stmt, SQLSRV_FETCH_ASSOC)) {
            $department_id = $row['department_id'];
        }
        sqlsrv_free_stmt($unit_stmt);
        
        // If trying to reactivate, check if parent department is active
        if ($new_status === 'active' && $department_id) {
            $check_sql = "SELECT status FROM departments WHERE department_id = ?";
            $check_params = array($department_id);
            $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
            if ($check_stmt && $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC)) {
                if ($row['status'] !== 'active') {
                    throw new Exception("Cannot reactivate unit - parent department is decommissioned");
                }
            }
            sqlsrv_free_stmt($check_stmt);
        }
        
        // Update unit status
        $update_sql = "UPDATE units SET status = ? WHERE unit_id = ?";
        $update_params = array($new_status, $unit_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update unit status");
        }
        sqlsrv_free_stmt($update_stmt);
        
        // If decommissioning, cascade to all children
        if ($new_status === 'decommissioned') {
            // Update numbers directly under this unit
            $update_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE unit_id = ?";
            $update_numbers_params = array($unit_id);
            $update_numbers_stmt = sqlsrv_query($conn, $update_numbers_sql, $update_numbers_params);
            sqlsrv_free_stmt($update_numbers_stmt);
            
            // Update offices under this unit
            $update_offices_sql = "UPDATE offices SET status = 'decommissioned' WHERE unit_id = ?";
            $update_offices_params = array($unit_id);
            $update_offices_stmt = sqlsrv_query($conn, $update_offices_sql, $update_offices_params);
            sqlsrv_free_stmt($update_offices_stmt);
            
            // Get all offices under this unit
            $office_ids_sql = "SELECT office_id FROM offices WHERE unit_id = ?";
            $office_ids_params = array($unit_id);
            $office_ids_stmt = sqlsrv_query($conn, $office_ids_sql, $office_ids_params);
            
            $office_ids = [];
            if ($office_ids_stmt) {
                while ($office = sqlsrv_fetch_array($office_ids_stmt, SQLSRV_FETCH_ASSOC)) {
                    $office_ids[] = $office['office_id'];
                }
                sqlsrv_free_stmt($office_ids_stmt);
            }
            
            if (!empty($office_ids)) {
                // Update numbers under these offices
                $placeholders = implode(',', array_fill(0, count($office_ids), '?'));
                $update_office_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE office_id IN ($placeholders)";
                $update_office_numbers_stmt = sqlsrv_prepare($conn, $update_office_numbers_sql, $office_ids);
                sqlsrv_execute($update_office_numbers_stmt);
                sqlsrv_free_stmt($update_office_numbers_stmt);
            }
        }
        
        sqlsrv_commit($conn);
        $_SESSION['edit_success'] = "Unit " . ($new_status === 'active' ? "reactivated" : "decommissioned") . " successfully! " .
                                    ($new_status === 'decommissioned' ? "All child organizations and numbers have been decommissioned." : "");
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=units");
    exit;
}

// Quick status change for offices
if (isset($_POST['quick_status_change_office'])) {
    $office_id = (int)$_POST['office_id'];
    $new_status = $_POST['new_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Get the office's unit_id first
        $office_sql = "SELECT unit_id FROM offices WHERE office_id = ?";
        $office_params = array($office_id);
        $office_stmt = sqlsrv_query($conn, $office_sql, $office_params);
        $unit_id = null;
        if ($office_stmt && $row = sqlsrv_fetch_array($office_stmt, SQLSRV_FETCH_ASSOC)) {
            $unit_id = $row['unit_id'];
        }
        sqlsrv_free_stmt($office_stmt);
        
        // If trying to reactivate, check if parent unit is active
        if ($new_status === 'active' && $unit_id) {
            $check_sql = "SELECT status FROM units WHERE unit_id = ?";
            $check_params = array($unit_id);
            $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
            if ($check_stmt && $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC)) {
                if ($row['status'] !== 'active') {
                    throw new Exception("Cannot reactivate office - parent unit is decommissioned");
                }
            }
            sqlsrv_free_stmt($check_stmt);
        }
        
        // Update office status
        $update_sql = "UPDATE offices SET status = ? WHERE office_id = ?";
        $update_params = array($new_status, $office_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update office status");
        }
        sqlsrv_free_stmt($update_stmt);
        
        // If decommissioning, cascade to numbers
        if ($new_status === 'decommissioned') {
            $update_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE office_id = ?";
            $update_numbers_params = array($office_id);
            $update_numbers_stmt = sqlsrv_query($conn, $update_numbers_sql, $update_numbers_params);
            sqlsrv_free_stmt($update_numbers_stmt);
        }
        
        sqlsrv_commit($conn);
        $_SESSION['edit_success'] = "Office " . ($new_status === 'active' ? "reactivated" : "decommissioned") . " successfully! " .
                                    ($new_status === 'decommissioned' ? "All numbers under this office have been decommissioned." : "");
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=offices");
    exit;
}

// Edit contact number - only for editing details, not status
if (isset($_POST['save_edit'])) {
    $edit_id = (int)$_POST['edit_id'];
    $new_number = trim($_POST['new_number']);
    $new_email = trim($_POST['new_email'] ?? '');
    $new_description = trim($_POST['new_description']);
    $new_head = trim($_POST['new_head']);
    $new_division_id = !empty($_POST['new_division_id']) ? (int)$_POST['new_division_id'] : NULL;
    $new_department_id = !empty($_POST['new_department_id']) ? (int)$_POST['new_department_id'] : NULL;
    $new_unit_id = !empty($_POST['new_unit_id']) ? (int)$_POST['new_unit_id'] : NULL;
    $new_office_id = !empty($_POST['new_office_id']) ? (int)$_POST['new_office_id'] : NULL;
    
    if (!empty($new_email) && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['edit_error'] = "Please enter a valid email address.";
        header("Location: editpage.php?section=$active_section&edit=$edit_id");
        exit;
    }
    
    try {
        sqlsrv_begin_transaction($conn);
        
        $update_sql = "UPDATE numbers SET numbers = ?, email = ?, description = ?, head = ?, division_id = ?, department_id = ?, unit_id = ?, office_id = ? WHERE number_id = ?";
        $update_params = array($new_number, $new_email, $new_description, $new_head, $new_division_id, $new_department_id, $new_unit_id, $new_office_id, $edit_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update contact number");
        }
        sqlsrv_free_stmt($update_stmt);
        
        sqlsrv_commit($conn);
        $_SESSION['edit_success'] = "Contact number updated successfully!";
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=$active_section");
    exit;
}

// Handle update division (full edit)
if (isset($_POST['update_division'])) {
    $division_id = (int)$_POST['division_id'];
    $division_name = trim($_POST['division_name']);
    $division_head = trim($_POST['division_head']);
    $division_status = $_POST['division_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Update division
        $update_sql = "UPDATE divisions SET division_name = ?, status = ? WHERE division_id = ?";
        $update_params = array($division_name, $division_status, $division_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update division");
        }
        sqlsrv_free_stmt($update_stmt);
        
        // Update head in numbers
        if (!empty($division_head)) {
            $update_head_sql = "UPDATE numbers SET head = ? WHERE division_id = ?";
            $update_head_params = array($division_head, $division_id);
            sqlsrv_query($conn, $update_head_sql, $update_head_params);
        }
        
        // If decommissioning, cascade to all children
        if ($division_status === 'decommissioned') {
            // Update numbers
            $update_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE division_id = ?";
            $update_numbers_params = array($division_id);
            sqlsrv_query($conn, $update_numbers_sql, $update_numbers_params);
            
            // Update departments
            $update_depts_sql = "UPDATE departments SET status = 'decommissioned' WHERE division_id = ?";
            $update_depts_params = array($division_id);
            sqlsrv_query($conn, $update_depts_sql, $update_depts_params);
            
            // Update units under these departments
            $update_units_sql = "UPDATE u SET u.status = 'decommissioned' FROM units u INNER JOIN departments d ON u.department_id = d.department_id WHERE d.division_id = ?";
            $update_units_params = array($division_id);
            sqlsrv_query($conn, $update_units_sql, $update_units_params);
            
            // Update offices under these units
            $update_offices_sql = "UPDATE o SET o.status = 'decommissioned' FROM offices o INNER JOIN units u ON o.unit_id = u.unit_id INNER JOIN departments d ON u.department_id = d.department_id WHERE d.division_id = ?";
            $update_offices_params = array($division_id);
            sqlsrv_query($conn, $update_offices_sql, $update_offices_params);
        }
        
        sqlsrv_commit($conn);
        $_SESSION['edit_success'] = "Division updated successfully!" . ($division_status === 'decommissioned' ? " All child organizations decommissioned." : "");
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=divisions");
    exit;
}

// Handle update department (full edit)
if (isset($_POST['update_department'])) {
    $department_id = (int)$_POST['department_id'];
    $department_name = trim($_POST['department_name']);
    $department_head = trim($_POST['department_head']);
    $division_id = (int)$_POST['division_id'];
    $department_status = $_POST['department_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Update department
        $update_sql = "UPDATE departments SET department_name = ?, division_id = ?, status = ? WHERE department_id = ?";
        $update_params = array($department_name, $division_id, $department_status, $department_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update department");
        }
        sqlsrv_free_stmt($update_stmt);
        
        // Update head in numbers
        if (!empty($department_head)) {
            $update_head_sql = "UPDATE numbers SET head = ? WHERE department_id = ?";
            $update_head_params = array($department_head, $department_id);
            sqlsrv_query($conn, $update_head_sql, $update_head_params);
        }
        
        // If decommissioning, cascade to all children
        if ($department_status === 'decommissioned') {
            // Update numbers
            $update_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE department_id = ?";
            $update_numbers_params = array($department_id);
            sqlsrv_query($conn, $update_numbers_sql, $update_numbers_params);
            
            // Update units
            $update_units_sql = "UPDATE units SET status = 'decommissioned' WHERE department_id = ?";
            $update_units_params = array($department_id);
            sqlsrv_query($conn, $update_units_sql, $update_units_params);
            
            // Update offices under these units
            $update_offices_sql = "UPDATE o SET o.status = 'decommissioned' FROM offices o INNER JOIN units u ON o.unit_id = u.unit_id WHERE u.department_id = ?";
            $update_offices_params = array($department_id);
            sqlsrv_query($conn, $update_offices_sql, $update_offices_params);
        }
        
        sqlsrv_commit($conn);
        $_SESSION['edit_success'] = "Department updated successfully!" . ($department_status === 'decommissioned' ? " All child organizations decommissioned." : "");
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=departments");
    exit;
}

// Handle update unit (full edit)
if (isset($_POST['update_unit'])) {
    $unit_id = (int)$_POST['unit_id'];
    $unit_name = trim($_POST['unit_name']);
    $unit_head = trim($_POST['unit_head']);
    $department_id = (int)$_POST['department_id'];
    $unit_status = $_POST['unit_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Update unit
        $update_sql = "UPDATE units SET unit_name = ?, department_id = ?, status = ? WHERE unit_id = ?";
        $update_params = array($unit_name, $department_id, $unit_status, $unit_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update unit");
        }
        sqlsrv_free_stmt($update_stmt);
        
        // Update head in numbers
        if (!empty($unit_head)) {
            $update_head_sql = "UPDATE numbers SET head = ? WHERE unit_id = ?";
            $update_head_params = array($unit_head, $unit_id);
            sqlsrv_query($conn, $update_head_sql, $update_head_params);
        }
        
        // If decommissioning, cascade to all children
        if ($unit_status === 'decommissioned') {
            // Update numbers
            $update_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE unit_id = ?";
            $update_numbers_params = array($unit_id);
            sqlsrv_query($conn, $update_numbers_sql, $update_numbers_params);
            
            // Update offices
            $update_offices_sql = "UPDATE offices SET status = 'decommissioned' WHERE unit_id = ?";
            $update_offices_params = array($unit_id);
            sqlsrv_query($conn, $update_offices_sql, $update_offices_params);
        }
        
        sqlsrv_commit($conn);
        $_SESSION['edit_success'] = "Unit updated successfully!" . ($unit_status === 'decommissioned' ? " All child organizations decommissioned." : "");
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=units");
    exit;
}

// Handle update office (full edit)
if (isset($_POST['update_office'])) {
    $office_id = (int)$_POST['office_id'];
    $office_name = trim($_POST['office_name']);
    $office_head = trim($_POST['office_head']);
    $unit_id = (int)$_POST['unit_id'];
    $office_status = $_POST['office_status'];
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Update office
        $update_sql = "UPDATE offices SET office_name = ?, unit_id = ?, status = ? WHERE office_id = ?";
        $update_params = array($office_name, $unit_id, $office_status, $office_id);
        $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
        
        if (!$update_stmt) {
            throw new Exception("Failed to update office");
        }
        sqlsrv_free_stmt($update_stmt);
        
        // Update head in numbers
        if (!empty($office_head)) {
            $update_head_sql = "UPDATE numbers SET head = ? WHERE office_id = ?";
            $update_head_params = array($office_head, $office_id);
            sqlsrv_query($conn, $update_head_sql, $update_head_params);
        }
        
        // If decommissioning, cascade to all numbers
        if ($office_status === 'decommissioned') {
            $update_numbers_sql = "UPDATE numbers SET status = 'decommissioned' WHERE office_id = ?";
            $update_numbers_params = array($office_id);
            sqlsrv_query($conn, $update_numbers_sql, $update_numbers_params);
        }
        
        sqlsrv_commit($conn);
        $_SESSION['edit_success'] = "Office updated successfully!" . ($office_status === 'decommissioned' ? " All numbers under this office decommissioned." : "");
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        $_SESSION['edit_error'] = $e->getMessage();
    }
    header("Location: editpage.php?section=offices");
    exit;
}

// Handle edit mode
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

// Fetch all contact numbers
$query = "SELECT 
    n.numbers, n.email, n.description, n.head, n.number_id, n.status,
    d.division_id, d.division_name,
    dp.department_id, dp.department_name,
    un.unit_id, un.unit_name,
    o.office_id, o.office_name
FROM numbers n
LEFT JOIN divisions d ON n.division_id = d.division_id
LEFT JOIN departments dp ON n.department_id = dp.department_id
LEFT JOIN units un ON n.unit_id = un.unit_id
LEFT JOIN offices o ON n.office_id = o.office_id
ORDER BY 
    CASE WHEN n.status = 'active' THEN 1 ELSE 2 END,
    d.division_name,
    dp.department_name,
    un.unit_name,
    o.office_name;";

$result = sqlsrv_query($conn, $query);
$numbers = [];
if ($result) {
    while ($row = sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC)) {
        $numbers[] = $row;
    }
    sqlsrv_free_stmt($result);
}

// Get divisions with heads
$divisions_query = "SELECT d.*, (
    SELECT TOP 1 n.head FROM numbers n WHERE n.division_id = d.division_id ORDER BY n.number_id DESC
) as head FROM divisions d ORDER BY d.division_name";
$divisions_result = sqlsrv_query($conn, $divisions_query);
$divisions_list = [];
if ($divisions_result) {
    while ($row = sqlsrv_fetch_array($divisions_result, SQLSRV_FETCH_ASSOC)) {
        $row['head'] = $row['head'] ?? 'Not assigned';
        $divisions_list[] = $row;
    }
    sqlsrv_free_stmt($divisions_result);
}

// Get departments with heads
$departments_query = "SELECT d.*, dv.division_name, (
    SELECT TOP 1 n.head FROM numbers n WHERE n.department_id = d.department_id ORDER BY n.number_id DESC
) as head FROM departments d LEFT JOIN divisions dv ON d.division_id = dv.division_id ORDER BY d.department_name";
$departments_result = sqlsrv_query($conn, $departments_query);
$departments_list = [];
if ($departments_result) {
    while ($row = sqlsrv_fetch_array($departments_result, SQLSRV_FETCH_ASSOC)) {
        $row['head'] = $row['head'] ?? 'Not assigned';
        $departments_list[] = $row;
    }
    sqlsrv_free_stmt($departments_result);
}

// Get units with heads
$units_query = "SELECT u.*, d.department_name, dv.division_name, (
    SELECT TOP 1 n.head FROM numbers n WHERE n.unit_id = u.unit_id ORDER BY n.number_id DESC
) as head FROM units u LEFT JOIN departments d ON u.department_id = d.department_id LEFT JOIN divisions dv ON d.division_id = dv.division_id ORDER BY u.unit_name";
$units_result = sqlsrv_query($conn, $units_query);
$units_list = [];
if ($units_result) {
    while ($row = sqlsrv_fetch_array($units_result, SQLSRV_FETCH_ASSOC)) {
        $row['head'] = $row['head'] ?? 'Not assigned';
        $units_list[] = $row;
    }
    sqlsrv_free_stmt($units_result);
}

// Get offices with heads
$offices_query = "SELECT o.*, u.unit_name, d.department_name, dv.division_name, (
    SELECT TOP 1 n.head FROM numbers n WHERE n.office_id = o.office_id ORDER BY n.number_id DESC
) as head FROM offices o LEFT JOIN units u ON o.unit_id = u.unit_id LEFT JOIN departments d ON u.department_id = d.department_id LEFT JOIN divisions dv ON d.division_id = dv.division_id ORDER BY o.office_name";
$offices_result = sqlsrv_query($conn, $offices_query);
$offices_list = [];
if ($offices_result) {
    while ($row = sqlsrv_fetch_array($offices_result, SQLSRV_FETCH_ASSOC)) {
        $row['head'] = $row['head'] ?? 'Not assigned';
        $offices_list[] = $row;
    }
    sqlsrv_free_stmt($offices_result);
}

// Get dropdown data
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

$division_heads = getUsersByRoleIds($conn, [3]); // Only Division Heads
$department_heads = getUsersByRoleIds($conn, [3, 4]); // Division and Department Heads
$unit_heads = getUsersByRoleIds($conn, [3, 4, 5]); // Division, Department, Unit Heads
$office_heads = getUsersByRoleIds($conn, [3, 4, 5, 6]); // All Heads

// Also get all heads for the contact numbers form
$all_heads = getUsersByRoleIds($conn, [3, 4, 5, 6]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit/Remove Contacts</title>
    <style>
        * { box-sizing: border-box; margin:0; padding:0; font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
        body { min-height: 100vh; display: flex; flex-direction: column; background-color: #edf4fc; }
        
        /* Header */
        .header { position: fixed; top: 0; left: 0; width: 100%; background-color: #07417f; color: white; padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-bottom: 3px solid #2b6cb0; }
        .header .logo { display: flex; align-items: center; gap: 15px; }
        .header .logo img { width: 55px; height: 55px; object-fit: contain; }
        .header .logo span { font-size: 1.5rem; font-weight: 700; color: white; }
        ul.nav { display: flex; list-style: none; gap: 8px; }
        ul.nav li a { display: block; color: white; text-decoration: none; padding: 10px 18px; font-weight: 600; border-radius: 6px; }
        ul.nav li a:hover { background-color: rgba(255,255,255,0.2); }
        ul.nav li a.active { background-color: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); }
        
        /* Notification System - Like homepage.php */
        .notification-indicator { position: relative; }
        .nav-notification-badge { background-color: #e53e3e; color: white; font-size: 11px; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; margin-left: 5px; animation: pulse 2s infinite; display: inline-block; }
        .admin-notification-container { position: relative; display: inline-block; margin-left: 10px; }
        .admin-notification-btn { width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, #e53e3e, #c53030); color: white; border: 3px solid white; cursor: pointer; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 20px; box-shadow: 0 3px 10px rgba(229, 62, 62, 0.3); transition: all 0.3s; position: relative; }
        .admin-notification-btn:hover { background: linear-gradient(135deg, #c53030, #9b2c2c); transform: scale(1.05); box-shadow: 0 5px 15px rgba(229, 62, 62, 0.4); }
        .notification-bell-badge { position: absolute; top: -5px; right: -5px; background-color: #e53e3e; color: white; font-size: 12px; padding: 3px 8px; border-radius: 10px; min-width: 24px; text-align: center; font-weight: bold; border: 2px solid white; animation: pulse 1.5s infinite; display: none; }
        .notification-dropdown { position: absolute; top: 100%; right: 0; width: 350px; background: white; border-radius: 8px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); margin-top: 15px; padding: 0; z-index: 1000; opacity: 0; visibility: hidden; transform: translateY(-10px); transition: all 0.3s; }
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
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }
        
        /* Main content */
        .content { flex: 1; margin-top: 100px; padding: 20px; }
        .edit-container { background-color: #fff; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); padding: 25px; }
        .edit-container h2 { color: #2b6cb0; margin-bottom: 25px; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; text-align: center; }
        
        /* Section buttons */
        .section-buttons { display: flex; justify-content: center; gap: 10px; margin-bottom: 30px; flex-wrap: wrap; }
        .edit-section-btn { padding: 12px 24px; border: none; background-color: #e2e8f0; color: #4a5568; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s; }
        .edit-section-btn:hover { background-color: #cbd5e0; }
        .edit-section-btn.active { background-color: #2b6cb0; color: white; }
        
        /* Sections */
        .edit-section { display: none; }
        .edit-section.active { display: block; }
        .section-container { background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .section-container h3 { color: #2d3748; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #e2e8f0; }
        
        /* Messages */
        .error-message { background-color: #fed7d7; color: #9b2c2c; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #feb2b2; animation: slideIn 0.3s ease; }
        .success-message { background-color: #c6f6d5; color: #276749; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #9ae6b4; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        /* Forms */
        .edit-form, .org-edit-form { background-color: white; padding: 25px; border-radius: 8px; margin-bottom: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #4a5568; }
        .form-row { display: flex; gap: 20px; margin-bottom: 15px; }
        .form-row .form-group { flex: 1; }
        input[type="text"], select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; transition: border-color 0.2s; }
        input[type="text"]:focus, select:focus { outline: none; border-color: #2b6cb0; box-shadow: 0 0 0 3px rgba(43,108,176,0.1); }
        
        /* Buttons */
        .btn-save { background-color: #28a745; color: white; border: none; border-radius: 4px; padding: 10px 20px; cursor: pointer; font-size: 14px; font-weight: 600; transition: background-color 0.2s; }
        .btn-save:hover { background-color: #218838; }
        .btn-cancel { background-color: #6c757d; color: white; border: none; border-radius: 4px; padding: 10px 20px; cursor: pointer; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-block; transition: background-color 0.2s; }
        .btn-cancel:hover { background-color: #5a6268; }
        .btn-decommission { background-color: #dc3545; color: white; border: none; border-radius: 4px; padding: 6px 12px; cursor: pointer; font-size: 12px; font-weight: 600; transition: background-color 0.2s; }
        .btn-reactivate { background-color: #28a745; color: white; border: none; border-radius: 4px; padding: 6px 12px; cursor: pointer; font-size: 12px; font-weight: 600; transition: background-color 0.2s; }
        .btn-decommission:hover { background-color: #c82333; }
        .btn-reactivate:hover { background-color: #218838; }
        
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .edit-btn, .delete-btn { padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: none; border: none; transition: background-color 0.2s; }
        .edit-btn { background-color: #2b6cb0; color: white; }
        .edit-btn:hover { background-color: #1e4e8c; }
        .delete-btn { background-color: #e53e3e; color: white; }
        .delete-btn:hover { background-color: #c53030; }
        
        /* Tables */
        .contacts-table, .org-table { width: 100%; border-collapse: collapse; border-radius: 6px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .contacts-table th, .org-table th { background-color: #2b6cb0; color: white; padding: 12px 15px; text-align: left; font-weight: 600; }
        .contacts-table td, .org-table td { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; }
        .contacts-table tr:hover, .org-table tr:hover { background-color: #f7fafc; }
        
        /* Status badges */
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase; }
        .status-active { background-color: #c6f6d5; color: #276749; border: 1px solid #9ae6b4; }
        .status-decommissioned { background-color: #fed7d7; color: #9b2c2c; border: 1px solid #feb2b2; }
        
        /* Search filter */
        .search-filter { margin-bottom: 20px; }
        .search-filter input { width: 100%; padding: 12px 15px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; }
        .search-filter input:focus { outline: none; border-color: #2b6cb0; box-shadow: 0 0 0 3px rgba(43,108,176,0.1); }
        
        .no-items { text-align: center; padding: 40px; color: #718096; font-style: italic; }
        .footer { background-color: #07417f; color: #fff; text-align: center; padding: 18px 10px; font-size: 14px; margin-top: auto; }
        
        @media (max-width: 768px) {
            .header { flex-direction: column; padding: 15px; }
            .section-buttons { flex-direction: column; }
            .form-row { flex-direction: column; }
            .contacts-table, .org-table { display: block; overflow-x: auto; }
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
                <li><a href="editpage.php" class="active">Edit page</a></li>
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
            <span class="notification-bell-badge" id="notificationBellBadge" style="display: none;">0</span>
        </button>
        <div class="notification-dropdown" id="notificationDropdown">
            <div class="notification-header">
                <h4>📨 New Chat Requests (<span id="adminNotificationCount">0</span>)</h4>
            </div>
            <div class="notification-list" id="adminNotificationList">
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
                    <div class="no-notifications">No new chat requests</div>
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
        
        <!-- Section Buttons -->
        <div class="section-buttons">
            <button class="edit-section-btn <?php echo $active_section == 'contacts' ? 'active' : ''; ?>" onclick="showSection('contacts')">Contact Numbers (<?php echo count($numbers); ?>)</button>
            <button class="edit-section-btn <?php echo $active_section == 'divisions' ? 'active' : ''; ?>" onclick="showSection('divisions')">Divisions (<?php echo count($divisions_list); ?>)</button>
            <button class="edit-section-btn <?php echo $active_section == 'departments' ? 'active' : ''; ?>" onclick="showSection('departments')">Departments (<?php echo count($departments_list); ?>)</button>
            <button class="edit-section-btn <?php echo $active_section == 'units' ? 'active' : ''; ?>" onclick="showSection('units')">Units (<?php echo count($units_list); ?>)</button>
            <button class="edit-section-btn <?php echo $active_section == 'offices' ? 'active' : ''; ?>" onclick="showSection('offices')">Offices (<?php echo count($offices_list); ?>)</button>
        </div>
        
        <!-- Edit Contact Number Form -->
        <?php if ($edit_mode && $current_item): ?>
        <div class="edit-form">
            <h3>Edit Contact Details</h3>
            <form method="POST" action="editpage.php?section=<?php echo $active_section; ?>">
                <input type="hidden" name="edit_id" value="<?php echo $current_item['number_id']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Phone Number(s) *</label>
                        <input type="text" name="new_number" value="<?php echo htmlspecialchars($current_item['numbers']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="text" name="new_email" value="<?php echo htmlspecialchars($current_item['email'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Description *</label>
                        <input type="text" name="new_description" value="<?php echo htmlspecialchars($current_item['description']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Head *</label>
                        <select name="new_head" required>
                            <option value="">Select Head</option>
                            <?php foreach ($all_heads as $user): ?>
                                <option value="<?php echo htmlspecialchars($user['full_name']); ?>" <?php echo $current_item['head'] == $user['full_name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Division</label>
                        <select name="new_division_id" id="new_division_id">
                            <option value="">None</option>
                            <?php foreach ($all_divisions as $division): ?>
                                <option value="<?php echo $division['division_id']; ?>" <?php echo $current_item['division_id'] == $division['division_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($division['division_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="new_department_id" id="new_department_id">
                            <option value="">None</option>
                            <?php foreach ($all_departments as $department): ?>
                                <option value="<?php echo $department['department_id']; ?>" <?php echo $current_item['department_id'] == $department['department_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($department['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Unit</label>
                        <select name="new_unit_id" id="new_unit_id">
                            <option value="">None</option>
                            <?php foreach ($all_units as $unit): ?>
                                <option value="<?php echo $unit['unit_id']; ?>" <?php echo $current_item['unit_id'] == $unit['unit_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($unit['unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Office</label>
                        <select name="new_office_id" id="new_office_id">
                            <option value="">None</option>
                            <?php foreach ($offices as $office): ?>
                                <option value="<?php echo $office['office_id']; ?>" <?php echo $current_item['office_id'] == $office['office_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($office['office_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="save_edit" class="btn-save">Save Changes</button>
                    <a href="?section=<?php echo $active_section; ?>" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Division Edit Form -->
        <?php if ($edit_org_mode == 'division' && $current_org_item): ?>
        <div class="org-edit-form">
            <h3>Edit Division: <?php echo htmlspecialchars($current_org_item['division_name']); ?></h3>
            <form method="POST" action="editpage.php?section=divisions">
                <input type="hidden" name="division_id" value="<?php echo $current_org_item['division_id']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Division Name *</label>
                        <input type="text" name="division_name" value="<?php echo htmlspecialchars($current_org_item['division_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Division Head (leave empty to keep current)</label>
                        <select name="division_head">
                            <option value="">-- Keep Current Head --</option>
                            <?php foreach ($division_heads as $head): ?>
                                <option value="<?php echo htmlspecialchars($head['full_name']); ?>">
                                    <?php echo htmlspecialchars($head['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #666; font-size: 11px;">Current head: <?php echo htmlspecialchars($current_org_item['head'] ?? 'Not assigned'); ?></small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="division_status" required onchange="showDecommissionWarning(this, 'division')">
                            <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                        </select>
                    </div>
                </div>
                
                <div id="decommissionWarning" style="display: none; background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin: 10px 0; color: #856404;">
                    <strong>Warning:</strong> Decommissioning this division will also decommission all departments, units, offices, and numbers under it.
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="update_division" class="btn-save">Update Division</button>
                    <a href="?section=divisions" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Department Edit Form -->
        <?php if ($edit_org_mode == 'department' && $current_org_item): ?>
        <div class="org-edit-form">
            <h3>Edit Department: <?php echo htmlspecialchars($current_org_item['department_name']); ?></h3>
            <form method="POST" action="editpage.php?section=departments">
                <input type="hidden" name="department_id" value="<?php echo $current_org_item['department_id']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Department Name *</label>
                        <input type="text" name="department_name" value="<?php echo htmlspecialchars($current_org_item['department_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Department Head (leave empty to keep current)</label>
                        <select name="department_head">
                            <option value="">-- Keep Current Head --</option>
                            <?php foreach ($department_heads as $head): ?>
                                <option value="<?php echo htmlspecialchars($head['full_name']); ?>">
                                    <?php echo htmlspecialchars($head['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #666; font-size: 11px;">Current head: <?php echo htmlspecialchars($current_org_item['head'] ?? 'Not assigned'); ?></small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Division *</label>
                        <select name="division_id" required>
                            <option value="">Select Division</option>
                            <?php foreach ($all_divisions as $division): ?>
                                <option value="<?php echo $division['division_id']; ?>" <?php echo $current_org_item['division_id'] == $division['division_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($division['division_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="department_status" required onchange="showDecommissionWarning(this, 'department')">
                            <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                        </select>
                    </div>
                </div>
                
                <div id="decommissionWarning" style="display: none; background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin: 10px 0; color: #856404;">
                    <strong>Warning:</strong> Decommissioning this department will also decommission all units, offices, and numbers under it.
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="update_department" class="btn-save">Update Department</button>
                    <a href="?section=departments" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Unit Edit Form -->
        <?php if ($edit_org_mode == 'unit' && $current_org_item): ?>
        <div class="org-edit-form">
            <h3>Edit Unit: <?php echo htmlspecialchars($current_org_item['unit_name']); ?></h3>
            <form method="POST" action="editpage.php?section=units">
                <input type="hidden" name="unit_id" value="<?php echo $current_org_item['unit_id']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Unit Name *</label>
                        <input type="text" name="unit_name" value="<?php echo htmlspecialchars($current_org_item['unit_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Unit Head (leave empty to keep current)</label>
                        <select name="unit_head">
                            <option value="">-- Keep Current Head --</option>
                            <?php foreach ($unit_heads as $head): ?>
                                <option value="<?php echo htmlspecialchars($head['full_name']); ?>">
                                    <?php echo htmlspecialchars($head['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #666; font-size: 11px;">Current head: <?php echo htmlspecialchars($current_org_item['head'] ?? 'Not assigned'); ?></small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Department *</label>
                        <select name="department_id" required>
                            <option value="">Select Department</option>
                            <?php foreach ($all_departments as $department): ?>
                                <option value="<?php echo $department['department_id']; ?>" <?php echo $current_org_item['department_id'] == $department['department_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($department['department_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="unit_status" required onchange="showDecommissionWarning(this, 'unit')">
                            <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                        </select>
                    </div>
                </div>
                
                <div id="decommissionWarning" style="display: none; background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin: 10px 0; color: #856404;">
                    <strong>Warning:</strong> Decommissioning this unit will also decommission all offices and numbers under it.
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="update_unit" class="btn-save">Update Unit</button>
                    <a href="?section=units" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Office Edit Form -->
        <?php if ($edit_org_mode == 'office' && $current_org_item): ?>
        <div class="org-edit-form">
            <h3>Edit Office: <?php echo htmlspecialchars($current_org_item['office_name']); ?></h3>
            <form method="POST" action="editpage.php?section=offices">
                <input type="hidden" name="office_id" value="<?php echo $current_org_item['office_id']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Office Name *</label>
                        <input type="text" name="office_name" value="<?php echo htmlspecialchars($current_org_item['office_name']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Office Head (leave empty to keep current)</label>
                        <select name="office_head">
                            <option value="">-- Keep Current Head --</option>
                            <?php foreach ($office_heads as $head): ?>
                                <option value="<?php echo htmlspecialchars($head['full_name']); ?>">
                                    <?php echo htmlspecialchars($head['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #666; font-size: 11px;">Current head: <?php echo htmlspecialchars($current_org_item['head'] ?? 'Not assigned'); ?></small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Unit *</label>
                        <select name="unit_id" required>
                            <option value="">Select Unit</option>
                            <?php foreach ($all_units as $unit): ?>
                                <option value="<?php echo $unit['unit_id']; ?>" <?php echo $current_org_item['unit_id'] == $unit['unit_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($unit['unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="office_status" required onchange="showDecommissionWarning(this, 'office')">
                            <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                        </select>
                    </div>
                </div>
                
                <div id="decommissionWarning" style="display: none; background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin: 10px 0; color: #856404;">
                    <strong>Warning:</strong> Decommissioning this office will also decommission all numbers under it.
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="update_office" class="btn-save">Update Office</button>
                    <a href="?section=offices" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Contact Numbers Table -->
        <div class="edit-section <?php echo $active_section == 'contacts' ? 'active' : ''; ?>" id="contacts-section">
            <div class="section-container">
                <h3>Contact Numbers</h3>
                <div class="search-filter">
                    <input type="text" id="searchContacts" placeholder="Search by number, description, email, head, or organization..." onkeyup="filterTable('contactsTable', 'searchContacts')">
                </div>
                
                <?php if (count($numbers) > 0): ?>
                    <table class="contacts-table" id="contactsTable">
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Description</th>
                                <th>Email</th>
                                <th>Head</th>
                                <th>Organization</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($numbers as $number): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($number['numbers']); ?></td>
                                <td><?php echo htmlspecialchars($number['description']); ?></td>
                                <td><?php echo htmlspecialchars($number['email'] ?? 'No email'); ?></td>
                                <td><?php echo htmlspecialchars($number['head']); ?></td>
                                <td>
                                    <?php
                                    if (!empty($number['office_name'])) echo "Office: " . htmlspecialchars($number['office_name']);
                                    elseif (!empty($number['unit_name'])) echo "Unit: " . htmlspecialchars($number['unit_name']);
                                    elseif (!empty($number['department_name'])) echo "Dept: " . htmlspecialchars($number['department_name']);
                                    elseif (!empty($number['division_name'])) echo "Division: " . htmlspecialchars($number['division_name']);
                                    else echo "None";
                                    ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $number['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                        <?php echo ucfirst($number['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?section=contacts&edit=<?php echo $number['number_id']; ?>" class="edit-btn">Edit</a>
                                        
                                        <?php if ($number['status'] == 'active'): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Decommission this number? This will also decommission its parent organization.');">
                                            <input type="hidden" name="number_id" value="<?php echo $number['number_id']; ?>">
                                            <input type="hidden" name="new_status" value="decommissioned">
                                            <button type="submit" name="quick_status_change" class="btn-decommission">Decommission</button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Reactivate this number? Make sure parent organization is active first.');">
                                            <input type="hidden" name="number_id" value="<?php echo $number['number_id']; ?>">
                                            <input type="hidden" name="new_status" value="active">
                                            <button type="submit" name="quick_status_change" class="btn-reactivate">Reactivate</button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="delete_id" value="<?php echo $number['number_id']; ?>">
                                            <button type="submit" class="delete-btn" onclick="return confirm('Delete this contact? This cannot be undone.')">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-items">No contact numbers found.</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Divisions Table with Decommission/Reactivate Buttons -->
        <div class="edit-section <?php echo $active_section == 'divisions' ? 'active' : ''; ?>" id="divisions-section">
            <div class="section-container">
                <h3>Divisions</h3>
                <div class="search-filter">
                    <input type="text" id="searchDivisions" placeholder="Search by division name or head..." onkeyup="filterTable('divisionsTable', 'searchDivisions')">
                </div>
                
                <?php if (count($divisions_list) > 0): ?>
                    <table class="org-table" id="divisionsTable">
                        <thead>
                            <tr>
                                <th>Division Name</th>
                                <th>Head</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($divisions_list as $division): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($division['division_name']); ?></td>
                                <td><?php echo htmlspecialchars($division['head']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $division['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                        <?php echo ucfirst($division['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?section=divisions&edit_division=<?php echo $division['division_id']; ?>" class="edit-btn">Edit</a>
                                        
                                        <?php if ($division['status'] == 'active'): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Decommission this division? This will decommission all departments, units, offices, and numbers under it.');">
                                            <input type="hidden" name="division_id" value="<?php echo $division['division_id']; ?>">
                                            <input type="hidden" name="new_status" value="decommissioned">
                                            <button type="submit" name="quick_status_change_division" class="btn-decommission">Decommission</button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Reactivate this division? This will set it to active but child organizations will remain decommissioned until individually reactivated.');">
                                            <input type="hidden" name="division_id" value="<?php echo $division['division_id']; ?>">
                                            <input type="hidden" name="new_status" value="active">
                                            <button type="submit" name="quick_status_change_division" class="btn-reactivate">Reactivate</button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="delete_division" value="<?php echo $division['division_id']; ?>">
                                            <button type="submit" class="delete-btn" onclick="return confirm('Delete this division? This will permanently delete all associated data.')">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-items">No divisions found.</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Departments Table with Decommission/Reactivate Buttons -->
        <div class="edit-section <?php echo $active_section == 'departments' ? 'active' : ''; ?>" id="departments-section">
            <div class="section-container">
                <h3>Departments</h3>
                <div class="search-filter">
                    <input type="text" id="searchDepartments" placeholder="Search by department name, head, or division..." onkeyup="filterTable('departmentsTable', 'searchDepartments')">
                </div>
                
                <?php if (count($departments_list) > 0): ?>
                    <table class="org-table" id="departmentsTable">
                        <thead>
                            <tr>
                                <th>Department Name</th>
                                <th>Head</th>
                                <th>Division</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($departments_list as $department): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($department['department_name']); ?></td>
                                <td><?php echo htmlspecialchars($department['head']); ?></td>
                                <td><?php echo htmlspecialchars($department['division_name'] ?? 'None'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $department['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                        <?php echo ucfirst($department['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?section=departments&edit_department=<?php echo $department['department_id']; ?>" class="edit-btn">Edit</a>
                                        
                                        <?php if ($department['status'] == 'active'): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Decommission this department? This will decommission all units, offices, and numbers under it.');">
                                            <input type="hidden" name="department_id" value="<?php echo $department['department_id']; ?>">
                                            <input type="hidden" name="new_status" value="decommissioned">
                                            <button type="submit" name="quick_status_change_department" class="btn-decommission">Decommission</button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Reactivate this department? This will set it to active but child organizations will remain decommissioned until individually reactivated.');">
                                            <input type="hidden" name="department_id" value="<?php echo $department['department_id']; ?>">
                                            <input type="hidden" name="new_status" value="active">
                                            <button type="submit" name="quick_status_change_department" class="btn-reactivate">Reactivate</button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="delete_department" value="<?php echo $department['department_id']; ?>">
                                            <button type="submit" class="delete-btn" onclick="return confirm('Delete this department? This will permanently delete all associated data.')">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-items">No departments found.</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Units Table with Decommission/Reactivate Buttons -->
        <div class="edit-section <?php echo $active_section == 'units' ? 'active' : ''; ?>" id="units-section">
            <div class="section-container">
                <h3>Units</h3>
                <div class="search-filter">
                    <input type="text" id="searchUnits" placeholder="Search by unit name, head, or department..." onkeyup="filterTable('unitsTable', 'searchUnits')">
                </div>
                
                <?php if (count($units_list) > 0): ?>
                    <table class="org-table" id="unitsTable">
                        <thead>
                            <tr>
                                <th>Unit Name</th>
                                <th>Head</th>
                                <th>Department</th>
                                <th>Division</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($units_list as $unit): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($unit['unit_name']); ?></td>
                                <td><?php echo htmlspecialchars($unit['head']); ?></td>
                                <td><?php echo htmlspecialchars($unit['department_name'] ?? 'None'); ?></td>
                                <td><?php echo htmlspecialchars($unit['division_name'] ?? 'None'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $unit['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                        <?php echo ucfirst($unit['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?section=units&edit_unit=<?php echo $unit['unit_id']; ?>" class="edit-btn">Edit</a>
                                        
                                        <?php if ($unit['status'] == 'active'): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Decommission this unit? This will decommission all offices and numbers under it.');">
                                            <input type="hidden" name="unit_id" value="<?php echo $unit['unit_id']; ?>">
                                            <input type="hidden" name="new_status" value="decommissioned">
                                            <button type="submit" name="quick_status_change_unit" class="btn-decommission">Decommission</button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Reactivate this unit? This will set it to active but child organizations will remain decommissioned until individually reactivated.');">
                                            <input type="hidden" name="unit_id" value="<?php echo $unit['unit_id']; ?>">
                                            <input type="hidden" name="new_status" value="active">
                                            <button type="submit" name="quick_status_change_unit" class="btn-reactivate">Reactivate</button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="delete_unit" value="<?php echo $unit['unit_id']; ?>">
                                            <button type="submit" class="delete-btn" onclick="return confirm('Delete this unit? This will permanently delete all associated data.')">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-items">No units found.</div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Offices Table with Decommission/Reactivate Buttons -->
        <div class="edit-section <?php echo $active_section == 'offices' ? 'active' : ''; ?>" id="offices-section">
            <div class="section-container">
                <h3>Offices</h3>
                <div class="search-filter">
                    <input type="text" id="searchOffices" placeholder="Search by office name, head, or unit..." onkeyup="filterTable('officesTable', 'searchOffices')">
                </div>
                
                <?php if (count($offices_list) > 0): ?>
                    <table class="org-table" id="officesTable">
                        <thead>
                            <tr>
                                <th>Office Name</th>
                                <th>Head</th>
                                <th>Unit</th>
                                <th>Department</th>
                                <th>Division</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($offices_list as $office): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($office['office_name']); ?></td>
                                <td><?php echo htmlspecialchars($office['head']); ?></td>
                                <td><?php echo htmlspecialchars($office['unit_name'] ?? 'None'); ?></td>
                                <td><?php echo htmlspecialchars($office['department_name'] ?? 'None'); ?></td>
                                <td><?php echo htmlspecialchars($office['division_name'] ?? 'None'); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $office['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                        <?php echo ucfirst($office['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="?section=offices&edit_office=<?php echo $office['office_id']; ?>" class="edit-btn">Edit</a>
                                        
                                        <?php if ($office['status'] == 'active'): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Decommission this office? This will decommission all numbers under it.');">
                                            <input type="hidden" name="office_id" value="<?php echo $office['office_id']; ?>">
                                            <input type="hidden" name="new_status" value="decommissioned">
                                            <button type="submit" name="quick_status_change_office" class="btn-decommission">Decommission</button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Reactivate this office? This will set it to active.');">
                                            <input type="hidden" name="office_id" value="<?php echo $office['office_id']; ?>">
                                            <input type="hidden" name="new_status" value="active">
                                            <button type="submit" name="quick_status_change_office" class="btn-reactivate">Reactivate</button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="delete_office" value="<?php echo $office['office_id']; ?>">
                                            <button type="submit" class="delete-btn" onclick="return confirm('Delete this office? This will permanently delete all associated numbers.')">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-items">No offices found.</div>
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
        const filter = input.value.toLowerCase();
        const table = document.getElementById(tableId);
        const rows = table.getElementsByTagName('tr');
        
        for (let i = 1; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName('td');
            let found = false;
            
            for (let j = 0; j < cells.length - 1; j++) {
                if (cells[j]) {
                    const text = cells[j].textContent.toLowerCase();
                    if (text.indexOf(filter) > -1) {
                        found = true;
                        break;
                    }
                }
            }
            
            rows[i].style.display = found ? '' : 'none';
        }
    }
    
    function showDecommissionWarning(select, type) {
        const warningDiv = document.getElementById('decommissionWarning');
        if (select.value === 'decommissioned') {
            warningDiv.style.display = 'block';
        } else {
            warningDiv.style.display = 'none';
        }
    }
    
    // Clear other organization selections
    document.addEventListener('DOMContentLoaded', function() {
        const divisionSelect = document.getElementById('new_division_id');
        const departmentSelect = document.getElementById('new_department_id');
        const unitSelect = document.getElementById('new_unit_id');
        const officeSelect = document.getElementById('new_office_id');
        
        if (divisionSelect) {
            function clearOthers(selected) {
                const selects = [divisionSelect, departmentSelect, unitSelect, officeSelect];
                selects.forEach(select => {
                    if (select && select !== selected && select.value) {
                        select.value = '';
                    }
                });
            }
            
            divisionSelect.addEventListener('change', function() { if (this.value) clearOthers(this); });
            departmentSelect.addEventListener('change', function() { if (this.value) clearOthers(this); });
            unitSelect.addEventListener('change', function() { if (this.value) clearOthers(this); });
            officeSelect.addEventListener('change', function() { if (this.value) clearOthers(this); });
        }
        
        // Auto-hide messages after 8 seconds
        const errorMsg = document.querySelector('.error-message');
        const successMsg = document.querySelector('.success-message');
        
        if (errorMsg) {
            setTimeout(() => {
                errorMsg.style.transition = 'opacity 0.5s';
                errorMsg.style.opacity = '0';
                setTimeout(() => errorMsg.remove(), 500);
            }, 8000);
        }

        if (successMsg) {
            setTimeout(() => {
                successMsg.style.transition = 'opacity 0.5s';
                successMsg.style.opacity = '0';
                setTimeout(() => successMsg.remove(), 500);
            }, 8000);
        }

        // Start notification checking
        if (userId) {
            startNotificationChecking();
        }
    });

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
        // Create toast container if it doesn't exist
        let container = document.getElementById('notificationToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'notificationToastContainer';
            container.className = 'notification-toast-container';
            document.body.appendChild(container);
        }

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

    // Add toast container styles if not already present
    const style = document.createElement('style');
    style.textContent = `
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
    `;
    document.head.appendChild(style);
</script>

</body>
</html>