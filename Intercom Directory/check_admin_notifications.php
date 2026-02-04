<?php
require_once 'conn.php';
session_start();

if(!isset($_SESSION['user_id']) || !isAdmin()) {
    echo json_encode(['count' => 0]);
    exit();
}

$admin_id = $_SESSION['user_id'];
$unread_count = getAdminUnreadChatsCount($conn, $admin_id);

$latest_request = [];
if($unread_count > 0) {
    $sql = "SELECT u.full_name as user_name 
            FROM admin_messages am
            INNER JOIN admin_chats ac ON am.chat_id = ac.chat_id
            INNER JOIN users u ON am.sender_id = u.user_id
            WHERE ac.admin_id = ? 
            AND am.sender_id != ? 
            AND am.is_read = 0
            AND ac.is_archived = 0
            ORDER BY am.created_at DESC
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $admin_id, $admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0) {
        $latest_request = $result->fetch_assoc();
    }
}

echo json_encode([
    'count' => $unread_count,
    'latest' => $latest_request
]);
?>