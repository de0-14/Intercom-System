<?php
session_start();
$host = "localhost";
$user = "root";
$password = "";
$database = "drmc_intercom";

$conn = mysqli_connect($host, $user, $password, $database);
if(!$conn) {
    die("Connection Failed: ".mysqli_connect_error());
}
    
$role_names = [
    1=>"Admin",
    2=>"MCC",
    3=>"Division Head",
    4=>"Department Head",
    5=>"Unit Head",
    6=>"Office Head",
    7=>"Staff"
];

function getDivisions($conn, $activeOnly = true) {
    $sql = "SELECT * FROM divisions";
    if($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= " ORDER BY division_name";
    $result = mysqli_query($conn, $sql);
    $divisions = [];
    while($row = mysqli_fetch_assoc($result)) {
        $divisions[] = $row;
    }
    return $divisions;
}

function getDepartmentsByDivision($conn, $division_id, $activeOnly = true) {
    $division_id = mysqli_real_escape_string($conn, $division_id);
    $sql = "SELECT * FROM departments WHERE division_id = $division_id";
    if($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY department_name";
    $result = mysqli_query($conn, $sql);
    $departments = [];
    while($row = mysqli_fetch_assoc($result)) {
        $departments[] = $row;
    }
    return $departments;
}

function getUnitsByDepartment($conn, $department_id, $activeOnly = true) {
    $department_id = mysqli_real_escape_string($conn, $department_id);
    $sql = "SELECT * FROM units WHERE department_id = $department_id";
    if($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY unit_name";
    $result = mysqli_query($conn, $sql);
    $units = [];
    while($row = mysqli_fetch_assoc($result)) {
        $units[] = $row;
    }
    return $units;
}

function getOfficesByUnit($conn, $unit_id, $activeOnly = true) {
    $unit_id = mysqli_real_escape_string($conn, $unit_id);
    $sql = "SELECT * FROM offices WHERE unit_id = $unit_id";
    if($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY office_name";
    $result = mysqli_query($conn, $sql);
    $offices = [];
    while($row = mysqli_fetch_assoc($result)) {
        $offices[] = $row;
    }
    return $offices;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role_id']) && intval($_SESSION['role_id']) == 1;
}

function getUserName() {
    if(isset($_SESSION['username'])) {
        return htmlspecialchars($_SESSION['username']);
    } else {
        return 'WALA NI GANA';
    }
}

function getDepartments($conn, $activeOnly = true) {
    $sql = "SELECT * FROM departments";
    if($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= " ORDER BY department_name";
    $result = $conn->query($sql);
    $departments = [];
    while($row = $result->fetch_assoc()) {
        $departments[] = $row;
    }
    return $departments;
}

function getUnits($conn, $activeOnly = true) {
    $sql = "SELECT * FROM units";
    if($activeOnly) {
        $sql .= " WHERE status = 'active'";
    }
    $sql .= " ORDER BY unit_name";
    $result = $conn->query($sql);
    $units = [];
    while($row = $result->fetch_assoc()) {
        $units[] = $row;
    }
    return $units;
}

function updateUserActivity($conn, $userId) {
    $currentTime = time();
    $sql = "UPDATE users SET last_activity = ? WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $currentTime, $userId);
    $stmt->execute();
}

function getOnlineAdmins($conn) {
    $timeout = 300;
    $onlineTime = time() - $timeout;
    
    $sql = "SELECT COUNT(*) as online_count FROM users 
            WHERE role_id = 1 
            AND last_activity > ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $onlineTime);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row['online_count'] ?? 0;
}

function updateAllUsersActivity($conn) {
    if (isset($_SESSION['user_id'])) {
        updateUserActivity($conn, $_SESSION['user_id']);
    }
}

function getHeadUserId($conn, $number_id) {
    $sql = "SELECT head_user_id FROM numbers WHERE number_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $number_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['head_user_id'] ?? null;
}

function debugHeadInfo($conn, $number_id, $current_user_id) {
    $head_user_id = getHeadUserId($conn, $number_id);
    $head_name = getHeadName($conn, $number_id);
    
    echo "<div style='background:#f0f0f0; padding:10px; margin:10px 0; border:1px solid #ccc;'>";
    echo "<strong>DEBUG INFO:</strong><br>";
    echo "Number ID: $number_id<br>";
    echo "Current User ID: $current_user_id<br>";
    echo "Head Name: $head_name<br>";
    echo "Head User ID: $head_user_id<br>";
    echo "Is Current User Head?: " . ($head_user_id == $current_user_id ? 'YES' : 'NO') . "<br>";
    echo "</div>";
    
    return $head_user_id;
}
// ... (all your existing conn.php code) ...

function getHeadName($conn, $number_id) {
    $sql = "SELECT head FROM numbers WHERE number_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $number_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['head'] ?? null;
}

// ... (rest of your functions) ...
?>
?>