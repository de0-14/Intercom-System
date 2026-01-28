<?php
require_once 'conn.php';
updateAllUsersActivity($conn);

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$edit_mode = false;
$edit_org_mode = '';
$add_mode = false; // New mode for adding numbers
$current_item = null;
$current_org_item = null;

$active_section = isset($_GET['section']) ? $_GET['section'] : 'contacts';

// Handle delete contact number
if (isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];
    
    $delete_stmt = $conn->prepare("DELETE FROM numbers WHERE number_id = ?");
    $delete_stmt->bind_param("i", $delete_id);
    
    if ($delete_stmt->execute()) {
        $success = "Contact number deleted successfully!";
    } else {
        $error = "Failed to delete contact number: " . $delete_stmt->error;
    }
    $delete_stmt->close();
}

// Handle delete division
if (isset($_POST['delete_division'])) {
    $division_id = (int)$_POST['delete_division'];
    
    try {
        $conn->begin_transaction();
        
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM departments WHERE division_id = ?");
        $check_stmt->bind_param("i", $division_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $row = $result->fetch_assoc();
        $check_stmt->close();
        
        if ($row['count'] > 0) {
            throw new Exception("Cannot delete division. It contains departments.");
        }
        
        $delete_numbers = $conn->prepare("DELETE FROM numbers WHERE division_id = ?");
        $delete_numbers->bind_param("i", $division_id);
        $delete_numbers->execute();
        $delete_numbers->close();
        
        $delete_stmt = $conn->prepare("DELETE FROM divisions WHERE division_id = ?");
        $delete_stmt->bind_param("i", $division_id);
        
        if ($delete_stmt->execute()) {
            $conn->commit();
            $success = "Division deleted successfully!";
        } else {
            throw new Exception("Failed to delete division: " . $delete_stmt->error);
        }
        $delete_stmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
}

// Handle delete department
if (isset($_POST['delete_department'])) {
    $department_id = (int)$_POST['delete_department'];
    
    try {
        $conn->begin_transaction();
        
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM units WHERE department_id = ?");
        $check_stmt->bind_param("i", $department_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $row = $result->fetch_assoc();
        $check_stmt->close();
        
        if ($row['count'] > 0) {
            throw new Exception("Cannot delete department. It contains units.");
        }
        
        $delete_numbers = $conn->prepare("DELETE FROM numbers WHERE department_id = ?");
        $delete_numbers->bind_param("i", $department_id);
        $delete_numbers->execute();
        $delete_numbers->close();
        
        $delete_stmt = $conn->prepare("DELETE FROM departments WHERE department_id = ?");
        $delete_stmt->bind_param("i", $department_id);
        
        if ($delete_stmt->execute()) {
            $conn->commit();
            $success = "Department deleted successfully!";
        } else {
            throw new Exception("Failed to delete department: " . $delete_stmt->error);
        }
        $delete_stmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
}

// Handle delete unit
if (isset($_POST['delete_unit'])) {
    $unit_id = (int)$_POST['delete_unit'];
    
    try {
        $conn->begin_transaction();
        
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM offices WHERE unit_id = ?");
        $check_stmt->bind_param("i", $unit_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $row = $result->fetch_assoc();
        $check_stmt->close();
        
        if ($row['count'] > 0) {
            throw new Exception("Cannot delete unit. It contains offices.");
        }
        
        $delete_numbers = $conn->prepare("DELETE FROM numbers WHERE unit_id = ?");
        $delete_numbers->bind_param("i", $unit_id);
        $delete_numbers->execute();
        $delete_numbers->close();
        
        $delete_stmt = $conn->prepare("DELETE FROM units WHERE unit_id = ?");
        $delete_stmt->bind_param("i", $unit_id);
        
        if ($delete_stmt->execute()) {
            $conn->commit();
            $success = "Unit deleted successfully!";
        } else {
            throw new Exception("Failed to delete unit: " . $delete_stmt->error);
        }
        $delete_stmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
}

// Handle delete office
if (isset($_POST['delete_office'])) {
    $office_id = (int)$_POST['delete_office'];
    
    try {
        $conn->begin_transaction();
        
        $delete_numbers = $conn->prepare("DELETE FROM numbers WHERE office_id = ?");
        $delete_numbers->bind_param("i", $office_id);
        $delete_numbers->execute();
        $delete_numbers->close();
        
        $delete_stmt = $conn->prepare("DELETE FROM offices WHERE office_id = ?");
        $delete_stmt->bind_param("i", $office_id);
        
        if ($delete_stmt->execute()) {
            $conn->commit();
            $success = "Office deleted successfully!";
        } else {
            throw new Exception("Failed to delete office: " . $delete_stmt->error);
        }
        $delete_stmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
}

// Handle edit contact number (with status)
if (isset($_POST['save_edit'])) {
    $edit_id = (int)$_POST['edit_id'];
    $new_number = trim($_POST['new_number']);
    $new_description = trim($_POST['new_description']);
    $new_head = trim($_POST['new_head']);
    $new_status = trim($_POST['new_status']); // Added status field
    $new_division_id = !empty($_POST['new_division_id']) ? (int)$_POST['new_division_id'] : NULL;
    $new_department_id = !empty($_POST['new_department_id']) ? (int)$_POST['new_department_id'] : NULL;
    $new_unit_id = !empty($_POST['new_unit_id']) ? (int)$_POST['new_unit_id'] : NULL;
    $new_office_id = !empty($_POST['new_office_id']) ? (int)$_POST['new_office_id'] : NULL;
    
    $update_stmt = $conn->prepare("UPDATE numbers SET numbers = ?, description = ?, head = ?, status = ?, division_id = ?, department_id = ?, unit_id = ?, office_id = ? WHERE number_id = ?");
    $update_stmt->bind_param("ssssiiiii", $new_number, $new_description, $new_head, $new_status, $new_division_id, $new_department_id, $new_unit_id, $new_office_id, $edit_id);
    
    if ($update_stmt->execute()) {
        $success = "Contact number updated successfully!";
        $edit_mode = false;
        header("Location: editpage.php?section=$active_section&success=" . urlencode($success));
        exit;
    } else {
        $error = "Failed to update contact number: " . $update_stmt->error;
    }
    $update_stmt->close();
}

// Handle add new number to existing organization
if (isset($_POST['add_new_number'])) {
    $new_number = trim($_POST['new_number']);
    $new_description = trim($_POST['new_description']);
    $new_head = trim($_POST['new_head']);
    $new_status = trim($_POST['new_status']);
    $new_division_id = !empty($_POST['new_division_id']) ? (int)$_POST['new_division_id'] : NULL;
    $new_department_id = !empty($_POST['new_department_id']) ? (int)$_POST['new_department_id'] : NULL;
    $new_unit_id = !empty($_POST['new_unit_id']) ? (int)$_POST['new_unit_id'] : NULL;
    $new_office_id = !empty($_POST['new_office_id']) ? (int)$_POST['new_office_id'] : NULL;
    
    // Validate that at least one organization is selected
    if (!$new_division_id && !$new_department_id && !$new_unit_id && !$new_office_id) {
        $error = "Please select at least one organization (Division, Department, Unit, or Office).";
    } else {
        $insert_stmt = $conn->prepare("INSERT INTO numbers (numbers, description, head, status, division_id, department_id, unit_id, office_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("ssssiiii", $new_number, $new_description, $new_head, $new_status, $new_division_id, $new_department_id, $new_unit_id, $new_office_id);
        
        if ($insert_stmt->execute()) {
            $success = "New contact number added successfully!";
            $add_mode = false;
            header("Location: editpage.php?section=$active_section&success=" . urlencode($success));
            exit;
        } else {
            $error = "Failed to add new contact number: " . $insert_stmt->error;
        }
        $insert_stmt->close();
    }
}

// Handle update division
if (isset($_POST['update_division'])) {
    $division_id = (int)$_POST['division_id'];
    $division_name = trim($_POST['division_name']);
    $division_head = trim($_POST['division_head']);
    $division_status = $_POST['division_status'];
    
    try {
        $conn->begin_transaction();
        
        // Update the division
        $update_stmt = $conn->prepare("UPDATE divisions SET division_name = ?, status = ? WHERE division_id = ?");
        $update_stmt->bind_param("ssi", $division_name, $division_status, $division_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update division: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        // Also update the head in the associated numbers
        if (!empty($division_head)) {
            $update_head_stmt = $conn->prepare("UPDATE numbers SET head = ? WHERE division_id = ?");
            $update_head_stmt->bind_param("si", $division_head, $division_id);
            if (!$update_head_stmt->execute()) {
                throw new Exception("Failed to update division head: " . $update_head_stmt->error);
            }
            $update_head_stmt->close();
        }
        
        // If decommissioning, cascade to all child organizations and their numbers
        if ($division_status === 'decommissioned') {
            // Update all numbers under this division
            $update_numbers = $conn->prepare("UPDATE numbers SET status = 'decommissioned' WHERE division_id = ?");
            $update_numbers->bind_param("i", $division_id);
            if (!$update_numbers->execute()) {
                throw new Exception("Failed to update numbers: " . $update_numbers->error);
            }
            $update_numbers->close();
            
            // Update all departments under this division
            $update_depts = $conn->prepare("UPDATE departments SET status = 'decommissioned' WHERE division_id = ?");
            $update_depts->bind_param("i", $division_id);
            if (!$update_depts->execute()) {
                throw new Exception("Failed to update departments: " . $update_depts->error);
            }
            $update_depts->close();
            
            // Get all departments under this division to cascade to units
            $dept_ids_stmt = $conn->prepare("SELECT department_id FROM departments WHERE division_id = ?");
            $dept_ids_stmt->bind_param("i", $division_id);
            $dept_ids_stmt->execute();
            $dept_ids_result = $dept_ids_stmt->get_result();
            $dept_ids_stmt->close();
            
            $dept_ids = [];
            while ($dept = $dept_ids_result->fetch_assoc()) {
                $dept_ids[] = $dept['department_id'];
            }
            
            if (!empty($dept_ids)) {
                // Update all units under these departments
                $placeholders = implode(',', array_fill(0, count($dept_ids), '?'));
                $update_units = $conn->prepare("UPDATE units SET status = 'decommissioned' WHERE department_id IN ($placeholders)");
                $types = str_repeat('i', count($dept_ids));
                $update_units->bind_param($types, ...$dept_ids);
                if (!$update_units->execute()) {
                    throw new Exception("Failed to update units: " . $update_units->error);
                }
                $update_units->close();
                
                // Get all units under these departments to cascade to offices
                $unit_ids_stmt = $conn->prepare("SELECT unit_id FROM units WHERE department_id IN ($placeholders)");
                $unit_ids_stmt->bind_param($types, ...$dept_ids);
                $unit_ids_stmt->execute();
                $unit_ids_result = $unit_ids_stmt->get_result();
                $unit_ids_stmt->close();
                
                $unit_ids = [];
                while ($unit = $unit_ids_result->fetch_assoc()) {
                    $unit_ids[] = $unit['unit_id'];
                }
                
                if (!empty($unit_ids)) {
                    // Update all offices under these units
                    $office_placeholders = implode(',', array_fill(0, count($unit_ids), '?'));
                    $update_offices = $conn->prepare("UPDATE offices SET status = 'decommissioned' WHERE unit_id IN ($office_placeholders)");
                    $office_types = str_repeat('i', count($unit_ids));
                    $update_offices->bind_param($office_types, ...$unit_ids);
                    if (!$update_offices->execute()) {
                        throw new Exception("Failed to update offices: " . $update_offices->error);
                    }
                    $update_offices->close();
                    
                    // Update all numbers under these offices
                    $update_office_numbers = $conn->prepare("UPDATE numbers SET status = 'decommissioned' WHERE office_id IN ($office_placeholders)");
                    $update_office_numbers->bind_param($office_types, ...$unit_ids);
                    if (!$update_office_numbers->execute()) {
                        throw new Exception("Failed to update office numbers: " . $update_office_numbers->error);
                    }
                    $update_office_numbers->close();
                }
            }
        }
        
        $conn->commit();
        $success = "Division updated successfully!" . ($division_status === 'decommissioned' ? " All child organizations and their numbers have been decommissioned." : "");
        $edit_org_mode = '';
        header("Location: editpage.php?section=divisions&success=" . urlencode($success));
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
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
        $conn->begin_transaction();
        
        $update_stmt = $conn->prepare("UPDATE departments SET department_name = ?, division_id = ?, status = ? WHERE department_id = ?");
        $update_stmt->bind_param("sisi", $department_name, $division_id, $department_status, $department_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update department: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        if (!empty($department_head)) {
            $update_head_stmt = $conn->prepare("UPDATE numbers SET head = ? WHERE department_id = ?");
            $update_head_stmt->bind_param("si", $department_head, $department_id);
            if (!$update_head_stmt->execute()) {
                throw new Exception("Failed to update department head: " . $update_head_stmt->error);
            }
            $update_head_stmt->close();
        }
        
        // If decommissioning, cascade to all child units, offices, and their numbers
        if ($department_status === 'decommissioned') {
            // Update all numbers under this department
            $update_numbers = $conn->prepare("UPDATE numbers SET status = 'decommissioned' WHERE department_id = ?");
            $update_numbers->bind_param("i", $department_id);
            if (!$update_numbers->execute()) {
                throw new Exception("Failed to update numbers: " . $update_numbers->error);
            }
            $update_numbers->close();
            
            // Update all units under this department
            $update_units = $conn->prepare("UPDATE units SET status = 'decommissioned' WHERE department_id = ?");
            $update_units->bind_param("i", $department_id);
            if (!$update_units->execute()) {
                throw new Exception("Failed to update units: " . $update_units->error);
            }
            $update_units->close();
            
            // Get all units under this department to cascade to offices
            $unit_ids_stmt = $conn->prepare("SELECT unit_id FROM units WHERE department_id = ?");
            $unit_ids_stmt->bind_param("i", $department_id);
            $unit_ids_stmt->execute();
            $unit_ids_result = $unit_ids_stmt->get_result();
            $unit_ids_stmt->close();
            
            $unit_ids = [];
            while ($unit = $unit_ids_result->fetch_assoc()) {
                $unit_ids[] = $unit['unit_id'];
            }
            
            if (!empty($unit_ids)) {
                // Update all offices under these units
                $placeholders = implode(',', array_fill(0, count($unit_ids), '?'));
                $update_offices = $conn->prepare("UPDATE offices SET status = 'decommissioned' WHERE unit_id IN ($placeholders)");
                $types = str_repeat('i', count($unit_ids));
                $update_offices->bind_param($types, ...$unit_ids);
                if (!$update_offices->execute()) {
                    throw new Exception("Failed to update offices: " . $update_offices->error);
                }
                $update_offices->close();
                
                // Update all numbers under these offices
                $update_office_numbers = $conn->prepare("UPDATE numbers SET status = 'decommissioned' WHERE office_id IN ($placeholders)");
                $update_office_numbers->bind_param($types, ...$unit_ids);
                if (!$update_office_numbers->execute()) {
                    throw new Exception("Failed to update office numbers: " . $update_office_numbers->error);
                }
                $update_office_numbers->close();
            }
        }
        
        $conn->commit();
        $success = "Department updated successfully!" . ($department_status === 'decommissioned' ? " All child units, offices, and their numbers have been decommissioned." : "");
        $edit_org_mode = '';
        header("Location: editpage.php?section=departments&success=" . urlencode($success));
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
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
        $conn->begin_transaction();
        
        $update_stmt = $conn->prepare("UPDATE units SET unit_name = ?, department_id = ?, status = ? WHERE unit_id = ?");
        $update_stmt->bind_param("sisi", $unit_name, $department_id, $unit_status, $unit_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update unit: " . $update_stmt->error);
        }
        $update_stmt->close();
        
        if (!empty($unit_head)) {
            $update_head_stmt = $conn->prepare("UPDATE numbers SET head = ? WHERE unit_id = ?");
            $update_head_stmt->bind_param("si", $unit_head, $unit_id);
            if (!$update_head_stmt->execute()) {
                throw new Exception("Failed to update unit head: " . $update_head_stmt->error);
            }
            $update_head_stmt->close();
        }
        
        // If decommissioning, cascade to all child offices and their numbers
        if ($unit_status === 'decommissioned') {
            // Update all numbers under this unit
            $update_numbers = $conn->prepare("UPDATE numbers SET status = 'decommissioned' WHERE unit_id = ?");
            $update_numbers->bind_param("i", $unit_id);
            if (!$update_numbers->execute()) {
                throw new Exception("Failed to update numbers: " . $update_numbers->error);
            }
            $update_numbers->close();
            
            // Update all offices under this unit
            $update_offices = $conn->prepare("UPDATE offices SET status = 'decommissioned' WHERE unit_id = ?");
            $update_offices->bind_param("i", $unit_id);
            if (!$update_offices->execute()) {
                throw new Exception("Failed to update offices: " . $update_offices->error);
            }
            $update_offices->close();
            
            // Update all numbers under offices in this unit
            $update_office_numbers = $conn->prepare("UPDATE numbers n JOIN offices o ON n.office_id = o.office_id SET n.status = 'decommissioned' WHERE o.unit_id = ?");
            $update_office_numbers->bind_param("i", $unit_id);
            if (!$update_office_numbers->execute()) {
                throw new Exception("Failed to update office numbers: " . $update_office_numbers->error);
            }
            $update_office_numbers->close();
        }
        
        $conn->commit();
        $success = "Unit updated successfully!" . ($unit_status === 'decommissioned' ? " All child offices and their numbers have been decommissioned." : "");
        $edit_org_mode = '';
        header("Location: editpage.php?section=units&success=" . urlencode($success));
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
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
    
    $update_stmt = $conn->prepare("UPDATE offices SET office_name = ?, unit_id = ?, status = ? WHERE office_id = ?");
    $update_stmt->bind_param("sisi", $office_name, $unit_id, $office_status, $office_id);
    
    if ($update_stmt->execute()) {
        if (!empty($office_head)) {
            $update_head_stmt = $conn->prepare("UPDATE numbers SET head = ? WHERE office_id = ?");
            $update_head_stmt->bind_param("si", $office_head, $office_id);
            $update_head_stmt->execute();
            $update_head_stmt->close();
        }
        $success = "Office updated successfully!";
        $edit_org_mode = '';
        header("Location: editpage.php?section=offices&success=" . urlencode($success));
        exit;
    } else {
        $error = "Failed to update office: " . $update_stmt->error;
    }
    $update_stmt->close();
}

// Handle edit mode for contact number
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    
    $stmt = $conn->prepare("SELECT * FROM numbers WHERE number_id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $current_item = $result->fetch_assoc();
        $edit_mode = true;
    } else {
        $error = "Contact not found!";
    }
    $stmt->close();
}

// Handle add mode for new number
if (isset($_GET['add_number'])) {
    $add_mode = true;
    
    // Pre-select organization if provided in URL
    if (isset($_GET['division_id'])) {
        $_POST['new_division_id'] = (int)$_GET['division_id'];
    }
    if (isset($_GET['department_id'])) {
        $_POST['new_department_id'] = (int)$_GET['department_id'];
    }
    if (isset($_GET['unit_id'])) {
        $_POST['new_unit_id'] = (int)$_GET['unit_id'];
    }
    if (isset($_GET['office_id'])) {
        $_POST['new_office_id'] = (int)$_GET['office_id'];
    }
}

// Handle edit division
if (isset($_GET['edit_division'])) {
    $division_id = (int)$_GET['edit_division'];
    
    $stmt = $conn->prepare("SELECT * FROM divisions WHERE division_id = ?");
    $stmt->bind_param("i", $division_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $current_org_item = $result->fetch_assoc();
        $edit_org_mode = 'division';
    } else {
        $error = "Division not found!";
    }
    $stmt->close();
}

// Handle edit department
if (isset($_GET['edit_department'])) {
    $department_id = (int)$_GET['edit_department'];
    
    $stmt = $conn->prepare("SELECT * FROM departments WHERE department_id = ?");
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $current_org_item = $result->fetch_assoc();
        $edit_org_mode = 'department';
    } else {
        $error = "Department not found!";
    }
    $stmt->close();
}

// Handle edit unit
if (isset($_GET['edit_unit'])) {
    $unit_id = (int)$_GET['edit_unit'];
    
    $stmt = $conn->prepare("SELECT * FROM units WHERE unit_id = ?");
    $stmt->bind_param("i", $unit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $current_org_item = $result->fetch_assoc();
        $edit_org_mode = 'unit';
    } else {
        $error = "Unit not found!";
    }
    $stmt->close();
}

// Handle edit office
if (isset($_GET['edit_office'])) {
    $office_id = (int)$_GET['edit_office'];
    
    $stmt = $conn->prepare("SELECT * FROM offices WHERE office_id = ?");
    $stmt->bind_param("i", $office_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $current_org_item = $result->fetch_assoc();
        $edit_org_mode = 'office';
    } else {
        $error = "Office not found!";
    }
    $stmt->close();
}

if (isset($_GET['cancel'])) {
    $edit_mode = false;
    $edit_org_mode = '';
    $add_mode = false;
}

// Fetch all contact numbers
$query = "SELECT 
    n.*,
    d.division_name,
    dept.department_name,
    u.unit_name,
    o.office_name
FROM numbers n
LEFT JOIN divisions d ON n.division_id = d.division_id
LEFT JOIN departments dept ON n.department_id = dept.department_id
LEFT JOIN units u ON n.unit_id = u.unit_id
LEFT JOIN offices o ON n.office_id = o.office_id
ORDER BY n.number_id DESC";

$result = $conn->query($query);
$numbers = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $numbers[] = $row;
    }
}

// Fetch all organizational units for dropdowns - FIXED QUERIES
$divisions_result = $conn->query("SELECT dv.* FROM divisions dv ORDER BY division_name");
$divisions_list = $divisions_result->fetch_all(MYSQLI_ASSOC);

// For divisions, get the most recent head from numbers
foreach ($divisions_list as &$division) {
    $head_stmt = $conn->prepare("SELECT head FROM numbers WHERE division_id = ? ORDER BY number_id DESC LIMIT 1");
    $head_stmt->bind_param("i", $division['division_id']);
    $head_stmt->execute();
    $head_result = $head_stmt->get_result();
    if ($head_row = $head_result->fetch_assoc()) {
        $division['head'] = $head_row['head'];
    } else {
        $division['head'] = 'Not assigned';
    }
    $head_stmt->close();
}

$departments_result = $conn->query("SELECT d.*, dv.division_name FROM departments d LEFT JOIN divisions dv ON d.division_id = dv.division_id ORDER BY d.department_name");
$departments_list = $departments_result->fetch_all(MYSQLI_ASSOC);

// For departments, get the most recent head from numbers
foreach ($departments_list as &$department) {
    $head_stmt = $conn->prepare("SELECT head FROM numbers WHERE department_id = ? ORDER BY number_id DESC LIMIT 1");
    $head_stmt->bind_param("i", $department['department_id']);
    $head_stmt->execute();
    $head_result = $head_stmt->get_result();
    if ($head_row = $head_result->fetch_assoc()) {
        $department['head'] = $head_row['head'];
    } else {
        $department['head'] = 'Not assigned';
    }
    $head_stmt->close();
}

$units_result = $conn->query("SELECT u.*, d.department_name, dv.division_name FROM units u LEFT JOIN departments d ON u.department_id = d.department_id LEFT JOIN divisions dv ON d.division_id = dv.division_id ORDER BY u.unit_name");
$units_list = $units_result->fetch_all(MYSQLI_ASSOC);

// For units, get the most recent head from numbers
foreach ($units_list as &$unit) {
    $head_stmt = $conn->prepare("SELECT head FROM numbers WHERE unit_id = ? ORDER BY number_id DESC LIMIT 1");
    $head_stmt->bind_param("i", $unit['unit_id']);
    $head_stmt->execute();
    $head_result = $head_stmt->get_result();
    if ($head_row = $head_result->fetch_assoc()) {
        $unit['head'] = $head_row['head'];
    } else {
        $unit['head'] = 'Not assigned';
    }
    $head_stmt->close();
}

$offices_result = $conn->query("SELECT o.*, u.unit_name, d.department_name, dv.division_name FROM offices o LEFT JOIN units u ON o.unit_id = u.unit_id LEFT JOIN departments d ON u.department_id = d.department_id LEFT JOIN divisions dv ON d.division_id = dv.division_id ORDER BY o.office_name");
$offices_list = $offices_result->fetch_all(MYSQLI_ASSOC);

// For offices, get the most recent head from numbers
foreach ($offices_list as &$office) {
    $head_stmt = $conn->prepare("SELECT head FROM numbers WHERE office_id = ? ORDER BY number_id DESC LIMIT 1");
    $head_stmt->bind_param("i", $office['office_id']);
    $head_stmt->execute();
    $head_result = $head_stmt->get_result();
    if ($head_row = $head_result->fetch_assoc()) {
        $office['head'] = $head_row['head'];
    } else {
        $office['head'] = 'Not assigned';
    }
    $head_stmt->close();
}   

// Fetch for dropdowns in forms
$all_divisions = getDivisions($conn, false);
$all_departments = getDepartments($conn, false);
$all_units = getUnits($conn, false);
$offices_result2 = $conn->query("SELECT * FROM offices ORDER BY office_name");
$offices = $offices_result2->fetch_all(MYSQLI_ASSOC);

// Fetch all users' full names for dropdown
$head_names_result = $conn->query("SELECT user_id, full_name FROM users WHERE status = 'active' ORDER BY full_name");
$head_names = $head_names_result->fetch_all(MYSQLI_ASSOC);

if (isset($_GET['success'])) {
    $success = urldecode($_GET['success']);
}
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

        .add-new-btn {
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 20px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
        }

        .add-new-btn:hover {
            background-color: #218838;
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

        /* --- TABLE COLUMN WIDTHS --- */
        /* --- TABLE COLUMN WIDTHS --- */
        .contacts-table th:nth-child(1), 
        .contacts-table td:nth-child(1) {
            width: 15%;
        }

        .contacts-table th:nth-child(2), 
        .contacts-table td:nth-child(2) {
            width: 10%;
        }

        .contacts-table th:nth-child(3), 
        .contacts-table td:nth-child(3) {
            width: 10%;
        }

        .contacts-table th:nth-child(4), 
        .contacts-table td:nth-child(4) {
            width: 15%; /* Head column */
        }

        .contacts-table th:nth-child(5), 
        .contacts-table td:nth-child(5) {
            width: 25%; /* Organization column */
        }

        .contacts-table th:nth-child(6), 
        .contacts-table td:nth-child(6) {
            width: 25%; /* Actions column */
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
        <li><a href="createpage.php">Create page</a></li>
        <li><a href="editpage.php">Edit page</a></li>
        <li><a href="profilepage.php">Profile</a></li>
        <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
    </ul>
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
        
        <!-- Edit Contact Number Form -->
        <?php if ($edit_mode && $current_item): ?>
            <div class="edit-form">
                <h3>Edit Contact #<?php echo htmlspecialchars($current_item['numbers']); ?></h3>
                <form method="POST">
                    <input type="hidden" name="edit_id" value="<?php echo $current_item['number_id']; ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_number">Contact Number *</label>
                            <input type="text" id="new_number" name="new_number" 
                                   value="<?php echo htmlspecialchars($current_item['numbers']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_description">Description *</label>
                            <input type="text" id="new_description" name="new_description" 
                                   value="<?php echo htmlspecialchars($current_item['description']); ?>" required 
                                   placeholder="e.g., SMS, Landline, Intercom, etc.">
                        </div>
                        
                        <div class="form-group">
                            <label for="new_status">Status *</label>
                            <select id="new_status" name="new_status" required>
                                <option value="active" <?php echo (isset($current_item['status']) && $current_item['status'] == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="decommissioned" <?php echo (isset($current_item['status']) && $current_item['status'] == 'decommissioned') ? 'selected' : ''; ?>>Decommissioned</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_head">Head Name *</label>
                            <select id="new_head" name="new_head" required>
                                <option value="">Select Head</option>
                                <?php foreach ($head_names as $user): ?>
                                    <option value="<?php echo htmlspecialchars($user['full_name']); ?>"
                                        <?php echo $current_item['head'] == $user['full_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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
        
        <!-- Add New Number Form -->
        <?php if ($add_mode): ?>
            <div class="add-form">
                <h3>Add New Contact Number</h3>
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_number">Contact Number *</label>
                            <input type="text" id="new_number" name="new_number" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_description">Description *</label>
                            <input type="text" id="new_description" name="new_description" required 
                                   placeholder="e.g., SMS, Landline, Intercom, etc.">
                        </div>
                        
                        <div class="form-group">
                            <label for="new_status">Status *</label>
                            <select id="new_status" name="new_status" required>
                                <option value="active">Active</option>
                                <option value="decommissioned">Decommissioned</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_head">Head Name *</label>
                            <select id="new_head" name="new_head" required>
                                <option value="">Select Head</option>
                                <?php foreach ($head_names as $user): ?>
                                    <option value="<?php echo htmlspecialchars($user['full_name']); ?>">
                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="new_division_id">Division</label>
                            <select id="new_division_id" name="new_division_id">
                                <option value="">Select Division</option>
                                <?php foreach ($all_divisions as $division): ?>
                                    <option value="<?php echo $division['division_id']; ?>"
                                        <?php echo isset($_POST['new_division_id']) && $_POST['new_division_id'] == $division['division_id'] ? 'selected' : ''; ?>>
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
                                        <?php echo isset($_POST['new_department_id']) && $_POST['new_department_id'] == $department['department_id'] ? 'selected' : ''; ?>>
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
                                        <?php echo isset($_POST['new_unit_id']) && $_POST['new_unit_id'] == $unit['unit_id'] ? 'selected' : ''; ?>>
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
                                        <?php echo isset($_POST['new_office_id']) && $_POST['new_office_id'] == $office['office_id'] ? 'selected' : ''; ?>>
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
                        <button type="submit" name="add_new_number" class="btn-save">Add Contact Number</button>
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
                        <label for="division_head">Division Head</label>
                        <div class="dropdown-with-input">
                            <select id="division_head" name="division_head" onchange="handleHeadSelection(this, 'division_custom_input')">
                                <option value="">Select or enter new head</option>
                                <?php 
                                $current_head = $current_org_item['head'] ?? '';
                                $has_existing_option = false;
                                foreach ($head_names as $head): 
                                    if ($head['head'] === $current_head) {
                                        $has_existing_option = true;
                                    }
                                ?>
                                    <option value="<?php echo htmlspecialchars($head['full_name']); ?>"
                                        <?php echo $current_head === $head['full_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($head['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="__custom__" <?php echo !$has_existing_option && !empty($current_head) ? 'selected' : ''; ?>>Enter custom name...</option>
                            </select>
                            <button type="button" class="input-toggle" onclick="toggleCustomInput('division_head', 'division_custom_input')">✏️</button>
                        </div>
                        <div id="division_custom_input" class="custom-input" style="<?php echo !$has_existing_option && !empty($current_head) ? 'display: block;' : 'display: none;' ?>">
                            <input type="text" name="division_head_custom" placeholder="Enter division head name" 
                                   value="<?php echo !$has_existing_option && !empty($current_head) ? htmlspecialchars($current_head) : ''; ?>" 
                                   oninput="updateHeadSelect(this, 'division_head')">
                            <small style="color: #666;">Enter a new head name</small>
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
                        <label for="department_head">Department Head</label>
                        <div class="dropdown-with-input">
                            <select id="department_head" name="department_head" onchange="handleHeadSelection(this, 'department_custom_input')">
                                <option value="">Select or enter new head</option>
                                <?php 
                                $current_head = $current_org_item['head'] ?? '';
                                $has_existing_option = false;
                                foreach ($head_names as $head): 
                                    if ($head['head'] === $current_head) {
                                        $has_existing_option = true;
                                    }
                                ?>
                                    <option value="<?php echo htmlspecialchars($head['head']); ?>"
                                        <?php echo $current_head === $head['head'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($head['head']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="__custom__" <?php echo !$has_existing_option && !empty($current_head) ? 'selected' : ''; ?>>Enter custom name...</option>
                            </select>
                            <button type="button" class="input-toggle" onclick="toggleCustomInput('department_head', 'department_custom_input')">✏️</button>
                        </div>
                        <div id="department_custom_input" class="custom-input" style="<?php echo !$has_existing_option && !empty($current_head) ? 'display: block;' : 'display: none;' ?>">
                            <input type="text" name="department_head_custom" placeholder="Enter department head name" 
                                   value="<?php echo !$has_existing_option && !empty($current_head) ? htmlspecialchars($current_head) : ''; ?>" 
                                   oninput="updateHeadSelect(this, 'department_head')">
                            <small style="color: #666;">Enter a new head name</small>
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
                    
                    <div class="form-group">
                        <label for="unit_head">Unit Head</label>
                        <div class="dropdown-with-input">
                            <select id="unit_head" name="unit_head" onchange="handleHeadSelection(this, 'unit_custom_input')">
                                <option value="">Select or enter new head</option>
                                <?php 
                                $current_head = $current_org_item['head'] ?? '';
                                $has_existing_option = false;
                                foreach ($head_names as $head): 
                                    if ($head['head'] === $current_head) {
                                        $has_existing_option = true;
                                    }
                                ?>
                                    <option value="<?php echo htmlspecialchars($head['head']); ?>"
                                        <?php echo $current_head === $head['head'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($head['head']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="__custom__" <?php echo !$has_existing_option && !empty($current_head) ? 'selected' : ''; ?>>Enter custom name...</option>
                            </select>
                            <button type="button" class="input-toggle" onclick="toggleCustomInput('unit_head', 'unit_custom_input')">✏️</button>
                        </div>
                        <div id="unit_custom_input" class="custom-input" style="<?php echo !$has_existing_option && !empty($current_head) ? 'display: block;' : 'display: none;' ?>">
                            <input type="text" name="unit_head_custom" placeholder="Enter unit head name" 
                                   value="<?php echo !$has_existing_option && !empty($current_head) ? htmlspecialchars($current_head) : ''; ?>" 
                                   oninput="updateHeadSelect(this, 'unit_head')">
                            <small style="color: #666;">Enter a new head name</small>
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
                        <label for="office_head">Office Head</label>
                        <div class="dropdown-with-input">
                            <select id="office_head" name="office_head" onchange="handleHeadSelection(this, 'office_custom_input')">
                                <option value="">Select or enter new head</option>
                                <?php 
                                $current_head = $current_org_item['head'] ?? '';
                                $has_existing_option = false;
                                foreach ($head_names as $head): 
                                    if ($head['head'] === $current_head) {
                                        $has_existing_option = true;
                                    }
                                ?>
                                    <option value="<?php echo htmlspecialchars($head['head']); ?>"
                                        <?php echo $current_head === $head['head'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($head['head']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="__custom__" <?php echo !$has_existing_option && !empty($current_head) ? 'selected' : ''; ?>>Enter custom name...</option>
                            </select>
                            <button type="button" class="input-toggle" onclick="toggleCustomInput('office_head', 'office_custom_input')">✏️</button>
                        </div>
                        <div id="office_custom_input" class="custom-input" style="<?php echo !$has_existing_option && !empty($current_head) ? 'display: block;' : 'display: none;' ?>">
                            <input type="text" name="office_head_custom" placeholder="Enter office head name" 
                                   value="<?php echo !$has_existing_option && !empty($current_head) ? htmlspecialchars($current_head) : ''; ?>" 
                                   oninput="updateHeadSelect(this, 'office_head')">
                            <small style="color: #666;">Enter a new head name</small>
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
        
        <!-- Contact Numbers Section -->
        <div class="edit-section <?php echo $active_section == 'contacts' ? 'active' : ''; ?>" id="contacts-section">
            <div class="section-container">
                <h3>Contact Numbers
                    <button type="button" class="add-new-btn" onclick="showAddForm()">
                        <span>+</span> Add New Number
                    </button>
                </h3>
                <div class="search-filter">
                    <input type="text" id="searchContacts" placeholder="Search by contact number, description, head, or status..." onkeyup="filterTable('contactsTable', 'searchContacts')">
                </div>
                
                <?php if (count($numbers) > 0): ?>
                    <table class="contacts-table" id="contactsTable">
                        <thead>
                            <tr>
                                <tr>
                                <th>Contact Number</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Head</th> <!-- Separate Head column -->
                                <th>Organization</th> <!-- Separate Organization column -->
                                <th>Actions</th>
                            </tr>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($numbers as $number): 
                                $org_type = '';
                                $org_name = '';
                                
                                if (!empty($number['division_name'])) {
                                    $org_type = 'Division';
                                    $org_name = $number['division_name'];
                                } elseif (!empty($number['department_name'])) {
                                    $org_type = 'Department';
                                    $org_name = $number['department_name'];
                                } elseif (!empty($number['unit_name'])) {
                                    $org_type = 'Unit';
                                    $org_name = $number['unit_name'];
                                } elseif (!empty($number['office_name'])) {
                                    $org_type = 'Office';
                                    $org_name = $number['office_name'];
                                }
                                
                                // Determine status class
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
                                <tr>
                                    <td><?php echo htmlspecialchars($number['numbers']); ?></td>
                                    <td><?php echo htmlspecialchars($number['description']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </td>
                                    <!-- Head Column (separate from Organization) -->
                                    <td style="font-weight: 600; color: #2d3748;">
                                        <?php echo htmlspecialchars($number['head']); ?>
                                    </td>
                                    <!-- Organization Column (separate from Head) -->
                                    <td>
                                        <?php if ($org_type && $org_name): ?>
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <span class="org-badge"><?php echo $org_type; ?></span>
                                                <?php echo htmlspecialchars($org_name); ?>
                                            </div>
                                        <?php else: ?>
                                            <em style="color: #999; font-size: 14px;">Not assigned</em>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?section=contacts&edit=<?php echo $number['number_id']; ?>" class="edit-btn">Edit</a>
                                            
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="delete_id" value="<?php echo $number['number_id']; ?>">
                                                <button type="submit" class="delete-btn" 
                                                        onclick="return confirm('Delete <?php echo htmlspecialchars($number['numbers']); ?>?')">
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
                        <button type="button" class="add-new-btn" onclick="showAddForm()" style="margin-left: 10px;">
                            <span>+</span> Add First Number
                        </button>
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
                                    <td><?php echo htmlspecialchars($division['head']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $division['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                            <?php echo ucfirst($division['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="org-action-buttons">
                                            <a href="?section=divisions&edit_division=<?php echo $division['division_id']; ?>" class="edit-btn">Edit</a>
                                            <a href="?section=contacts&add_number&division_id=<?php echo $division['division_id']; ?>" class="add-new-btn" style="padding: 5px 10px; font-size: 12px;">
                                                + Add Number
                                            </a>
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
                                <tr>
                                    <td><?php echo htmlspecialchars($department['department_name']); ?></td>
                                    <td><?php echo htmlspecialchars($department['head']); ?></td>
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
                                            <a href="?section=contacts&add_number&department_id=<?php echo $department['department_id']; ?>" class="add-new-btn" style="padding: 5px 10px; font-size: 12px;">
                                                + Add Number
                                            </a>
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
                                <tr>
                                    <td><?php echo htmlspecialchars($unit['unit_name']); ?></td>
                                    <td><?php echo htmlspecialchars($unit['head']); ?></td>
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
                                            <a href="?section=contacts&add_number&unit_id=<?php echo $unit['unit_id']; ?>" class="add-new-btn" style="padding: 5px 10px; font-size: 12px;">
                                                + Add Number
                                            </a>
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
                                <tr>
                                    <td><?php echo htmlspecialchars($office['office_name']); ?></td>
                                    <td><?php echo htmlspecialchars($office['head']); ?></td>
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
                                            <a href="?section=contacts&add_number&office_id=<?php echo $office['office_id']; ?>" class="add-new-btn" style="padding: 5px 10px; font-size: 12px;">
                                                + Add Number
                                            </a>
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
    
    function showAddForm() {
        window.location.href = `editpage.php?section=contacts&add_number=1`;
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
        
        // Handle form submission for custom head names
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
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
    });
</script>
</body>
</html>