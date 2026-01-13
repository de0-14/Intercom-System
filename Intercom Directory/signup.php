<?php
require_once 'conn.php';

$error = "";
$success = "";

// Get active divisions for dropdown
$divisions = getDivisions($conn);

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role_id = (int)$_POST['role_id'];
    $division_id = !empty($_POST['division_id']) ? (int)$_POST['division_id'] : NULL;
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : NULL;
    $unit_id = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : NULL;
    $office_id = !empty($_POST['office_id']) ? (int)$_POST['office_id'] : NULL;
    
    // Validate role-based assignments
    $valid = true;
    if($role_id == 3 && empty($division_id)) {
        $error = "Division Head must be assigned to a division";
        $valid = false;
    }
    if($role_id == 4 && (empty($division_id) || empty($department_id))) {
        $error = "Department Head must be assigned to a division and department";
        $valid = false;
    }
    if($role_id == 5 && (empty($division_id) || empty($department_id) || empty($unit_id))) {
        $error = "Unit Head must be assigned to a division, department, and unit";
        $valid = false;
    }
    if(($role_id == 6 || $role_id == 7) && (empty($division_id) || empty($department_id) || empty($unit_id) || empty($office_id))) {
        $error = "Office Head/Staff must be assigned to all levels";
        $valid = false;
    }
    
    if($valid) {
        $check_sql = "SELECT user_id FROM users WHERE username = ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "s", $username);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if(mysqli_stmt_num_rows($check_stmt) > 0) {
            $error = "Username already exists!";
            mysqli_stmt_close($check_stmt);
        } else {
            mysqli_stmt_close($check_stmt);
            
            // Start transaction
            mysqli_begin_transaction($conn);
            
            try {
                // Insert user with all references
                $user_sql = "INSERT INTO users (username, password, role_id, division_id, department_id, unit_id, office_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $user_stmt = mysqli_prepare($conn, $user_sql);
                mysqli_stmt_bind_param($user_stmt, "ssiiiii", $username, $password, $role_id, $division_id, $department_id, $unit_id, $office_id);
                
                if(!mysqli_stmt_execute($user_stmt)) {
                    throw new Exception("Error creating user: ".mysqli_error($conn));
                }
                
                $user_id = mysqli_insert_id($conn);
                mysqli_stmt_close($user_stmt);
                
                // Commit transaction
                mysqli_commit($conn);
                $success = "Account created successfully!";
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Account</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <!-- HEADER -->
    <div class="header">
        <div class="logo">
            <img src="hospitalLogo.png" alt="Hospital Logo">
            <span>DAVAO REGIONAL MEDICAL CENTER</span>
        </div>
    </div>

    <!-- MAIN -->
    <div class="main">
        <div class="card">
            <h2>Create Account</h2>
            <p>Fill out the form to register</p>
            
            <?php if($error): ?>
                <div class="alert error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if($success): ?>
                <div class="alert success"><?php echo $success; ?></div>
            <?php endif; ?>

            <form id="createAccountForm" method="POST">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Enter username" required>

                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter password" required>

                <label for="role">Role</label>
                <select id="role" name="role_id" required onchange="toggleFields()">
                    <option value="">Select Role</option>
                    <?php foreach($role_names as $id => $name): ?>
                        <option value="<?php echo $id; ?>"><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="division">Division</label>
                <select id="division" name="division_id" disabled onchange="loadDepartments(this.value)">
                    <option value="">Select Division</option>
                    <?php foreach($divisions as $division): ?>
                        <option value="<?php echo $division['division_id']; ?>">
                            <?php echo htmlspecialchars($division['division_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <label for="department">Department</label>
                <select id="department" name="department_id" disabled onchange="loadUnits(this.value)">
                    <option value="">Select Department</option>
                </select>
                
                <label for="unit">Unit</label>
                <select id="unit" name="unit_id" disabled onchange="loadOffices(this.value)">
                    <option value="">Select Unit</option>
                </select>
                
                <label for="office">Office</label>
                <select id="office" name="office_id" disabled>
                    <option value="">Select Office</option>
                </select>

                <button type="submit" class="submit-btn">Create Account</button>
                <a href="login.php">Already have an account? Click here.</a>
            </form>
        </div>
    </div>

    <div class="footer">
        © 2026 Intercom Directory. All rights reserved.<br>
        Developed by TNTS Programming Students JT.DP.RR
    </div>
</body>
<script>
function toggleFields() {
    const roleId = parseInt(document.getElementById('role').value);
    const division = document.getElementById('division');
    const department = document.getElementById('department');
    const unit = document.getElementById('unit');
    const office = document.getElementById('office');
    
    // Reset all fields
    [department, unit, office].forEach(field => {
        field.disabled = true;
        field.required = false;
        field.innerHTML = '<option value="">Select ' + field.name.replace('_', ' ').replace('id', '') + '</option>';
    });

    division.disabled = true;
    division.required = false;
    
    // Enable based on role
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

function loadDepartments(divisionId) {
    if (!divisionId) {
        document.getElementById('department').innerHTML = '<option value="">Select Department</option>';
        document.getElementById('unit').innerHTML = '<option value="">Select Unit</option>';
        document.getElementById('office').innerHTML = '<option value="">Select Office</option>';
        return;
    }
    
    $.ajax({
        url: 'ajax_get_departments.php',
        type: 'POST',
        data: {division_id: divisionId},
        success: function(response) {
            $('#department').html(response);
            $('#unit').html('<option value="">Select Unit</option>');
            $('#office').html('<option value="">Select Office</option>');
        }
    });
}

function loadUnits(departmentId) {
    if (!departmentId) {
        document.getElementById('unit').innerHTML = '<option value="">Select Unit</option>';
        document.getElementById('office').innerHTML = '<option value="">Select Office</option>';
        return;
    }
    
    $.ajax({
        url: 'ajax_get_units.php',
        type: 'POST',
        data: {department_id: departmentId},
        success: function(response) {
            $('#unit').html(response);
            $('#office').html('<option value="">Select Office</option>');
        }
    });
}

function loadOffices(unitId) {
    if (!unitId) {
        document.getElementById('office').innerHTML = '<option value="">Select Office</option>';
        return;
    }
    
    $.ajax({
        url: 'ajax_get_offices.php',
        type: 'POST',
        data: {unit_id: unitId},
        success: function(response) {
            $('#office').html(response);
        }
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('role').addEventListener('change', toggleFields);
});
</script>
</html>