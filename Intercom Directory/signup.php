<?php
require_once 'conn.php';
updateAllUsersActivity($conn);
$error = "";
$success = "";

$username = $email = $fullname = '';

// Fetch divisions for dropdown
$divisions = getDivisions($conn);

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $fullname = trim($_POST['fn']);
    $password = $_POST['password'];
    $division_id = !empty($_POST['division_id']) ? (int)$_POST['division_id'] : NULL;
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : NULL;
    $unit_id = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : NULL;
    $office_id = !empty($_POST['office_id']) ? (int)$_POST['office_id'] : NULL;

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format!";
    } else {
        $allowed_domains = ['gmail.com','yahoo.com','outlook.com','hotmail.com','icloud.com','protonmail.com','aol.com'];
        $email_parts = explode('@', $email);
        if (count($email_parts) !== 2 || !in_array(strtolower($email_parts[1]), $allowed_domains)) {
            $error = "Email must be from a valid provider (Gmail, Yahoo, Outlook, etc.)";
        }
    }

    // Password validation
    if (empty($error) && strlen($password) < 8) {
        $error = "Password must be at least 8 characters long!";
    }

    // Check existing user/email
    if(empty($error)) {
        $check_sql = "SELECT user_id FROM users WHERE username = ? OR email = ?";
        $stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($stmt, "ss", $username, $email);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);

        if(mysqli_stmt_num_rows($stmt) > 0) {
            $error = "Username or Email already exists!";
        } else {
            // Validate organizational hierarchy
            $valid_hierarchy = true;
            $hierarchy_error = "";
            
            if ($office_id && !$unit_id) {
                $valid_hierarchy = false;
                $hierarchy_error = "Unit must be selected when Office is selected.";
            }
            if ($unit_id && !$department_id) {
                $valid_hierarchy = false;
                $hierarchy_error = "Department must be selected when Unit is selected.";
            }
            if ($department_id && !$division_id) {
                $valid_hierarchy = false;
                $hierarchy_error = "Division must be selected when Department is selected.";
            }
            
            if (!$valid_hierarchy) {
                $error = $hierarchy_error;
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert user with organizational data
                $insert_sql = "INSERT INTO users (username, email, full_name, password, role_id, division_id, department_id, unit_id, office_id, status) 
                               VALUES (?, ?, ?, ?, 7, ?, ?, ?, ?, 'active')";
                $stmt2 = mysqli_prepare($conn, $insert_sql);
                mysqli_stmt_bind_param($stmt2, "ssssiiii", $username, $email, $fullname, $password_hash, $division_id, $department_id, $unit_id, $office_id);
                
                if(mysqli_stmt_execute($stmt2)) {
                    $success = "Account created successfully!";
                    // Reset form fields
                    $username = $email = $fullname = '';
                    // Don't use header redirect here, use JavaScript instead
                    $redirect = true;
                } else {
                    $error = "Error creating account: " . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt2);
            }
        }
        mysqli_stmt_close($stmt);
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Account - DRMC Intercom</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
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

/* Overlay */
body::before {
    content: "";
    position: absolute;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background-color: rgba(237,244,252,0.6);
    z-index: 0;
}

/* --- HEADER (IDENTICAL TO LOGIN) --- */
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

/* --- MAIN CONTENT --- */
.main {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    width: 100%;
    padding: 130px 20px 20px;
    position: relative;
    z-index: 1;
}

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

input[type=text], input[type=email], input[type=password] {
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

/* Password toggle */
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

/* Buttons */
button.submit-btn {
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
}
label {
    display: block;
    margin-top: 12px;
    margin-bottom: 5px;
    color: #333;
}

input, select {
    width: 100%;
    padding: 11px 12px;
    margin-bottom: 12px;
    border-radius: 6px;
    border: 1px solid #ccd6e3;
    font-size: 14px;
}

input:focus, select:focus {
    outline: none;
    border-color: #2b6cb0;
}

button.submit-btn:hover {
    background-color: #1f4f8b;
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(0,0,0,0.15);
}

p.login-link {
    text-align: center;
    margin-top: 10px;
}

a {
    text-decoration: none;
    color: #07417f;
    font-weight: 600;
}

/* Error/Success messages */
.alert {
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 15px;
    font-weight: 500;
    text-align: center;
}
.alert.error { background-color: rgba(255,0,0,0.05); border-left: 4px solid #f44336; color: #b00020; }
.alert.success { background-color: rgba(0,255,0,0.05); border-left: 4px solid #4CAF50; color: #0a8a0a; }

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

/* --- RESPONSIVE --- */
@media (max-width:768px){
    .main { padding:150px 15px 20px; }
    .card { padding: 25px; }
    .header .logo span { font-size: 1.3rem; }
}
@media (max-width:480px){
    .header .logo span { font-size: 1.1rem; }
    .header .logo img { width:45px; height:45px; }
}
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
                    <script>
                        setTimeout(function() {
                            window.location.href = 'login.php';
                        }, 5000);
                    </script>
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
                
                <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                    <label for="division">Division</label>
                    <select id="division" name="division_id" onchange="loadDepartments(this.value)">
                        <option value="">Select Division</option>
                        <?php foreach($divisions as $division): ?>
                            <option value="<?php echo $division['division_id']; ?>"
                                <?php echo isset($_POST['division_id']) && $_POST['division_id'] == $division['division_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($division['division_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <label for="department" style="margin-top: 10px; display: block;">Department</label>
                    <select id="department" name="department_id" onchange="loadUnits(this.value)" <?php echo empty($_POST['division_id']) ? 'disabled' : ''; ?>>
                        <option value="">Select Department</option>
                    </select>
                    
                    <label for="unit" style="margin-top: 10px; display: block;">Unit</label>
                    <select id="unit" name="unit_id" onchange="loadOffices(this.value)" <?php echo empty($_POST['department_id']) ? 'disabled' : ''; ?>>
                        <option value="">Select Unit</option>
                    </select>
                    
                    <label for="office" style="margin-top: 10px; display: block;">Office</label>
                    <select id="office" name="office_id" <?php echo empty($_POST['unit_id']) ? 'disabled' : ''; ?>>
                        <option value="">Select Office</option>
                    </select>
                </div>

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

function loadDepartments(divisionId) {
    const departmentSelect = document.getElementById('department');
    const unitSelect = document.getElementById('unit');
    const officeSelect = document.getElementById('office');
    
    if (!divisionId) {
        departmentSelect.innerHTML = '<option value="">Select Department</option>';
        unitSelect.innerHTML = '<option value="">Select Unit</option>';
        officeSelect.innerHTML = '<option value="">Select Office</option>';
        departmentSelect.disabled = true;
        unitSelect.disabled = true;
        officeSelect.disabled = true;
        return;
    }
    
    // Enable department dropdown
    departmentSelect.disabled = false;
    
    fetch('ajax_get_departments.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'division_id=' + divisionId
    })
    .then(response => response.text())
    .then(data => {
        departmentSelect.innerHTML = data;
        // Reset dependent dropdowns
        unitSelect.innerHTML = '<option value="">Select Unit</option>';
        officeSelect.innerHTML = '<option value="">Select Office</option>';
        unitSelect.disabled = true;
        officeSelect.disabled = true;
    })
    .catch(error => console.error('Error:', error));
}

function loadUnits(departmentId) {
    const unitSelect = document.getElementById('unit');
    const officeSelect = document.getElementById('office');
    
    if (!departmentId) {
        unitSelect.innerHTML = '<option value="">Select Unit</option>';
        officeSelect.innerHTML = '<option value="">Select Office</option>';
        unitSelect.disabled = true;
        officeSelect.disabled = true;
        return;
    }
    
    // Enable unit dropdown
    unitSelect.disabled = false;
    
    fetch('ajax_get_units.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'department_id=' + departmentId
    })
    .then(response => response.text())
    .then(data => {
        unitSelect.innerHTML = data;
        // Reset dependent dropdown
        officeSelect.innerHTML = '<option value="">Select Office</option>';
        officeSelect.disabled = true;
    })
    .catch(error => console.error('Error:', error));
}

function loadOffices(unitId) {
    const officeSelect = document.getElementById('office');
    
    if (!unitId) {
        officeSelect.innerHTML = '<option value="">Select Office</option>';
        officeSelect.disabled = true;
        return;
    }
    
    // Enable office dropdown
    officeSelect.disabled = false;
    
    fetch('ajax_get_offices.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'unit_id=' + unitId
    })
    .then(response => response.text())
    .then(data => {
        officeSelect.innerHTML = data;
    })
    .catch(error => console.error('Error:', error));
}

function validateForm() {
    const password = document.getElementById('password').value;
    if (password.length < 8) {
        alert("Password must be at least 8 characters long!");
        return false;
    }
    return true;
}
</script>
</body>
</html>