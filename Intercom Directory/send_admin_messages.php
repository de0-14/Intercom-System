<?php
require_once 'conn.php';
require_once 'admin_archive_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$chat_id = isset($_POST['chat_id']) ? (int)$_POST['chat_id'] : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if (!$chat_id || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit();
}

// Send the message
if (sendAdminMessage($conn, $chat_id, $user_id, $message)) {
    updateAdminChatActivity($conn, $chat_id);
    
    // Get the newly sent message
    $sql = "SELECT TOP 1 am.*, u.full_name, u.username 
            FROM admin_messages am 
            JOIN users u ON am.sender_id = u.user_id 
            WHERE am.chat_id = ? 
            ORDER BY am.created_at DESC";
    
    $params = array($chat_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    $new_message = null;
    
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $new_message = [
            'message_id' => $row['message_id'],
            'sender_id' => $row['sender_id'],
            'full_name' => $row['full_name'],
            'message' => $row['message'],
            'created_at' => $row['created_at'] instanceof DateTime 
                ? $row['created_at']->format('Y-m-d H:i:s') 
                : $row['created_at'],
            'is_sent' => true
        ];
    }
    
    echo json_encode([
        'success' => true, 
        'message' => $new_message,
        'chat_id' => $chat_id
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to send message']);
}
?>