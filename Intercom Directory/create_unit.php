<?php
require_once 'conn.php';
require_once 'config.php';
updateAllUsersActivity($conn);
// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];

    try {
        $unitName = trim($_POST['name']);
        $unitHead = trim($_POST['head']);
        $underDepartment = trim($_POST['under']); // Department handling this Unit

        $departments = getDepartments($conn);

        if (empty($unitName)) throw new Exception("Unit name is required.");
        if (empty($unitHead)) throw new Exception("Unit Head is required.");
        if (empty($underDepartment)) throw new Exception("Please select a department.");

        // Check if unit exists
        $checkStmt = $conn->prepare("SELECT unit_id FROM units WHERE unit_name = ?");
        $checkStmt->bind_param("s", $unitName);
        $checkStmt->execute();
        $checkStmt->store_result();
        if ($checkStmt->num_rows > 0) throw new Exception("Unit '$unitName' already exists.");
        $checkStmt->close();

        $conn->begin_transaction();

        $stmt = $conn->prepare("INSERT INTO units (unit_name, department_id, status) VALUES (?, ?, 'active')");
        $stmt->bind_param("si", $unitName, $underDepartment);
        if (!$stmt->execute()) throw new Exception("Failed to create unit: " . $stmt->error);
        $unitId = $conn->insert_id;
        $stmt->close();

        // Process contact numbers
        if (isset($_POST['numbers'])) {
            $phoneStmt = $conn->prepare("INSERT INTO numbers (numbers, description, head, unit_id) VALUES (?, ?, ?, ?)");
            foreach ($_POST['numbers'] as $index => $numberData) {
                $phoneNumber = trim($numberData['number']);
                if (!empty($phoneNumber)) {
                    if (isset($numberData['types'])) {
                        foreach ($numberData['types'] as $type) {
                            $phoneStmt->bind_param("sssi", $phoneNumber, $type, $unitHead, $unitId);
                            if (!$phoneStmt->execute()) throw new Exception("Failed to save phone number: " . $phoneStmt->error);
                        }
                    }
                }
            }
            $phoneStmt->close();
        }

        $conn->commit();
        $response['success'] = true;
        $response['message'] = "Unit '$unitName' created successfully!";
    } catch (Exception $e) {
        if ($conn) $conn->rollback();
        $response['message'] = $e->getMessage();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$departments = getDepartments($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Unit</title>
<style>
/* --- GENERAL --- */
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

/* --- CREATE BUTTONS --- */
.choice {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap:20px; margin-bottom:30px;
}
.choice li a {
    display:flex; align-items:center; justify-content:center;
    text-decoration:none; padding:18px 12px; background-color:#2b6cb0;
    color:white; font-weight:600; border-radius:8px; box-shadow:0 4px 8px rgba(0,0,0,0.1);
    transition: all 0.2s; font-size:16px;
}
.choice li a:hover {
    background-color:#1f4f8b; transform:translateY(-2px); box-shadow:0 6px 12px rgba(0,0,0,0.15);
}

/* --- FORM CONTAINER --- */
.big-container {
    background-color:white; padding:30px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.1);
    max-width:700px; margin:auto;
}
.big-container h2 { color:#2b6cb0; margin-bottom:20px; text-align:center; }

/* --- FORM --- */
.form-group { margin-bottom:18px; display:flex; flex-direction:column; gap:6px; }
.form-group input, .form-group select { padding:10px; border:1px solid #ced4da; border-radius:4px; font-size:14px; }
.number-entry { display:flex; align-items:center; gap:10px; margin-bottom:15px; padding:10px; background-color:#f8f9fa; border-radius:6px; border:1px solid #e9ecef; }
.number-input-container { flex:1; display:flex; align-items:center; gap:10px; }
.number-type-checkboxes { display:flex; gap:15px; align-items:center; }
.checkbox-group { display:flex; align-items:center; gap:5px; }
.remove-number-btn { background-color:#dc3545; color:white; border:none; border-radius:4px; padding:5px 10px; cursor:pointer; font-size:14px; }
.remove-number-btn:hover { background-color:#c82333; }
#add-number-btn { background-color:#28a745; color:white; border:none; border-radius:6px; padding:10px 20px; cursor:pointer; font-size:16px; margin-bottom:20px; display:flex; align-items:center; gap:8px; }
#add-number-btn:hover { background-color:#218838; }
.form-actions { display:flex; gap:10px; margin-top:20px; }
.submit-btn { background-color:#2b6cb0; color:white; border:none; border-radius:6px; padding:12px 24px; cursor:pointer; font-size:16px; flex:1; }
.submit-btn:hover { background-color:#1f4f8b; }

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
    .choice { flex-direction:column; }
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
                <li><a href="createpage.php">Create page</a></li>
                <li><a href="editpage.php">Edit page</a></li>
            <?php endif; ?>
            <li><a href="profilepage.php">Profile</a></li>
            <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
        <?php endif; ?>
    </ul>
</div>

<div class="content">
    <ul class="choice">
        <li><a href="createpage.php">Create Division</a></li>
        <li><a href="create_department.php">Create Department</a></li>
        <li><a href="create_unit.php">Create Unit</a></li>
        <li><a href="create_office.php">Create Office</a></li>
    </ul>

    <div class="big-container">
        <h2>Create a New Unit</h2>
        <div id="message-container"></div>
        <form method="POST" id="unit-form">
            <div class="form-group">
                <label for="name">Name of Unit *</label>
                <input type="text" name="name" id="name" required>
            </div>
            <div class="form-group">
                <label for="head">Head of Unit *</label>
                <input type="text" name="head" id="head" required>
            </div>
            <div class="form-group">
                <label for="under">Department handling this Unit *</label>
                <select id="under" name="under" required>
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['department_id']; ?>"><?php echo htmlspecialchars($dept['department_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Unit Contact Numbers</label>
                <button type="button" id="add-number-btn"><span>+</span> Add Contact Number</button>
                <div id="numbers-container"></div>
            </div>

            <div class="form-actions">
                <button type="submit" class="submit-btn">Create Unit</button>
                <button type="button" onclick="window.location.href='homepage.php'" style="background-color:#6c757d; color:white; border:none; border-radius:6px; padding:12px 24px; cursor:pointer;">Cancel</button>
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
    const form = document.getElementById('unit-form');
    let numberCounter = 0;

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
            <div class="number-input-container">
                <input type="text" name="numbers[${entryId}][number]" placeholder="Enter contact number" style="flex:1; padding:8px; border:1px solid #ced4da; border-radius:4px;">
                <div class="number-type-checkboxes">
                    <div class="checkbox-group">
                        <input type="checkbox" name="numbers[${entryId}][types][]" value="SMS" id="${entryId}_sms">
                        <label for="${entryId}_sms">SMS</label>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="numbers[${entryId}][types][]" value="Landline" id="${entryId}_landline">
                        <label for="${entryId}_landline">Landline</label>
                    </div>
                    <div class="checkbox-group">
                        <input type="checkbox" name="numbers[${entryId}][types][]" value="Intercom" id="${entryId}_intercom">
                        <label for="${entryId}_intercom">Intercom</label>
                    </div>
                </div>
            </div>
            <button type="button" class="remove-number-btn" onclick="removeNumberEntry('${entryId}')">Remove</button>
        `;
        return div;
    }

    numbersContainer.appendChild(createNumberEntry());
    addNumberBtn.addEventListener('click', ()=>numbersContainer.appendChild(createNumberEntry()));

    form.addEventListener('submit', function(e){
        e.preventDefault();
        messageContainer.innerHTML='';

        const name = document.getElementById('name').value.trim();
        const head = document.getElementById('head').value.trim();
        const under = document.getElementById('under').value;
        if(!name){ showMessage('Unit name is required.'); return; }
        if(!head){ showMessage('Unit Head is required.'); return; }
        if(!under){ showMessage('Please select a department.'); return; }

        const formData = new FormData();
        formData.append('name', name);
        formData.append('head', head);
        formData.append('under', under);

        const numberEntries = document.querySelectorAll('.number-entry');
        let hasValid=false;
        numberEntries.forEach((entry,index)=>{
            const phoneInput = entry.querySelector('input[type="text"]').value.trim();
            if(phoneInput){
                hasValid=true;
                formData.append(`numbers[${index}][number]`, phoneInput);
                entry.querySelectorAll('input[type="checkbox"]:checked').forEach(cb=>{
                    formData.append(`numbers[${index}][types][]`, cb.value);
                });
            }
        });
        if(!hasValid){ showMessage('At least one contact number is required.'); return; }

        const submitBtn = form.querySelector('.submit-btn');
        const originalText = submitBtn.textContent;
        submitBtn.textContent='Creating...'; submitBtn.disabled=true;

        fetch('', { method:'POST', body:formData })
        .then(res=>res.json())
        .then(data=>{
            if(data.success){
                showMessage(data.message,'success');
                form.reset();
                numbersContainer.innerHTML=''; numbersContainer.appendChild(createNumberEntry());
                numberCounter=1;
            } else showMessage(data.message,'error');
        })
        .catch(()=>showMessage('An error occurred. Please try again.','error'))
        .finally(()=>{submitBtn.textContent=originalText; submitBtn.disabled=false;});
    });
});

function removeNumberEntry(entryId){
    const entry=document.getElementById(entryId);
    if(entry && document.querySelectorAll('.number-entry').length>1) entry.remove();
    else alert('You must have at least one contact number.');
}
</script>
</body>
</html>
