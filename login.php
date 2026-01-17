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
<title>Intercom Directory</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" type="text/css" href="style.css">
<style>
* { box-sizing:border-box; margin:0; padding:0; font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
body { min-height:100vh; display:flex; flex-direction: column;
       background: url('drmc.jpg') no-repeat center center fixed; background-size: cover; position: relative; }
body::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(237,244,252,0.6);
    z-index: 0;
}
.main { flex:1; display:flex; justify-content:center; align-items:center; padding:20px; position:relative; z-index:1; }
.card {
    width: 100%;
    max-width: 380px;
    background-color: rgba(255,255,255,0.4);
    border-radius: 12px;
    padding: 28px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.08);
    backdrop-filter: blur(6px);
}
h2 { text-align:center; margin-bottom:8px; color:#2b6cb0; font-size:22px; }
p { text-align:center; margin-bottom:20px; font-size:14px; color:#666; }
input { width:100%; padding:11px 12px; margin-bottom:14px; border-radius:6px; border:1px solid #ccd6e3; font-size:14px; }
input:focus { outline:none; border-color:#2b6cb0; }
.login-btn { width:100%; padding:12px; background-color:#2b6cb0; color:#fff; border:none; border-radius:6px; font-size:15px; font-weight:600; cursor:pointer; transition: all 0.3s ease; }
.login-btn:hover { background-color:#1f4f8b; transform:translateY(-1px); }
.actions { margin-top:18px; display:flex; flex-direction:column; gap:14px; }
.divider { height:1px; background:#e2ebf6; margin-bottom:18px; }
.create-btn { padding:12px; font-size:15px; font-weight:600; text-align:center; border-radius:6px; border:2px solid #2b6cb0; background:#fff; color:#2b6cb0; cursor:pointer; transition: all 0.3s ease; width:100%; display:inline-block; }
.create-btn:hover { background:#2b6cb0; color:#fff; transform:translateY(-1px); }
.footer { background-color:#2b6cb0; color:#fff; text-align:center; padding:15px 10px; font-size:13px; position:relative; z-index:1; }

.side-menu::-webkit-scrollbar { width:6px; }
.side-menu::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.3); border-radius:3px; }

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
</style>
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
		<li id="logout"><a href="login.php"> Login </a></li>
    </ul>
    </div>
</div>

<div class="main">
    <div class="card">
        <h2>Welcome</h2>
        <p>Please log in to continue</p>

        <form method="POST">
            <label for="username" class="sr-only">Username</label>
            <input id="username" type="text" name="username" required autocomplete="username">

            <div class="password-container">
                <label for="password" class="sr-only">Password</label>
                <input id="password" type="password" name="password" placeholder="Password" required autocomplete="current-password">
                <button type="button" id="togglePassword">Show</button>
            </div>

            <button type="submit" class="login-btn">Log In</button>
        </form>

        <?php if (!empty($error)): ?>
            <p style="color: red;"><?php echo $error; ?></p>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <p style="color: green;"><?php echo $success; ?></p>
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

// Existing division/department/unit/office JS
const divisionSelect = document.getElementById('divisionSelect');
const departmentSelect = document.getElementById('departmentSelect');
const unitSelect = document.getElementById('unitSelect');
const officeSelect = document.getElementById('officeSelect');

if (typeof data !== 'undefined') {
    Object.keys(data).forEach(div => {
        let opt = document.createElement('option');
        opt.value = div; opt.textContent = div;
        if(divisionSelect) divisionSelect.appendChild(opt);
    });

    if(divisionSelect) divisionSelect.addEventListener('change', () => {
        const div = divisionSelect.value;
        if(departmentSelect) departmentSelect.innerHTML = '<option value="">-- Select Department --</option>';
        if(unitSelect) unitSelect.innerHTML = '<option value="">-- Select Unit --</option>';
        if(officeSelect) officeSelect.innerHTML = '<option value="">-- Select Office --</option>';
        departmentSelect.disabled = !div;
        unitSelect.disabled = true;
        officeSelect.disabled = true;
        if(div) Object.keys(data[div]).forEach(dept => {
            let opt = document.createElement('option'); opt.value=dept; opt.textContent=dept;
            departmentSelect.appendChild(opt);
        });
    });

    if(departmentSelect) departmentSelect.addEventListener('change', () => {
        const div = divisionSelect.value;
        const dept = departmentSelect.value;
        unitSelect.innerHTML = '<option value="">-- Select Unit --</option>';
        officeSelect.innerHTML = '<option value="">-- Select Office --</option>';
        unitSelect.disabled = !dept;
        officeSelect.disabled = true;
        if(div && dept) Object.keys(data[div][dept]).forEach(unit => {
            let opt = document.createElement('option'); opt.value=unit; opt.textContent=unit;
            unitSelect.appendChild(opt);
        });
    });

    if(unitSelect) unitSelect.addEventListener('change', () => {
        const div = divisionSelect.value;
        const dept = departmentSelect.value;
        const unit = unitSelect.value;
        officeSelect.innerHTML = '<option value="">-- Select Office --</option>';
        officeSelect.disabled = !unit;
        if(div && dept && unit) data[div][dept][unit].forEach(off => {
            let opt = document.createElement('option'); opt.value=off; opt.textContent=off;
            officeSelect.appendChild(opt);
        });
    });
}

document.querySelectorAll('ul.nav li a').forEach(link => {
    link.addEventListener('click', () => {
        const nav = document.querySelector('ul.nav');      
        nav.classList.remove('active');
    });
});
</script>

</body>
</html>