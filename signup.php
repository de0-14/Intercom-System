<?php
require_once 'conn.php';

$error = "";
$success = "";

// Get active divisions for dropdown
$divisions = getDivisions($conn);

// Keep form field values for repopulation
$username = $email = $fullname = '';

// Signup POST handling (same as before)
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ... Your existing signup validation and insertion logic ...
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Create Account - DRMC Intercom</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<style>
/* --- GENERAL & BODY --- */
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

/* --- MAIN CONTENT --- */
.main {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    width: 100%;
    padding: 120px 20px 20px; /* Extra top padding for fixed header */
    position: relative;
    z-index: 1;
}

/* Signup Card */
.card {
    width: 100%;
    max-width: 500px;
    background-color: rgba(255,255,255,0.9);
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    border: 1px solid rgba(255,255,255,0.3);
    box-sizing: border-box;
}

.card h2 { text-align: center; margin-bottom: 10px; color: #07417f; font-size: 26px; }
.card p { text-align: center; margin-bottom: 25px; font-size: 15px; color: #666; }

/* Form Inputs */
input[type=text], input[type=email], input[type=password], select {
    width: 100%;
    padding: 14px;
    margin-bottom: 16px;
    border-radius: 8px;
    border: 1px solid #ccd6e3;
    font-size: 15px;
    transition: all 0.2s;
}

input:focus, select:focus {
    outline: none;
    border-color: #2b6cb0;
    box-shadow: 0 0 0 3px rgba(43,108,176,0.15);
}

/* Password toggle */
.password-container { position: relative; margin-bottom: 5px; }
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
    font-weight: 600;
}

/* Submit Button */
.submit-btn {
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

.submit-btn:hover {
    background-color: #1f4f8b;
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(0,0,0,0.15);
}

/* Error/Success Messages */
.alert {
    text-align: center;
    padding: 10px;
    border-radius: 6px;
    font-weight: 500;
    margin-bottom: 15px;
}

.alert.error { background-color: rgba(255,0,0,0.05); border-left: 4px solid #f44336; color: #f44336; }
.alert.success { background-color: rgba(0,255,0,0.05); border-left: 4px solid #4CAF50; color: #4CAF50; }

/* Footer */
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

/* Responsive */
@media (max-width: 768px) {
    .header { flex-direction: column; padding: 15px; gap: 15px; text-align: center; }
    .header .logo span { font-size: 1.3rem; }
    ul.nav { width: 100%; justify-content: center; }
    ul.nav li a { padding: 8px 16px; font-size: 0.9rem; }
    .main { padding: 130px 15px 20px; }
    .card { padding: 25px; }
}

@media (max-width: 480px) {
    .header .logo span { font-size: 1.1rem; }
    .header .logo img { width: 45px; height: 45px; }
    ul.nav { flex-wrap: wrap; justify-content: center; }
    ul.nav li a { padding: 8px 14px; font-size: 0.85rem; }
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
        
    </ul>
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
        <?php endif; ?>

        <form id="createAccountForm" method="POST" onsubmit="return validateForm()">
            <label for="email">Email Address <span class="required">*</span></label>
            <input type="email" id="email" name="email" placeholder="Enter Email" required value="<?php echo htmlspecialchars($email); ?>">

            <label for="fn">Full Name <span class="required">*</span></label>
            <input type="text" id="fn" name="fn" placeholder="Enter Full Name" required value="<?php echo htmlspecialchars($fullname); ?>">

            <label for="username">Username <span class="required">*</span></label>
            <input type="text" id="username" name="username" placeholder="Enter username" required value="<?php echo htmlspecialchars($username); ?>">

            <div class="password-container">
                <input id="password" type="password" name="password" placeholder="Password" required>
                <button type="button" id="togglePassword">Show</button>
            </div>
            <small>Password must be at least 8 characters long</small>

            <label for="role">Role <span class="required">*</span></label>
            <select id="role" name="role_id" required onchange="toggleFields()">
                <option value="">Select Role</option>
                <?php foreach($role_names as $id => $name): ?>
                    <option value="<?php echo $id; ?>" <?php echo isset($_POST['role_id']) && $_POST['role_id']==$id ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="division">Division</label>
            <select id="division" name="division_id" disabled onchange="loadDepartments(this.value)">
                <option value="">Select Division</option>
                <?php foreach($divisions as $division): ?>
                    <option value="<?php echo $division['division_id']; ?>" <?php echo isset($_POST['division_id']) && $_POST['division_id']==$division['division_id'] ? 'selected':''; ?>><?php echo htmlspecialchars($division['division_name']); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="department">Department</label>
            <select id="department" name="department_id" disabled></select>

            <label for="unit">Unit</label>
            <select id="unit" name="unit_id" disabled></select>

            <label for="office">Office</label>
            <select id="office" name="office_id" disabled></select>

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
// Password toggle
const passwordInput = document.getElementById('password');
const togglePasswordBtn = document.getElementById('togglePassword');
if(togglePasswordBtn){
    togglePasswordBtn.addEventListener('click', () => {
        if(passwordInput.type==='password'){
            passwordInput.type='text';
            togglePasswordBtn.textContent='Hide';
        } else{
            passwordInput.type='password';
            togglePasswordBtn.textContent='Show';
        }
    });
}
</script>

</body>
</html>
