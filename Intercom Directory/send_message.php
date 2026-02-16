<?php
// DISABLE ERROR DISPLAY
ini_set('display_errors', 0);
error_reporting(0);
ob_start();

require_once 'conn.php';
require_once 'archive_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$number_id = isset($_POST['number_id']) ? (int)$_POST['number_id'] : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if (!$number_id || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit();
}

if (strlen($message) > 1000) {
    echo json_encode(['success' => false, 'error' => 'Message too long (max 1000 characters)']);
    exit();
}

// Get head user ID
$head_user_id = getHeadUserId($conn, $number_id);
if (!$head_user_id) {
    echo json_encode(['success' => false, 'error' => 'Head user not found']);
    exit();
}

// Check if conversation exists
$check_sql = "SELECT TOP 1 conversation_id FROM conversations 
              WHERE number_id = ? AND initiated_by = ? AND is_archived = 0";
$check_params = array($number_id, $user_id);
$check_stmt = sqlsrv_prepare($conn, $check_sql, $check_params);

$conversation_id = null;

if ($check_stmt && sqlsrv_execute($check_stmt)) {
    if (sqlsrv_has_rows($check_stmt)) {
        $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
        $conversation_id = $row['conversation_id'];
    }
    sqlsrv_free_stmt($check_stmt);
}

// Create new conversation if needed
if (!$conversation_id) {
    $create_sql = "INSERT INTO conversations (number_id, initiated_by, created_at, last_activity) 
                   VALUES (?, ?, GETDATE(), GETDATE())";
    $create_params = array($number_id, $user_id);
    $create_stmt = sqlsrv_prepare($conn, $create_sql, $create_params);
    
    if ($create_stmt && sqlsrv_execute($create_stmt)) {
        $conversation_id = getLastInsertId($conn);
    }
    sqlsrv_free_stmt($create_stmt);
}

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Failed to create conversation']);
    exit();
}

// Insert message
$insert_sql = "INSERT INTO messages (sender_id, receiver_id, number_id, conversation_id, message, created_at) 
               VALUES (?, ?, ?, ?, ?, GETDATE())";
$insert_params = array($user_id, $head_user_id, $number_id, $conversation_id, $message);
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
    
    // Clear any output buffers
    ob_clean();
    
    echo json_encode([
        'success' => true,
        'message' => 'Message sent successfully',
        'conversation_id' => $conversation_id,
        'message_data' => $message_data
    ]);
    exit();
} else {
    $errors = sqlsrv_errors();
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Failed to send message: ' . print_r($errors, true)]);
    exit();
}
?>