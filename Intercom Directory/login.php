<?php
session_start();

/* ================= DATABASE CONFIG ================= */
$host = "localhost";
$user = "root";
$pass = "";
$db   = "drmc_intercom";

/* ================= CONNECT ================= */
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Database connection failed");
}

/* ================= LOGIN LOGIC ================= */
$error = "";

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $conn->prepare("SELECT user_id, username, password FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();

        if (password_verify($password, $row['password'])) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            header("Location: index.php");
            exit;
        } else {
            $error = "Invalid username or password";
        }
    } else {
        $error = "Invalid username or password";
    }
}

/* ================= LOGOUT ================= */
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Intercom Directory</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:"Segoe UI",Tahoma,Verdana,sans-serif;}
body{
    min-height:100vh;
    background:#edf4fc;
    display:flex;
    flex-direction:column;
}
.header{
    height:90px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:0 32px;
    background:#fff;
    border-bottom:1px solid #dde8f6;
}
.logo{display:flex;align-items:center;gap:12px;color:#2b6cb0;font-size:26px;font-weight:600;}
.hamburger{width:30px;height:22px;display:flex;flex-direction:column;justify-content:space-between;cursor:pointer;}
.hamburger span{height:4px;background:#2b6cb0;border-radius:2px;transition:.3s;}

.side-menu{
    position:fixed;
    top:0;
    right:-320px;
    width:320px;
    height:100%;
    background:#2b6cb0;
    padding:90px 20px;
    transition:.3s;
}
.side-menu.active{right:0}

.close-btn{
    position:absolute;
    top:20px;
    right:20px;
    width:40px;
    height:40px;
    border-radius:50%;
    background:rgba(255,255,255,.2);
    border:none;
    color:#fff;
    font-size:24px;
    cursor:pointer;
}

.auth-container{
    position:absolute;
    bottom:20px;
    right:20px;
    display:flex;
    gap:10px;
}
.auth-btn{
    padding:10px 16px;
    border-radius:20px;
    border:none;
    cursor:pointer;
    font-weight:600;
}
.login-btn{background:#fff;color:#2b6cb0}
.signup-btn{background:#ffd54f;color:#2b6cb0}

.main{
    flex:1;
    display:flex;
    justify-content:center;
    align-items:center;
}
.card{
    width:100%;
    max-width:360px;
    background:#fff;
    padding:28px;
    border-radius:12px;
    box-shadow:0 10px 30px rgba(0,0,0,.1);
}
input{
    width:100%;
    padding:12px;
    margin-bottom:14px;
    border-radius:6px;
    border:1px solid #ccc;
}
.submit-btn{
    width:100%;
    padding:12px;
    background:#2b6cb0;
    color:#fff;
    border:none;
    border-radius:6px;
    cursor:pointer;
}
.error{text-align:center;color:red;margin-bottom:10px}
.footer{
    background:#2b6cb0;
    color:#fff;
    text-align:center;
    padding:15px;
    font-size:13px;
}
</style>
</head>

<body>

<div class="header">
    <div class="logo">DAVAO REGIONAL MEDICAL CENTER</div>
    <div class="hamburger" id="hamburger">
        <span></span><span></span><span></span>
    </div>
</div>

<div class="side-menu" id="sideMenu">
    <button class="close-btn" id="closeMenu">×</button>

    <?php if(isset($_SESSION['username'])): ?>
        <p style="color:white;">Logged in as <b><?= $_SESSION['username'] ?></b></p>
        <a href="?logout=1" style="color:white;">Logout</a>
    <?php endif; ?>

    <div class="auth-container">
        <?php if(!isset($_SESSION['username'])): ?>
            <button class="auth-btn login-btn" onclick="document.getElementById('loginCard').scrollIntoView()">Log In</button>
            <button class="auth-btn signup-btn">Sign Up</button>
        <?php endif; ?>
    </div>
</div>

<div class="main" id="loginCard">
    <div class="card">
        <h2 style="text-align:center;color:#2b6cb0">
            <?= isset($_SESSION['username']) ? "Welcome" : "Login" ?>
        </h2>

        <?php if(!isset($_SESSION['username'])): ?>
            <?php if($error): ?><div class="error"><?= $error ?></div><?php endif; ?>
            <form method="POST">
                <input type="text" name="username" placeholder="Username" required>
                <input type="password" name="password" placeholder="Password" required>
                <button class="submit-btn" name="login">Log In</button>
            </form>
        <?php else: ?>
            <p style="text-align:center;">You are logged in.</p>
        <?php endif; ?>
    </div>
</div>

<div class="footer">
    © 2026 Intercom Directory
</div>

<script>
const hamburger=document.getElementById('hamburger');
const sideMenu=document.getElementById('sideMenu');
const closeMenu=document.getElementById('closeMenu');

hamburger.onclick=()=>sideMenu.classList.toggle('active');
closeMenu.onclick=()=>sideMenu.classList.remove('active');
</script>

</body>
</html>
