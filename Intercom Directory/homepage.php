<?php
require_once 'conn.php';
updateAllUsersActivity($conn);
// Get all contacts with rating information
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
            COALESCE(
                d.status,
                dept.status,
                u.status,
                o.status
            ) as status,
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
            n.office_id,
            COALESCE(f.avg_rating, 0) as avg_rating,
            COALESCE(f.total_feedbacks, 0) as total_feedbacks
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
        LEFT JOIN (
            SELECT 
                number_id, 
                AVG(rating) as avg_rating, 
                COUNT(*) as total_feedbacks 
            FROM feedback 
            GROUP BY number_id
        ) f ON n.number_id = f.number_id
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
$onlineAdminCount = getOnlineAdmins($conn);
$allNumbers = getAllContactNumbers($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hospital Contact Directory</title>
<style>
/* --- GENERAL --- */
* { box-sizing: border-box; margin:0; padding:0; font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }

body {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background-color: #edf4fc;
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
}

ul.nav li a {
    display: block;
    color: white;
    text-decoration: none;
    padding: 10px 18px;
    font-weight: 600;
    border-radius: 6px;
    transition: all 0.2s;
}

ul.nav li a:hover {
    background-color: rgba(255,255,255,0.2);
}

/* --- MAIN CONTENT --- */
.content {
    flex: 1;
    margin-top: 100px; /* leave space for fixed header */
    padding: 20px;
}

/* --- CONTACT DIRECTORY CARD --- */
.contact-directory {
    padding: 20px;
    background-color: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}

.contact-directory h2 {
    color: #2b6cb0;
    margin-bottom: 20px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 10px;
}

/* --- STATS SUMMARY --- */
.stats-summary {
    display: flex;
    justify-content: space-around;
    flex-wrap: wrap;
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

/* --- SEARCH FILTER --- */
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
    box-shadow: 0 0 0 3px rgba(43,108,176,0.1);
}

/* --- FILTER BUTTONS --- */
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

.filter-btn.active {
    background-color: #2b6cb0;
    color: white;
    border-color: #2b6cb0;
}

.filter-btn:hover {
    background-color: #edf2f7;
}

/* Sorting Options */
.sorting-options {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #e2e8f0;
}

.sorting-options h4 {
    color: #4a5568;
    margin-bottom: 10px;
    font-size: 14px;
}

.sort-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.sort-btn {
    padding: 6px 12px;
    border: 1px solid #e2e8f0;
    background-color: #f8f9fa;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
}

.sort-btn.active {
    background-color: #38a169;
    color: white;
    border-color: #38a169;
}

.sort-btn:hover {
    background-color: #e2e8f0;
}

/* --- CONTACT TABLE --- */
.contact-table {
    width: 100%;
    border-collapse: collapse;
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

/* --- UNIT TYPE & STATUS BADGES --- */
.unit-type {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.type-division { background-color: #bee3f8; color: #2c5282; }
.type-department { background-color: #c6f6d5; color: #276749; }
.type-unit { background-color: #fed7d7; color: #9b2c2c; }
.type-office { background-color: #fefcbf; color: #744210; }

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-active { background-color: #c6f6d5; color:#276749; border:1px solid #9ae6b4; }
.status-decommissioned { background-color:#fed7d7; color:#9b2c2c; border:1px solid #feb2b2; }
.status-inactive { background-color:#e2e8f0; color:#4a5568; border:1px solid #cbd5e0; }

/* --- RATING STARS --- */
.rating-container {
    display: flex;
    align-items: center;
    gap: 5px;
}

.star-rating-small {
    display: flex;
    gap: 2px;
}

.star {
    color: #e2e8f0;
    font-size: 14px;
}

.star.filled {
    color: #ffc107;
}

.rating-value {
    font-size: 12px;
    color: #718096;
    min-width: 40px;
}

/* --- CONTACT CELL STYLING --- */
.contact-number { font-family:'Courier New', monospace; font-weight:bold; color:#2d3748; }
.contact-head { color:#4a5568; font-style:italic; }
.contact-description { color:#718096; font-size:14px; }
.parent-info { font-size:12px; color:#a0aec0; }

/* --- NO CONTACTS --- */
.no-contacts { text-align:center; padding:40px; color:#718096; font-style:italic; }

/* --- FOOTER --- */
.footer {
    background-color: #07417f;
    color: #fff;
    text-align: center;
    padding: 18px 10px;
    font-size: 14px;
    margin-top: auto;
}

/* --- RESPONSIVE --- */
@media (max-width: 768px) {
    .header { flex-direction: column; padding: 15px; text-align: center; }
    .header .logo span { font-size: 1.3rem; }
    .contact-table { display: block; overflow-x:auto; }
    .stats-summary { flex-direction: column; gap:15px; }
    .filter-buttons, .sort-buttons { flex-direction: column; }
}
.clickable-row {
    cursor: pointer;
    transition: background-color 0.2s;
}

.clickable-row:hover {
    background-color: #f0f8ff;
}
/* --- HEADER SECTION WITH ADMIN BUTTON --- */
.header-section {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.header-section h2 {
    margin: 0;
    flex: 1;
}

/* Admin Online Button */
.admin-online-container {
    position: relative;
}

.admin-online-btn {
    background: linear-gradient(135deg, #2b6cb0, #1f4f8b);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s;
    box-shadow: 0 3px 10px rgba(43, 108, 176, 0.3);
    min-width: 150px;
}

.admin-online-btn:hover {
    background: linear-gradient(135deg, #1f4f8b, #153a6e);
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(43, 108, 176, 0.4);
}

.admin-count {
    background: rgba(255, 255, 255, 0.2);
    padding: 3px 10px;
    border-radius: 20px;
    font-weight: bold;
    font-size: 16px;
    min-width: 30px;
    text-align: center;
}

.admin-label {
    flex: 1;
    text-align: center;
}

.dropdown-arrow {
    font-size: 10px;
    transition: transform 0.3s;
}

/* Admin Dropdown */
.admin-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    width: 300px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
    margin-top: 10px;
    padding: 15px;
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.3s;
}

.admin-online-container:hover .admin-dropdown {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.admin-dropdown h4 {
    color: #2b6cb0;
    margin: 0 0 15px 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 16px;
}

.admin-list {
    max-height: 300px;
    overflow-y: auto;
}

.admin-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #f7fafc;
}

.admin-item:last-child {
    border-bottom: none;
}

.admin-name {
    color: #2d3748;
    font-weight: 500;
}

.admin-status {
    color: #718096;
    font-size: 12px;
    background: #f7fafc;
    padding: 3px 8px;
    border-radius: 4px;
}

.no-admins {
    text-align: center;
    color: #a0aec0;
    font-style: italic;
    padding: 20px;
}

.admin-footer {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #e2e8f0;
}

.current-user {
    color: #2b6cb0;
    font-weight: 600;
    font-size: 13px;
    text-align: center;
}

/* --- RESPONSIVE --- */
@media (max-width: 768px) {
    .header-section {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .admin-online-container {
        align-self: flex-end;
    }
    
    .admin-dropdown {
        width: 280px;
        right: -50px;
    }
    
    .admin-dropdown:before {
        right: 60px;
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
        <?php if (isLoggedIn()): ?>
            <?php if (isAdmin()): ?>
                <li><a href="createpage.php">Create page</a></li>
                <li><a href="editpage.php">Edit page</a></li>
            <?php endif; ?>
            <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
        <?php endif; ?>
    </ul>
</div>

<div class="content">
    <div class="contact-directory">
        <h2>Hospital Contact Directory</h2>
            <div class="admin-online-container">
                <button class="admin-online-btn" id="adminOnlineBtn">
                    <span class="admin-count"><?php echo $onlineAdminCount; ?></span>
                    <span class="admin-label">Admins Online</span>
                    <span class="dropdown-arrow">▼</span>
                </button>
                <div class="admin-dropdown" id="adminDropdown">
                    <h4>Currently Online (<?php echo $onlineAdminCount; ?>)</h4>
                    <div class="admin-list">
                        <?php
                        // Get online admins details
                        $timeout = 300; // 5 minutes
                        $onlineTime = time() - $timeout;
                        
                        $sql = "SELECT username, role_id, last_activity FROM users 
                                WHERE role_id = 1 
                                AND last_activity > ? 
                                ORDER BY username";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("i", $onlineTime);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows > 0) {
                            while ($admin = $result->fetch_assoc()) {
                                $timeAgo = time() - $admin['last_activity'];
                                $minutesAgo = floor($timeAgo / 60);
                                
                                echo '<div class="admin-item">';
                                echo '<span class="admin-name">' . htmlspecialchars($admin['username']) . '</span>';
                                echo '<span class="admin-status">';
                                if ($minutesAgo < 1) {
                                    echo 'Just now';
                                } elseif ($minutesAgo == 1) {
                                    echo '1 min ago';
                                } else {
                                    echo $minutesAgo . ' mins ago';
                                }
                                echo '</span>';
                                echo '</div>';
                            }
                        } else {
                            echo '<div class="no-admins">No admins currently online</div>';
                        }
                        ?>
                    </div>
                    <div class="admin-footer">
                        <?php 
                        // Show current user if admin
                        if (isAdmin()) {
                            echo '<div class="current-user">You: ' . htmlspecialchars($_SESSION['username'] ?? '') . '</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
            
        <!-- Stats Summary -->
        <?php 
        $totalContacts = count($allNumbers);
        $divisionsCount = 0; $departmentsCount = 0; $unitsCount = 0; $officesCount = 0; 
        $activeCount = 0; $decommissionedCount = 0;
        foreach($allNumbers as $number){
            switch($number['unit_type']){
                case 'Division': $divisionsCount++; break;
                case 'Department': $departmentsCount++; break;
                case 'Unit': $unitsCount++; break;
                case 'Office': $officesCount++; break;
            }
            if(isset($number['status'])){
                if($number['status']==='active') $activeCount++;
                elseif($number['status']==='decommissioned') $decommissionedCount++;
            }
        }
        ?>
        <div class="stats-summary">
            <div class="stat-item"><div class="stat-value"><?php echo $totalContacts; ?></div><div class="stat-label">Total Contacts</div></div>
            <div class="stat-item"><div class="stat-value"><?php echo $officesCount; ?></div><div class="stat-label">Offices</div></div>
            <div class="stat-item"><div class="stat-value"><?php echo $activeCount; ?></div><div class="stat-label">Active</div></div>
            <div class="stat-item"><div class="stat-value"><?php echo $decommissionedCount; ?></div><div class="stat-label">Decommissioned</div></div>
        </div>
        
        <!-- Search Filter -->
        <div class="search-filter">
            <input type="text" id="searchInput" placeholder="Search by number, head, description, unit, or status..." onkeyup="filterSearch()">
        </div>
        
        <!-- Filter Buttons -->
        <div class="filter-options">
            <div class="filter-buttons">
                <button class="filter-btn active" onclick="filterTable('all')">All Contacts</button>
                <button class="filter-btn" onclick="filterTable('division')">Divisions</button>
                <button class="filter-btn" onclick="filterTable('department')">Departments</button>
                <button class="filter-btn" onclick="filterTable('unit')">Units</button>
                <button class="filter-btn" onclick="filterTable('office')">Offices</button>
                <button class="filter-btn" onclick="filterTable('active')">Active</button>
                <button class="filter-btn" onclick="filterTable('decommissioned')">Decommissioned</button>
            </div>
            
            <!-- Sorting Options -->
            <div class="sorting-options">
                <h4>Sort by:</h4>
                <div class="sort-buttons">
                    <button class="sort-btn active" onclick="sortTable('default')">Default</button>
                    <button class="sort-btn" onclick="sortTable('rating')">Rating</button>
                </div>
            </div>
        </div>
        
        <!-- Contact Table -->
        <?php if($totalContacts>0): ?>
        <table class="contact-table" id="contacts-table">
            <thead>
                <tr>
                    <th>Contact Number</th>
                    <th>Description</th>
                    <th>Type</th>
                    <th>Unit/Department Name</th>
                    <th>Rating</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="contacts-tbody">
                <?php foreach($allNumbers as $contact): 
                    $typeClass=''; 
                    switch($contact['unit_type']){
                        case 'Division': $typeClass='type-division'; break;
                        case 'Department': $typeClass='type-department'; break;
                        case 'Unit': $typeClass='type-unit'; break;
                        case 'Office': $typeClass='type-office'; break;
                    }
                    $statusClass='status-inactive'; $statusText='Unknown';
                    if(isset($contact['status'])){
                        if($contact['status']==='active'){ $statusClass='status-active'; $statusText='Active'; }
                        elseif($contact['status']==='decommissioned'){ $statusClass='status-decommissioned'; $statusText='Decommissioned'; }
                    }
                    
                    // Create star rating HTML
                    $avgRating = $contact['avg_rating'] ?? 0;
                    $starHTML = '';
                    $fullStars = floor($avgRating);
                    $hasHalfStar = ($avgRating - $fullStars) >= 0.5;
                    
                    for ($i = 1; $i <= 5; $i++) {
                        if ($i <= $fullStars) {
                            $starHTML .= '<span class="star filled">★</span>';
                        } elseif ($i == $fullStars + 1 && $hasHalfStar) {
                            $starHTML .= '<span class="star filled">★</span>';
                        } else {
                            $starHTML .= '<span class="star">★</span>';
                        }
                    }
                ?>
                <tr class="contact-row <?php echo isLoggedIn() ? 'clickable-row' : 'non-clickable'; ?>" 
                    <?php if(isLoggedIn()): ?>
                        data-id="<?php echo $contact['number_id']; ?>"
                    <?php endif; ?>
                    data-type="<?php echo strtolower($contact['unit_type']); ?>" 
                    data-status="<?php echo isset($contact['status'])?$contact['status']:'unknown';?>"
                    data-rating="<?php echo $avgRating; ?>">
                        <td class="contact-number"><?php echo htmlspecialchars($contact['contact_number']); ?></td>
                        <td class="contact-description"><?php echo htmlspecialchars($contact['description']); ?></td>
                        <td><span class="unit-type <?php echo $typeClass;?>"><?php echo $contact['unit_type'];?></span></td>
                        <td>
                            <?php echo htmlspecialchars($contact['unit_name']);?>
                            <?php if($contact['parent_division'] && $contact['unit_type']!='Division'): ?>
                                <div class="parent-info">Under: <?php echo htmlspecialchars($contact['parent_division']);?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="rating-container">
                                <div class="star-rating-small">
                                    <?php echo $starHTML; ?>
                                </div>
                                <span class="rating-value"><?php echo number_format($avgRating, 1); ?> (<?php echo $contact['total_feedbacks']; ?>)</span>
                            </div>
                        </td>
                        <td><span class="status-badge <?php echo $statusClass;?>"><?php echo $statusText;?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="no-contacts">
                No contact numbers have been added yet.
                <?php if(isLoggedIn()): ?><a href="createpage.php">Add your first contact</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="footer">
    © 2026 Intercom Directory. All rights reserved.<br>
    Developed by TNTS Programming Students JT.DP.RR
</div>

<script>
let currentSort = 'default';
let originalRows = []; // Store original row order

function filterSearch(){
    const input=document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.contact-row').forEach(row=>{
        let match=false;
        row.querySelectorAll('td').forEach(td=>{
            if(td.innerText.toLowerCase().includes(input)) match=true;
        });
        row.style.display=match?'':'none';
    });
}

function filterTable(type){
    const rows=document.querySelectorAll('.contact-row');
    const buttons=document.querySelectorAll('.filter-btn');
    buttons.forEach(btn=>btn.classList.remove('active'));
    document.querySelector('.filter-btn.'+type)?.classList.add('active');

    rows.forEach(row=>{
        let show=false;
        const rowType=row.getAttribute('data-type');
        const rowStatus=row.getAttribute('data-status');
        if(type==='all') show=true;
        else if(type==='active'||type==='decommissioned') show=rowStatus===type;
        else show=rowType===type;
        row.style.display=show?'':'none';
    });
    
    // Apply current sort after filtering
    applySort(currentSort);
}

function sortTable(sortType){
    const sortButtons = document.querySelectorAll('.sort-btn');
    sortButtons.forEach(btn=>btn.classList.remove('active'));
    document.querySelector('.sort-btn[onclick="sortTable(\'' + sortType + '\')"]').classList.add('active');
    
    currentSort = sortType;
    applySort(sortType);
}

function applySort(sortType){
    const tbody = document.getElementById('contacts-tbody');
    const visibleRows = Array.from(tbody.querySelectorAll('.contact-row:not([style*="display: none"])'));
    
    // If switching back to default, restore original order
    if (sortType === 'default') {
        restoreOriginalOrder();
        return;
    }
    
    // Sort visible rows
    visibleRows.sort((a, b) => {
        switch(sortType){
            case 'rating':
                const ratingA = parseFloat(a.getAttribute('data-rating'));
                const ratingB = parseFloat(b.getAttribute('data-rating'));
                return ratingB - ratingA; // Descending order
                
            default:
                return 0;
        }
    });
    
    // Reorder visible rows in the DOM
    visibleRows.forEach(row => tbody.appendChild(row));
}

function restoreOriginalOrder() {
    const tbody = document.getElementById('contacts-tbody');
    
    // Clear and re-add all rows in original order
    tbody.innerHTML = '';
    originalRows.forEach(row => {
        // Only add row if it's visible (not filtered out)
        if (row.style.display !== 'none') {
            tbody.appendChild(row);
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Store original row order on page load
    const tbody = document.getElementById('contacts-tbody');
    originalRows = Array.from(tbody.querySelectorAll('.contact-row'));
    
    const rows = document.querySelectorAll('.clickable-row');
    
    rows.forEach(row => {
        row.addEventListener('click', function() {
            const contactId = this.getAttribute('data-id');
            window.location.href = `numpage.php?id=${contactId}`;
        });
    });
});
// Admin online button interaction
document.addEventListener('DOMContentLoaded', function() {
    const adminBtn = document.getElementById('adminOnlineBtn');
    const adminDropdown = document.getElementById('adminDropdown');
    
    if (adminBtn && adminDropdown) {
        // Toggle dropdown on click
        adminBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            adminDropdown.classList.toggle('show');
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!adminBtn.contains(e.target) && !adminDropdown.contains(e.target)) {
                adminDropdown.classList.remove('show');
            }
        });
        
        // Update admin count periodically (every 30 seconds)
        setInterval(function() {
            fetch('get_online_admins.php')
                .then(response => response.json())
                .then(data => {
                    const adminCount = document.querySelector('.admin-count');
                    const dropdownCount = adminDropdown.querySelector('h4');
                    
                    if (adminCount && data.count !== undefined) {
                        adminCount.textContent = data.count;
                    }
                    if (dropdownCount && data.count !== undefined) {
                        dropdownCount.textContent = `Currently Online (${data.count})`;
                    }
                })
                .catch(error => console.error('Error updating admin count:', error));
        }, 30000); // 30 seconds
    }
});
</script>

</body>
</html>
