<?php
session_start();

$serverName = "172.16.0.34";
$uid = "ojt_user";
$pass = "ICT@2026";
$database = "testDB";

$connection = [
    "Database" => $database,
    "Uid" => $uid,
    "Pwd" => $pass,
    "TrustServerCertificate" => true,
    "CharacterSet" => "UTF-8"
];

$conn = sqlsrv_connect($serverName, $connection);
if(!$conn) {
    die(print_r(sqlsrv_errors(), true));
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

// ============================================
// SQL SERVER COMPATIBLE FUNCTIONS
// ============================================

function getHeadUnreadForContact($conn, $head_user_id, $number_id) {
    $sql = "SELECT COUNT(DISTINCT m.message_id) as unread_count
            FROM messages m
            INNER JOIN conversations c ON m.conversation_id = c.conversation_id
            WHERE c.number_id = ?
            AND m.receiver_id = ?
            AND m.is_read = 0
            AND m.is_archived = 0
            AND c.is_archived = 0";
    
    $params = array($number_id, $head_user_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return 0;
    
    if(!sqlsrv_execute($stmt)) return 0;
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row['unread_count'] ?? 0;
}

function getAllActiveUsers($conn) {
    $sql = "SELECT user_id, username, full_name FROM users WHERE status = 'active' ORDER BY full_name";
    $stmt = sqlsrv_query($conn, $sql);
    if($stmt === false) return [];
    
    $users = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $users[] = $row;
    }
    return $users;
}

function getUsersByMinRole($conn, $minRoleId) {
    $sql = "SELECT u.user_id, u.username, u.full_name 
            FROM users u 
            WHERE u.role_id <= ? AND u.status = 'active'
            ORDER BY u.full_name";
    
    $params = array($minRoleId);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return [];
    
    if(!sqlsrv_execute($stmt)) return [];
    
    $users = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
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
    
    $stmt = sqlsrv_query($conn, $sql);
    if($stmt === false) return [];
    
    $divisions = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $divisions[] = $row;
    }
    return $divisions;
}

function getDepartmentsByDivision($conn, $division_id, $activeOnly = true) {
    $sql = "SELECT * FROM departments WHERE division_id = ?";
    if($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY department_name";
    
    $params = array($division_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return [];
    
    if(!sqlsrv_execute($stmt)) return [];
    
    $departments = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $departments[] = $row;
    }
    return $departments;
}

function getUnitsByDepartment($conn, $department_id, $activeOnly = true) {
    $sql = "SELECT * FROM units WHERE department_id = ?";
    if($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY unit_name";
    
    $params = array($department_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return [];
    
    if(!sqlsrv_execute($stmt)) return [];
    
    $units = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $units[] = $row;
    }
    return $units;
}

function getOfficesByUnit($conn, $unit_id, $activeOnly = true) {
    $sql = "SELECT * FROM offices WHERE unit_id = ?";
    if($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY office_name";
    
    $params = array($unit_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return [];
    
    if(!sqlsrv_execute($stmt)) return [];
    
    $offices = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
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
    
    $stmt = sqlsrv_query($conn, $sql);
    if($stmt === false) return [];
    
    $departments = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
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
    
    $stmt = sqlsrv_query($conn, $sql);
    if($stmt === false) return [];
    
    $units = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $units[] = $row;
    }
    return $units;
}

function updateUserActivity($conn, $userId) {
    $currentTime = time();
    $sql = "UPDATE users SET last_activity = ? WHERE user_id = ?";
    $params = array($currentTime, $userId);
    
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return false;
    
    return sqlsrv_execute($stmt);
}

function getOnlineAdmins($conn) {
    $timeout = 300;
    $onlineTime = time() - $timeout;
    
    $sql = "SELECT COUNT(*) as online_count FROM users 
            WHERE role_id = 1 
            AND last_activity > ?";
    
    $params = array($onlineTime);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return 0;
    
    if(!sqlsrv_execute($stmt)) return 0;
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row['online_count'] ?? 0;
}

function updateAllUsersActivity($conn) {
    if (isset($_SESSION['user_id'])) {
        $current_time = time(); // Use integer timestamp
        $sql = "UPDATE users SET last_activity = ? WHERE user_id = ?";
        $stmt = sqlsrv_query($conn, $sql, array($current_time, $_SESSION['user_id']));
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
    }
}

function getHeadUserId($conn, $number_id) {
    $sql = "SELECT head_user_id FROM numbers WHERE number_id = ?";
    $params = array($number_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return null;
    
    if(!sqlsrv_execute($stmt)) return null;
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row['head_user_id'] ?? null;
}

function getOnlineUsers($conn) {
    $timeout = 300; // 5 minutes
    $onlineTime = time() - $timeout;
    
    // Since last_activity is stored as an integer (Unix timestamp)
    // Compare directly with the integer timestamp
    $sql = "SELECT 
                u.user_id, 
                u.username, 
                u.full_name, 
                u.role_id,
                u.last_activity,
                r.role_name
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.role_id
            WHERE u.last_activity > ?
            ORDER BY u.last_activity DESC";
    
    $params = array($onlineTime); // Pass integer directly, not formatted date
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        error_log("getOnlineUsers SQL Error: " . print_r(sqlsrv_errors(), true));
        return [];
    }
    
    $online_users = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $online_users[] = $row;
    }
    
    sqlsrv_free_stmt($stmt);
    return $online_users;
}

function createAdminChatConversation($conn, $admin_id, $user_id) {
    // First, check if there's an active chat
    $check_sql = "SELECT chat_id FROM admin_chats WHERE admin_id = ? AND user_id = ? AND is_archived = 0";
    $params = array($admin_id, $user_id);
    $stmt = sqlsrv_prepare($conn, $check_sql, $params);
    
    if(!$stmt) return null;
    if(!sqlsrv_execute($stmt)) return null;
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if($row) {
        return $row['chat_id'];
    }
    
    // Create new chat
    $insert_sql = "INSERT INTO admin_chats (admin_id, user_id, created_at, last_activity, is_archived) 
                   OUTPUT INSERTED.chat_id
                   VALUES (?, ?, GETDATE(), GETDATE(), 0)";
    
    $params = array($admin_id, $user_id);
    $stmt = sqlsrv_prepare($conn, $insert_sql, $params);
    if(!$stmt) return null;
    
    if(!sqlsrv_execute($stmt)) return null;
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row ? $row['chat_id'] : null;
}

function getAdminChatMessages($conn, $chat_id) {
    $sql = "
        SELECT m.*, u.username, u.full_name 
        FROM admin_messages m 
        JOIN users u ON m.sender_id = u.user_id 
        WHERE m.chat_id = ? 
        AND m.is_archived = 0  -- Add this condition!
        ORDER BY m.created_at ASC
    ";
    
    $params = array($chat_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if (!$stmt) {
        error_log("getAdminChatMessages error: " . print_r(sqlsrv_errors(), true));
        return [];
    }
    
    $messages = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $messages[] = $row;
    }
    
    sqlsrv_free_stmt($stmt);
    return $messages;
}

// Add this function to conn.php
function getUsersByRoleIds($conn, $role_ids) {
    if (empty($role_ids)) return [];
    
    $placeholders = implode(',', array_fill(0, count($role_ids), '?'));
    $sql = "SELECT user_id, full_name, username, role_id 
            FROM users 
            WHERE role_id IN ($placeholders) 
            AND status = 'active'
            ORDER BY full_name";
    
    $stmt = sqlsrv_prepare($conn, $sql, $role_ids);
    if (!$stmt) return [];
    
    if (!sqlsrv_execute($stmt)) return [];
    
    $users = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $users[] = $row;
    }
    return $users;
}

/**
 * Safely format a date/time value from SQL Server
 * Handles both DateTime objects and string dates
 */
function safeDateFormat($dateTime, $format = 'Y-m-d H:i:s') {
    if ($dateTime === null) {
        return 'N/A';
    }
    
    if ($dateTime instanceof DateTime) {
        return $dateTime->format($format);
    }
    
    // If it's already a string, try to create DateTime from it
    if (is_string($dateTime)) {
        try {
            $dt = new DateTime($dateTime);
            return $dt->format($format);
        } catch (Exception $e) {
            return $dateTime; // Return original string if parsing fails
        }
    }
    
    return 'N/A';
}

function sendAdminMessage($conn, $chat_id, $sender_id, $message) {
    $sql = "INSERT INTO admin_messages (chat_id, sender_id, message, created_at) 
            VALUES (?, ?, ?, GETDATE())";
    
    $params = array($chat_id, $sender_id, $message);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return false;
    
    return sqlsrv_execute($stmt);
}

function getAdminChats($conn, $user_id, $is_admin = false) {
    if($is_admin) {
        $sql = "
            SELECT 
                ac.*, 
                u.username, 
                u.full_name, 
                u.role_id,
                (
                    SELECT TOP 1 message 
                    FROM admin_messages 
                    WHERE chat_id = ac.chat_id 
                    AND is_archived = 0  -- Add this condition!
                    ORDER BY created_at DESC
                ) as last_message,
                (
                    SELECT TOP 1 created_at 
                    FROM admin_messages 
                    WHERE chat_id = ac.chat_id 
                    AND is_archived = 0  -- Add this condition!
                    ORDER BY created_at DESC
                ) as last_message_time
            FROM admin_chats ac 
            JOIN users u ON ac.user_id = u.user_id 
            WHERE ac.admin_id = ? 
            AND ac.is_archived = 0
            ORDER BY ac.last_activity DESC
        ";
    } else {
        $sql = "
            SELECT 
                ac.*, 
                u.username, 
                u.full_name, 
                u.role_id,
                (
                    SELECT TOP 1 message 
                    FROM admin_messages 
                    WHERE chat_id = ac.chat_id 
                    AND is_archived = 0  -- Add this condition!
                    ORDER BY created_at DESC
                ) as last_message,
                (
                    SELECT TOP 1 created_at 
                    FROM admin_messages 
                    WHERE chat_id = ac.chat_id 
                    AND is_archived = 0  -- Add this condition!
                    ORDER BY created_at DESC
                ) as last_message_time
            FROM admin_chats ac 
            JOIN users u ON ac.admin_id = u.user_id 
            WHERE ac.user_id = ? 
            AND ac.is_archived = 0
            ORDER BY ac.last_activity DESC
        ";
    }
    
    $params = array($user_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if (!$stmt) {
        error_log("getAdminChats error: " . print_r(sqlsrv_errors(), true));
        return [];
    }
    
    $chats = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Fix: If last_message is NULL, show 'No messages yet'
        $row['last_message'] = $row['last_message'] ?? 'No messages yet';
        $row['last_message_time'] = $row['last_message_time'] ?? null;
        $chats[] = $row;
    }
    
    sqlsrv_free_stmt($stmt);
    return $chats;
}

function markAdminMessagesAsRead($conn, $chat_id, $receiver_id) {
    $sql = "UPDATE admin_messages SET is_read = 1 WHERE chat_id = ? AND sender_id != ? AND is_read = 0";
    $params = array($chat_id, $receiver_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return false;
    
    return sqlsrv_execute($stmt);
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
    
    $params = array($user_id, $user_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return 0;
    
    if(!sqlsrv_execute($stmt)) return 0;
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row['unread_count'] ?? 0;
}

function getAvailableAdmins($conn) {
    $sql = "SELECT u.user_id, u.username, u.full_name 
            FROM users u 
            WHERE u.role_id = 1 
            AND u.status = 'active'
            ORDER BY u.full_name";
    
    $stmt = sqlsrv_query($conn, $sql);
    if($stmt === false) return [];
    
    $admins = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $admins[] = $row;
    }
    
    return $admins;
}

function getAdminChatWithUser($conn, $admin_id, $user_id) {
    $sql = "SELECT chat_id FROM admin_chats WHERE admin_id = ? AND user_id = ? AND is_archived = 0";
    $params = array($admin_id, $user_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return null;
    
    if(!sqlsrv_execute($stmt)) return null;
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row ? $row['chat_id'] : null;
}

function getUserChatsWithAllAdmins($conn, $user_id) {
    $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
            (SELECT TOP 1 message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as last_message,
            (SELECT TOP 1 created_at FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as last_message_time
            FROM admin_chats ac 
            JOIN users u ON ac.admin_id = u.user_id 
            WHERE ac.user_id = ? AND ac.is_archived = 0
            ORDER BY last_message_time DESC";
    
    $params = array($user_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return [];
    
    if(!sqlsrv_execute($stmt)) return [];
    
    $chats = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $chats[] = $row;
    }
    
    return $chats;
}

function getAdminsWithChatStatus($conn, $user_id) {
    $sql = "SELECT u.user_id, u.username, u.full_name, 
            (SELECT TOP 1 chat_id FROM admin_chats WHERE admin_id = u.user_id AND user_id = ? AND is_archived = 0) as chat_id,
            (SELECT COUNT(*) FROM admin_messages am 
             JOIN admin_chats ac ON am.chat_id = ac.chat_id 
             WHERE ac.admin_id = u.user_id AND ac.user_id = ? AND am.sender_id != ? AND am.is_read = 0) as unread_count
            FROM users u 
            WHERE u.role_id = 1 
            AND u.status = 'active'
            AND u.user_id != ?
            ORDER BY u.full_name";
    
    $params = array($user_id, $user_id, $user_id, $user_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return [];
    
    if(!sqlsrv_execute($stmt)) return [];
    
    $admins = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $admins[] = $row;
    }
    
    return $admins;
}

function updateConversationActivity($conn, $conversation_id) {
    $update_sql = "UPDATE conversations SET last_activity = GETDATE() WHERE conversation_id = ?";
    $params = array($conversation_id);
    $stmt = sqlsrv_prepare($conn, $update_sql, $params);
    if(!$stmt) return false;
    
    return sqlsrv_execute($stmt);
}

function archiveInactiveConversations($conn, $inactive_minutes = 5) {
    try {
        // Get conversations inactive for X minutes and not already archived
        $inactive_time = date('Y-m-d H:i:s', strtotime("-$inactive_minutes minutes"));
        
        $sql = "SELECT conversation_id 
                FROM conversations 
                WHERE is_archived = 0 
                AND last_activity < ? 
                AND conversation_id IN (
                    SELECT DISTINCT conversation_id FROM messages
                )";
        
        $params = array($inactive_time);
        $stmt = sqlsrv_prepare($conn, $sql, $params);
        if(!$stmt) return 0;
        if(!sqlsrv_execute($stmt)) return 0;
        
        $archived_count = 0;
        
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $conversation_id = $row['conversation_id'];
            
            // Archive conversation
            $archive_conv = "INSERT INTO conversations_archive SELECT * FROM conversations WHERE conversation_id = ?";
            $params_conv = array($conversation_id);
            $stmt_conv = sqlsrv_prepare($conn, $archive_conv, $params_conv);
            if($stmt_conv && sqlsrv_execute($stmt_conv)) {
                // Archive messages
                $archive_msgs = "INSERT INTO messages_archive SELECT * FROM messages WHERE conversation_id = ?";
                $params_msgs = array($conversation_id);
                $stmt_msgs = sqlsrv_prepare($conn, $archive_msgs, $params_msgs);
                if($stmt_msgs && sqlsrv_execute($stmt_msgs)) {
                    // Mark as archived
                    $mark_conv = "UPDATE conversations SET is_archived = 1, archived_at = GETDATE() WHERE conversation_id = ?";
                    $mark_msgs = "UPDATE messages SET is_archived = 1, archived_at = GETDATE() WHERE conversation_id = ?";
                    
                    $params_mark = array($conversation_id);
                    $stmt_mark1 = sqlsrv_prepare($conn, $mark_conv, $params_mark);
                    $stmt_mark2 = sqlsrv_prepare($conn, $mark_msgs, $params_mark);
                    
                    if($stmt_mark1 && $stmt_mark2 && sqlsrv_execute($stmt_mark1) && sqlsrv_execute($stmt_mark2)) {
                        $archived_count++;
                    }
                }
            }
        }
        
        return $archived_count;
        
    } catch (Exception $e) {
        error_log("Archive error: " . $e->getMessage());
        return 0;
    }
}

function getAdminChatsWithArchive($conn, $user_id, $is_admin = false) {
    if($is_admin) {
        $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
                (SELECT TOP 1 message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as last_message,
                (SELECT TOP 1 created_at FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as last_message_time
                FROM admin_chats ac 
                JOIN users u ON ac.user_id = u.user_id 
                WHERE ac.admin_id = ? AND ac.is_archived = 0
                ORDER BY last_message_time DESC";
    } else {
        $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
                (SELECT TOP 1 message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as last_message,
                (SELECT TOP 1 created_at FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as last_message_time
                FROM admin_chats ac 
                JOIN users u ON ac.admin_id = u.user_id 
                WHERE ac.user_id = ? AND ac.is_archived = 0
                ORDER BY last_message_time DESC";
    }
    
    $params = array($user_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return [];
    
    if(!sqlsrv_execute($stmt)) return [];
    
    $chats = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $chats[] = $row;
    }
    
    return $chats;
}

function getHeadContacts($conn, $user_id) {
    $sql = "SELECT n.*, d.division_name, dept.department_name, u.unit_name, o.office_name,
                   COALESCE(d.division_name, dept.department_name, u.unit_name, o.office_name) as unit_name,
                   CASE 
                       WHEN n.division_id IS NOT NULL THEN 'Division'
                       WHEN n.department_id IS NOT NULL THEN 'Department'
                       WHEN n.unit_id IS NOT NULL THEN 'Unit'
                       WHEN n.office_id IS NOT NULL THEN 'Office'
                       ELSE 'Unknown'
                   END as unit_type
            FROM numbers n
            LEFT JOIN divisions d ON n.division_id = d.division_id
            LEFT JOIN departments dept ON n.department_id = dept.department_id
            LEFT JOIN units u ON n.unit_id = u.unit_id
            LEFT JOIN offices o ON n.office_id = o.office_id
            WHERE n.head_user_id = ? 
            AND n.status = 'active'
            ORDER BY unit_type, unit_name";
    
    $params = array($user_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return [];
    
    if(!sqlsrv_execute($stmt)) return [];
    
    $contacts = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $contacts[] = $row;
    }
    
    return $contacts;
}

function getHeadUnreadCount($conn, $head_user_id) {
    $sql = "SELECT COUNT(DISTINCT m.message_id) as unread_count
            FROM messages m
            INNER JOIN conversations c ON m.conversation_id = c.conversation_id
            INNER JOIN numbers n ON c.number_id = n.number_id
            WHERE n.head_user_id = ?
            AND m.receiver_id = ?
            AND m.is_read = 0
            AND m.is_archived = 0
            AND c.is_archived = 0";
    
    $params = array($head_user_id, $head_user_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return 0;
    
    if(!sqlsrv_execute($stmt)) return 0;
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row['unread_count'] ?? 0;
}

function getHeadUserUnreadNotificationCount($conn, $head_user_id) {
    $sql = "SELECT COUNT(DISTINCT m.message_id) as unread_count
            FROM messages m
            INNER JOIN conversations c ON m.conversation_id = c.conversation_id
            INNER JOIN numbers n ON c.number_id = n.number_id
            WHERE n.head_user_id = ?
            AND m.receiver_id = ?
            AND m.is_read = 0
            AND m.is_archived = 0
            AND c.is_archived = 0";
    
    $params = array($head_user_id, $head_user_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return 0;
    
    if(!sqlsrv_execute($stmt)) return 0;
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row['unread_count'] ?? 0;
}

function getAdminChatRequests($conn, $admin_id) {
    $sql = "SELECT DISTINCT u.user_id, u.username, u.full_name, 
            MIN(am.created_at) as first_message_time,
            COUNT(DISTINCT am.message_id) as message_count
            FROM admin_messages am
            INNER JOIN admin_chats ac ON am.chat_id = ac.chat_id
            INNER JOIN users u ON am.sender_id = u.user_id
            WHERE ac.admin_id = ? 
            AND am.sender_id != ? 
            AND am.is_read = 0
            AND ac.is_archived = 0
            GROUP BY u.user_id, u.username, u.full_name
            ORDER BY first_message_time ASC";
    
    $params = array($admin_id, $admin_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return [];
    
    if(!sqlsrv_execute($stmt)) return [];
    
    $requests = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $requests[] = $row;
    }
    
    return $requests;
}

function getAdminUnreadChatsCount($conn, $admin_id) {
    $sql = "SELECT COUNT(DISTINCT ac.chat_id) as chat_count
            FROM admin_chats ac
            INNER JOIN admin_messages am ON ac.chat_id = am.chat_id
            WHERE ac.admin_id = ? 
            AND am.sender_id != ? 
            AND am.is_read = 0
            AND ac.is_archived = 0";
    
    $params = array($admin_id, $admin_id);
    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if(!$stmt) return 0;
    
    if(!sqlsrv_execute($stmt)) return 0;
    
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    return $row['chat_count'] ?? 0;
}

function getUserHierarchyInfo($conn, $user_id) {
    // Initialize with default values for all expected keys
    $hierarchy_info = array(
        'division' => null,
        'department' => null,
        'unit' => null,
        'office' => null,
        'head_info' => null,
        'role_name' => null,
        'position' => null,
        'full_name' => '',
        'email' => '',
        'is_head' => false,
        'current_unit' => null,
        'unit_type' => null,
        'heads_contacts' => array(),
        'role_id' => null,
        'all_units' => array(), // New: store all units this user belongs to or heads
        'staff_of' => array()    // New: store all units where this user is a staff member
    );
    
    // Get user's role and name
    $role_sql = "SELECT u.role_id, r.role_name, u.full_name, u.email 
                 FROM users u 
                 JOIN roles r ON u.role_id = r.role_id 
                 WHERE u.user_id = ?";
    $params = array($user_id);
    $stmt = sqlsrv_prepare($conn, $role_sql, $params);
    if(!$stmt) return $hierarchy_info;
    
    if(!sqlsrv_execute($stmt)) return $hierarchy_info;
    
    $role_data = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if(!$role_data) return $hierarchy_info;
    
    $role_id = $role_data['role_id'];
    $hierarchy_info['role_id'] = $role_id;
    $hierarchy_info['role_name'] = $role_data['role_name'];
    $hierarchy_info['full_name'] = $role_data['full_name'];
    $hierarchy_info['email'] = $role_data['email'] ?? '';
    
    // Check if user is a head (role_id 3-6 are heads)
    $hierarchy_info['is_head'] = ($role_id >= 3 && $role_id <= 6);
    
    // For HEADS - Get ALL organizations they head - FINAL FIX
    if ($hierarchy_info['is_head']) {
        $all_units = array();
        
        // Get Divisions
        $div_sql = "SELECT n.division_id, d.division_name 
                    FROM numbers n 
                    JOIN divisions d ON n.division_id = d.division_id 
                    WHERE n.head_user_id = ? AND n.division_id IS NOT NULL AND n.status = 'active'";
        $div_stmt = sqlsrv_query($conn, $div_sql, array($user_id));
        if ($div_stmt) {
            while ($div = sqlsrv_fetch_array($div_stmt, SQLSRV_FETCH_ASSOC)) {
                $all_units[] = [
                    'unit_name' => $div['division_name'],
                    'unit_type' => 'Division'
                ];
            }
            sqlsrv_free_stmt($div_stmt);
        }
        
        // Get Departments
        $dept_sql = "SELECT n.department_id, dept.department_name 
                    FROM numbers n 
                    JOIN departments dept ON n.department_id = dept.department_id 
                    WHERE n.head_user_id = ? AND n.department_id IS NOT NULL AND n.status = 'active'";
        $dept_stmt = sqlsrv_query($conn, $dept_sql, array($user_id));
        if ($dept_stmt) {
            while ($dept = sqlsrv_fetch_array($dept_stmt, SQLSRV_FETCH_ASSOC)) {
                $all_units[] = [
                    'unit_name' => $dept['department_name'],
                    'unit_type' => 'Department'
                ];
            }
            sqlsrv_free_stmt($dept_stmt);
        }
        
        // Get Units
        $unit_sql = "SELECT n.unit_id, u.unit_name 
                    FROM numbers n 
                    JOIN units u ON n.unit_id = u.unit_id 
                    WHERE n.head_user_id = ? AND n.unit_id IS NOT NULL AND n.status = 'active'";
        $unit_stmt = sqlsrv_query($conn, $unit_sql, array($user_id));
        if ($unit_stmt) {
            while ($unit = sqlsrv_fetch_array($unit_stmt, SQLSRV_FETCH_ASSOC)) {
                $all_units[] = [
                    'unit_name' => $unit['unit_name'],
                    'unit_type' => 'Unit'
                ];
            }
            sqlsrv_free_stmt($unit_stmt);
        }
        
        // Get Offices
        $office_sql = "SELECT n.office_id, o.office_name 
                    FROM numbers n 
                    JOIN offices o ON n.office_id = o.office_id 
                    WHERE n.head_user_id = ? AND n.office_id IS NOT NULL AND n.status = 'active'";
        $office_stmt = sqlsrv_query($conn, $office_sql, array($user_id));
        if ($office_stmt) {
            while ($office = sqlsrv_fetch_array($office_stmt, SQLSRV_FETCH_ASSOC)) {
                $all_units[] = [
                    'unit_name' => $office['office_name'],
                    'unit_type' => 'Office'
                ];
            }
            sqlsrv_free_stmt($office_stmt);
        }
        
        // Set the first unit as primary
        if (!empty($all_units)) {
            $hierarchy_info['current_unit'] = $all_units[0]['unit_name'];
            $hierarchy_info['unit_type'] = $all_units[0]['unit_type'];
        }
        
        $hierarchy_info['heads_contacts'] = $all_units;
        $hierarchy_info['all_units'] = $all_units;
    }
    
    // Get ALL organizations where this user is a STAFF MEMBER (for staff)
    if ($role_id == 7 || $role_id > 6) {
        // Get staff's assigned organization from users table with proper hierarchy priority
        $staff_sql = "SELECT 
                            u.user_id,
                            u.division_id, 
                            u.department_id, 
                            u.unit_id, 
                            u.office_id,
                            d.division_name, 
                            dept.department_name, 
                            un.unit_name, 
                            o.office_name,
                            -- Determine the actual unit with priority: Office > Unit > Department > Division
                            CASE 
                                WHEN u.office_id IS NOT NULL THEN o.office_name
                                WHEN u.unit_id IS NOT NULL THEN un.unit_name
                                WHEN u.department_id IS NOT NULL THEN dept.department_name
                                WHEN u.division_id IS NOT NULL THEN d.division_name
                                ELSE NULL
                            END as unit_name,
                            CASE 
                                WHEN u.office_id IS NOT NULL THEN 'Office'
                                WHEN u.unit_id IS NOT NULL THEN 'Unit'
                                WHEN u.department_id IS NOT NULL THEN 'Department'
                                WHEN u.division_id IS NOT NULL THEN 'Division'
                                ELSE 'Unknown'
                            END as unit_type
                    FROM users u
                    LEFT JOIN divisions d ON u.division_id = d.division_id AND d.status = 'active'
                    LEFT JOIN departments dept ON u.department_id = dept.department_id AND dept.status = 'active'
                    LEFT JOIN units un ON u.unit_id = un.unit_id AND un.status = 'active'
                    LEFT JOIN offices o ON u.office_id = o.office_id AND o.status = 'active'
                    WHERE u.user_id = ?";
        
        $staff_params = array($user_id);
        $staff_stmt = sqlsrv_prepare($conn, $staff_sql, $staff_params);
        
        $staff_units = array();
        if($staff_stmt && sqlsrv_execute($staff_stmt)) {
            while($unit = sqlsrv_fetch_array($staff_stmt, SQLSRV_FETCH_ASSOC)) {
                // Now find the head of this organization
                $head_sql = "SELECT head.full_name, head.email, head.user_id as head_user_id
                            FROM numbers n
                            JOIN users head ON n.head_user_id = head.user_id
                            WHERE n.status = 'active' 
                            AND (";
                
                $head_conditions = array();
                $head_params = array();
                
                // Check with priority: office first, then unit, then department, then division
                if (!empty($unit['office_id'])) {
                    $head_conditions[] = "n.office_id = ?";
                    $head_params[] = $unit['office_id'];
                }
                if (!empty($unit['unit_id'])) {
                    $head_conditions[] = "n.unit_id = ?";
                    $head_params[] = $unit['unit_id'];
                }
                if (!empty($unit['department_id'])) {
                    $head_conditions[] = "n.department_id = ?";
                    $head_params[] = $unit['department_id'];
                }
                if (!empty($unit['division_id'])) {
                    $head_conditions[] = "n.division_id = ?";
                    $head_params[] = $unit['division_id'];
                }
                
                if (!empty($head_conditions)) {
                    $head_sql .= implode(" OR ", $head_conditions) . ")";
                    $head_stmt = sqlsrv_query($conn, $head_sql, $head_params);
                    
                    if ($head_stmt && $head_row = sqlsrv_fetch_array($head_stmt, SQLSRV_FETCH_ASSOC)) {
                        $unit['head_name'] = $head_row['full_name'];
                        $unit['head_email'] = $head_row['email'] ?? '';
                        $unit['head_user_id'] = $head_row['head_user_id'] ?? null;
                        
                        // Store head info (use the first one found)
                        if (empty($hierarchy_info['head_info'])) {
                            $hierarchy_info['head_info'] = array(
                                'head_name' => $head_row['full_name'],
                                'head_email' => $head_row['email'] ?? '',
                                'head_user_id' => $head_row['head_user_id'] ?? null
                            );
                        }
                    }
                    if ($head_stmt) sqlsrv_free_stmt($head_stmt);
                }
                
                $staff_units[] = $unit;
                
                // Set the unit as current_unit with proper priority
                if (empty($hierarchy_info['current_unit']) && !empty($unit['unit_name'])) {
                    $hierarchy_info['current_unit'] = $unit['unit_name'];
                    $hierarchy_info['unit_type'] = $unit['unit_type'] ?? 'Unknown';
                    
                    // Set individual fields based on what's assigned
                    if (!empty($unit['division_id'])) {
                        $hierarchy_info['division'] = $unit['division_name'];
                    }
                    if (!empty($unit['department_id'])) {
                        $hierarchy_info['department'] = $unit['department_name'];
                    }
                    if (!empty($unit['unit_id'])) {
                        $hierarchy_info['unit'] = $unit['unit_name'];
                    }
                    if (!empty($unit['office_id'])) {
                        $hierarchy_info['office'] = $unit['office_name'];
                    }
                }
            }
            $hierarchy_info['staff_of'] = $staff_units;
            sqlsrv_free_stmt($staff_stmt);
        }
    }

    // For HEADS - Get ALL organizations they head (modified to include all)
    if ($hierarchy_info['is_head']) {
        $heads_sql = "SELECT n.*, d.division_name, dept.department_name, un.unit_name, o.office_name,
                            COALESCE(d.division_name, dept.department_name, un.unit_name, o.office_name) as unit_name,
                            CASE 
                                WHEN n.division_id IS NOT NULL THEN 'Division'
                                WHEN n.department_id IS NOT NULL THEN 'Department'
                                WHEN n.unit_id IS NOT NULL THEN 'Unit'
                                WHEN n.office_id IS NOT NULL THEN 'Office'
                                ELSE 'Unknown'
                            END as unit_type
                    FROM numbers n
                    LEFT JOIN divisions d ON n.division_id = d.division_id AND d.status = 'active'
                    LEFT JOIN departments dept ON n.department_id = dept.department_id AND dept.status = 'active'
                    LEFT JOIN units un ON n.unit_id = un.unit_id AND un.status = 'active'
                    LEFT JOIN offices o ON n.office_id = o.office_id AND o.status = 'active'
                    WHERE n.head_user_id = ? AND n.status = 'active'";
        
        $heads_params = array($user_id);
        $heads_stmt = sqlsrv_prepare($conn, $heads_sql, $heads_params);
        
        if($heads_stmt && sqlsrv_execute($heads_stmt)) {
            $all_units = array();
            while($unit = sqlsrv_fetch_array($heads_stmt, SQLSRV_FETCH_ASSOC)) {
                $all_units[] = $unit;
                
                // Set the first unit as the primary current_unit
                if (empty($hierarchy_info['current_unit'])) {
                    $hierarchy_info['current_unit'] = $unit['unit_name'];
                    $hierarchy_info['unit_type'] = $unit['unit_type'];
                    
                    // Set individual fields based on unit type
                    if ($unit['division_id']) {
                        $hierarchy_info['division'] = $unit['division_name'];
                    }
                    if ($unit['department_id']) {
                        $hierarchy_info['department'] = $unit['department_name'];
                    }
                    if ($unit['unit_id']) {
                        $hierarchy_info['unit'] = $unit['unit_name'];
                    }
                    if ($unit['office_id']) {
                        $hierarchy_info['office'] = $unit['office_name'];
                    }
                }
            }
            $hierarchy_info['heads_contacts'] = $all_units;
            $hierarchy_info['all_units'] = $all_units; // This will now contain ALL units
            sqlsrv_free_stmt($heads_stmt);
        }
    }
    
    // Set position if not set yet
    if (empty($hierarchy_info['position'])) {
        if($role_id == 1) $hierarchy_info['position'] = 'Admin';
        elseif($role_id == 2) $hierarchy_info['position'] = 'MCC';
        elseif($role_id == 3) $hierarchy_info['position'] = 'Division Head';
        elseif($role_id == 4) $hierarchy_info['position'] = 'Department Head';
        elseif($role_id == 5) $hierarchy_info['position'] = 'Unit Head';
        elseif($role_id == 6) $hierarchy_info['position'] = 'Office Head';
        else $hierarchy_info['position'] = 'Staff';
    }
    
    return $hierarchy_info;
}

// Add this function to your archive_functions.php or create it in your conn.php
function getLastInsertId($conn) {
    $query = "SELECT SCOPE_IDENTITY() AS last_id";
    $stmt = sqlsrv_query($conn, $query);
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        return $row['last_id'];
    }
    return null;
}

function time_ago($datetime, $full = false) {
    $now = new DateTime;
    
    // Handle both string and DateTime object inputs
    if ($datetime instanceof DateTime) {
        $ago = $datetime;
    } else {
        try {
            $ago = new DateTime($datetime);
        } catch (Exception $e) {
            return 'Invalid date';
        }
    }
    
    $diff = $now->diff($ago);

    $string = [
        'y' => 'year',
        'm' => 'month',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    ];
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }
    
    if (!$full) {
        $string = array_slice($string, 0, 1);
    }
    
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}



/**
 * Archive all inactive chats (both admin and regular conversations)
 * Called on every page load of chat-related pages
 */
function archiveAllInactiveChatsOnLoad($conn) {
    $total_archived = 0;
    
    error_log("=== ARCHIVE SYSTEM STARTED ===");
    
    try {
        // Debug: Check what functions exist
        error_log("Function exists check:");
        error_log("autoArchiveInactiveAdminChats: " . (function_exists('autoArchiveInactiveAdminChats') ? 'YES' : 'NO'));
        error_log("archiveConversationImmediately: " . (function_exists('archiveConversationImmediately') ? 'YES' : 'NO'));
        error_log("removeOldArchivedAdminChats: " . (function_exists('removeOldArchivedAdminChats') ? 'YES' : 'NO'));
        
        // Archive inactive admin chats (30+ minutes inactive)
        if (function_exists('autoArchiveInactiveAdminChats')) {
            error_log("Calling autoArchiveInactiveAdminChats...");
            $admin_archived = autoArchiveInactiveAdminChats($conn, 30);
            $total_archived += $admin_archived;
            error_log("Admin chats archived: $admin_archived");
        } else {
            error_log("ERROR: autoArchiveInactiveAdminChats function not found!");
        }
        
        // Archive inactive regular conversations (30+ minutes inactive)
        $inactive_time = date('Y-m-d H:i:s', strtotime('-30 minutes'));
        error_log("Checking for conversations inactive since: $inactive_time");
        
        $get_inactive_sql = "
            SELECT conversation_id 
            FROM conversations 
            WHERE is_archived = 0 
            AND last_activity < ? 
            AND EXISTS (
                SELECT 1 FROM messages 
                WHERE conversation_id = conversations.conversation_id 
                AND is_archived = 0
            )
        ";
        
        $get_inactive_params = array($inactive_time);
        $get_inactive_stmt = sqlsrv_query($conn, $get_inactive_sql, $get_inactive_params);
        
        if ($get_inactive_stmt) {
            $count = 0;
            while ($row = sqlsrv_fetch_array($get_inactive_stmt, SQLSRV_FETCH_ASSOC)) {
                $count++;
                $conversation_id = $row['conversation_id'];
                error_log("Found inactive conversation #$conversation_id");
                
                if (function_exists('archiveConversationImmediately')) {
                    error_log("Attempting to archive conversation #$conversation_id");
                    if (archiveConversationImmediately($conn, $conversation_id)) {
                        $total_archived++;
                        error_log("SUCCESS: Archived conversation #$conversation_id");
                    } else {
                        error_log("FAILED: Could not archive conversation #$conversation_id");
                    }
                } else {
                    error_log("ERROR: archiveConversationImmediately function not found!");
                }
            }
            
            if ($count == 0) {
                error_log("No inactive conversations found to archive");
            }
            
            sqlsrv_free_stmt($get_inactive_stmt);
        } else {
            error_log("ERROR: SQL query failed: " . print_r(sqlsrv_errors(), true));
        }
        
        // Also clean up old archived chats (7+ days old)
        $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
        error_log("Cleaning up archives older than: $seven_days_ago");
        
        // Clean old admin archived chats
        if (function_exists('removeOldArchivedAdminChats')) {
            error_log("Calling removeOldArchivedAdminChats...");
            $admin_removed = removeOldArchivedAdminChats($conn, null, null, $seven_days_ago);
            error_log("Old admin archives removed: $admin_removed");
        } else {
            error_log("ERROR: removeOldArchivedAdminChats function not found!");
        }
        
        error_log("=== ARCHIVE SYSTEM COMPLETED ===");
        error_log("Total archived this run: $total_archived");
        
        return $total_archived;
        
    } catch (Exception $e) {
        error_log("!!! ARCHIVE SYSTEM ERROR: " . $e->getMessage());
        error_log("=== ARCHIVE SYSTEM FAILED ===");
        return 0;
    }
}
?>