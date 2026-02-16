<?php
require_once 'conn.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'No conversation ID']);
    exit;
}

// Check permissions and get messages
$result = getArchivedMessages($conn, $conversation_id, $user_id);
header('Content-Type: application/json');
echo json_encode($result);

if (!function_exists('getAdminChats_FIXED')) {
    function getAdminChats_FIXED($conn, $admin_id, $include_archived = false) {
        $sql = "SELECT 
                    ac.chat_id,
                    ac.admin_id,
                    ac.user_id,
                    ac.created_at,
                    ac.last_activity,
                    u.full_name,
                    u.email,
                    u.is_admin,
                    (SELECT TOP 1 message FROM admin_messages WHERE chat_id = ac.chat_id AND is_archived = 0 ORDER BY created_at DESC) as last_message,
                    (SELECT TOP 1 created_at FROM admin_messages WHERE chat_id = ac.chat_id AND is_archived = 0 ORDER BY created_at DESC) as last_message_time
                FROM admin_chats ac
                JOIN users u ON (CASE WHEN ac.admin_id = ? THEN ac.user_id ELSE ac.admin_id END) = u.user_id
                WHERE (ac.admin_id = ? OR ac.user_id = ?)";
        
        if (!$include_archived) {
            $sql .= " AND ac.is_archived = 0";
        }
        
        $sql .= " ORDER BY ac.last_activity DESC";
        
        $params = array($admin_id, $admin_id, $admin_id);
        $stmt = sqlsrv_query($conn, $sql, $params);
        
        $chats = [];
        if ($stmt) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $chats[] = $row;
            }
            sqlsrv_free_stmt($stmt);
        }
        
        return $chats;
    }
}
