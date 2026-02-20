<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'conn.php';
session_start();

header('Content-Type: application/json');

$response = ['success' => false, 'error' => '', 'unread' => []];

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Not logged in');
    }

    $user_id = $_SESSION['user_id'];
    $is_head = isset($_GET['is_head']) ? (bool)$_GET['is_head'] : false;

    if ($is_head) {
        // Get unread counts for contacts where user is the head
        $sql = "
            SELECT 
                n.number_id,
                COUNT(DISTINCT m.message_id) as unread_count
            FROM numbers n
            LEFT JOIN conversations c ON n.number_id = c.number_id AND c.is_archived = 0
            LEFT JOIN messages m ON c.conversation_id = m.conversation_id 
                AND m.receiver_id = ? 
                AND m.is_read = 0 
                AND m.is_archived = 0
            WHERE n.head_user_id = ?
            GROUP BY n.number_id
            HAVING COUNT(DISTINCT m.message_id) > 0
        ";
        $params = array($user_id, $user_id);
    } else {
        // Get unread counts for contacts where user is a regular user
        $sql = "
            SELECT 
                n.number_id,
                COUNT(DISTINCT m.message_id) as unread_count
            FROM numbers n
            LEFT JOIN conversations c ON n.number_id = c.number_id 
                AND c.initiated_by = ? 
                AND c.is_archived = 0
            LEFT JOIN messages m ON c.conversation_id = m.conversation_id 
                AND m.sender_id = n.head_user_id 
                AND m.receiver_id = ?
                AND m.is_read = 0 
                AND m.is_archived = 0
            WHERE n.status = 'active'
            GROUP BY n.number_id
            HAVING COUNT(DISTINCT m.message_id) > 0
        ";
        $params = array($user_id, $user_id);
    }

    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        throw new Exception('Database error: ' . print_r(sqlsrv_errors(), true));
    }

    $unread = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $unread[$row['number_id']] = (int)$row['unread_count'];
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