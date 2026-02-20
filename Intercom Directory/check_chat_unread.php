<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Turn off display errors to not break JSON

require_once 'conn.php';
session_start();

header('Content-Type: application/json');

$response = ['success' => false, 'error' => '', 'unread' => 0];

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Not logged in');
    }

    $user_id = $_SESSION['user_id'];
    $chat_id = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;

    if (!$chat_id) {
        throw new Exception('Chat ID required');
    }

    // Get unread count for this chat (messages from the other person)
    $sql = "SELECT COUNT(*) as unread_count
            FROM admin_messages am
            JOIN admin_chats ac ON am.chat_id = ac.chat_id
            WHERE am.chat_id = ? 
            AND am.sender_id != ? 
            AND am.is_read = 0
            AND ac.is_archived = 0";

    $params = array($chat_id, $user_id);
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        throw new Exception('Database error: ' . print_r(sqlsrv_errors(), true));
    }

    $unread = 0;
    if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $unread = (int)$row['unread_count'];
    }
    sqlsrv_free_stmt($stmt);

    $response['success'] = true;
    $response['unread'] = $unread;

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
exit;
?>