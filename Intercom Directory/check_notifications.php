<?php
require_once 'conn.php';
require_once 'admin_archive_functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$is_admin = isAdmin();
$response = [
    'success' => true,
    'timestamp' => time(),
    'notifications' => [],
    'total_unread' => 0
];

if ($is_admin) {
    // ADMIN NOTIFICATIONS - Get ONLY chats with unread messages
    // Use a UNION to combine but avoid duplicates
    $sql = "
        -- New chat requests (chats with no messages from admin yet)
        SELECT DISTINCT 
            'admin_chat_request' as type,
            u.user_id,
            u.username,
            u.full_name,
            MIN(am.created_at) as first_message_time,
            COUNT(DISTINCT am.message_id) as message_count,
            NULL as unread_count,
            NULL as chat_id,
            NULL as last_message_time,
            NULL as latest_message
        FROM admin_messages am
        INNER JOIN admin_chats ac ON am.chat_id = ac.chat_id
        INNER JOIN users u ON am.sender_id = u.user_id
        WHERE ac.admin_id = ? 
            AND am.sender_id != ? 
            AND am.is_read = 0
            AND ac.is_archived = 0
            AND NOT EXISTS (
                -- Check if admin has replied to this chat
                SELECT 1 FROM admin_messages am2
                WHERE am2.chat_id = ac.chat_id
                AND am2.sender_id = ?
            )
        GROUP BY u.user_id, u.username, u.full_name
        
        UNION ALL
        
        -- Existing chats with unread messages (admin has replied before)
        SELECT 
            'admin_chat_message' as type,
            u.user_id,
            u.username,
            u.full_name,
            NULL as first_message_time,
            NULL as message_count,
            COUNT(am.message_id) as unread_count,
            ac.chat_id,
            MAX(am.created_at) as last_message_time,
            (SELECT TOP 1 message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as latest_message
        FROM admin_chats ac
        INNER JOIN admin_messages am ON ac.chat_id = am.chat_id
        INNER JOIN users u ON ac.user_id = u.user_id
        WHERE ac.admin_id = ?
            AND am.sender_id = ac.user_id
            AND am.is_read = 0
            AND ac.is_archived = 0
            AND EXISTS (
                -- Only if admin has replied at least once
                SELECT 1 FROM admin_messages am2
                WHERE am2.chat_id = ac.chat_id
                AND am2.sender_id = ?
            )
        GROUP BY ac.chat_id, u.user_id, u.username, u.full_name
    ";
    
    $params = array($user_id, $user_id, $user_id, $user_id, $user_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // For admin_chat_request, get the chat_id
            if ($row['type'] === 'admin_chat_request') {
                $row['chat_id'] = getAdminChatWithUser($conn, $user_id, $row['user_id']);
            }
            
            // Format time fields if they exist
            if ($row['first_message_time'] instanceof DateTime) {
                $row['first_message_time'] = $row['first_message_time']->format('Y-m-d H:i:s');
            }
            if ($row['last_message_time'] instanceof DateTime) {
                $row['last_message_time'] = $row['last_message_time']->format('Y-m-d H:i:s');
            }
            
            $response['notifications'][] = $row;
            
            // Calculate total unread
            if ($row['type'] === 'admin_chat_request') {
                $response['total_unread'] += $row['message_count'] ?? 0;
            } else {
                $response['total_unread'] += $row['unread_count'] ?? 0;
            }
        }
        sqlsrv_free_stmt($stmt);
    }
    
} else {
    // USER NOTIFICATIONS - Similar logic for regular users
    $sql = "
        -- Head messages
        SELECT 
            'head_message' as type,
            n.number_id,
            n.numbers as contact_number,
            n.description,
            COUNT(DISTINCT m.message_id) as unread_count,
            MAX(m.created_at) as last_message_time,
            (SELECT TOP 1 message FROM messages 
             WHERE conversation_id IN (
                 SELECT TOP 1 conversation_id FROM conversations 
                 WHERE number_id = n.number_id AND is_archived = 0
                 ORDER BY last_activity DESC
             )
             ORDER BY created_at DESC) as latest_message,
            (SELECT TOP 1 u.full_name FROM messages msg
             JOIN users u ON msg.sender_id = u.user_id
             WHERE msg.conversation_id IN (
                 SELECT TOP 1 conversation_id FROM conversations 
                 WHERE number_id = n.number_id AND is_archived = 0
                 ORDER BY last_activity DESC
             )
             AND msg.sender_id != ?
             ORDER BY msg.created_at DESC) as last_sender
        FROM numbers n
        LEFT JOIN conversations c ON n.number_id = c.number_id AND c.is_archived = 0
        LEFT JOIN messages m ON c.conversation_id = m.conversation_id 
            AND m.receiver_id = ? 
            AND m.is_read = 0 
            AND m.is_archived = 0
        WHERE n.head_user_id = ?
        GROUP BY n.number_id, n.numbers, n.description
        HAVING COUNT(DISTINCT m.message_id) > 0
        
        UNION ALL
        
        -- Admin replies
        SELECT 
            'admin_reply' as type,
            NULL as number_id,
            NULL as contact_number,
            NULL as description,
            COUNT(am.message_id) as unread_count,
            MAX(am.created_at) as last_message_time,
            (SELECT TOP 1 message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as latest_message,
            u.full_name as last_sender
        FROM admin_chats ac
        INNER JOIN admin_messages am ON ac.chat_id = am.chat_id
        INNER JOIN users u ON ac.admin_id = u.user_id
        WHERE ac.user_id = ?
            AND am.sender_id = ac.admin_id
            AND am.is_read = 0
            AND ac.is_archived = 0
        GROUP BY ac.chat_id, u.full_name
        HAVING COUNT(am.message_id) > 0
    ";
    
    $params = array($user_id, $user_id, $user_id, $user_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            if ($row['last_message_time'] instanceof DateTime) {
                $row['last_message_time'] = $row['last_message_time']->format('Y-m-d H:i:s');
            }
            $response['notifications'][] = $row;
            $response['total_unread'] += $row['unread_count'] ?? 0;
        }
        sqlsrv_free_stmt($stmt);
    }
}

echo json_encode($response);
?>