<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'conn.php';
session_start();

header('Content-Type: application/json');

$response = ['success' => false, 'error' => '', 'unread' => 0];

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Not logged in');
    }

    $user_id = $_SESSION['user_id'];
    $conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;

    if (!$conversation_id) {
        throw new Exception('Conversation ID required');
    }

    // Check if user is the head of this conversation's number
    $check_sql = "
        SELECT n.head_user_id
        FROM conversations c
        JOIN numbers n ON c.number_id = n.number_id
        WHERE c.conversation_id = ?
    ";
    
    $check_params = array($conversation_id);
    $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
    
    if ($check_stmt && $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC)) {
        if ($row['head_user_id'] != $user_id) {
            // If not head, check if user is the initiator (for regular users)
            $check_initiator = "
                SELECT initiated_by 
                FROM conversations 
                WHERE conversation_id = ? AND initiated_by = ?
            ";
            $check_init_params = array($conversation_id, $user_id);
            $check_init_stmt = sqlsrv_query($conn, $check_initiator, $check_init_params);
            
            if (!$check_init_stmt || !sqlsrv_has_rows($check_init_stmt)) {
                throw new Exception('Not authorized');
            }
            sqlsrv_free_stmt($check_init_stmt);
        }
    } else {
        throw new Exception('Conversation not found');
    }
    sqlsrv_free_stmt($check_stmt);

    // Count unread messages (messages from the other party)
    $sql = "
        SELECT COUNT(*) as unread_count
        FROM messages m
        WHERE m.conversation_id = ?
        AND m.sender_id != ?
        AND m.is_read = 0
        AND m.is_archived = 0
    ";

    $params = array($conversation_id, $user_id);
    $stmt = sqlsrv_query($conn, $sql, $params);

    if ($stmt === false) {
        throw new Exception('Database error');
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