<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');
ob_start();

require_once 'conn.php';
require_once 'config.php';
updateAllUsersActivity($conn);

// Get divisions
$divisions = getDivisions($conn);

// Get notification data
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$is_admin = isAdmin();

// Initialize variables for notifications
$onlineAdminCount = getOnlineAdmins($conn);
$admin_notifications_count = 0;
$admin_chat_requests = [];
$user_unread = 0;

if($user_id) {
    if(!$is_admin) {
        $user_unread = getUnreadAdminMessageCount($conn, $user_id, false);
    } else {
        $admin_notifications_count = getAdminUnreadChatsCount($conn, $user_id);
        $admin_chat_requests = getAdminChatRequests($conn, $user_id);
    }
}

// Function to get heads based on create type using role_id
function getHeadsByCreateType($conn, $createType = '') {
    $heads = [];
    
    switch($createType) {
        case 'division':
            $sql = "SELECT user_id, username, full_name, role_id 
                    FROM users 
                    WHERE status = 'active' 
                    AND role_id IN (3)
                    ORDER BY full_name";
            break;
            
        case 'department':
            $sql = "SELECT user_id, username, full_name, role_id 
                    FROM users 
                    WHERE status = 'active' 
                    AND role_id IN (3, 4)
                    ORDER BY full_name";
            break;
            
        case 'unit':
            $sql = "SELECT user_id, username, full_name, role_id 
                    FROM users 
                    WHERE status = 'active' 
                    AND role_id IN (3, 4, 5)
                    ORDER BY full_name";
            break;
            
        case 'office':
            $sql = "SELECT user_id, username, full_name, role_id 
                    FROM users 
                    WHERE status = 'active' 
                    AND role_id IN (3, 4, 5, 6)
                    ORDER BY full_name";
            break;
            
        default:
            // Initial load: show all active users
            $sql = "SELECT user_id, username, full_name, role_id 
                    FROM users 
                    WHERE status = 'active' 
                    ORDER BY full_name";
    }
    
    $stmt = sqlsrv_query($conn, $sql);
    if($stmt) {
        while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Add role name for display
            $roleNames = [
                1 => 'Admin',
                2 => 'MCC',
                3 => 'Division Head',
                4 => 'Department Head',
                5 => 'Unit Head',
                6 => 'Office Head',
                7 => 'Staff'
            ];
            $row['role_name'] = $roleNames[$row['role_id']] ?? 'Unknown';
            $heads[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    
    return $heads;
}

function canUserBeHead($conn, $userId, $createType) {
    // Get user's role
    $sql = "SELECT role_id FROM users WHERE user_id = ?";
    $params = array($userId);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if (!$stmt) return false;
    
    $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    
    if (!$user) return false;
    
    $roleId = $user['role_id'];
    
    // Check based on create type - EXACT role match or higher role for lower position
    switch($createType) {
        case 'division':
            // For division: role_id must be EXACTLY 3 (division head)
            return $roleId == 3;
            
        case 'department':
            // For department: role_id must be 3 OR 4 (division head OR department head)
            return $roleId == 3 || $roleId == 4;
            
        case 'unit':
            // For unit: role_id must be 3 OR 4 OR 5
            return $roleId == 3 || $roleId == 4 || $roleId == 5;
            
        case 'office':
            // For office: role_id must be 3 OR 4 OR 5 OR 6
            return $roleId == 3 || $roleId == 4 || $roleId == 5 || $roleId == 6;
            
        default:
            return false;
    }
}

// Helper functions for organizational hierarchy
function getDivisionIdFromDepartment($conn, $department_id) {
    $sql = "SELECT division_id FROM departments WHERE department_id = ?";
    $params = array($department_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row['division_id'];
    }
    
    return NULL;
}

function getDepartmentIdFromUnit($conn, $unit_id) {
    $sql = "SELECT department_id FROM units WHERE unit_id = ?";
    $params = array($unit_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt && sqlsrv_has_rows($stmt)) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        return $row['department_id'];
    }
    
    return NULL;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    try {
        $createType = trim($_POST['create_type']);
        $name = trim($_POST['name']);
        $headUserId = (int)$_POST['head'];
        $email = trim($_POST['email'] ?? '');
        
        if (empty($createType)) throw new Exception("Please select what to create.");
        if (empty($name)) throw new Exception("Name is required.");
        if (empty($headUserId)) throw new Exception("Head is required.");
        
        // Validate email if provided
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Please enter a valid email address.");
        }
        
        // Check if selected user can be head for this create type
        if (!canUserBeHead($conn, $headUserId, $createType)) {
            $typeNames = [
                'division' => 'Division',
                'department' => 'Department', 
                'unit' => 'Unit',
                'office' => 'Office'
            ];
            $typeName = $typeNames[$createType] ?? 'Item';
            throw new Exception("Selected user does not have the required role to be a $typeName head.");
        }
        
        // Get head user details
        $headQuery = sqlsrv_prepare($conn, "SELECT username, full_name, role_id FROM users WHERE user_id = ?", array($headUserId));
        if (!$headQuery) {
            throw new Exception("Database error: " . print_r(sqlsrv_errors(), true));
        }
        
        if (!sqlsrv_execute($headQuery)) {
            throw new Exception("Failed to execute query: " . print_r(sqlsrv_errors(), true));
        }
        
        $headUser = sqlsrv_fetch_array($headQuery, SQLSRV_FETCH_ASSOC);
        if (!$headUser) {
            throw new Exception("Head user not found in the system.");
        }
        
        $headUsername = $headUser['username'];
        $headFullName = $headUser['full_name'];
        $headRoleId = $headUser['role_id'];
        sqlsrv_free_stmt($headQuery);
        
        // Begin transaction
        sqlsrv_begin_transaction($conn);
        
        // Variables for organizational hierarchy (to be populated based on create type)
        $division_id = NULL;
        $department_id = NULL;
        $unit_id = NULL;
        $office_id = NULL;
        $tableField = ''; // Which field to use for the new ID
        
        switch ($createType) {
            case 'division':
                // Check if division already exists
                $checkStmt = sqlsrv_prepare($conn, "SELECT division_id FROM divisions WHERE division_name = ?", array($name));
                if (!$checkStmt || !sqlsrv_execute($checkStmt)) {
                    throw new Exception("Database error: " . print_r(sqlsrv_errors(), true));
                }
                
                if (sqlsrv_has_rows($checkStmt)) {
                    sqlsrv_free_stmt($checkStmt);
                    throw new Exception("Division '$name' already exists.");
                }
                sqlsrv_free_stmt($checkStmt);
                
                // Insert division
                $stmt = sqlsrv_prepare($conn, "INSERT INTO divisions (division_name, status) VALUES (?, 'active')", array($name));
                if (!$stmt || !sqlsrv_execute($stmt)) {
                    throw new Exception("Failed to create division: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($stmt);
                
                $sql = "SELECT division_id FROM divisions WHERE division_name = ?";
                $stmt = sqlsrv_prepare($conn, $sql, array($name));
                if (!$stmt || !sqlsrv_execute($stmt)) {
                    throw new Exception("Failed to get new division ID: " . print_r(sqlsrv_errors(), true));
                }
                if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $newId = $row['division_id'];
                } else {
                    throw new Exception("Failed to retrieve new division ID.");
                }
                sqlsrv_free_stmt($stmt);
                
                $tableField = 'division_id';
                $descriptionType = "Division contact";
                $division_id = $newId;
                break;
                
            case 'department':
                $underDivision = (int)$_POST['under_division'];
                if (empty($underDivision)) throw new Exception("Please select a division.");
                
                // Check if department already exists under this division
                $checkStmt = sqlsrv_prepare($conn, 
                    "SELECT department_id FROM departments WHERE department_name = ? AND division_id = ?", 
                    array($name, $underDivision)
                );
                if (!$checkStmt || !sqlsrv_execute($checkStmt)) {
                    throw new Exception("Database error: " . print_r(sqlsrv_errors(), true));
                }
                
                if (sqlsrv_has_rows($checkStmt)) {
                    sqlsrv_free_stmt($checkStmt);
                    throw new Exception("Department '$name' already exists in this division.");
                }
                sqlsrv_free_stmt($checkStmt);
                
                // Insert department
                $stmt = sqlsrv_prepare($conn, 
                    "INSERT INTO departments (department_name, division_id, status) VALUES (?, ?, 'active')", 
                    array($name, $underDivision)
                );
                if (!$stmt || !sqlsrv_execute($stmt)) {
                    throw new Exception("Failed to create department: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($stmt);
                
                // Get new ID
                $sql = "SELECT department_id FROM departments WHERE department_name = ?";
                $stmt = sqlsrv_prepare($conn, $sql, array($name));
                if (!$stmt || !sqlsrv_execute($stmt)) {
                    throw new Exception("Failed to get new department ID: " . print_r(sqlsrv_errors(), true));
                }
                if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $newId = $row['department_id'];
                } else {
                    throw new Exception("Failed to retrieve new department ID.");
                }
                sqlsrv_free_stmt($stmt);
                
                $tableField = 'department_id';
                $descriptionType = "Department contact";
                $division_id = $underDivision;
                $department_id = $newId;
                break;
                
            case 'unit':
                $underDepartment = (int)$_POST['under_department'];
                if (empty($underDepartment)) throw new Exception("Please select a department.");
                
                // Check if unit already exists under this department
                $checkStmt = sqlsrv_prepare($conn, 
                    "SELECT unit_id FROM units WHERE unit_name = ? AND department_id = ?", 
                    array($name, $underDepartment)
                );
                if (!$checkStmt || !sqlsrv_execute($checkStmt)) {
                    throw new Exception("Database error: " . print_r(sqlsrv_errors(), true));
                }
                
                if (sqlsrv_has_rows($checkStmt)) {
                    sqlsrv_free_stmt($checkStmt);
                    throw new Exception("Unit '$name' already exists in this department.");
                }
                sqlsrv_free_stmt($checkStmt);
                
                // Insert unit
                $stmt = sqlsrv_prepare($conn, 
                    "INSERT INTO units (unit_name, department_id, status) VALUES (?, ?, 'active')", 
                    array($name, $underDepartment)
                );
                if (!$stmt || !sqlsrv_execute($stmt)) {
                    throw new Exception("Failed to create unit: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($stmt);
                
                // Get new ID
                $sql = "SELECT unit_id FROM units WHERE unit_name = ?";
                $stmt = sqlsrv_prepare($conn, $sql, array($name));
                if (!$stmt || !sqlsrv_execute($stmt)) {
                    throw new Exception("Failed to get new unit ID: " . print_r(sqlsrv_errors(), true));
                }
                if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $newId = $row['unit_id'];
                } else {
                    throw new Exception("Failed to retrieve new unit ID.");
                }
                sqlsrv_free_stmt($stmt);
                
                $tableField = 'unit_id';
                $descriptionType = "Unit contact";
                // Get division_id from department
                $division_id = getDivisionIdFromDepartment($conn, $underDepartment);
                $department_id = $underDepartment;
                $unit_id = $newId;
                break;
                
            case 'office':
                $underUnit = (int)$_POST['under_unit'];
                if (empty($underUnit)) throw new Exception("Please select a unit.");
                
                // Check if office already exists under this unit
                $checkStmt = sqlsrv_prepare($conn, 
                    "SELECT office_id FROM offices WHERE office_name = ? AND unit_id = ?", 
                    array($name, $underUnit)
                );
                if (!$checkStmt || !sqlsrv_execute($checkStmt)) {
                    throw new Exception("Database error: " . print_r(sqlsrv_errors(), true));
                }
                
                if (sqlsrv_has_rows($checkStmt)) {
                    sqlsrv_free_stmt($checkStmt);
                    throw new Exception("Office '$name' already exists in this unit.");
                }
                sqlsrv_free_stmt($checkStmt);
                
                // Insert office
                $stmt = sqlsrv_prepare($conn, 
                    "INSERT INTO offices (office_name, unit_id, status) VALUES (?, ?, 'active')", 
                    array($name, $underUnit)
                );
                if (!$stmt || !sqlsrv_execute($stmt)) {
                    throw new Exception("Failed to create office: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($stmt);
                
                // Get new ID
                $sql = "SELECT office_id FROM offices WHERE office_name = ?";
                $stmt = sqlsrv_prepare($conn, $sql, array($name));
                if (!$stmt || !sqlsrv_execute($stmt)) {
                    throw new Exception("Failed to get new office ID: " . print_r(sqlsrv_errors(), true));
                }
                if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $newId = $row['office_id'];
                } else {
                    throw new Exception("Failed to retrieve new office ID.");
                }
                sqlsrv_free_stmt($stmt);
                
                $tableField = 'office_id';
                $descriptionType = "Office contact";
                // Get department_id and division_id from unit
                $department_id = getDepartmentIdFromUnit($conn, $underUnit);
                $division_id = getDivisionIdFromDepartment($conn, $department_id);
                $unit_id = $underUnit;
                $office_id = $newId;
                break;
                
            default:
                throw new Exception("Invalid create type.");
        }
        
        // Check if we have at least one contact method (email or phone)
        $hasContactMethod = false;
        $phoneNumbers = [];

        // Handle phone numbers in simpler array format
        if (isset($_POST['phone_numbers']) && is_array($_POST['phone_numbers'])) {
            $phoneNumbersArray = $_POST['phone_numbers'];
            $phoneDescriptionsArray = $_POST['phone_descriptions'] ?? [];
            
            for ($i = 0; $i < count($phoneNumbersArray); $i++) {
                $phoneNumber = trim($phoneNumbersArray[$i] ?? '');
                if (!empty($phoneNumber)) {
                    $hasContactMethod = true;
                    $description = trim($phoneDescriptionsArray[$i] ?? '');
                    if (empty($description)) {
                        $description = $descriptionType;
                    }
                    $phoneNumbers[] = [
                        'number' => $phoneNumber,
                        'description' => $description
                    ];
                }
            }
        }

        // Check if email is provided
        if (!empty($email)) {
            $hasContactMethod = true;
        }

        if (!$hasContactMethod) {
            throw new Exception("Please provide at least one contact method (email address or contact number).");
        }

        // Debug: Log what we parsed
        error_log("Parsed phone numbers: " . print_r($phoneNumbers, true));

        // Process contact information - ONE ROW with combined contacts
        $phoneFieldValue = '';
        $emailFieldValue = $email;
        $combinedDescription = $descriptionType;

        if (!empty($phoneNumbers)) {
            // If multiple phones, combine them with comma separation
            if (count($phoneNumbers) > 1) {
                $phoneList = [];
                $descriptions = [];
                
                foreach ($phoneNumbers as $phone) {
                    $phoneList[] = $phone['number'];
                    if (!empty($phone['description']) && $phone['description'] !== $descriptionType) {
                        $descriptions[] = $phone['description'];
                    }
                }
                
                $phoneFieldValue = implode(', ', $phoneList);
                
                // Add note about multiple lines if we have them
                if (!empty($descriptions)) {
                    $combinedDescription .= " (" . implode(', ', array_unique($descriptions)) . ")";
                } else {
                    $combinedDescription .= " (Multiple lines)";
                }
            } else {
                // Single phone number
                $phoneFieldValue = $phoneNumbers[0]['number'];
                if (!empty($phoneNumbers[0]['description']) && $phoneNumbers[0]['description'] !== $descriptionType) {
                    $combinedDescription = $phoneNumbers[0]['description'];
                }
            }
        } else {
            // No phone numbers provided
            $phoneFieldValue = "N/A";
            $combinedDescription .= " (Phone: Not Applicable)";
        }

        // Add email note to description if email is provided
        if (!empty($email)) {
            $combinedDescription .= " - Email available";
        }

        // Insert contact information with ONLY the ID of the item being created
        // Reset all IDs to NULL
        $division_id_insert = NULL;
        $department_id_insert = NULL;
        $unit_id_insert = NULL;
        $office_id_insert = NULL;

        // Only set the ID for the current create type (the new item's ID)
        switch ($createType) {
            case 'division':
                $division_id_insert = $newId; // The new division ID
                break;
            case 'department':
                $department_id_insert = $newId; // The new department ID
                break;
            case 'unit':
                $unit_id_insert = $newId; // The new unit ID
                break;
            case 'office':
                $office_id_insert = $newId; // The new office ID
                break;
        }

        // Insert contact information with ONLY the relevant ID field
        error_log("Inserting into numbers table with:");
        error_log("phoneFieldValue: " . $phoneFieldValue);
        error_log("emailFieldValue: " . $emailFieldValue);
        error_log("combinedDescription: " . $combinedDescription);
        error_log("headUserId: " . $headUserId);
        error_log("headFullName: " . $headFullName);
        error_log("division_id_insert: " . ($division_id_insert ?? 'NULL'));
        error_log("department_id_insert: " . ($department_id_insert ?? 'NULL'));
        error_log("unit_id_insert: " . ($unit_id_insert ?? 'NULL'));
        error_log("office_id_insert: " . ($office_id_insert ?? 'NULL'));

        $contactStmt = sqlsrv_prepare($conn, 
        "INSERT INTO numbers (numbers, email, description, head_user_id, head, division_id, department_id, unit_id, office_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", 
            array(
                $phoneFieldValue, 
                $emailFieldValue, 
                $combinedDescription, 
                $headUserId, 
                $headFullName,
                $division_id_insert,  // Only filled for divisions (new division ID)
                $department_id_insert, // Only filled for departments (new department ID)
                $unit_id_insert,       // Only filled for units (new unit ID)
                $office_id_insert      // Only filled for offices (new office ID)
            )
        );

        if (!$contactStmt) {
            error_log("Failed to prepare contact statement: " . print_r(sqlsrv_errors(), true));
            throw new Exception("Failed to prepare contact statement.");
        }

        if (!sqlsrv_execute($contactStmt)) {
            error_log("Failed to execute contact statement: " . print_r(sqlsrv_errors(), true));
            throw new Exception("Failed to save contact information: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($contactStmt);
        
        // Commit transaction
        sqlsrv_commit($conn);
        
        // Get the correct type name for the success message
        $typeNames = [
            'division' => 'Division',
            'department' => 'Department',
            'unit' => 'Unit',
            'office' => 'Office'
        ];
        
        $typeName = $typeNames[$createType] ?? 'Item';
        $response['success'] = true;
        $response['message'] = "$typeName '$name' created successfully!";
        $response['new_id'] = $newId;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn) sqlsrv_rollback($conn);
        $response['message'] = $e->getMessage();
    }
    
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Get initial heads for the dropdown (all active users)
$initialHeads = getHeadsByCreateType($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create New Entry</title>
<style>
/* All CSS remains the same as before, just adding a few styles for role display */
.role-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    margin-left: 8px;
    text-transform: uppercase;
}

.role-admin { background-color: #e53e3e; color: white; }
.role-mcc { background-color: #d69e2e; color: white; }
.role-division { background-color: #2b6cb0; color: white; }
.role-department { background-color: #38a169; color: white; }
.role-unit { background-color: #d69e2e; color: white; }
.role-office { background-color: #805ad5; color: white; }
.role-staff { background-color: #718096; color: white; }

/* Rest of the CSS remains exactly the same as before */
* { box-sizing:border-box; margin:0; padding:0; font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif; }
body { min-height:100vh; display:flex; flex-direction:column; background-color:#edf4fc; }

/* --- HEADER --- */
.header {
    position: fixed; top:0; left:0; width:100%; background-color:#07417f; color:white;
    padding:20px 30px; display:flex; justify-content:space-between; align-items:center;
    z-index:1000; box-shadow:0 4px 12px rgba(0,0,0,0.15); border-bottom:3px solid #2b6cb0;
}
.header .logo { display:flex; align-items:center; gap:15px; }
.header .logo img { width:55px; height:55px; object-fit:contain; }
.header .logo span { font-size:1.5rem; font-weight:700; color:white; text-shadow:0 1px 2px rgba(0,0,0,0.2); }
ul.nav { display:flex; list-style:none; gap:8px; }
ul.nav li a { display:block; color:white; text-decoration:none; padding:10px 18px; font-weight:600; border-radius:6px; transition:all 0.2s; }
ul.nav li a:hover { background-color:rgba(255,255,255,0.2); }

/* --- CONTENT --- */
.content { flex:1; margin-top:100px; padding:20px; }

/* --- FORM CONTAINER --- */
.big-container {
    background-color:white; padding:30px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.1);
    max-width:700px; margin:auto;
}
.big-container h2 { color:#2b6cb0; margin-bottom:20px; text-align:center; }

/* --- FORM --- */
.form-group { margin-bottom:18px; display:flex; flex-direction:column; gap:6px; }
.form-group input, .form-group select, .form-group textarea { padding:10px; border:1px solid #ced4da; border-radius:4px; font-size:14px; }
.form-group input[type="email"] { direction: ltr; }

.number-entry { 
    display:flex; 
    flex-direction: column;
    gap: 10px;
    margin-bottom:15px; 
    padding:15px; 
    background-color:#f8f9fa; 
    border-radius:6px; 
    border:1px solid #e9ecef; 
}
.number-input-row { 
    display: flex; 
    align-items: center; 
    gap: 10px; 
    width: 100%; 
}
.number-input-container { 
    flex: 1; 
    display: flex; 
    gap: 10px; 
}
.description-container {
    display: flex;
    gap: 10px;
    width: 100%;
}
.description-container input {
    flex: 1;
}
.remove-number-btn { 
    background-color:#dc3545; 
    color:white; 
    border:none; 
    border-radius:4px; 
    padding:8px 15px; 
    cursor:pointer; 
    font-size:14px; 
    align-self: flex-start;
}
.remove-number-btn:hover { background-color:#c82333; }
#add-number-btn { 
    background-color:#28a745; 
    color:white; 
    border:none; 
    border-radius:6px; 
    padding:10px 20px; 
    cursor:pointer; 
    font-size:16px; 
    margin-bottom:20px; 
    display:flex; 
    align-items:center; 
    gap:8px; 
}
#add-number-btn:hover { background-color:#218838; }
.form-actions { display:flex; gap:10px; margin-top:20px; }
.submit-btn { background-color:#2b6cb0; color:white; border:none; border-radius:6px; padding:12px 24px; cursor:pointer; font-size:16px; flex:1; }
.submit-btn:hover { background-color:#1f4f8b; }
.cancel-btn { background-color:#6c757d; color:white; border:none; border-radius:6px; padding:12px 24px; cursor:pointer; font-size:16px; flex:1; }
.cancel-btn:hover { background-color:#5a6268; }

/* Additional dropdown styling */
.under-dropdown { margin-top: 5px; }

/* Loading spinner for dropdowns */
.loading-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #3498db;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    margin-left: 10px;
    vertical-align: middle;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* --- MESSAGES --- */
.message { padding:12px; margin:15px 0; border-radius:6px; text-align:center; }
.success { background-color:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.error { background-color:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* --- FOOTER --- */
.footer { background-color:#07417f; color:#fff; text-align:center; padding:18px 10px; font-size:14px; margin-top:auto; }

/* --- RESPONSIVE --- */
@media (max-width:768px){
    .header { flex-direction:column; padding:15px; text-align:center; }
    .header .logo span { font-size:1.3rem; }
    .number-input-row { flex-direction: column; align-items: stretch; }
    .description-container { flex-direction: column; }
    .form-actions { flex-direction: column; }
}

/* Contact method requirement hint */
.contact-hint {
    font-size: 12px;
    color: #6c757d;
    margin-top: 4px;
    font-style: italic;
}

/* Notification dropdown styles from homepage.php */
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

/* Active link styling */
ul.nav li a.active {
    background-color: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.3);
}

/* Head type indicator */
.head-type-info {
    font-size: 12px;
    color: #38a169;
    font-style: italic;
    margin-top: 4px;
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
                <li><a href="createpage.php" class="active">Create page</a></li>
                <li><a href="editpage.php">Edit page</a></li>
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
    <div class="big-container">
        <h2>Create New Entry</h2>
        <div id="message-container"></div>
        <form method="POST" id="create-form">
            <div class="form-group">
                <label for="create_type">What would you like to create? *</label>
                <select name="create_type" id="create_type" required onchange="toggleUnderDropdowns()">
                    <option value="">Select Type</option>
                    <option value="division">Division</option>
                    <option value="department">Department</option>
                    <option value="unit">Unit</option>
                    <option value="office">Office</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="name" id="name-label">Name *</label>
                <input type="text" name="name" id="name" required>
            </div>
            
            <div class="form-group">
                <label for="head" id="head-label">Head *</label>
                <div style="display: flex; align-items: center;">
                    <select name="head" id="head" required style="flex: 1;">
                        <option value="">Select Custodian/Head</option>
                        <?php foreach($initialHeads as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>" data-role-id="<?php echo $user['role_id']; ?>">
                                <?php echo htmlspecialchars($user['full_name']) . ' (' . $user['username'] . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span id="head-loading" class="loading-spinner" style="display: none; margin-left: 10px;"></span>
                </div>
                <div id="head-type-info" class="head-type-info"></div>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" name="email" id="email" placeholder="e.g., department@hospital.com">
                <div class="contact-hint">Note: At least one contact method is required (email OR phone number). Multiple phone numbers will be combined in one field.</div>
            </div>
            
            <!-- Division dropdown (for department) -->
            <div class="form-group under-dropdown" id="under-division-group" style="display: none;">
                <label for="under_division">Under which Division? *</label>
                <div style="display: flex; align-items: center;">
                    <select name="under_division" id="under_division" style="flex: 1;" onchange="loadDepartments(this.value)">
                        <option value="">Select Division</option>
                        <?php foreach ($divisions as $division): ?>
                            <option value="<?php echo $division['division_id']; ?>"><?php echo htmlspecialchars($division['division_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span id="division-loading" class="loading-spinner" style="display: none;"></span>
                </div>
            </div>
            
            <!-- Department dropdown (for unit) -->
            <div class="form-group under-dropdown" id="under-department-group" style="display: none;">
                <label for="under_department">Under which Department? *</label>
                <div style="display: flex; align-items: center;">
                    <select name="under_department" id="under_department" style="flex: 1;" onchange="loadUnits(this.value)">
                        <option value="">Select Department</option>
                        <!-- Departments will be loaded via AJAX -->
                    </select>
                    <span id="department-loading" class="loading-spinner" style="display: none;"></span>
                </div>
            </div>
            
            <!-- Unit dropdown (for office) -->
            <div class="form-group under-dropdown" id="under-unit-group" style="display: none;">
                <label for="under_unit">Under which Unit? *</label>
                <div style="display: flex; align-items: center;">
                    <select name="under_unit" id="under_unit" style="flex: 1;">
                        <option value="">Select Unit</option>
                        <!-- Units will be loaded via AJAX -->
                    </select>
                    <span id="unit-loading" class="loading-spinner" style="display: none;"></span>
                </div>
            </div>
            
            <div class="form-group">
                <label>Contact Numbers</label>
                <button type="button" id="add-number-btn"><span>+</span> Add Contact Number</button>
                <div id="numbers-container"></div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="submit-btn" id="submit-btn">Create</button>
                <button type="button" class="cancel-btn" onclick="window.location.href='homepage.php'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="footer">
    © 2026 Intercom Directory. All rights reserved.<br>
    Developed by TNTS Programming Students JT.DP.RR
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const numbersContainer = document.getElementById('numbers-container');
    const addNumberBtn = document.getElementById('add-number-btn');
    const messageContainer = document.getElementById('message-container');
    const form = document.getElementById('create-form');
    const createTypeSelect = document.getElementById('create_type');
    const headSelect = document.getElementById('head');
    const submitBtn = document.getElementById('submit-btn');
    const headTypeInfo = document.getElementById('head-type-info');
    const underDivisionSelect = document.getElementById('under_division');
    let numberCounter = 0;
    
    // Notification dropdown functionality
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
            if(!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                notificationDropdown.style.opacity = '0';
                notificationDropdown.style.visibility = 'hidden';
                notificationDropdown.style.transform = 'translateY(-10px)';
            }
        });
    }
    
    // Initialize with one number entry
    numbersContainer.appendChild(createNumberEntry());
    
    function showMessage(message, type='error') {
        messageContainer.innerHTML = `<div class="message ${type}">${message}</div>`;
        if(type==='success') setTimeout(()=>{messageContainer.innerHTML='';},5000);
    }
    
    function createNumberEntry() {
        const entryId = numberCounter++;
        const div = document.createElement('div');
        div.className='number-entry';
        div.id=`number_${entryId}`;
        div.innerHTML=`
            <div class="number-input-row">
                <div class="number-input-container">
                    <input type="text" name="phone_numbers[]" placeholder="Enter contact number" style="flex:1; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                    <input type="text" name="phone_descriptions[]" placeholder="Description" style="flex:1; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                </div>
                <button type="button" class="remove-number-btn" onclick="removeNumberEntry('number_${entryId}')">Remove</button>
            </div>
        `;
        return div;
    }
    
    function updateFormLabels() {
        const createType = createTypeSelect.value;
        const nameLabel = document.getElementById('name-label');
        const headLabel = document.getElementById('head-label');
        const submitBtn = document.getElementById('submit-btn');
        
        const labels = {
            'division': { name: 'Name of Division', head: 'Head of Division', submit: 'Create Division' },
            'department': { name: 'Name of Department', head: 'Head of Department', submit: 'Create Department' },
            'unit': { name: 'Name of Unit', head: 'Head of Unit', submit: 'Create Unit' },
            'office': { name: 'Name of Office', head: 'Head of Office', submit: 'Create Office' }
        };
        
        if (labels[createType]) {
            nameLabel.textContent = labels[createType].name + ' *';
            headLabel.textContent = labels[createType].head + ' *';
            submitBtn.textContent = labels[createType].submit;
        } else {
            nameLabel.textContent = 'Name *';
            headLabel.textContent = 'Head *';
            submitBtn.textContent = 'Create';
        }
    }
    
    function updateHeadTypeInfo(createType) {
        const infoText = {
            'division': 'Note: Only users with role "Division Head" or higher can be selected.',
            'department': 'Note: You can choose from Division Heads or Department Heads.',
            'unit': 'Note: You can choose from Division Heads, Department Heads, or Unit Heads.',
            'office': 'Note: You can choose from Division Heads, Department Heads, Unit Heads, or Office Heads.'
        };
        
        if (infoText[createType]) {
            headTypeInfo.textContent = infoText[createType];
            headTypeInfo.style.display = 'block';
        } else {
            headTypeInfo.textContent = '';
            headTypeInfo.style.display = 'none';
        }
    }
    
    function loadHeads(createType) {
        const loadingElement = document.getElementById('head-loading');
        
        // Show loading
        if (loadingElement) {
            loadingElement.style.display = 'inline-block';
        }
        
        // Fetch heads via separate AJAX file
        fetch(`ajax_get_heads.php?type=${createType}`)
            .then(response => response.json())
            .then(data => {
                // Clear current options
                headSelect.innerHTML = '<option value="">Select Custodian/Head</option>';
                
                if (data.success && data.heads && data.heads.length > 0) {
                    data.heads.forEach(user => {
                        const option = document.createElement('option');
                        option.value = user.user_id;
                        
                        // Add role badge to display text
                        const roleClass = getRoleClass(user.role_id);
                        const roleName = user.role_name || getRoleName(user.role_id);
                        const displayText = `${user.full_name} (${user.username}) <span class="role-badge ${roleClass}">${roleName}</span>`;
                        
                        // Create a temporary div to set innerHTML and get text content
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = displayText;
                        option.textContent = tempDiv.textContent;
                        option.innerHTML = displayText; // Set HTML for display
                        
                        // Store role_id as data attribute
                        option.setAttribute('data-role-id', user.role_id);
                        
                        headSelect.appendChild(option);
                    });
                } else {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = 'No eligible heads found';
                    headSelect.appendChild(option);
                }
                
                updateHeadTypeInfo(createType);
            })
            .catch(error => {
                console.error('Error loading heads:', error);
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'Error loading heads';
                headSelect.appendChild(option);
            })
            .finally(() => {
                if (loadingElement) {
                    loadingElement.style.display = 'none';
                }
            });
    }
    
    function getRoleClass(roleId) {
        const roleClasses = {
            1: 'role-admin',
            2: 'role-mcc', 
            3: 'role-division',
            4: 'role-department',
            5: 'role-unit',
            6: 'role-office',
            7: 'role-staff'
        };
        return roleClasses[roleId] || 'role-staff';
    }
    
    function getRoleName(roleId) {
        const roleNames = {
            1: 'Admin',
            2: 'MCC',
            3: 'Division Head',
            4: 'Department Head',
            5: 'Unit Head',
            6: 'Office Head',
            7: 'Staff'
        };
        return roleNames[roleId] || 'Unknown';
    }
    
    // AJAX function to load departments
    function loadDepartments(divisionId) {
        const departmentSelect = document.getElementById('under_department');
        const unitSelect = document.getElementById('under_unit');
        const loadingElement = document.getElementById('department-loading');
        
        // Clear dependent dropdowns
        departmentSelect.innerHTML = '<option value="">Select Department</option>';
        unitSelect.innerHTML = '<option value="">Select Unit</option>';
        
        if (!divisionId) return;
        
        loadingElement.style.display = 'inline-block';
        
        // Use the separate AJAX file
        fetch(`ajax_get_departments.php?division_id=${divisionId}`)
            .then(response => response.text())
            .then(html => {
                departmentSelect.innerHTML = html;
            })
            .catch(error => {
                console.error('Error loading departments:', error);
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'Error loading departments';
                departmentSelect.appendChild(option);
            })
            .finally(() => {
                loadingElement.style.display = 'none';
            });
    }

    // AJAX function to load units
    function loadUnits(departmentId) {
        const unitSelect = document.getElementById('under_unit');
        const loadingElement = document.getElementById('unit-loading');
        
        // Clear unit dropdown
        unitSelect.innerHTML = '<option value="">Select Unit</option>';
        
        if (!departmentId) return;
        
        loadingElement.style.display = 'inline-block';
        
        // Use the separate AJAX file
        fetch(`ajax_get_units.php?department_id=${departmentId}`)
            .then(response => response.text())
            .then(html => {
                unitSelect.innerHTML = html;
            })
            .catch(error => {
                console.error('Error loading units:', error);
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'Error loading units';
                unitSelect.appendChild(option);
            })
            .finally(() => {
                loadingElement.style.display = 'none';
            });
    }
    
    function toggleUnderDropdowns() {
        const createType = createTypeSelect.value;
        const underDivisionGroup = document.getElementById('under-division-group');
        const underDepartmentGroup = document.getElementById('under-department-group');
        const underUnitGroup = document.getElementById('under-unit-group');
        
        // Reset all dropdowns
        underDivisionGroup.style.display = 'none';
        underDepartmentGroup.style.display = 'none';
        underUnitGroup.style.display = 'none';
        
        // Clear dropdowns
        document.getElementById('under_department').innerHTML = '<option value="">Select Department</option>';
        document.getElementById('under_unit').innerHTML = '<option value="">Select Unit</option>';
        
        // Clear required attributes
        document.getElementById('under_division').required = false;
        document.getElementById('under_department').required = false;
        document.getElementById('under_unit').required = false;
        
        // Show appropriate dropdown and load heads
        switch(createType) {
            case 'division':
                // Load division heads only
                loadHeads('division');
                break;
                
            case 'department':
                underDivisionGroup.style.display = 'flex';
                document.getElementById('under_division').required = true;
                // Load heads for department creation
                loadHeads('department');
                break;
                
            case 'unit':
                underDivisionGroup.style.display = 'flex';
                underDepartmentGroup.style.display = 'flex';
                document.getElementById('under_division').required = true;
                document.getElementById('under_department').required = true;
                // Load heads for unit creation
                loadHeads('unit');
                break;
                
            case 'office':
                underDivisionGroup.style.display = 'flex';
                underDepartmentGroup.style.display = 'flex';
                underUnitGroup.style.display = 'flex';
                document.getElementById('under_division').required = true;
                document.getElementById('under_department').required = true;
                document.getElementById('under_unit').required = true;
                // Load heads for office creation
                loadHeads('office');
                break;
        }
        
        updateFormLabels();
        updateHeadTypeInfo(createType);
    }
    
    // Event listeners
    createTypeSelect.addEventListener('change', toggleUnderDropdowns);
    
    // Add event listener for division dropdown change
    if (underDivisionSelect) {
        underDivisionSelect.addEventListener('change', function() {
            const divisionId = this.value;
            const createType = createTypeSelect.value;
            
            if (divisionId) {
                loadDepartments(divisionId);
                
                // Clear dependent dropdowns
                document.getElementById('under_department').innerHTML = '<option value="">Select Department</option>';
                document.getElementById('under_unit').innerHTML = '<option value="">Select Unit</option>';
            }
        });
    }
    
    // Add event listener for department dropdown change
    const underDepartmentSelect = document.getElementById('under_department');
    if (underDepartmentSelect) {
        underDepartmentSelect.addEventListener('change', function() {
            const departmentId = this.value;
            
            if (departmentId) {
                loadUnits(departmentId);
                
                // Clear unit dropdown
                document.getElementById('under_unit').innerHTML = '<option value="">Select Unit</option>';
            }
        });
    }
    
    addNumberBtn.addEventListener('click', ()=>numbersContainer.appendChild(createNumberEntry()));
    
    form.addEventListener('submit', function(e){
        e.preventDefault();
        messageContainer.innerHTML='';
        
        const createType = createTypeSelect.value;
        const name = document.getElementById('name').value.trim();
        const head = document.getElementById('head').value;
        const email = document.getElementById('email').value.trim();
        
        if(!createType){ showMessage('Please select what to create.'); return; }
        if(!name){ showMessage('Name is required.'); return; }
        if(!head){ showMessage('Head is required.'); return; }
        
        // Validate email if provided
        if (email && !isValidEmail(email)) {
            showMessage('Please enter a valid email address.');
            return;
        }
        
        // Validate under dropdowns based on type
        if(createType === 'department') {
            const underDivision = document.getElementById('under_division').value;
            if(!underDivision){ showMessage('Please select a division.'); return; }
        } else if(createType === 'unit') {
            const underDivision = document.getElementById('under_division').value;
            const underDepartment = document.getElementById('under_department').value;
            if(!underDivision){ showMessage('Please select a division.'); return; }
            if(!underDepartment){ showMessage('Please select a department.'); return; }
        } else if(createType === 'office') {
            const underDivision = document.getElementById('under_division').value;
            const underDepartment = document.getElementById('under_department').value;
            const underUnit = document.getElementById('under_unit').value;
            if(!underDivision){ showMessage('Please select a division.'); return; }
            if(!underDepartment){ showMessage('Please select a department.'); return; }
            if(!underUnit){ showMessage('Please select a unit.'); return; }
        }
        
        // Check if we have at least one contact method
        const numberEntries = document.querySelectorAll('.number-entry');
        let hasPhoneNumber = false;
        
        numberEntries.forEach((entry) => {
            const phoneInput = entry.querySelector('input[placeholder*="contact number"]').value.trim();
            if(phoneInput){
                hasPhoneNumber = true;
            }
        });
        
        if(!hasPhoneNumber && !email) {
            showMessage('Please provide at least one contact method (email address or contact number).');
            return;
        }
        
        const formData = new FormData();
        formData.append('create_type', createType);
        formData.append('name', name);
        formData.append('head', head);
        formData.append('email', email);

        console.log("Form data being sent:");
        for (let pair of formData.entries()) {
            console.log(pair[0] + ': ' + pair[1]);
        }
        
        if(createType === 'department') {
            formData.append('under_division', document.getElementById('under_division').value);
        } else if(createType === 'unit') {
            formData.append('under_division', document.getElementById('under_division').value);
            formData.append('under_department', document.getElementById('under_department').value);
        } else if(createType === 'office') {
            formData.append('under_division', document.getElementById('under_division').value);
            formData.append('under_department', document.getElementById('under_department').value);
            formData.append('under_unit', document.getElementById('under_unit').value);
        }
        
        // Collect phone numbers
        numberEntries.forEach((entry) => {
            const phoneInput = entry.querySelector('input[placeholder*="contact number"]');
            const descInput = entry.querySelector('input[placeholder*="Description"]');
            const phoneValue = phoneInput.value.trim();
            const descValue = descInput.value.trim();
            
            if(phoneValue){
                formData.append('phone_numbers[]', phoneValue);
                formData.append('phone_descriptions[]', descValue);
            }
        });
        
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'Creating...';
        submitBtn.disabled = true;
        
        fetch('', { 
            method: 'POST', 
            body: formData 
        })
        .then(res => res.json())
        .then(data => {
            if(data.success){
                showMessage(data.message, 'success');
                // Reset form but keep the create type
                document.getElementById('name').value = '';
                document.getElementById('head').value = '';
                document.getElementById('email').value = '';
                document.getElementById('under_division').value = '';
                document.getElementById('under_department').innerHTML = '<option value="">Select Department</option>';
                document.getElementById('under_unit').innerHTML = '<option value="">Select Unit</option>';
                
                numbersContainer.innerHTML = '';
                numbersContainer.appendChild(createNumberEntry());
                numberCounter = 1;
                
                // Reload heads for current create type
                const currentType = createTypeSelect.value;
                loadHeads(currentType);
            } else {
                showMessage(data.message, 'error');
            }
        })
        .catch(() => showMessage('An error occurred. Please try again.', 'error'))
        .finally(() => {
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        });
    });
    
    // Initialize labels and info
    updateFormLabels();
    updateHeadTypeInfo('');
});

function removeNumberEntry(entryId){
    const entry = document.getElementById(entryId);
    if(entry && document.querySelectorAll('.number-entry').length > 1) {
        entry.remove();
    } else {
        alert('You must have at least one contact number field. You can leave it empty if you only want to use email.');
    }
}

function isValidEmail(email) {
    const re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
    return re.test(String(email).toLowerCase());
}
</script>
</body>
</html>