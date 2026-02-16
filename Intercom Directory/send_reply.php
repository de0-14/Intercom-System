<?php
ini_set('display_errors', 0);
error_reporting(0);

// Ensure no output before JSON
ob_start();

require_once 'conn.php';
require_once 'archive_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;
$message = isset($_POST['reply_message']) ? trim($_POST['reply_message']) : '';

if (!$conversation_id || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit();
}

if (strlen($message) > 1000) {
    echo json_encode(['success' => false, 'error' => 'Message too long (max 1000 characters)']);
    exit();
}

// Get conversation info
$conv_sql = "SELECT c.*, n.head_user_id 
             FROM conversations c
             JOIN numbers n ON c.number_id = n.number_id
             WHERE c.conversation_id = ? AND c.is_archived = 0";
$conv_params = array($conversation_id);
$conv_stmt = sqlsrv_query($conn, $conv_sql, $conv_params);

if (!$conv_stmt || !sqlsrv_has_rows($conv_stmt)) {
    echo json_encode(['success' => false, 'error' => 'Conversation not found or archived']);
    exit();
}

$conv_data = sqlsrv_fetch_array($conv_stmt, SQLSRV_FETCH_ASSOC);
sqlsrv_free_stmt($conv_stmt);

// Determine receiver
if ($user_id == $conv_data['initiated_by']) {
    // User replying to head
    $receiver_id = $conv_data['head_user_id'];
} else {
    // Head replying to user
    $receiver_id = $conv_data['initiated_by'];
}

// Insert reply
$insert_sql = "INSERT INTO messages (sender_id, receiver_id, number_id, conversation_id, message, created_at) 
               VALUES (?, ?, ?, ?, ?, GETDATE())";
$insert_params = array($user_id, $receiver_id, $conv_data['number_id'], $conversation_id, $message);
$insert_stmt = sqlsrv_prepare($conn, $insert_sql, $insert_params);

if ($insert_stmt && sqlsrv_execute($insert_stmt)) {
    // Update conversation activity
    updateConversationActivity($conn, $conversation_id);
    
    // Get the inserted message
    $get_sql = "SELECT TOP 1 m.*, u.username, u.full_name 
                FROM messages m
                JOIN users u ON m.sender_id = u.user_id
                WHERE m.conversation_id = ? AND m.sender_id = ?
                ORDER BY m.created_at DESC";
    $get_params = array($conversation_id, $user_id);
    $get_stmt = sqlsrv_query($conn, $get_sql, $get_params);
    
    $message_data = null;
    if ($get_stmt && $row = sqlsrv_fetch_array($get_stmt, SQLSRV_FETCH_ASSOC)) {
        $message_data = [
            'message_id' => $row['message_id'],
            'sender_id' => $row['sender_id'],
            'sender_name' => $row['full_name'],
            'message' => $row['message'],
            'created_at' => $row['created_at'] instanceof DateTime 
                ? $row['created_at']->format('Y-m-d H:i:s') 
                : $row['created_at'],
            'is_sent' => true
        ];
    }
    sqlsrv_free_stmt($get_stmt);
    
    echo json_encode([
        'success' => true,
        'message' => 'Reply sent successfully',
        'message_data' => $message_data
    ]);
    exit();
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to send reply']);
    exit();
}
?>