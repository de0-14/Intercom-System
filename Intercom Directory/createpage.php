<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

require_once 'conn.php';
require_once 'config.php';
updateAllUsersActivity($conn);

// Get all active users for head selection
$headUsers = getAllActiveUsers($conn);
$divisions = getDivisions($conn);

// AJAX endpoint for getting dropdown data
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_dropdowns') {
    $type = $_GET['type'] ?? '';
    $parentId = $_GET['parent_id'] ?? 0;
    
    $response = [];
    
    switch($type) {
        case 'departments':
            if ($parentId > 0) {
                $departments = getDepartmentsByDivision($conn, $parentId);
                $response = $departments;
            }
            break;
        case 'units':
            if ($parentId > 0) {
                $units = getUnitsByDepartment($conn, $parentId);
                $response = $units;
            }
            break;
        case 'offices':
            if ($parentId > 0) {
                $offices = getOfficesByUnit($conn, $parentId);
                $response = $offices;
            }
            break;
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
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
        
        // Get head user details
        $headQuery = $conn->prepare("SELECT username, full_name FROM users WHERE user_id = ?");
        $headQuery->bind_param("i", $headUserId);
        $headQuery->execute();
        $headResult = $headQuery->get_result();
        
        if ($headResult->num_rows === 0) {
            throw new Exception("Head user not found in the system.");
        }
        
        $headUser = $headResult->fetch_assoc();
        $headUsername = $headUser['username'];
        $headFullName = $headUser['full_name'];
        $headQuery->close();
        
        $conn->begin_transaction();
        
        switch ($createType) {
            case 'division':
                // Check if division already exists
                $checkStmt = $conn->prepare("SELECT division_id FROM divisions WHERE division_name = ?");
                $checkStmt->bind_param("s", $name);
                $checkStmt->execute();
                $checkStmt->store_result();
                if ($checkStmt->num_rows > 0) throw new Exception("Division '$name' already exists.");
                $checkStmt->close();
                
                // Insert division
                $stmt = $conn->prepare("INSERT INTO divisions (division_name, status) VALUES (?, 'active')");
                $stmt->bind_param("s", $name);
                if (!$stmt->execute()) throw new Exception("Failed to create division: " . $stmt->error);
                $newId = $conn->insert_id;
                $stmt->close();
                
                $tableField = 'division_id';
                $descriptionType = "Division contact";
                break;
                
            case 'department':
                $underDivision = (int)$_POST['under_division'];
                if (empty($underDivision)) throw new Exception("Please select a division.");
                
                // Check if department already exists under this division
                $checkStmt = $conn->prepare("SELECT department_id FROM departments WHERE department_name = ? AND division_id = ?");
                $checkStmt->bind_param("si", $name, $underDivision);
                $checkStmt->execute();
                $checkStmt->store_result();
                if ($checkStmt->num_rows > 0) throw new Exception("Department '$name' already exists in this division.");
                $checkStmt->close();
                
                // Insert department
                $stmt = $conn->prepare("INSERT INTO departments (department_name, division_id, status) VALUES (?, ?, 'active')");
                $stmt->bind_param("si", $name, $underDivision);
                if (!$stmt->execute()) throw new Exception("Failed to create department: " . $stmt->error);
                $newId = $conn->insert_id;
                $stmt->close();
                
                $tableField = 'department_id';
                $descriptionType = "Department contact";
                break;
                
            case 'unit':
                $underDepartment = (int)$_POST['under_department'];
                if (empty($underDepartment)) throw new Exception("Please select a department.");
                
                // Check if unit already exists under this department
                $checkStmt = $conn->prepare("SELECT unit_id FROM units WHERE unit_name = ? AND department_id = ?");
                $checkStmt->bind_param("si", $name, $underDepartment);
                $checkStmt->execute();
                $checkStmt->store_result();
                if ($checkStmt->num_rows > 0) throw new Exception("Unit '$name' already exists in this department.");
                $checkStmt->close();
                
                // Insert unit
                $stmt = $conn->prepare("INSERT INTO units (unit_name, department_id, status) VALUES (?, ?, 'active')");
                $stmt->bind_param("si", $name, $underDepartment);
                if (!$stmt->execute()) throw new Exception("Failed to create unit: " . $stmt->error);
                $newId = $conn->insert_id;
                $stmt->close();
                
                $tableField = 'unit_id';
                $descriptionType = "Unit contact";
                break;
                
            case 'office':
                $underUnit = (int)$_POST['under_unit'];
                if (empty($underUnit)) throw new Exception("Please select a unit.");
                
                // Check if office already exists under this unit
                $checkStmt = $conn->prepare("SELECT office_id FROM offices WHERE office_name = ? AND unit_id = ?");
                $checkStmt->bind_param("si", $name, $underUnit);
                $checkStmt->execute();
                $checkStmt->store_result();
                if ($checkStmt->num_rows > 0) throw new Exception("Office '$name' already exists in this unit.");
                $checkStmt->close();
                
                // Insert office
                $stmt = $conn->prepare("INSERT INTO offices (office_name, unit_id, status) VALUES (?, ?, 'active')");
                $stmt->bind_param("si", $name, $underUnit);
                if (!$stmt->execute()) throw new Exception("Failed to create office: " . $stmt->error);
                $newId = $conn->insert_id;
                $stmt->close();
                
                $tableField = 'office_id';
                $descriptionType = "Office contact";
                break;
                
            default:
                throw new Exception("Invalid create type.");
        }
        
        // Check if we have at least one contact method (email or phone)
$hasContactMethod = false;
$phoneNumbers = [];

if (isset($_POST['numbers'])) {
    foreach ($_POST['numbers'] as $index => $numberData) {
        $phoneNumber = trim($numberData['number']);
        if (!empty($phoneNumber)) {
            $hasContactMethod = true;
            $description = trim($numberData['description'] ?? '');
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

// Insert ONE row with both phone and email
$contactStmt = $conn->prepare("INSERT INTO numbers (numbers, email, description, head_user_id, head, $tableField) VALUES (?, ?, ?, ?, ?, ?)");
$contactStmt->bind_param("sssisi", $phoneFieldValue, $emailFieldValue, $combinedDescription, $headUserId, $headFullName, $newId);
if (!$contactStmt->execute()) {
    throw new Exception("Failed to save contact information: " . $contactStmt->error);
}
$contactStmt->close();
        
        $conn->commit();
        
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
        if ($conn) $conn->rollback();
        $response['message'] = $e->getMessage();
    }
    
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create New Entry</title>
<style>
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
                <li><a href="adminpanel.php">Admin Panel</a></li>
            <?php else: ?>
                <li><a href="adminchat.php">Chat with Admin</a></li>
            <?php endif; ?>
            <li><a href="profilepage.php">Profile</a></li>
            <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
        <?php endif; ?>
    </ul>
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
                <select name="head" id="head" required>
                    <option value="">Select Custodian/Head</option>
                    <?php foreach($headUsers as $user): ?>
                        <option value="<?php echo $user['user_id']; ?>">
                            <?php echo htmlspecialchars($user['full_name']) . ' (' . $user['username'] . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
    const submitBtn = document.getElementById('submit-btn');
    let numberCounter = 0;
    
    // Initialize with one number entry
    numbersContainer.appendChild(createNumberEntry());
    
    function showMessage(message, type='error') {
        messageContainer.innerHTML = `<div class="message ${type}">${message}</div>`;
        if(type==='success') setTimeout(()=>{messageContainer.innerHTML='';},5000);
    }
    
    function createNumberEntry() {
        const entryId = `number_${numberCounter++}`;
        const div = document.createElement('div');
        div.className='number-entry';
        div.id=entryId;
        div.innerHTML=`
            <div class="number-input-row">
                <div class="number-input-container">
                    <input type="text" name="numbers[${entryId}][number]" placeholder="Enter contact number" style="flex:1; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                </div>
                <button type="button" class="remove-number-btn" onclick="removeNumberEntry('${entryId}')">Remove</button>
            </div>
            <div class="description-container">
                <input type="text" name="numbers[${entryId}][description]" placeholder="Description (e.g., Main line, Emergency line, etc.)" style="padding:8px; border:1px solid #ced4da; border-radius:4px;">
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
            'department': { name: 'Name of Department', head: 'Name of Custodian/Head', submit: 'Create Department' },
            'unit': { name: 'Name of Unit', head: 'Name of Custodian/Head', submit: 'Create Unit' },
            'office': { name: 'Name of Office', head: 'Name of Custodian/Head', submit: 'Create Office' }
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
        
        // Show appropriate dropdown
        switch(createType) {
            case 'department':
                underDivisionGroup.style.display = 'flex';
                document.getElementById('under_division').required = true;
                break;
            case 'unit':
                underDivisionGroup.style.display = 'flex';
                underDepartmentGroup.style.display = 'flex';
                document.getElementById('under_division').required = true;
                document.getElementById('under_department').required = true;
                // Load departments when switching to unit type
                loadDepartments(document.getElementById('under_division').value);
                break;
            case 'office':
                underDivisionGroup.style.display = 'flex';
                underDepartmentGroup.style.display = 'flex';
                underUnitGroup.style.display = 'flex';
                document.getElementById('under_division').required = true;
                document.getElementById('under_department').required = true;
                document.getElementById('under_unit').required = true;
                // Load departments and units when switching to office type
                const divisionId = document.getElementById('under_division').value;
                if (divisionId) {
                    loadDepartments(divisionId);
                    const deptId = document.getElementById('under_department').value;
                    if (deptId) {
                        loadUnits(deptId);
                    }
                }
                break;
        }
        
        updateFormLabels();
    }
    
    // AJAX function to load departments
    window.loadDepartments = function(divisionId) {
        const departmentSelect = document.getElementById('under_department');
        const unitSelect = document.getElementById('under_unit');
        const loadingElement = document.getElementById('department-loading');
        
        // Clear dependent dropdowns
        departmentSelect.innerHTML = '<option value="">Select Department</option>';
        unitSelect.innerHTML = '<option value="">Select Unit</option>';
        
        if (!divisionId) return;
        
        loadingElement.style.display = 'inline-block';
        
        // Fetch departments via AJAX
        fetch(`?ajax=get_dropdowns&type=departments&parent_id=${divisionId}`)
            .then(response => response.json())
            .then(data => {
                if (data && data.length > 0) {
                    data.forEach(dept => {
                        const option = document.createElement('option');
                        option.value = dept.department_id;
                        option.textContent = dept.department_name;
                        departmentSelect.appendChild(option);
                    });
                } else {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = 'No departments found';
                    departmentSelect.appendChild(option);
                }
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
    };
    
    // AJAX function to load units
    window.loadUnits = function(departmentId) {
        const unitSelect = document.getElementById('under_unit');
        const loadingElement = document.getElementById('unit-loading');
        
        // Clear unit dropdown
        unitSelect.innerHTML = '<option value="">Select Unit</option>';
        
        if (!departmentId) return;
        
        loadingElement.style.display = 'inline-block';
        
        // Fetch units via AJAX
        fetch(`?ajax=get_dropdowns&type=units&parent_id=${departmentId}`)
            .then(response => response.json())
            .then(data => {
                if (data && data.length > 0) {
                    data.forEach(unit => {
                        const option = document.createElement('option');
                        option.value = unit.unit_id;
                        option.textContent = unit.unit_name;
                        unitSelect.appendChild(option);
                    });
                } else {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = 'No units found';
                    unitSelect.appendChild(option);
                }
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
    };
    
    // Event listeners
    createTypeSelect.addEventListener('change', toggleUnderDropdowns);
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
        
        numberEntries.forEach((entry, index) => {
            const phoneInput = entry.querySelector('input[placeholder*="contact number"]').value.trim();
            const descInput = entry.querySelector('input[placeholder*="Description"]').value.trim();
            if(phoneInput){
                formData.append(`numbers[${index}][number]`, phoneInput);
                formData.append(`numbers[${index}][description]`, descInput);
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
    
    // Initialize labels
    updateFormLabels();
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