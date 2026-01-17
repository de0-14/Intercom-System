<?php
require_once 'conn.php';

// Remove the duplicate isLoggedIn() and getUserName() functions from here
// They are already defined in conn.php

function getAllContactNumbers($conn) {
    $sql = "
        SELECT 
            n.number_id,
            n.numbers as contact_number,
            n.description,
            n.head,
            COALESCE(
                d.division_name,
                dept.department_name,
                u.unit_name,
                o.office_name
            ) as unit_name,
            CASE 
                WHEN n.division_id IS NOT NULL THEN 'Division'
                WHEN n.department_id IS NOT NULL THEN 'Department'
                WHEN n.unit_id IS NOT NULL THEN 'Unit'
                WHEN n.office_id IS NOT NULL THEN 'Office'
                ELSE 'Unknown'
            END as unit_type,
            CASE 
                WHEN n.division_id IS NOT NULL THEN d.division_name
                WHEN n.department_id IS NOT NULL THEN d2.division_name
                WHEN n.unit_id IS NOT NULL THEN d3.division_name
                                WHEN n.office_id IS NOT NULL THEN d4.division_name
                ELSE NULL
            END as parent_division,
            n.division_id,
            n.department_id,
            n.unit_id,
            n.office_id
        FROM numbers n
        LEFT JOIN divisions d ON n.division_id = d.division_id
        LEFT JOIN departments dept ON n.department_id = dept.department_id
        LEFT JOIN divisions d2 ON dept.division_id = d2.division_id
        LEFT JOIN units u ON n.unit_id = u.unit_id
        LEFT JOIN departments dept2 ON u.department_id = dept2.department_id
        LEFT JOIN divisions d3 ON dept2.division_id = d3.division_id
        LEFT JOIN offices o ON n.office_id = o.office_id
        LEFT JOIN units u2 ON o.unit_id = u2.unit_id
        LEFT JOIN departments dept3 ON u2.department_id = dept3.department_id
        LEFT JOIN divisions d4 ON dept3.division_id = d4.division_id
        ORDER BY 
            CASE unit_type
                WHEN 'Division' THEN 1
                WHEN 'Department' THEN 2
                WHEN 'Unit' THEN 3
                WHEN 'Office' THEN 4
                ELSE 5
            END,
            unit_name,
            n.description
    ";
    
    $result = mysqli_query($conn, $sql);
    $numbers = [];
    while($row = mysqli_fetch_assoc($result)) {
        $numbers[] = $row;
    }
    return $numbers;
}

$allNumbers = getAllContactNumbers($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Contact Directory</title>
    <link rel="stylesheet" type="text/css" href="style.css">
    <style>
        .contact-directory {
            margin: 20px;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .contact-directory h2 {
            color: #2b6cb0;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .contact-table {
            width: 100%;
            border-collapse: collapse;
            background-color: white;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .contact-table th {
            background-color: #2b6cb0;
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .contact-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .contact-table tr:hover {
            background-color: #f7fafc;
        }
        
        .contact-table tr:last-child td {
            border-bottom: none;
        }
        
        .unit-type {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .type-division {
            background-color: #bee3f8;
            color: #2c5282;
        }
        
        .type-department {
            background-color: #c6f6d5;
            color: #276749;
        }
        
        .type-unit {
            background-color: #fed7d7;
            color: #9b2c2c;
        }
        
        .type-office {
            background-color: #fefcbf;
            color: #744210;
        }
        
        .contact-number {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #2d3748;
        }
        
        .contact-head {
            color: #4a5568;
            font-style: italic;
        }
        
        .contact-description {
            color: #718096;
            font-size: 14px;
        }
        
        .parent-info {
            font-size: 12px;
            color: #a0aec0;
        }
        
        .no-contacts {
            text-align: center;
            padding: 40px;
            color: #718096;
            font-style: italic;
        }
        
        .stats-summary {
            display: flex;
            justify-content: space-around;
            margin-bottom: 20px;
            padding: 15px;
            background-color: white;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #2b6cb0;
        }
        
        .stat-label {
            font-size: 14px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .filter-options {
            margin-bottom: 20px;
            padding: 15px;
            background-color: white;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            background-color: white;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .filter-btn:hover {
            background-color: #edf2f7;
        }
        
        .filter-btn.active {
            background-color: #2b6cb0;
            color: white;
            border-color: #2b6cb0;
        }
        
        .search-filter {
            background-color: white;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .search-filter input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .search-filter input:focus {
            outline: none;
            border-color: #2b6cb0;
            box-shadow: 0 0 0 3px rgba(43, 108, 176, 0.1);
        }
        
        @media (max-width: 768px) {
            .contact-table {
                display: block;
                overflow-x: auto;
            }
            
            .stats-summary {
                flex-direction: column;
                gap: 15px;
            }
            
            .contact-table th,
            .contact-table td {
                padding: 8px 10px;
                font-size: 14px;
            }
            
            .filter-buttons {
                flex-direction: column;
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
        <div>
            <ul class="nav">
                <li id="home"><a href="homepage.php"> Homepage </a></li>
                <?php if (isLoggedIn()): ?>
                    <li id="create"><a href="createpage.php"> Create page</a></li>
                    <li id="edit"><a href="editpage.php"> Edit page </a></li>
                    <li id="logout"><a href="logout.php"> Logout (<?php echo getUserName(); ?>)</a></li>
                <?php else: ?>
                    <li id="login"><a href="login.php"> Login </a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="content">
        <div class="contact-directory">
            <h2>Hospital Contact Directory</h2>
            
            <?php 
            $totalContacts = count($allNumbers);
            $divisionsCount = 0;
            $departmentsCount = 0;
            $unitsCount = 0;
            $officesCount = 0;
            
            foreach ($allNumbers as $number) {
                switch($number['unit_type']) {
                    case 'Division': $divisionsCount++; break;
                    case 'Department': $departmentsCount++; break;
                    case 'Unit': $unitsCount++; break;
                    case 'Office': $officesCount++; break;
                }
            }
            ?>
            
            <div class="stats-summary">
                <div class="stat-item">
                    <div class="stat-value"><?php echo $totalContacts; ?></div>
                    <div class="stat-label">Total Contacts</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $divisionsCount; ?></div>
                    <div class="stat-label">Divisions</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $departmentsCount; ?></div>
                    <div class="stat-label">Departments</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $unitsCount; ?></div>
                    <div class="stat-label">Units</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo $officesCount; ?></div>
                    <div class="stat-label">Offices</div>
                </div>
            </div>
            
            <div class="search-filter">
                <input type="text" id="searchInput" placeholder="Search by contact number, head name, description, or unit..." onkeyup="filterSearch()">
            </div>
            
            <div class="filter-options">
                <div class="filter-buttons">
                    <button class="filter-btn active" onclick="filterTable('all')">All Contacts</button>
                    <button class="filter-btn" onclick="filterTable('division')">Divisions</button>
                    <button class="filter-btn" onclick="filterTable('department')">Departments</button>
                    <button class="filter-btn" onclick="filterTable('unit')">Units</button>
                    <button class="filter-btn" onclick="filterTable('office')">Offices</button>
                </div>
            </div>
            
            <?php if ($totalContacts > 0): ?>
                <table class="contact-table" id="contacts-table">
                    <thead>
                        <tr>
                            <th>Contact Number</th>
                            <th>Type</th>
                            <th>Unit/Department Name</th>
                            <th>Head</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allNumbers as $contact): ?>
                            <?php 
                            $typeClass = '';
                            switch($contact['unit_type']) {
                                case 'Division': $typeClass = 'type-division'; break;
                                case 'Department': $typeClass = 'type-department'; break;
                                case 'Unit': $typeClass = 'type-unit'; break;
                                case 'Office': $typeClass = 'type-office'; break;
                            }
                            ?>
                            <tr class="contact-row" data-type="<?php echo strtolower($contact['unit_type']); ?>">
                                <td>
                                    <div class="contact-number"><?php echo htmlspecialchars($contact['contact_number']); ?></div>
                                </td>
                                <td>
                                    <span class="unit-type <?php echo $typeClass; ?>">
                                        <?php echo $contact['unit_type']; ?>
                                    </span>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($contact['unit_name']); ?></div>
                                    <?php if ($contact['parent_division'] && $contact['unit_type'] != 'Division'): ?>
                                        <div class="parent-info">
                                            Under: <?php echo htmlspecialchars($contact['parent_division']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="contact-head"><?php echo htmlspecialchars($contact['head']); ?></div>
                                </td>
                                <td>
                                    <div class="contact-description"><?php echo htmlspecialchars($contact['description']); ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-contacts">
                    No contact numbers have been added yet. 
                    <?php if (isLoggedIn()): ?>
                        <a href="createpage.php">Add your first contact</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function filterSearch() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const rows = document.querySelectorAll('.contact-row');
            
            rows.forEach(row => {
                const cells = row.getElementsByTagName('td');
                let found = false;
                
                for (let i = 0; i < cells.length; i++) {
                    if (cells[i]) {
                        const text = cells[i].textContent || cells[i].innerText;
                        if (text.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                row.style.display = found ? '' : 'none';
            });
        }
        
        function filterTable(type) {
            const rows = document.querySelectorAll('.contact-row');
            const buttons = document.querySelectorAll('.filter-btn');
            
            buttons.forEach(btn => {
                btn.classList.remove('active');
                if (type === 'all' && btn.textContent.includes('All')) {
                    btn.classList.add('active');
                } else if (type === 'division' && btn.textContent.includes('Divisions')) {
                    btn.classList.add('active');
                } else if (type === 'department' && btn.textContent.includes('Departments')) {
                    btn.classList.add('active');
                } else if (type === 'unit' && btn.textContent.includes('Units')) {
                    btn.classList.add('active');
                } else if (type === 'office' && btn.textContent.includes('Offices')) {
                    btn.classList.add('active');
                }
            });
            
            let visibleCount = 0;
            rows.forEach(row => {
                const rowType = row.getAttribute('data-type');
                
                if (type === 'all' || rowType === type) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            if (visibleCount === 0 && type !== 'all') {
                let noResultsMsg = document.getElementById('no-results-message');
                if (!noResultsMsg) {
                    noResultsMsg = document.createElement('div');
                    noResultsMsg.id = 'no-results-message';
                    noResultsMsg.className = 'no-contacts';
                    noResultsMsg.innerHTML = `No ${type} contacts found.`;
                    const table = document.getElementById('contacts-table');
                    table.parentNode.insertBefore(noResultsMsg, table.nextSibling);
                }
            } else {
                const noResultsMsg = document.getElementById('no-results-message');
                if (noResultsMsg) {
                    noResultsMsg.remove();
                }
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const filterButtons = document.querySelectorAll('.filter-btn');
            filterButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const btnText = this.textContent.trim();
                    let filterType = 'all';
                    
                    if (btnText.includes('Divisions')) filterType = 'division';
                    else if (btnText.includes('Departments')) filterType = 'department';
                    else if (btnText.includes('Units')) filterType = 'unit';
                    else if (btnText.includes('Offices')) filterType = 'office';
                    
                    filterTable(filterType);
                });
            });
        });
    </script>
</body>
</html>