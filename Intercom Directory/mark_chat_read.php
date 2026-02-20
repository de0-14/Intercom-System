<?php
require_once 'conn.php';
session_start();

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$chat_id = $data['chat_id'] ?? null;

if (!$chat_id) {
    echo json_encode(['success' => false, 'error' => 'Chat ID required']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Mark all messages in this chat as read where admin is the receiver
$sql = "UPDATE admin_messages 
        SET is_read = 1 
        WHERE chat_id = ? 
        AND receiver_id = ? 
        AND is_read = 0";

$stmt = sqlsrv_query($conn, $sql, array($chat_id, $user_id));

if ($stmt) {
    // Also update the chat activity
    $update_sql = "UPDATE admin_chats SET last_activity = GETDATE() WHERE chat_id = ?";
    sqlsrv_query($conn, $update_sql, array($chat_id));
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>