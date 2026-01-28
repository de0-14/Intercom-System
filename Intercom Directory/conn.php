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

function getAllActiveUsers($conn) {
    $sql = "SELECT user_id, username, full_name FROM users WHERE status = 'active' ORDER BY full_name";
    $result = mysqli_query($conn, $sql);
    $users = [];
    while($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }
    return $users;
}

function getUsersByMinRole($conn, $minRoleId) {
    $sql = "SELECT u.user_id, u.username, u.full_name 
            FROM users u 
            WHERE u.role_id <= ? AND u.status = 'active'
            ORDER BY u.full_name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $minRoleId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    return $users;
}

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

function getOnlineUsers($conn) {
    $timeout = 300;
    $onlineTime = time() - $timeout;
    
    $sql = "SELECT u.user_id, u.username, u.full_name, u.role_id, r.role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.role_id
            WHERE u.last_activity > ? 
            AND u.status = 'active'
            ORDER BY u.role_id, u.full_name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $onlineTime);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    return $users;
}

function createAdminChatConversation($conn, $admin_id, $user_id) {
    $check_sql = "SELECT chat_id FROM admin_chats WHERE admin_id = ? AND user_id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("ii", $admin_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['chat_id'];
    }
    
    $insert_sql = "INSERT INTO admin_chats (admin_id, user_id, created_at) VALUES (?, ?, NOW())";
    $stmt = $conn->prepare($insert_sql);
    $stmt->bind_param("ii", $admin_id, $user_id);
    if($stmt->execute()) {
        return $conn->insert_id;
    }
    
    return null;
}

function getAdminChatMessages($conn, $chat_id) {
    $sql = "SELECT m.*, u.username, u.full_name 
            FROM admin_messages m 
            JOIN users u ON m.sender_id = u.user_id 
            WHERE m.chat_id = ? 
            ORDER BY m.created_at ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $chat_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    
    return $messages;
}

function sendAdminMessage($conn, $chat_id, $sender_id, $message) {
    $sql = "INSERT INTO admin_messages (chat_id, sender_id, message, created_at) VALUES (?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $chat_id, $sender_id, $message);
    return $stmt->execute();
}

function getAdminChats($conn, $user_id, $is_admin = false) {
    if($is_admin) {
        $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
                (SELECT message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message_time
                FROM admin_chats ac 
                JOIN users u ON ac.user_id = u.user_id 
                WHERE ac.admin_id = ?
                ORDER BY last_message_time DESC";
    } else {
        $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
                (SELECT message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message_time
                FROM admin_chats ac 
                JOIN users u ON ac.admin_id = u.user_id 
                WHERE ac.user_id = ?
                ORDER BY last_message_time DESC";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $chats = [];
    while($row = $result->fetch_assoc()) {
        $chats[] = $row;
    }
    
    return $chats;
}

function markAdminMessagesAsRead($conn, $chat_id, $receiver_id) {
    $sql = "UPDATE admin_messages SET is_read = 1 WHERE chat_id = ? AND sender_id != ? AND is_read = 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $chat_id, $receiver_id);
    return $stmt->execute();
}

function getUnreadAdminMessageCount($conn, $user_id, $is_admin = false) {
    if($is_admin) {
        $sql = "SELECT COUNT(*) as unread_count 
                FROM admin_messages am 
                JOIN admin_chats ac ON am.chat_id = ac.chat_id 
                WHERE ac.admin_id = ? AND am.sender_id != ? AND am.is_read = 0";
    } else {
        $sql = "SELECT COUNT(*) as unread_count 
                FROM admin_messages am 
                JOIN admin_chats ac ON am.chat_id = ac.chat_id 
                WHERE ac.user_id = ? AND am.sender_id != ? AND am.is_read = 0";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row['unread_count'] ?? 0;
}

function getAvailableAdmins($conn) {
    $sql = "SELECT u.user_id, u.username, u.full_name 
            FROM users u 
            WHERE u.role_id = 1 
            AND u.status = 'active'
            ORDER BY u.full_name";
    $result = mysqli_query($conn, $sql);
    
    $admins = [];
    while($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }
    
    return $admins;
}

function getAdminChatWithUser($conn, $admin_id, $user_id) {
    $sql = "SELECT chat_id FROM admin_chats WHERE admin_id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $admin_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['chat_id'];
    }
    
    return null;
}

function getUserChatsWithAllAdmins($conn, $user_id) {
    $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
            (SELECT message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message,
            (SELECT created_at FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message_time
            FROM admin_chats ac 
            JOIN users u ON ac.admin_id = u.user_id 
            WHERE ac.user_id = ?
            ORDER BY last_message_time DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $chats = [];
    while($row = $result->fetch_assoc()) {
        $chats[] = $row;
    }
    
    return $chats;
}

function getAdminsWithChatStatus($conn, $user_id) {
    $sql = "SELECT u.user_id, u.username, u.full_name, 
            (SELECT chat_id FROM admin_chats WHERE admin_id = u.user_id AND user_id = ?) as chat_id,
            (SELECT COUNT(*) FROM admin_messages am 
             JOIN admin_chats ac ON am.chat_id = ac.chat_id 
             WHERE ac.admin_id = u.user_id AND ac.user_id = ? AND am.sender_id != ? AND am.is_read = 0) as unread_count
            FROM users u 
            WHERE u.role_id = 1 
            AND u.status = 'active'
            AND u.user_id != ?
            ORDER BY u.full_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $user_id, $user_id, $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $admins = [];
    while($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }
    
    return $admins;
}
?>