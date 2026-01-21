<?php
require_once 'conn.php';

$error = "";
$success = "";

// Get active divisions for dropdown
$divisions = getDivisions($conn);

// Keep form field values for repopulation
$username = $email = $fullname = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate inputs
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $fullname = trim($_POST['fn']);
    $password = $_POST['password'];
    $role_id = (int)$_POST['role_id'];
    $division_id = !empty($_POST['division_id']) ? (int)$_POST['division_id'] : NULL;
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : NULL;
    $unit_id = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : NULL;
    $office_id = !empty($_POST['office_id']) ? (int)$_POST['office_id'] : NULL;
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format!";
    }
    // Validate email domain for security
    else {
        $allowed_domains = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com', 'protonmail.com', 'aol.com'];
        $email_parts = explode('@', $email);
        if (count($email_parts) !== 2) {
            $error = "Invalid email format!";
        } else {
            $domain = strtolower($email_parts[1]);
            if (!in_array($domain, $allowed_domains)) {
                $error = "Email must be from a valid provider (Gmail, Yahoo, Outlook, etc.)";
            }
        }
    }
    
    // Validate password strength
    if (empty($error) && strlen($password) < 8) {
        $error = "Password must be at least 8 characters long!";
    }
    
    // Validate role-based assignments
    if (empty($error)) {
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
    }
    
    // Only proceed if all validations passed
    if(empty($error)) {
        $check_sql = "SELECT user_id FROM users WHERE username = ? OR email = ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "ss", $username, $email);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if(mysqli_stmt_num_rows($check_stmt) > 0) {
            mysqli_stmt_close($check_stmt);
            
            $check_user = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
            $check_user->bind_param("s", $username);
            $check_user->execute();
            $check_user->store_result();
            
            $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->bind_param("s", $email);
            $check_email->execute();
            $check_email->store_result();
            
            if($check_user->num_rows > 0){
                $error = "Username already exists!";
            } elseif($check_email->num_rows > 0){
                $error = "Email already exists!";
            }
            
            $check_user->close();
            $check_email->close();
        } else {
            mysqli_stmt_close($check_stmt);
            mysqli_begin_transaction($conn);
            
            try {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                $user_sql = "INSERT INTO users (username, email, full_name, password, role_id, division_id, department_id, unit_id, office_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $user_stmt = mysqli_prepare($conn, $user_sql);
                
                if (!$user_stmt) {
                    throw new Exception("Prepare failed: " . mysqli_error($conn));
                }
                
                mysqli_stmt_bind_param($user_stmt, "ssssiiiii", $username, $email, $fullname, $password_hash, $role_id, $division_id, $department_id, $unit_id, $office_id);
                
                if(!mysqli_stmt_execute($user_stmt)) {
                    throw new Exception("Error creating user: " . mysqli_stmt_error($user_stmt));
                }
                
                $user_id = mysqli_insert_id($conn);
                mysqli_stmt_close($user_stmt);
                
                mysqli_commit($conn);
                $success = "Account created successfully!";
                
                $username = $email = $fullname = '';
                
            } catch (Exception $e) {
                mysqli_rollback($conn);
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Account - DRMC Intercom</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* Password toggle button */
        .password-container { position: relative; }
        #togglePassword {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            cursor: pointer;
            font-size: 14px;
            color: #2b6cb0;
        }
/* --- GENERAL --- */
* { box-sizing: border-box; margin: 0; padding: 0; font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }

body {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background: url('drmc.jpg') no-repeat center center fixed;
    background-size: cover;
    position: relative;
}

/* Overlay to dim background */
body::before {
    content: "";
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background-color: rgba(237,244,252,0.6);
    z-index: 0;
}

/* --- FIXED HEADER --- */
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

/* Navigation */
ul.nav {
    display: flex;
    list-style: none;
    gap: 8px;
    background-color: rgba(255, 255, 255, 0.1);
    padding: 6px;
    border-radius: 8px;
    backdrop-filter: blur(5px);
}

ul.nav li a {
    display: block;
    color: white;
    text-decoration: none;
    padding: 10px 22px;
    font-weight: 600;
    font-size: 0.95rem;
    border-radius: 6px;
    transition: all 0.2s ease;
}

ul.nav li a:hover {
    background-color: rgba(255, 255, 255, 0.2);
    transform: translateY(-1px);
}

/* Specific navigation items */
ul.nav li:first-child a {
    background-color: #2b6cb0;
}

ul.nav li:first-child a:hover {
    background-color: #1f4f8b;
}

ul.nav li:last-child a {
    background-color: #38a169;
}

ul.nav li:last-child a:hover {
    background-color: #2f855a;
}

/* --- MAIN CONTENT (adjusted for fixed header) --- */
.main {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    width: 100%;
    padding: 100px 20px 20px; /* Extra top padding for fixed header */
    position: relative;
    z-index: 1;
}

/* Login Card */
.card {
    width: 100%;
    max-width: 400px;
    background-color: rgba(255,255,255,0.9);
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.card h2 {
    text-align: center;
    margin-bottom: 10px;
    color: #07417f;
    font-size: 26px;
}

.card > p { 
    text-align: center; 
    margin-bottom: 25px; 
    font-size: 15px; 
    color: #666; 
}

/* Form Elements */
input[type=text], input[type=password] {
    width: 100%;
    padding: 14px;
    margin-bottom: 16px;
    border-radius: 8px;
    border: 1px solid #ccd6e3;
    font-size: 15px;
    transition: all 0.2s;
}

input:focus { 
    outline: none; 
    border-color: #2b6cb0; 
    box-shadow: 0 0 0 3px rgba(43,108,176,0.15); 
}

/* Password container */
.password-container { 
    position: relative; 
    margin-bottom: 5px;
}

#togglePassword {
    position: absolute;
    right: 12px; 
    top: 50%;
    transform: translateY(-50%);
    border: none; 
    background: none;
    cursor: pointer; 
    color: #2b6cb0;
    font-size: 14px;
    font-weight: 600;
}

/* Login Button */
button.login-btn {
    width: 100%;
    padding: 15px;
    background-color: #2b6cb0;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-top: 10px;
}

button.login-btn:hover {
    background-color: #1f4f8b;
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(0,0,0,0.15);
}

/* Error/Success Messages */
p[style*="color:red"], 
p[style*="color:green"] {
    text-align: center;
    margin-top: 15px;
    padding: 10px;
    border-radius: 6px;
    font-weight: 500;
}

p[style*="color:red"] {
    background-color: rgba(255, 0, 0, 0.05);
    border-left: 4px solid #f44336;
}

p[style*="color:green"] {
    background-color: rgba(0, 255, 0, 0.05);
    border-left: 4px solid #4CAF50;
}

/* Create Account Section */
.actions { 
    margin-top: 25px; 
}

.divider { 
    height: 1px; 
    background: #e2ebf6; 
    margin: 20px 0; 
}

.create-btn {
    display: block;
    padding: 14px;
    text-align: center;
    border-radius: 8px;
    border: 2px solid #2b6cb0;
    background: #fff; 
    color: #2b6cb0;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    font-size: 15px;
}

.create-btn:hover { 
    background: #2b6cb0; 
    color: #fff; 
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(43,108,176,0.2);
}

/* --- FOOTER --- */
.footer {
    background-color: #07417f;
    color: #fff;
    text-align: center;
    padding: 18px 10px;
    font-size: 14px;
    position: relative;
    z-index: 1;
    margin-top: auto;
}

/* --- RESPONSIVE DESIGN --- */
@media (max-width: 768px) {
    .header {
        flex-direction: column;
        padding: 15px;
        gap: 15px;
        text-align: center;
    }
    
    .header .logo span {
        font-size: 1.3rem;
    }
    
    ul.nav {
        width: 100%;
        justify-content: center;
    }
    
    ul.nav li a {
        padding: 8px 16px;
        font-size: 0.9rem;
    }
    
    .main {
        padding: 130px 15px 20px; /* More top padding for mobile */
    }
    
    .card {
        padding: 25px;
    }
}

@media (max-width: 480px) {
    .header .logo span {
        font-size: 1.1rem;
    }
    
    .header .logo img {
        width: 45px;
        height: 45px;
    }
    
    ul.nav {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    ul.nav li a {
        padding: 8px 14px;
        font-size: 0.85rem;
    }
}
</style>
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">
            <img src="hospitalLogo.png" alt="Hospital Logo">
            <span>DAVAO REGIONAL MEDICAL CENTER</span>
        </div>
    </div>

    <div class="main">
        <div class="card">
            <h2>Create Account</h2>
            <p>Fill out the form to register</p>
            
            <?php if($error): ?>
                <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if($success): ?>
                <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
                <?php if (strpos($success, 'successfully') !== false): ?>
                    <p style="color: blue; text-align: center;">You will be redirected to login page in 5 seconds...</p>
                    <?php header("refresh:5;url=login.php"); ?>
                <?php endif; ?>
            <?php endif; ?>

            <form id="createAccountForm" method="POST" onsubmit="return validateForm()">
                <label for="email">Email Address <span class="required">*</span></label>
                <input type="email" id="email" name="email" placeholder="Enter Email" required 
                       value="<?php echo htmlspecialchars($email); ?>">
                
                <label for="fn">Full Name <span class="required">*</span></label>
                <input type="text" id="fn" name="fn" placeholder="Enter Full Name" required 
                       value="<?php echo htmlspecialchars($fullname); ?>">

                <label for="username">Username <span class="required">*</span></label>
                <input type="text" id="username" name="username" placeholder="Enter username" required 
                       value="<?php echo htmlspecialchars($username); ?>">

                <div class="password-container">
                    <label for="password" class="sr-only">Password</label>
                    <input id="password" type="password" name="password" placeholder="Password" required autocomplete="current-password">
                    <button type="button" id="togglePassword">Show</button>
                </div>
                <small>Password must be at least 8 characters long</small>

                <label for="role">Role <span class="required">*</span></label>
                <select id="role" name="role_id" required onchange="toggleFields()">
                    <option value="">Select Role</option>
                    <?php foreach($role_names as $id => $name): ?>
                        <option value="<?php echo $id; ?>" <?php echo isset($_POST['role_id']) && $_POST['role_id'] == $id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="division">Division</label>
                <select id="division" name="division_id" disabled onchange="loadDepartments(this.value)">
                    <option value="">Select Division</option>
                    <?php foreach($divisions as $division): ?>
                        <option value="<?php echo $division['division_id']; ?>"
                            <?php echo isset($_POST['division_id']) && $_POST['division_id'] == $division['division_id'] ? 'selected' : ''; ?>>
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
                <p class="login-link">Already have an account? <a href="login.php">Click here to login</a></p>
            </form>
        </div>
    </div>

    <div class="footer">
        © 2026 Intercom Directory. All rights reserved.<br>
        Developed by TNTS Programming Students JT.DP.RR
    </div>

<script>
// Show/hide password
const passwordInput = document.getElementById('password');
const togglePasswordBtn = document.getElementById('togglePassword');

togglePasswordBtn.addEventListener('click', () => {
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        togglePasswordBtn.textContent = 'Hide';
    } else {
        passwordInput.type = 'password';
        togglePasswordBtn.textContent = 'Show';
    }
});

// Role-based field toggles
function toggleFields() {
    const roleId = parseInt(document.getElementById('role').value);
    const division = document.getElementById('division');
    const department = document.getElementById('department');
    const unit = document.getElementById('unit');
    const office = document.getElementById('office');
    
    [department, unit, office].forEach(field => {
        field.disabled = true;
        field.required = false;
        field.innerHTML = '<option value="">Select ' + field.name.replace('_', ' ').replace('id', '') + '</option>';
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

function loadDepartments(divisionId) {
    if (!divisionId) {
        document.getElementById('department').innerHTML = '<option value="">Select Department</option>';
        document.getElementById('unit').innerHTML = '<option value="">Select Unit</option>';
        document.getElementById('office').innerHTML = '<option value="">Select Office</option>';
        return;
    }
    $.post('ajax_get_departments.php', {division_id: divisionId}, function(response) {
        $('#department').html(response);
        $('#unit').html('<option value="">Select Unit</option>');
        $('#office').html('<option value="">Select Office</option>');
    });
}

function loadUnits(departmentId) {
    if (!departmentId) {
        document.getElementById('unit').innerHTML = '<option value="">Select Unit</option>';
        document.getElementById('office').innerHTML = '<option value="">Select Office</option>';
        return;
    }
    $.post('ajax_get_units.php', {department_id: departmentId}, function(response) {
        $('#unit').html(response);
        $('#office').html('<option value="">Select Office</option>');
    });
}

function loadOffices(unitId) {
    if (!unitId) {
        document.getElementById('office').innerHTML = '<option value="">Select Office</option>';
        return;
    }
    $.post('ajax_get_offices.php', {unit_id: unitId}, function(response) {
        $('#office').html(response);
    });
}

function validateForm() {
    const password = document.getElementById('password').value;
    if (password.length < 8) {
        alert("Password must be at least 8 characters long!");
        return false;
    }
    return true;
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('role').addEventListener('change', toggleFields);
});
</script>
</body>
</html>