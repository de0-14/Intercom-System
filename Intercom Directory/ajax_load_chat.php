<?php
require_once 'conn.php';
require_once 'admin_archive_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$chat_id = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;

if (!$chat_id) {
    echo json_encode(['success' => false, 'error' => 'Chat ID required']);
    exit;
}

// Get chat info
$chat_sql = "
    SELECT 
        ac.*,
        u.full_name,
        u.username
    FROM admin_chats ac
    JOIN users u ON ac.user_id = u.user_id
    WHERE ac.chat_id = ? AND ac.admin_id = ? AND ac.is_archived = 0
";

$chat_params = array($chat_id, $user_id);
$chat_stmt = sqlsrv_query($conn, $chat_sql, $chat_params);

if (!$chat_stmt || !sqlsrv_has_rows($chat_stmt)) {
    echo json_encode(['success' => false, 'error' => 'Chat not found']);
    exit;
}

$chat = sqlsrv_fetch_array($chat_stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($chat_stmt);

// Get messages
$messages_sql = "
    SELECT 
        am.*,
        u.full_name
    FROM admin_messages am
    JOIN users u ON am.sender_id = u.user_id
    WHERE am.chat_id = ? AND am.is_archived = 0
    ORDER BY am.created_at ASC
";

$messages_params = array($chat_id);
$messages_stmt = sqlsrv_query($conn, $messages_sql, $messages_params);

$messages = [];
if ($messages_stmt) {
    while ($msg = sqlsrv_fetch_array($messages_stmt, SQLSRV_FETCH_ASSOC)) {
        $messages[] = [
            'message_id' => $msg['message_id'],
            'sender_id' => $msg['sender_id'],
            'full_name' => $msg['full_name'],
            'message' => $msg['message'],
            'created_at' => $msg['created_at'] instanceof DateTime 
                ? $msg['created_at']->format('Y-m-d H:i:s') 
                : $msg['created_at'],
            'is_sent' => ($msg['sender_id'] == $user_id)
        ];
    }
    sqlsrv_free_stmt($messages_stmt);
}

// Mark messages as read
markAdminMessagesAsRead($conn, $chat_id, $user_id);
updateAdminChatActivity($conn, $chat_id);

echo json_encode([
    'success' => true,
    'chat_id' => $chat_id,
    'chat' => [
        'full_name' => $chat['full_name'],
        'username' => $chat['username']
    ],
    'messages' => $messages
]);
?>