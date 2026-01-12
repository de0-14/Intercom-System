<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Account   </title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="style.css">
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

            <form id="createAccountForm">
                <label for="username">Username</label>
                <input type="text" id="username" placeholder="Enter username" required>

                <label for="password">Password</label>
                <input type="password" id="password" placeholder="Enter password" required>

                <label for="role">Role</label>
                <select id="role" required>
                    <option value="">Select Role</option>
                    <option value="division">Division Head</option>
                    <option value="department">Department Head</option>
                    <option value="unit">Unit Head</option>
                    <option value="office">Office Head</option>
                </select>

                <label for="division">Division</label>
                <select id="division" disabled>
                    <option value="">Select Division</option>
                </select>

                <label for="department">Department</label>
                <select id="department" disabled>
                    <option value="">Select Department</option>
                </select>

                <label for="unit">Unit</label>
                <select id="unit" disabled>
                    <option value="">Select Unit</option>
                </select>

                <label for="office">Office</label>
                <select id="office" disabled>
                    <option value="">Select Office</option>
                </select>

                <button type="submit" class="submit-btn">Create Account</button>
                <a href="login.php">Already have an account? Click here.</a>
            </form>
        </div>
    </div>

    <div class="footer">
        © 2026 Intercom Directory. All rights reserved.<br>
        Developed by TNTS Programming Students JT.DP.RR
    </div>

    <script>
        // Sample hierarchical data
        const data = {
            "Division A": {
                "Dept 1": {
                    "Unit I": ["Office Alpha", "Office Beta"],
                    "Unit II": ["Office Gamma"]
                },
                "Dept 2": {
                    "Unit III": ["Office Delta"]
                }
            },
            "Division B": {
                "Dept 3": {
                    "Unit IV": ["Office Epsilon", "Office Zeta"]
                }
            }
        };

        const roleSelect = document.getElementById('role');
        const divisionSelect = document.getElementById('division');
        const departmentSelect = document.getElementById('department');
        const unitSelect = document.getElementById('unit');
        const officeSelect = document.getElementById('office');

        function populateDivisions() {
            divisionSelect.innerHTML = '<option value="">Select Division </option>';
            Object.keys(data).forEach(div => {
                const opt = document.createElement('option');
                opt.value = div;
                opt.textContent = div;
                divisionSelect.appendChild(opt);
            });
        }

        function populateDepartments() {
            const division = divisionSelect.value;
            departmentSelect.innerHTML = '<option value="">Select Department </option>';
            unitSelect.innerHTML = '<option value="">Select Unit</option>';
            officeSelect.innerHTML = '<option value="">Select Office</option>';
            if (division && data[division]) {
                Object.keys(data[division]).forEach(dept => {
                    const opt = document.createElement('option');
                    opt.value = dept;
                    opt.textContent = dept;
                    departmentSelect.appendChild(opt);
                });
            }
        }

        function populateUnits() {
            const division = divisionSelect.value;
            const department = departmentSelect.value;
            unitSelect.innerHTML = '<option value=""> Select Unit </option>';
            officeSelect.innerHTML = '<option value=""> Select Office </option>';
            if (division && department && data[division][department]) {
                Object.keys(data[division][department]).forEach(unit => {
                    const opt = document.createElement('option');
                    opt.value = unit;
                    opt.textContent = unit;
                    unitSelect.appendChild(opt);
                });
            }
        }

        function populateOffices() {
            const division = divisionSelect.value;
            const department = departmentSelect.value;
            const unit = unitSelect.value;
            officeSelect.innerHTML = '<option value="">Select Office </option>';
            if (division && department && unit && data[division][department][unit]) {
                data[division][department][unit].forEach(off => {
                    const opt = document.createElement('option');
                    opt.value = off;
                    opt.textContent = off;
                    officeSelect.appendChild(opt);
                });
            }
        }

        roleSelect.addEventListener('change', () => {
            const role = roleSelect.value;
            divisionSelect.disabled = false;
            departmentSelect.disabled = !(role === "department" || role === "unit" || role === "office");
            unitSelect.disabled = !(role === "unit" || role === "office");
            officeSelect.disabled = role !== "office";

            divisionSelect.value = "";
            departmentSelect.value = "";
            unitSelect.value = "";
            officeSelect.value = "";

            populateDivisions();
        });

        divisionSelect.addEventListener('change', populateDepartments);
        departmentSelect.addEventListener('change', populateUnits);
        unitSelect.addEventListener('change', populateOffices);
    </script>
</body>
</html>
