<?php
// Add these at the VERY TOP
error_reporting(E_ALL); // Keep this on for debugging, remove when working
ini_set('display_errors', 1);

require_once 'conn.php';
require_once 'config.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Start output buffering to catch any stray output
    ob_start();

    $response = ['success' => false, 'message' => ''];

    try {
        // Get form data
        $officeName = trim($_POST['name']); // FIXED: variable name for clarity
        $officeHead = trim($_POST['head']); // FIXED: variable name for clarity
        $underUnit = trim($_POST['under']); // This should be unit_id

        // Validate inputs
        if (empty($officeName)) {
            throw new Exception("Office name is required.");
        }
        if (empty($officeHead)) {
            throw new Exception("Office Head is required.");
        }
        if (empty($underUnit)) {
            throw new Exception("Please select a Unit.");
        }

        // Check if office already exists
        $checkStmt = $conn->prepare("SELECT office_id FROM offices WHERE office_name = ?");
        $checkStmt->bind_param("s", $officeName);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            throw new Exception("Office '$officeName' already exists.");
        }
        $checkStmt->close();

        // Begin transaction
        $conn->begin_transaction();

        // Insert office - FIXED: using correct variables
        $stmt = $conn->prepare("INSERT INTO offices (office_name, unit_id, status) VALUES (?, ?, 'active')");
        $stmt->bind_param("si", $officeName, $underUnit);

        if (!$stmt->execute()) {
            throw new Exception("Failed to create office: " . $stmt->error);
        }

        $officeId = $conn->insert_id; // FIXED: renamed variable for clarity
        $stmt->close();

        // Process phone numbers - FIXED THE MAIN ISSUE HERE
        if (isset($_POST['numbers']) && !empty($_POST['numbers'])) {
            // FIXED: Changed unit_id to office_id in the query
            $phoneStmt = $conn->prepare("INSERT INTO numbers (numbers, description, head, office_id) VALUES (?, ?, ?, ?)");
            
            // Debug: Check what numbers are being sent
            error_log("Numbers data: " . print_r($_POST['numbers'], true));

            foreach ($_POST['numbers'] as $index => $numberData) {
                $phoneNumber = trim($numberData['number']);

                if (!empty($phoneNumber)) {
                    // Get types for this number
                    if (isset($numberData['types']) && is_array($numberData['types'])) {
                        foreach ($numberData['types'] as $type) {
                            $phoneStmt->bind_param("sssi", $phoneNumber, $type, $officeHead, $officeId);
                            if (!$phoneStmt->execute()) {
                                throw new Exception("Failed to save phone number: " . $phoneStmt->error);
                            }
                            error_log("Inserted: $phoneNumber, $type, $officeHead, $officeId");
                        }
                    } else {
                        // If no types selected, insert at least one record
                        $phoneStmt->bind_param("sssi", $phoneNumber, 'General', $officeHead, $officeId);
                        if (!$phoneStmt->execute()) {
                            throw new Exception("Failed to save phone number: " . $phoneStmt->error);
                        }
                    }
                }
            }
            $phoneStmt->close();
        }

        // Commit transaction
        $conn->commit();

        $response['success'] = true;
        $response['message'] = "Office '$officeName' created successfully!";
        $response['office_id'] = $officeId; // FIXED: changed department_id to office_id
        
    } catch (Exception $e) {
        // Rollback on error
        if ($conn) {
            $conn->rollback();
        }
        $response['message'] = $e->getMessage();
        error_log("Error creating office: " . $e->getMessage());
    }

    // Clean any output buffers
    ob_end_clean();

    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// FIXED: Changed variable name for clarity
$units = getUnits($conn); // Make sure this function exists and returns units
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Office</title> <!-- FIXED: Changed title -->
    <link rel="stylesheet" type="text/css" href="style.css">
    <style>
        /* Your existing styles here */
        .number-entry {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .number-input-container {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .number-type-checkboxes {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .remove-number-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 5px 10px;
            cursor: pointer;
            font-size: 14px;
        }

        .remove-number-btn:hover {
            background-color: #c82333;
        }

        #add-number-btn {
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 20px;
            cursor: pointer;
            font-size: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        #add-number-btn:hover {
            background-color: #218838;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .submit-btn {
            background-color: #2b6cb0;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 12px 24px;
            cursor: pointer;
            font-size: 16px;
            flex: 1;
        }

        .submit-btn:hover {
            background-color: #1f4f8b;
        }

        /* Message styles */
        .message {
            padding: 12px;
            margin: 15px 0;
            border-radius: 6px;
            text-align: center;
        }

        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
                <li id="home"><a href="homepage.php"> Home page </a></li>
                <?php if (isLoggedIn()) : ?>
                    <li id="create"><a href="createpage.php"> Create page</a></li>
                    <li id="edit"><a href="editpage.php"> Edit/Remove page </a></li>
                    <li id="profile"><a href="profilepage.php"> Profile page </a></li>
                    <li id="logout"><a href="logout.php"> Logout (<?php echo htmlspecialchars(getUserName()); ?>)</a></li>
                <?php else: ?>
                    <li id="logout"><a href="login.php"> Login </a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="content">
        <ul class="choice">
            <li id="create_division"><a href="createpage.php"> Create Division </a></li>
            <li id="create_department"><a href="create_department.php"> Create Department </a></li>
            <li id="create_unit"><a href="create_unit.php"> Create Unit </a></li>
            <li id="create_office"><a href="create_office.php"> Create Office </a></li>
        </ul>
    </div>

    <div class="big-container">
        <h2>You are creating a new Office</h2>

        <!-- Message display area -->
        <div id="message-container"></div>

        <form method="POST" id="division-form">
            <div class="form-group">
                <label for="name">Name of Office *</label>
                <input type="text" name="name" id="name" required>
            </div>
            <div class="form-group">
                <label for="head">Head of Office *</label>
                <input type="text" name="head" id="head" required>
            </div>
            <div class="form-group">
                <label for="under">Unit handling this Office *</label>
                <select id="division" name="under" required>
                    <option value="">Select Unit</option>
                    <?php foreach ($units as $unit): ?> <!-- FIXED: Changed $divisions to $units -->
                        <option value="<?php echo $unit['unit_id']; ?>">
                            <?php echo htmlspecialchars($unit['unit_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Office Contact Numbers</label> <!-- FIXED: Changed label -->
                <button type="button" id="add-number-btn">
                    <span>+</span> Add Contact Number
                </button>

                <div id="numbers-container">
                    <!-- Dynamic number entries will be added here -->
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="submit-btn">Create Office</button> <!-- FIXED: Button text -->
                <button type="button" onclick="window.location.href='homepage.php'" style="background-color: #6c757d;">Cancel</button>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const numbersContainer = document.getElementById('numbers-container');
            const addNumberBtn = document.getElementById('add-number-btn');
            const messageContainer = document.getElementById('message-container');
            const form = document.getElementById('division-form');
            let numberCounter = 0;

            // Function to show message
            function showMessage(message, type = 'error') {
                messageContainer.innerHTML = `
                    <div class="message ${type}">
                        ${message}
                    </div>
                `;

                // Auto-hide success messages after 5 seconds
                if (type === 'success') {
                    setTimeout(() => {
                        messageContainer.innerHTML = '';
                    }, 5000);
                }
            }

            // Function to create a new number entry
            function createNumberEntry() {
                const entryId = `number_${numberCounter++}`;

                const numberEntry = document.createElement('div');
                numberEntry.className = 'number-entry';
                numberEntry.id = entryId;

                numberEntry.innerHTML = `
                    <div class="number-input-container">
                        <input type="text" 
                               name="numbers[${entryId}][number]" 
                               placeholder="Enter contact number" 
                               style="flex: 1; padding: 8px; border: 1px solid #ced4da; border-radius: 4px;">
                        
                        <div class="number-type-checkboxes">
                            <div class="checkbox-group">
                                <input type="checkbox" 
                                       name="numbers[${entryId}][types][]" 
                                       value="SMS" 
                                       id="${entryId}_sms">
                                <label for="${entryId}_sms">SMS</label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" 
                                       name="numbers[${entryId}][types][]" 
                                       value="Landline" 
                                       id="${entryId}_landline">
                                <label for="${entryId}_landline">Landline</label>
                            </div>
                            <div class="checkbox-group">
                                <input type="checkbox" 
                                       name="numbers[${entryId}][types][]" 
                                       value="Intercom" 
                                       id="${entryId}_intercom">
                                <label for="${entryId}_intercom">Intercom</label>
                            </div>
                        </div>
                    </div>
                    <button type="button" 
                            class="remove-number-btn" 
                            onclick="removeNumberEntry('${entryId}')">
                        Remove
                    </button>
                `;

                return numberEntry;
            }

            // Add initial number entry
            const initialEntry = createNumberEntry();
            numbersContainer.appendChild(initialEntry);

            // Add number button click handler
            addNumberBtn.addEventListener('click', function() {
                const newEntry = createNumberEntry();
                numbersContainer.appendChild(newEntry);
            });

            // Form submission handler
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Clear previous messages
                messageContainer.innerHTML = '';
                
                // Validate form
                const officeName = document.getElementById('name').value.trim();
                const officeHead = document.getElementById('head').value.trim();
                const underUnit = document.getElementById('division').value;
                
                if (!officeName) {
                    showMessage('Office name is required.');
                    return;
                }
                if (!officeHead) {
                    showMessage('Office Head is required.');
                    return;
                }
                if (!underUnit) {
                    showMessage('Please select a Unit.');
                    return;
                }
                
                // Create FormData object
                const formData = new FormData();
                formData.append('name', officeName);
                formData.append('head', officeHead);
                formData.append('under', underUnit);
                
                // Collect all number entries
                const numberEntries = document.querySelectorAll('.number-entry');
                let hasValidNumber = false;
                
                numberEntries.forEach((entry, index) => {
                    const numberInput = entry.querySelector('input[type="text"]');
                    const smsCheckbox = entry.querySelector('input[value="SMS"]');
                    const landlineCheckbox = entry.querySelector('input[value="Landline"]');
                    const intercomCheckbox = entry.querySelector('input[value="Intercom"]');
                    
                    const phoneNumber = numberInput.value.trim();
                    
                    if (phoneNumber) {
                        hasValidNumber = true;
                        
                        // Add phone number
                        formData.append(`numbers[${index}][number]`, phoneNumber);
                        
                        // Add checked types
                        if (smsCheckbox.checked) {
                            formData.append(`numbers[${index}][types][]`, 'SMS');
                        }
                        if (landlineCheckbox.checked) {
                            formData.append(`numbers[${index}][types][]`, 'Landline');
                        }
                        if (intercomCheckbox.checked) {
                            formData.append(`numbers[${index}][types][]`, 'Intercom');
                        }
                    }
                });
                
                if (!hasValidNumber) {
                    showMessage('At least one contact number is required.');
                    return;
                }
                
                // Show loading
                const submitBtn = form.querySelector('.submit-btn');
                const originalText = submitBtn.textContent;
                submitBtn.textContent = 'Creating...';
                submitBtn.disabled = true;
                
                // Send AJAX request
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage(data.message, 'success');
                        
                        // Reset form
                        document.getElementById('name').value = '';
                        document.getElementById('head').value = '';
                        document.getElementById('division').value = '';
                        
                        // Clear number entries and add one fresh entry
                        numbersContainer.innerHTML = '';
                        const newEntry = createNumberEntry();
                        numbersContainer.appendChild(newEntry);
                        numberCounter = 1;
                        
                    } else {
                        showMessage(data.message, 'error');
                    }
                })
                .catch(error => {
                    showMessage('An error occurred. Please try again.', 'error');
                    console.error('Error:', error);
                })
                .finally(() => {
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                });
            });
        });

        // Global function to remove number entry
        function removeNumberEntry(entryId) {
            const entry = document.getElementById(entryId);
            if (entry && document.querySelectorAll('.number-entry').length > 1) {
                entry.remove();
            } else {
                alert('You must have at least one contact number.');
            }
        }
    </script>
</body>

</html>