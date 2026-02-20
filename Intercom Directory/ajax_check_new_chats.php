<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Turn off display errors to not break JSON

require_once 'conn.php';
require_once 'admin_archive_functions.php';

header('Content-Type: application/json');

// Initialize response
$response = ['success' => false, 'error' => '', 'chats' => []];

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Not logged in');
    }

    if (!isAdmin()) {
        throw new Exception('Unauthorized - Admin only');
    }

    $user_id = $_SESSION['user_id'];

    // Get all active chats (including new ones)
    $sql = "
        SELECT 
            ac.chat_id,
            u.user_id,
            u.full_name,
            u.username,
            (
                SELECT TOP 1 message 
                FROM admin_messages 
                WHERE chat_id = ac.chat_id 
                AND is_archived = 0
                ORDER BY created_at DESC
            ) as last_message,
            (
                SELECT TOP 1 created_at 
                FROM admin_messages 
                WHERE chat_id = ac.chat_id 
                AND is_archived = 0
                ORDER BY created_at DESC
            ) as last_message_time
        FROM admin_chats ac
        JOIN users u ON ac.user_id = u.user_id
        WHERE ac.admin_id = ? 
        AND ac.is_archived = 0
        ORDER BY ac.last_activity DESC
    ";

    $params = array($user_id);
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        throw new Exception('Database error: ' . print_r(sqlsrv_errors(), true));
    }

    $chats = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $chats[] = [
            'chat_id' => (int)$row['chat_id'],
            'user_id' => (int)$row['user_id'],
            'full_name' => $row['full_name'] ?? 'Unknown User',
            'username' => $row['username'] ?? 'unknown',
            'last_message' => $row['last_message'] ?? 'No messages yet',
            'last_message_time' => $row['last_message_time'] instanceof DateTime 
                ? $row['last_message_time']->format('Y-m-d H:i:s') 
                : ($row['last_message_time'] ?? null)
        ];
    }
    sqlsrv_free_stmt($stmt);

    $response['success'] = true;
    $response['chats'] = $chats;

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

// Log any errors for debugging
if (!$response['success']) {
    error_log("ajax_check_new_chats.php error: " . $response['error']);
}

echo json_encode($response);
exit;
?>