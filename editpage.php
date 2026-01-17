<?php
require_once 'conn.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$edit_mode = false;
$edit_org_mode = '';
$current_item = null;
$current_org_item = null;

$active_section = isset($_GET['section']) ? $_GET['section'] : 'contacts';

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

if (isset($_POST['save_edit'])) {
    $edit_id = (int)$_POST['edit_id'];
    $new_number = trim($_POST['new_number']);
    $new_description = trim($_POST['new_description']);
    $new_head = trim($_POST['new_head']);
    $new_division_id = !empty($_POST['new_division_id']) ? (int)$_POST['new_division_id'] : NULL;
    $new_department_id = !empty($_POST['new_department_id']) ? (int)$_POST['new_department_id'] : NULL;
    $new_unit_id = !empty($_POST['new_unit_id']) ? (int)$_POST['new_unit_id'] : NULL;
    $new_office_id = !empty($_POST['new_office_id']) ? (int)$_POST['new_office_id'] : NULL;
    
    $update_stmt = $conn->prepare("UPDATE numbers SET numbers = ?, description = ?, head = ?, division_id = ?, department_id = ?, unit_id = ?, office_id = ? WHERE number_id = ?");
    $update_stmt->bind_param("sssiiiii", $new_number, $new_description, $new_head, $new_division_id, $new_department_id, $new_unit_id, $new_office_id, $edit_id);
    
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

if (isset($_POST['update_division'])) {
    $division_id = (int)$_POST['division_id'];
    $division_name = trim($_POST['division_name']);
    $division_status = $_POST['division_status'];
    
    $update_stmt = $conn->prepare("UPDATE divisions SET division_name = ?, status = ? WHERE division_id = ?");
    $update_stmt->bind_param("ssi", $division_name, $division_status, $division_id);
    
    if ($update_stmt->execute()) {
        $success = "Division updated successfully!";
        $edit_org_mode = '';
        header("Location: editpage.php?section=divisions&success=" . urlencode($success));
        exit;
    } else {
        $error = "Failed to update division: " . $update_stmt->error;
    }
    $update_stmt->close();
}

if (isset($_POST['update_department'])) {
    $department_id = (int)$_POST['department_id'];
    $department_name = trim($_POST['department_name']);
    $division_id = (int)$_POST['division_id'];
    $department_status = $_POST['department_status'];
    
    $update_stmt = $conn->prepare("UPDATE departments SET department_name = ?, division_id = ?, status = ? WHERE department_id = ?");
    $update_stmt->bind_param("sisi", $department_name, $division_id, $department_status, $department_id);
    
    if ($update_stmt->execute()) {
        $success = "Department updated successfully!";
        $edit_org_mode = '';
        header("Location: editpage.php?section=departments&success=" . urlencode($success));
        exit;
    } else {
        $error = "Failed to update department: " . $update_stmt->error;
    }
    $update_stmt->close();
}

if (isset($_POST['update_unit'])) {
    $unit_id = (int)$_POST['unit_id'];
    $unit_name = trim($_POST['unit_name']);
    $department_id = (int)$_POST['department_id'];
    $unit_status = $_POST['unit_status'];
    
    $update_stmt = $conn->prepare("UPDATE units SET unit_name = ?, department_id = ?, status = ? WHERE unit_id = ?");
    $update_stmt->bind_param("sisi", $unit_name, $department_id, $unit_status, $unit_id);
    
    if ($update_stmt->execute()) {
        $success = "Unit updated successfully!";
        $edit_org_mode = '';
        header("Location: editpage.php?section=units&success=" . urlencode($success));
        exit;
    } else {
        $error = "Failed to update unit: " . $update_stmt->error;
    }
    $update_stmt->close();
}

if (isset($_POST['update_office'])) {
    $office_id = (int)$_POST['office_id'];
    $office_name = trim($_POST['office_name']);
    $unit_id = (int)$_POST['unit_id'];
    $office_status = $_POST['office_status'];
    
    $update_stmt = $conn->prepare("UPDATE offices SET office_name = ?, unit_id = ?, status = ? WHERE office_id = ?");
    $update_stmt->bind_param("sisi", $office_name, $unit_id, $office_status, $office_id);
    
    if ($update_stmt->execute()) {
        $success = "Office updated successfully!";
        $edit_org_mode = '';
        header("Location: editpage.php?section=offices&success=" . urlencode($success));
        exit;
    } else {
        $error = "Failed to update office: " . $update_stmt->error;
    }
    $update_stmt->close();
}

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
}

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

$divisions_result = $conn->query("SELECT * FROM divisions ORDER BY division_name");
$divisions_list = $divisions_result->fetch_all(MYSQLI_ASSOC);

$departments_result = $conn->query("SELECT 
    d.*,
    dv.division_name 
    FROM departments d
    LEFT JOIN divisions dv ON d.division_id = dv.division_id
    ORDER BY d.department_name");
$departments_list = $departments_result->fetch_all(MYSQLI_ASSOC);

$units_result = $conn->query("SELECT 
    u.*,
    d.department_name,
    dv.division_name 
    FROM units u
    LEFT JOIN departments d ON u.department_id = d.department_id
    LEFT JOIN divisions dv ON d.division_id = dv.division_id
    ORDER BY u.unit_name");
$units_list = $units_result->fetch_all(MYSQLI_ASSOC);

$offices_result = $conn->query("SELECT 
    o.*,
    u.unit_name,
    d.department_name,
    dv.division_name 
    FROM offices o
    LEFT JOIN units u ON o.unit_id = u.unit_id
    LEFT JOIN departments d ON u.department_id = d.department_id
    LEFT JOIN divisions dv ON d.division_id = dv.division_id
    ORDER BY o.office_name");
$offices_list = $offices_result->fetch_all(MYSQLI_ASSOC);

$all_divisions = getDivisions($conn, false);
$all_departments = getDepartments($conn, false);
$all_units = getUnits($conn, false);
$offices_result2 = $conn->query("SELECT * FROM offices ORDER BY office_name");
$offices = $offices_result2->fetch_all(MYSQLI_ASSOC);

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
    <link rel="stylesheet" type="text/css" href="style.css">
    <link rel="stylesheet" type="text/css" href="edit_style.css">
</head>
<body>
    <div class="header">
        <div class="logo">
            <img src="hospitalLogo.png" alt="Hospital Logo">
            <span>DAVAO REGIONAL MEDICAL CENTER</span>
        </div>
        <div>
            <ul class="nav">
                <li id="home"><a href="homepage.php"> Homepage </a></li>
                <li id="create"><a href="createpage.php"> Create page</a></li>
                <li id="edit"><a href="editpage.php"> Edit page </a></li>
                <li id="logout"><a href="logout.php"> Logout (<?php echo htmlspecialchars(getUserName()); ?>)</a></li>
            </ul>
        </div>
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
            
            <div class="section-buttons">
                <button class="edit-section-btn <?php echo $active_section == 'contacts' ? 'active' : ''; ?>" onclick="showSection('contacts')">Contact Numbers</button>
                <button class="edit-section-btn <?php echo $active_section == 'divisions' ? 'active' : ''; ?>" onclick="showSection('divisions')">Divisions</button>
                <button class="edit-section-btn <?php echo $active_section == 'departments' ? 'active' : ''; ?>" onclick="showSection('departments')">Departments</button>
                <button class="edit-section-btn <?php echo $active_section == 'units' ? 'active' : ''; ?>" onclick="showSection('units')">Units</button>
                <button class="edit-section-btn <?php echo $active_section == 'offices' ? 'active' : ''; ?>" onclick="showSection('offices')">Offices</button>
            </div>
            
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
                                <select id="new_description" name="new_description" required>
                                    <option value="">Select Type</option>
                                    <option value="SMS" <?php echo $current_item['description'] == 'SMS' ? 'selected' : ''; ?>>SMS</option>
                                    <option value="Landline" <?php echo $current_item['description'] == 'Landline' ? 'selected' : ''; ?>>Landline</option>
                                    <option value="Intercom" <?php echo $current_item['description'] == 'Intercom' ? 'selected' : ''; ?>>Intercom</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_head">Head Name *</label>
                                <input type="text" id="new_head" name="new_head" 
                                       value="<?php echo htmlspecialchars($current_item['head']); ?>" required>
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
                            <label for="division_status">Status *</label>
                            <select id="division_status" name="division_status" required>
                                <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                            </select>
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
                            <select id="department_status" name="department_status" required>
                                <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                            </select>
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
                            <select id="unit_status" name="unit_status" required>
                                <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                            </select>
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
                            <select id="office_status" name="office_status" required>
                                <option value="active" <?php echo $current_org_item['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="decommissioned" <?php echo $current_org_item['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                            </select>
                        </div>
                        
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" name="update_office" class="btn-save">Update Office</button>
                            <a href="?section=offices&cancel=1" class="btn-cancel">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            
            <div class="edit-section <?php echo $active_section == 'contacts' ? 'active' : ''; ?>" id="contacts-section">
                <div class="section-container">
                    <h3>Contact Numbers</h3>
                    <div class="search-filter">
                        <input type="text" id="searchContacts" placeholder="Search by contact number, description, or head..." onkeyup="filterTable('contactsTable', 'searchContacts')">
                    </div>
                    
                    <?php if (count($numbers) > 0): ?>
                        <table class="contacts-table" id="contactsTable">
                            <thead>
                                <tr>
                                    <th>Contact Number</th>
                                    <th>Description</th>
                                    <th>Head</th>
                                    <th>Organization</th>
                                    <th>Actions</th>
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
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($number['numbers']); ?></td>
                                        <td><?php echo htmlspecialchars($number['description']); ?></td>
                                        <td><?php echo htmlspecialchars($number['head']); ?></td>
                                        <td>
                                            <?php if ($org_type && $org_name): ?>
                                                <span class="org-badge"><?php echo $org_type; ?></span>
                                                <?php echo htmlspecialchars($org_name); ?>
                                            <?php else: ?>
                                                <em style="color: #999;">Not assigned</em>
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
                            No contact numbers found. <a href="createpage.php">Create your first contact</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
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
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($divisions_list as $division): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($division['division_name']); ?></td>
                                        <td>
                                            <span class="<?php echo $division['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                                <?php echo ucfirst($division['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="?section=divisions&edit_division=<?php echo $division['division_id']; ?>" class="edit-btn">Edit</a>
                                                
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="delete_division" value="<?php echo $division['division_id']; ?>">
                                                    <button type="submit" class="delete-btn" 
                                                            onclick="return confirm('Are you sure you want to delete <?php echo htmlspecialchars($division['division_name']); ?>? This will also delete all associated contact numbers.')">
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
                                    <th>Division</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($departments_list as $department): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($department['department_name']); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($department['division_name']); ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo $department['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                                <?php echo ucfirst($department['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
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
                                        <td><?php echo htmlspecialchars($unit['department_name']); ?></td>
                                        <td>
                                            <div class="parent-info"><?php echo htmlspecialchars($unit['division_name']); ?></div>
                                        </td>
                                        <td>
                                            <span class="<?php echo $unit['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                                <?php echo ucfirst($unit['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
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
                                        <td><?php echo htmlspecialchars($office['unit_name']); ?></td>
                                        <td><?php echo htmlspecialchars($office['department_name']); ?></td>
                                        <td>
                                            <div class="parent-info"><?php echo htmlspecialchars($office['division_name']); ?></div>
                                        </td>
                                        <td>
                                            <span class="<?php echo $office['status'] == 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                                <?php echo ucfirst($office['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
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
        });
    </script>
</body>
</html>
