<?php
require_once 'conn.php';
require_once 'admin_archive_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$chat_id = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
$last_message_id = isset($_GET['last_message_id']) ? (int)$_GET['last_message_id'] : 0;
$is_admin = isAdmin();

if (!$chat_id) {
    echo json_encode(['error' => 'No chat ID']);
    exit();
}

// Get messages newer than last_message_id
$sql = "SELECT am.*, u.full_name, u.username 
        FROM admin_messages am 
        JOIN users u ON am.sender_id = u.user_id 
        WHERE am.chat_id = ? 
        AND am.is_archived = 0
        AND am.message_id > ?
        ORDER BY am.created_at ASC";

$params = array($chat_id, $last_message_id);
$stmt = sqlsrv_query($conn, $sql, $params);

$messages = [];
$new_last_message_id = $last_message_id;

if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $messages[] = [
            'message_id' => $row['message_id'],
            'sender_id' => $row['sender_id'],
            'full_name' => $row['full_name'],
            'message' => $row['message'],
            'created_at' => $row['created_at'] instanceof DateTime 
                ? $row['created_at']->format('Y-m-d H:i:s') 
                : $row['created_at'],
            'is_sent' => ($row['sender_id'] == $user_id)
        ];
        $new_last_message_id = $row['message_id'];
    }
    sqlsrv_free_stmt($stmt);
}

// Mark messages as read
markAdminMessagesAsRead($conn, $chat_id, $user_id);

// Update chat activity
updateAdminChatActivity($conn, $chat_id);

// Get unread count
$unread_count = getUnreadAdminMessageCount($conn, $user_id, $is_admin);

echo json_encode([
    'success' => true,
    'messages' => $messages,
    'last_message_id' => $new_last_message_id,
    'unread_count' => $unread_count,
    'timestamp' => time()
]);
?>