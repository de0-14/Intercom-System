<?php
require_once 'conn.php';

if (isset($_SESSION['user_id'])) {
    header('Location: homepage.php');
    exit;
}   

$success = "";
$error = "";

if (isset($_GET['message'])) {
    $success = htmlspecialchars(str_replace('+',' ', $_GET['message']));
}

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT user_id, username, password FROM users WHERE username = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows == 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $success = 'Login successful! Redirecting...';
            echo '<script>setTimeout(() => window.location.href = "homepage.php", 1500)</script>';
        } else {
            $error = "Wrong password!";
        }
    } else {
        $error = "Username not found!";
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">  
<title>Intercom Directory - Login</title>
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
</head>
<body>

<div class="header">
    <div class="logo">
        <img src="hospitalLogo.png" alt="Hospital Logo">
        <span>DAVAO REGIONAL MEDICAL CENTER</span>
    </div>
    <ul class="nav">
        <li><a href="homepage.php">Homepage</a></li>
        <li><a href="login.php">Login</a></li>
    </ul>
</div>

<div class="main">
    <div class="card">
        <h2>Welcome</h2>
        <p>Please log in to continue</p>
        <form method="POST">
            <input id="username" type="text" name="username" placeholder="Username" required autocomplete="username">
            <div class="password-container">
                <input id="password" type="password" name="password" placeholder="Password" required autocomplete="current-password">
                <button type="button" id="togglePassword">Show</button>
            </div>
            <button type="submit" class="login-btn">Log In</button>
        </form>

        <?php if (!empty($error)): ?>
            <p style="color:red;"><?php echo $error; ?></p>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <p style="color:green;"><?php echo $success; ?></p>
        <?php endif; ?>

        <div class="actions">
            <div class="divider"></div>
            <a href="signup.php" class="create-btn">Create an Account</a>
        </div>
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
togglePasswordBtn.addEventListener('click', () => {
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        togglePasswordBtn.textContent = 'Hide';
    } else {
        passwordInput.type = 'password';
        togglePasswordBtn.textContent = 'Show';
    }
});
</script>

</body>
</html>