<?php ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Intercom Directory</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:"Segoe UI",Tahoma,Geneva,Verdana,sans-serif;}

body{
    min-height:100vh;
    display:flex;
    flex-direction:column;
    background:url('drmc.jpg') no-repeat center center fixed;
    background-size:cover;
    position:relative;
}
body::before{
    content:"";
    position:absolute;
    inset:0;
    background:rgba(237,244,252,.6);
    z-index:0;
}

/* HEADER */
.header{
    height:90px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:0 32px;
    background:#fff;
    border-bottom:1px solid #dde8f6;
    z-index:3;
}
.logo{
    display:flex;
    align-items:center;
    gap:12px;
    color:#2b6cb0;
    font-size:26px;
    font-weight:600;
}
.logo img{width:70px}

/* HAMBURGER */
.hamburger{
    width:30px;
    height:22px;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    cursor:pointer;
}
.hamburger span{
    height:4px;
    background:#2b6cb0;
    border-radius:2px;
    transition:.3s;
}
.hamburger.active span:nth-child(1){transform:rotate(45deg) translateY(9px)}
.hamburger.active span:nth-child(2){opacity:0}
.hamburger.active span:nth-child(3){transform:rotate(-45deg) translateY(-9px)}

/* SIDE MENU */
.side-menu{
    position:fixed;
    top:0;
    right:-320px;
    width:320px;
    height:100%;
    background:#2b6cb0;
    padding:90px 20px 90px;
    transition:.3s;
    z-index:4;
    overflow-y:auto;
    box-shadow:-4px 0 12px rgba(0,0,0,.25);
}
.side-menu.active{right:0}

/* CLOSE BUTTON */
.close-btn{
    position:absolute;
    top:20px;
    right:20px;
    width:42px;
    height:42px;
    border-radius:50%;
    background:rgba(255,255,255,.18);
    border:1px solid rgba(255,255,255,.35);
    color:#fff;
    font-size:26px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    transition:.25s;
}
.close-btn:hover{
    background:#fff;
    color:#2b6cb0;
    transform:rotate(90deg);
}

/* MENU */
.menu-btn{
    width:100%;
    padding:12px;
    margin-bottom:10px;
    background:rgba(255,255,255,.15);
    color:#fff;
    border:none;
    border-radius:6px;
    font-size:16px;
    font-weight:600;
    text-align:left;
    cursor:pointer;
}
.menu-btn:hover{background:rgba(255,255,255,.25)}

.submenu{display:none;margin-bottom:16px}
.submenu select{
    width:100%;
    padding:8px;
    border-radius:4px;
    border:none;
    font-size:14px;
    color:#2b6cb0;
}

/* AUTH BUTTONS */
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
    font-size:14px;
    font-weight:600;
    border:none;
    cursor:pointer;
}
.login-btn{background:#fff;color:#2b6cb0}
.signup-btn{background:#ffd54f;color:#2b6cb0}

/* MAIN */
.main{
    flex:1;
    display:flex;
    justify-content:center;
    align-items:center;
    position:relative;
    z-index:1;
}
.card{
    width:100%;
    max-width:380px;
    background:rgba(255,255,255,.45);
    padding:28px;
    border-radius:12px;
    box-shadow:0 10px 30px rgba(0,0,0,.1);
    backdrop-filter:blur(6px);
}

/* FOOTER */
.footer{
    background:#2b6cb0;
    color:#fff;
    text-align:center;
    padding:15px;
    font-size:13px;
    z-index:1;
}
</style>
</head>

<body>

<div class="header">
    <div class="logo">
        <img src="hospitalLogo.png">
        DAVAO REGIONAL MEDICAL CENTER
    </div>
    <div class="hamburger" id="hamburger">
        <span></span><span></span><span></span>
    </div>
</div>

<div class="side-menu" id="sideMenu">
    <button class="close-btn" id="closeMenu">×</button>

    <button class="menu-btn" data-target="divBox">Division ▾</button>
    <div class="submenu" id="divBox">
        <select id="divisionSelect">
            <option value="">Select Division</option>
        </select>
    </div>

    <button class="menu-btn" data-target="deptBox">Department ▾</button>
    <div class="submenu" id="deptBox">
        <select id="departmentSelect" disabled>
            <option value="">Select Department</option>
        </select>
    </div>

    <button class="menu-btn" data-target="unitBox">Unit ▾</button>
    <div class="submenu" id="unitBox">
        <select id="unitSelect" disabled>
            <option value="">Select Unit</option>
        </select>
    </div>

    <button class="menu-btn" data-target="officeBox">Office ▾</button>
    <div class="submenu" id="officeBox">
        <select id="officeSelect" disabled>
            <option value="">Select Office</option>
        </select>
    </div>

    <div class="auth-container">
        <button class="auth-btn login-btn">Log In</button>
        <button class="auth-btn signup-btn">Sign Up</button>
    </div>
</div>

<div class="main">
    <div class="card">
        <h2 style="text-align:center;color:#2b6cb0">Welcome</h2>
        <p style="text-align:center;color:#666">Please log in to continue</p>
    </div>
</div>

<div class="footer">
    © 2026 Intercom Directory<br>
    Developed by TNTS Programming Students JT.DP.RR
</div>

<script>
const hamburger=document.getElementById('hamburger');
const sideMenu=document.getElementById('sideMenu');
const closeMenu=document.getElementById('closeMenu');

hamburger.onclick=()=>{
    hamburger.classList.toggle('active');
    sideMenu.classList.toggle('active');
};
closeMenu.onclick=()=>{
    hamburger.classList.remove('active');
    sideMenu.classList.remove('active');
};

document.querySelectorAll('.menu-btn').forEach(btn=>{
    btn.onclick=()=>{
        const box=document.getElementById(btn.dataset.target);
        box.style.display=box.style.display==='block'?'none':'block';
    };
});

const data={
    "Division A":{
        "Department 1":{
            "Unit 1":["Office A","Office B"]
        }
    },
    "Division B":{
        "Department 2":{
            "Unit 2":["Office C"]
        }
    }
};

const divSel=document.getElementById('divisionSelect');
const deptSel=document.getElementById('departmentSelect');
const unitSel=document.getElementById('unitSelect');
const offSel=document.getElementById('officeSelect');

Object.keys(data).forEach(d=>divSel.add(new Option(d,d)));

divSel.onchange=()=>{
    deptSel.innerHTML='<option value="">Select Department</option>';
    unitSel.innerHTML='<option value="">Select Unit</option>';
    offSel.innerHTML='<option value="">Select Office</option>';
    deptSel.disabled=!divSel.value;
    unitSel.disabled=true;
    offSel.disabled=true;
    if(divSel.value)
        Object.keys(data[divSel.value]).forEach(dep=>deptSel.add(new Option(dep,dep)));
};

deptSel.onchange=()=>{
    unitSel.innerHTML='<option value="">Select Unit</option>';
    offSel.innerHTML='<option value="">Select Office</option>';
    unitSel.disabled=!deptSel.value;
    offSel.disabled=true;
    if(deptSel.value)
        Object.keys(data[divSel.value][deptSel.value]).forEach(u=>unitSel.add(new Option(u,u)));
};

unitSel.onchange=()=>{
    offSel.innerHTML='<option value="">Select Office</option>';
    offSel.disabled=!unitSel.value;
    if(unitSel.value)
        data[divSel.value][deptSel.value][unitSel.value]
            .forEach(o=>offSel.add(new Option(o,o)));
};
</script>

</body>
</html>
