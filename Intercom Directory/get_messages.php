<?php
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
$conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;
$last_message_id = isset($_GET['last_message_id']) ? (int)$_GET['last_message_id'] : 0;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'No conversation ID']);
    exit();
}

// Verify access
$access_sql = "SELECT c.*, n.head_user_id 
               FROM conversations c
               JOIN numbers n ON c.number_id = n.number_id
               WHERE c.conversation_id = ? 
               AND c.is_archived = 0
               AND (c.initiated_by = ? OR ? = n.head_user_id)";
$access_params = array($conversation_id, $user_id, $user_id);
$access_stmt = sqlsrv_query($conn, $access_sql, $access_params);

if (!$access_stmt || !sqlsrv_has_rows($access_stmt)) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}
sqlsrv_free_stmt($access_stmt);

// Get new messages
$sql = "SELECT m.*, u.username, u.full_name 
        FROM messages m
        JOIN users u ON m.sender_id = u.user_id
        WHERE m.conversation_id = ? 
        AND m.message_id > ?
        AND m.is_archived = 0
        ORDER BY m.created_at ASC";

$params = array($conversation_id, $last_message_id);
$stmt = sqlsrv_query($conn, $sql, $params);

$messages = [];
$new_last_message_id = $last_message_id;

if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $messages[] = [
            'message_id' => $row['message_id'],
            'sender_id' => $row['sender_id'],
            'sender_name' => $row['full_name'],
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
$mark_sql = "UPDATE messages SET is_read = 1 
             WHERE conversation_id = ? AND receiver_id = ? AND is_read = 0";
$mark_params = array($conversation_id, $user_id);
sqlsrv_query($conn, $mark_sql, $mark_params);

ob_clean();
echo json_encode([
    'success' => true,
    'messages' => $messages,
    'last_message_id' => $new_last_message_id
]);
exit();
?>