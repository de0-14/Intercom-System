<?php
require_once 'conn.php';
updateAllUsersActivity($conn);
$error = "";
$success = "";

$username = $email = $fullname = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $fullname = trim($_POST['fn']);
    $password = $_POST['password'];

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
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $insert_sql = "INSERT INTO users (username,email,full_name,password,role_id) VALUES (?,?,?,?,7)";
            $stmt2 = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($stmt2, "ssss", $username, $email, $fullname, $password_hash);
            mysqli_stmt_execute($stmt2);
            $success = "Account created successfully!";
            header("refresh:5;url=login.php");
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
            <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
            <p style="color:blue;text-align:center;">Redirecting to login page…</p>
        <?php endif; ?>

        <form method="POST" onsubmit="return validateForm()">
            <label>Email Address *</label>
            <input type="email" name="email" required value="<?php echo htmlspecialchars($email); ?>">

            <label>Full Name *</label>
            <input type="text" name="fn" required value="<?php echo htmlspecialchars($fullname); ?>">

            <label>Username *</label>
            <input type="text" name="username" required value="<?php echo htmlspecialchars($username); ?>">

            <label>Password *</label>
            <div class="password-container">
                <input id="password" type="password" name="password" required>
                <button type="button" id="togglePassword">Show</button>
            </div>

            <button type="submit" class="submit-btn">Create Account</button>
            <p class="login-link">
                Already have an account? <a href="login.php">Click here to login</a>
            </p>
        </form>
    </div>
</div>

<!-- FOOTER -->
<div class="footer">
    © 2026 Intercom Directory. All rights reserved.<br>
    Developed by TNTS Programming Students JT.DP.RR
</div>

<script>
// Password toggle
const passwordInput = document.getElementById('password');
const togglePasswordBtn = document.getElementById('togglePassword');
togglePasswordBtn.addEventListener('click', () => {
    if(passwordInput.type === 'password') {
        passwordInput.type = 'text';
        togglePasswordBtn.textContent = 'Hide';
    } else {
        passwordInput.type = 'password';
        togglePasswordBtn.textContent = 'Show';
    }
});

function validateForm() {
    if(passwordInput.value.length < 8) {
        alert("Password must be at least 8 characters long!");
        return false;
    }
    return true;
}
</script>

</body>
</html>
