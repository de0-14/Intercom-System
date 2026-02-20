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
    $admin_id = isset($_GET['admin_id']) ? (int)$_GET['admin_id'] : 0;

    if (!$admin_id) {
        throw new Exception('Admin ID required');
    }

    // Check if there's a chat with this admin and count unread messages
    $sql = "SELECT 
                ac.chat_id,
                COUNT(am.message_id) as unread_count
            FROM admin_chats ac
            LEFT JOIN admin_messages am ON ac.chat_id = am.chat_id 
                AND am.sender_id = ac.admin_id 
                AND am.is_read = 0
            WHERE ac.user_id = ? AND ac.admin_id = ? AND ac.is_archived = 0
            GROUP BY ac.chat_id";

    $params = array($user_id, $admin_id);
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