<?php
function archiveAdminChatImmediately($conn, $chat_id) {
    try {
        sqlsrv_begin_transaction($conn);
        
        // 1. First check if already archived
        $check_sql = "SELECT chat_id FROM admin_chats_archive WHERE chat_id = ?";
        $check_params = array($chat_id);
        $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
        
        if ($check_stmt && sqlsrv_has_rows($check_stmt)) {
            sqlsrv_free_stmt($check_stmt);
            sqlsrv_commit($conn);
            return false;
        }
        sqlsrv_free_stmt($check_stmt);
        
        // 2. Get chat data
        $get_chat_sql = "SELECT * FROM admin_chats WHERE chat_id = ? AND is_archived = 0";
        $get_chat_params = array($chat_id);
        $get_chat_stmt = sqlsrv_query($conn, $get_chat_sql, $get_chat_params);
        
        if (!$get_chat_stmt || !sqlsrv_has_rows($get_chat_stmt)) {
            if ($get_chat_stmt) sqlsrv_free_stmt($get_chat_stmt);
            throw new Exception("Chat not found or already archived");
        }
        
        $chat_data = sqlsrv_fetch_array($get_chat_stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($get_chat_stmt);
        
        // 3. Insert into admin_chats_archive - NOW includes chat_id!
        $insert_chat_sql = "
            INSERT INTO admin_chats_archive 
            (chat_id, admin_id, user_id, created_at, is_archived, archived_at, last_activity)
            SELECT 
                chat_id,
                admin_id, 
                user_id, 
                created_at, 
                1, 
                GETDATE(), 
                last_activity
            FROM admin_chats 
            WHERE chat_id = ?
        ";
        $insert_chat_params = array($chat_id);
        $insert_chat_stmt = sqlsrv_query($conn, $insert_chat_sql, $insert_chat_params);
        
        if (!$insert_chat_stmt) {
            $errors = sqlsrv_errors();
            throw new Exception("Failed to insert chat into archive: " . print_r($errors, true));
        }
        sqlsrv_free_stmt($insert_chat_stmt);
        
        // 4. Copy messages - NOW includes message_id!
        $copy_msgs_sql = "
            INSERT INTO admin_messages_archive 
            (message_id, chat_id, sender_id, message, created_at, is_read, is_archived, archived_at)
            SELECT 
                message_id,
                chat_id, 
                sender_id, 
                message, 
                created_at, 
                is_read, 
                1, 
                GETDATE()
            FROM admin_messages 
            WHERE chat_id = ?
        ";
        $copy_msgs_params = array($chat_id);
        $copy_msgs_stmt = sqlsrv_query($conn, $copy_msgs_sql, $copy_msgs_params);
        
        if (!$copy_msgs_stmt) {
            $errors = sqlsrv_errors();
            throw new Exception("Failed to copy messages to archive: " . print_r($errors, true));
        }
        sqlsrv_free_stmt($copy_msgs_stmt);
        
        // 5. Delete messages from main table
        $delete_msgs_sql = "DELETE FROM admin_messages WHERE chat_id = ?";
        $delete_msgs_params = array($chat_id);
        $delete_msgs_stmt = sqlsrv_query($conn, $delete_msgs_sql, $delete_msgs_params);
        
        if (!$delete_msgs_stmt) {
            $errors = sqlsrv_errors();
            throw new Exception("Failed to delete admin messages: " . print_r($errors, true));
        }
        sqlsrv_free_stmt($delete_msgs_stmt);
        
        // 6. Mark chat as archived in main table
        $mark_archived_sql = "
            UPDATE admin_chats 
            SET is_archived = 1, archived_at = GETDATE() 
            WHERE chat_id = ?
        ";
        $mark_archived_params = array($chat_id);
        $mark_archived_stmt = sqlsrv_query($conn, $mark_archived_sql, $mark_archived_params);
        
        if (!$mark_archived_stmt) {
            $errors = sqlsrv_errors();
            throw new Exception("Failed to mark chat as archived: " . print_r($errors, true));
        }
        sqlsrv_free_stmt($mark_archived_stmt);
        
        sqlsrv_commit($conn);
        return true;
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        error_log("Admin archive error: " . $e->getMessage());
        return false;
    }
}

function removeOldArchivedAdminChats($conn, $admin_id = null, $user_id = null, $cutoff_date = null) {
    $removed_count = 0;
    
    if ($cutoff_date === null) {
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-7 days'));
    }
    
    try {
        sqlsrv_begin_transaction($conn);
        
        // Build query based on parameters
        $where_conditions = [];
        $params = [];
        
        if ($admin_id) {
            $where_conditions[] = "admin_id = ?";
            $params[] = $admin_id;
        }
        
        if ($user_id) {
            $where_conditions[] = "user_id = ?";
            $params[] = $user_id;
        }
        
        $where_conditions[] = "archived_at < ?";
        $params[] = $cutoff_date;
        
        $where_sql = implode(" AND ", $where_conditions);
        
        // First get chat IDs (these are the original chat_ids, not the identity column)
        $get_ids_sql = "
            SELECT chat_id 
            FROM admin_chats_archive 
            WHERE $where_sql
        ";
        $get_stmt = sqlsrv_query($conn, $get_ids_sql, $params);
        
        if (!$get_stmt) {
            throw new Exception("Failed to get archived chat IDs: " . print_r(sqlsrv_errors(), true));
        }
        
        $chat_ids = [];
        while ($row = sqlsrv_fetch_array($get_stmt, SQLSRV_FETCH_ASSOC)) {
            $chat_ids[] = $row['chat_id'];
        }
        sqlsrv_free_stmt($get_stmt);
        
        if (empty($chat_ids)) {
            sqlsrv_commit($conn);
            return 0;
        }
        
        // Delete messages from admin_messages_archive
        $placeholders = implode(',', array_fill(0, count($chat_ids), '?'));
        $delete_msgs_sql = "DELETE FROM admin_messages_archive WHERE chat_id IN ($placeholders)";
        $delete_msgs_stmt = sqlsrv_prepare($conn, $delete_msgs_sql, $chat_ids);
        
        if (!$delete_msgs_stmt || !sqlsrv_execute($delete_msgs_stmt)) {
            throw new Exception("Failed to delete archived messages: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_msgs_stmt);
        
        // Delete chats from admin_chats_archive
        $delete_chat_sql = "DELETE FROM admin_chats_archive WHERE chat_id IN ($placeholders)";
        $delete_chat_stmt = sqlsrv_prepare($conn, $delete_chat_sql, $chat_ids);
        
        if (!$delete_chat_stmt || !sqlsrv_execute($delete_chat_stmt)) {
            throw new Exception("Failed to delete archived chats: " . print_r(sqlsrv_errors(), true));
        }
        
        // Get row count
        $removed_count = sqlsrv_rows_affected($delete_chat_stmt);
        sqlsrv_free_stmt($delete_chat_stmt);
        
        sqlsrv_commit($conn);
        return $removed_count;
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        error_log("Remove old admin chats error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Update admin chat activity
 */
function updateAdminChatActivity($conn, $chat_id) {
    $update_sql = "UPDATE admin_chats SET last_activity = GETDATE() WHERE chat_id = ?";
    $params = array($chat_id);
    $stmt = sqlsrv_query($conn, $update_sql, $params);
    
    if ($stmt) {
        sqlsrv_free_stmt($stmt);
        return true;
    } else {
        return false;
    }
}

/**
 * Check if there are archived chats with a specific user
 */
function hasArchivedChatWithUser($conn, $current_user_id, $other_user_id, $is_admin) {
    if ($is_admin) {
        $sql = "SELECT COUNT(*) as archived_count 
                FROM admin_chats_archive 
                WHERE admin_id = ? AND user_id = ?";
        $params = array($current_user_id, $other_user_id);
    } else {
        $sql = "SELECT COUNT(*) as archived_count 
                FROM admin_chats_archive 
                WHERE admin_id = ? AND user_id = ?";
        $params = array($other_user_id, $current_user_id);
    }
    
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if (!$stmt) {
        error_log("hasArchivedChatWithUser error: " . print_r(sqlsrv_errors(), true));
        return false;
    }
    
    if (sqlsrv_fetch($stmt)) {
        $count = sqlsrv_get_field($stmt, 0);
        sqlsrv_free_stmt($stmt);
        return $count > 0;
    }
    
    sqlsrv_free_stmt($stmt);
    return false;
}

/**
 * Get active admin chats (excluding archived)
 */
function getActiveAdminChats($conn, $user_id, $is_admin = true) {
    if($is_admin) {
        $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
                (SELECT TOP 1 message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as last_message,
                (SELECT TOP 1 created_at FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as last_message_time
                FROM admin_chats ac 
                JOIN users u ON ac.user_id = u.user_id 
                WHERE ac.admin_id = ? AND ac.is_archived = 0
                ORDER BY ac.last_activity DESC";
    } else {
        $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
                (SELECT TOP 1 message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as last_message,
                (SELECT TOP 1 created_at FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC) as last_message_time
                FROM admin_chats ac 
                JOIN users u ON ac.admin_id = u.user_id 
                WHERE ac.user_id = ? AND ac.is_archived = 0
                ORDER BY ac.last_activity DESC";
    }
    
    $params = array($user_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if (!$stmt) {
        error_log("getActiveAdminChats error: " . print_r(sqlsrv_errors(), true));
        return [];
    }
    
    $chats = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Ensure all expected fields exist
        $row['last_message'] = $row['last_message'] ?? 'No messages yet';
        $row['last_message_time'] = $row['last_message_time'] ?? null;
        $chats[] = $row;
    }
    
    sqlsrv_free_stmt($stmt);
    return $chats;
}

/**
 * Get archived admin chats
 */
function getArchivedAdminChats($conn, $user_id, $is_admin = true) {
    if($is_admin) {
        $sql = "
            SELECT 
                ac.*, 
                u.username, 
                u.full_name, 
                u.role_id,
                -- Get the last message from archive
                (
                    SELECT TOP 1 message 
                    FROM admin_messages_archive 
                    WHERE chat_id = ac.chat_id 
                    ORDER BY created_at DESC
                ) as last_message,
                (
                    SELECT TOP 1 created_at 
                    FROM admin_messages_archive 
                    WHERE chat_id = ac.chat_id 
                    ORDER BY created_at DESC
                ) as last_message_time
            FROM admin_chats_archive ac 
            JOIN users u ON ac.user_id = u.user_id 
            WHERE ac.admin_id = ? 
            AND ac.is_archived = 1
            ORDER BY ac.archived_at DESC
        ";
    } else {
        $sql = "
            SELECT 
                ac.*, 
                u.username, 
                u.full_name, 
                u.role_id,
                -- Get the last message from archive
                (
                    SELECT TOP 1 message 
                    FROM admin_messages_archive 
                    WHERE chat_id = ac.chat_id 
                    ORDER BY created_at DESC
                ) as last_message,
                (
                    SELECT TOP 1 created_at 
                    FROM admin_messages_archive 
                    WHERE chat_id = ac.chat_id 
                    ORDER BY created_at DESC
                ) as last_message_time
            FROM admin_chats_archive ac 
            JOIN users u ON ac.admin_id = u.user_id 
            WHERE ac.user_id = ? 
            AND ac.is_archived = 1
            ORDER BY ac.archived_at DESC
        ";
    }
    
    $params = array($user_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if (!$stmt) {
        error_log("getArchivedAdminChats error: " . print_r(sqlsrv_errors(), true));
        return [];
    }
    
    $chats = [];
    while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $row['last_message'] = $row['last_message'] ?? 'No messages';
        $row['last_message_time'] = $row['last_message_time'] ?? null;
        $chats[] = $row;
    }
    
    sqlsrv_free_stmt($stmt);
    return $chats;
}

/**
 * Get archived admin messages
 */
function getArchivedAdminMessages($conn, $chat_id, $user_id) {
    try {
        // First, check if the chat exists in archive with this chat_id
        $check_sql = "
            SELECT 
                ac.*,
                -- Get the other user's full name properly
                CASE 
                    WHEN ? = ac.admin_id THEN u_user.full_name
                    ELSE u_admin.full_name
                END as other_full_name,
                CASE 
                    WHEN ? = ac.admin_id THEN 'admin'
                    ELSE 'user'
                END as user_type,
                -- Also get both users' names for reference
                u_admin.full_name as admin_name,
                u_admin.user_id as admin_id,
                u_user.full_name as user_name,
                u_user.user_id as user_id
            FROM admin_chats_archive ac
            LEFT JOIN users u_admin ON ac.admin_id = u_admin.user_id
            LEFT JOIN users u_user ON ac.user_id = u_user.user_id
            WHERE ac.chat_id = ?
            AND (? = ac.admin_id OR ? = ac.user_id)
        ";
        
        $params = array($user_id, $user_id, $chat_id, $user_id, $user_id);
        $stmt = sqlsrv_query($conn, $check_sql, $params);
        
        if (!$stmt) {
            $errors = sqlsrv_errors();
            error_log("Check query error: " . print_r($errors, true));
            throw new Exception("Check failed: " . print_r($errors, true));
        }
        
        if (!sqlsrv_has_rows($stmt)) {
            sqlsrv_free_stmt($stmt);
            return ["success" => false, "error" => "Chat not found or permission denied"];
        }
        
        $chat = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        // Get messages
        $messages_sql = "
            SELECT am.*, u.full_name, u.username
            FROM admin_messages_archive am
            JOIN users u ON am.sender_id = u.user_id
            WHERE am.chat_id = ?
            ORDER BY am.created_at ASC
        ";
        
        $messages_params = array($chat_id);
        $stmt2 = sqlsrv_query($conn, $messages_sql, $messages_params);
        
        if (!$stmt2) {
            $errors = sqlsrv_errors();
            error_log("Messages query error: " . print_r($errors, true));
            throw new Exception("Messages query failed: " . print_r($errors, true));
        }
        
        $messages = [];
        while ($row = sqlsrv_fetch_array($stmt2, SQLSRV_FETCH_ASSOC)) {
            $messages[] = $row;
        }
        sqlsrv_free_stmt($stmt2);
        
        return [
            "success" => true,
            "messages" => $messages,
            "chat_info" => $chat  // This now contains all the user info
        ];
        
    } catch (Exception $e) {
        error_log("Get archived admin messages error: " . $e->getMessage());
        return ["success" => false, "error" => $e->getMessage()];
    }
}

/**
 * Auto-archive inactive admin chats
 */
function autoArchiveInactiveAdminChats($conn, $inactive_minutes = 30) {
    $archived_count = 0;
    $inactive_time = date('Y-m-d H:i:s', strtotime("-$inactive_minutes minutes"));
    
    try {
        // Get chats inactive for X minutes and not already archived
        $get_inactive_sql = "
            SELECT chat_id 
            FROM admin_chats 
            WHERE is_archived = 0 
            AND last_activity < ? 
            AND chat_id IN (
                SELECT DISTINCT chat_id FROM admin_messages 
            )
        ";
        
        $get_inactive_params = array($inactive_time);
        $get_inactive_stmt = sqlsrv_query($conn, $get_inactive_sql, $get_inactive_params);
        
        if (!$get_inactive_stmt) {
            throw new Exception("Failed to get inactive chats: " . print_r(sqlsrv_errors(), true));
        }
        
        while ($chat = sqlsrv_fetch_array($get_inactive_stmt, SQLSRV_FETCH_ASSOC)) {
            $chat_id = $chat['chat_id'];
            
            if (archiveAdminChatImmediately($conn, $chat_id)) {
                $archived_count++;
            }
        }
        
        sqlsrv_free_stmt($get_inactive_stmt);
        return $archived_count;
        
    } catch (Exception $e) {
        error_log("Auto archive admin chats error: " . $e->getMessage());
        return 0;
    }
}
?>