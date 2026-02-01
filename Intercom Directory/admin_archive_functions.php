<?php
// admin_archive_functions.php

/**
 * Archive an admin chat immediately (copy to archive tables)
 */
/**
 * Archive an admin chat immediately (copy to archive tables)
 */
function archiveAdminChatImmediately($conn, $chat_id) {
    try {
        $conn->begin_transaction();
        
        // 1. First check if already archived
        $check_stmt = $conn->prepare("SELECT chat_id FROM admin_chats_archive WHERE chat_id = ?");
        $check_stmt->bind_param("i", $chat_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $check_stmt->close();
            $conn->commit();
            return false; // Already archived
        }
        $check_stmt->close();
        
        // 2. Get chat data
        $get_chat = $conn->prepare("SELECT * FROM admin_chats WHERE chat_id = ? AND is_archived = 0");
        $get_chat->bind_param("i", $chat_id);
        $get_chat->execute();
        $chat_data = $get_chat->get_result()->fetch_assoc();
        $get_chat->close();
        
        if (!$chat_data) {
            throw new Exception("Chat not found or already archived");
        }
        
        // 3. Insert into admin_chats_archive
        $insert_chat = $conn->prepare("
            INSERT INTO admin_chats_archive (chat_id, admin_id, user_id, created_at, is_archived, archived_at, last_activity)
            SELECT chat_id, admin_id, user_id, created_at, 1, NOW(), last_activity
            FROM admin_chats 
            WHERE chat_id = ?
        ");
        $insert_chat->bind_param("i", $chat_id);
        
        if (!$insert_chat->execute()) {
            throw new Exception("Failed to insert chat into archive: " . $insert_chat->error);
        }
        $insert_chat->close();
        
        // 4. Copy messages to admin_messages_archive
        $copy_msgs = $conn->prepare("
            INSERT INTO admin_messages_archive (message_id, chat_id, sender_id, message, created_at, is_read, is_archived, archived_at)
            SELECT message_id, chat_id, sender_id, message, created_at, is_read, 1, NOW()
            FROM admin_messages 
            WHERE chat_id = ?
        ");
        $copy_msgs->bind_param("i", $chat_id);
        
        if (!$copy_msgs->execute()) {
            throw new Exception("Failed to copy messages to archive: " . $copy_msgs->error);
        }
        $copied_count = $conn->affected_rows;
        $copy_msgs->close();
        
        // 5. Delete messages from main table
        $delete_msgs = $conn->prepare("DELETE FROM admin_messages WHERE chat_id = ?");
        $delete_msgs->bind_param("i", $chat_id);
        if (!$delete_msgs->execute()) {
            throw new Exception("Failed to delete admin messages: " . $delete_msgs->error);
        }
        $delete_msgs->close();
        
        // 6. Mark chat as archived in main table (DO NOT DELETE, just mark as archived)
        $mark_archived = $conn->prepare("
            UPDATE admin_chats 
            SET is_archived = 1, archived_at = NOW() 
            WHERE chat_id = ?
        ");
        $mark_archived->bind_param("i", $chat_id);
        
        if (!$mark_archived->execute()) {
            throw new Exception("Failed to mark chat as archived: " . $mark_archived->error);
        }
        $mark_archived->close();
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Admin archive error: " . $e->getMessage());
        return false;
    }
}

/**
 * Remove old archived admin chats (archived for 7+ days)
 */
function removeOldArchivedAdminChats($conn, $admin_id = null, $user_id = null, $cutoff_date = null) {
    $removed_count = 0;
    
    if ($cutoff_date === null) {
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-7 days'));
    }
    
    try {
        $conn->begin_transaction();
        
        // Build query based on parameters
        $where_conditions = [];
        $params = [];
        $types = "";
        
        if ($admin_id) {
            $where_conditions[] = "admin_id = ?";
            $params[] = $admin_id;
            $types .= "i";
        }
        
        if ($user_id) {
            $where_conditions[] = "user_id = ?";
            $params[] = $user_id;
            $types .= "i";
        }
        
        $where_conditions[] = "archived_at < ?";
        $params[] = $cutoff_date;
        $types .= "s";
        
        $where_sql = implode(" AND ", $where_conditions);
        
        // First get chat IDs
        $get_ids_sql = "
            SELECT chat_id 
            FROM admin_chats_archive 
            WHERE $where_sql
        ";
        $get_stmt = $conn->prepare($get_ids_sql);
        $get_stmt->bind_param($types, ...$params);
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        $chat_ids = [];
        
        while ($row = $result->fetch_assoc()) {
            $chat_ids[] = $row['chat_id'];
        }
        $get_stmt->close();
        
        if (empty($chat_ids)) {
            $conn->commit();
            return 0;
        }
        
        // Delete messages from admin_messages_archive
        $placeholders = str_repeat('?,', count($chat_ids) - 1) . '?';
        $delete_msgs_sql = "DELETE FROM admin_messages_archive WHERE chat_id IN ($placeholders)";
        $delete_msgs = $conn->prepare($delete_msgs_sql);
        $delete_msgs->bind_param(str_repeat('i', count($chat_ids)), ...$chat_ids);
        $delete_msgs->execute();
        $delete_msgs->close();
        
        // Delete chats from admin_chats_archive
        $delete_chat_sql = "DELETE FROM admin_chats_archive WHERE chat_id IN ($placeholders)";
        $delete_chat = $conn->prepare($delete_chat_sql);
        $delete_chat->bind_param(str_repeat('i', count($chat_ids)), ...$chat_ids);
        $delete_chat->execute();
        $removed_count = $delete_chat->affected_rows;
        $delete_chat->close();
        
        $conn->commit();
        return $removed_count;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Remove old admin chats error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Update admin chat activity
 */
function updateAdminChatActivity($conn, $chat_id) {
    $update_sql = "UPDATE admin_chats SET last_activity = NOW() WHERE chat_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("i", $chat_id);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Check if there are archived chats with a specific user
 */
function hasArchivedChatWithUser($conn, $current_user_id, $other_user_id, $is_admin) {
    if ($is_admin) {
        $sql = "SELECT COUNT(*) as archived_count 
                FROM admin_chats_archive 
                WHERE admin_id = ? AND user_id = ?";
    } else {
        $sql = "SELECT COUNT(*) as archived_count 
                FROM admin_chats_archive 
                WHERE admin_id = ? AND user_id = ?";
    }
    
    $stmt = $conn->prepare($sql);
    if ($is_admin) {
        $stmt->bind_param("ii", $current_user_id, $other_user_id);
    } else {
        $stmt->bind_param("ii", $other_user_id, $current_user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    
    return ($data['archived_count'] ?? 0) > 0;
}

/**
 * Get active admin chats (excluding archived)
 */
function getActiveAdminChats($conn, $user_id, $is_admin = true) {
    if($is_admin) {
        $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
                (SELECT message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message_time
                FROM admin_chats ac 
                JOIN users u ON ac.user_id = u.user_id 
                WHERE ac.admin_id = ? AND ac.is_archived = 0
                ORDER BY ac.last_activity DESC";
    } else {
        $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
                (SELECT message FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM admin_messages WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message_time
                FROM admin_chats ac 
                JOIN users u ON ac.admin_id = u.user_id 
                WHERE ac.user_id = ? AND ac.is_archived = 0
                ORDER BY ac.last_activity DESC";
    }
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $chats = [];
    while($row = $result->fetch_assoc()) {
        $chats[] = $row;
    }
    
    $stmt->close();
    return $chats;
}
/**
 * Get archived admin chats
 */
function getArchivedAdminChats($conn, $user_id, $is_admin = true) {
    if($is_admin) {
        $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
                (SELECT message FROM admin_messages_archive WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM admin_messages_archive WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message_time
                FROM admin_chats_archive ac 
                JOIN users u ON ac.user_id = u.user_id 
                WHERE ac.admin_id = ? AND ac.is_archived = 1
                ORDER BY ac.archived_at DESC";
    } else {
        $sql = "SELECT ac.*, u.username, u.full_name, u.role_id, 
                (SELECT message FROM admin_messages_archive WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message,
                (SELECT created_at FROM admin_messages_archive WHERE chat_id = ac.chat_id ORDER BY created_at DESC LIMIT 1) as last_message_time
                FROM admin_chats_archive ac 
                JOIN users u ON ac.admin_id = u.user_id 
                WHERE ac.user_id = ? AND ac.is_archived = 1
                ORDER BY ac.archived_at DESC";
    }
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $chats = [];
    while($row = $result->fetch_assoc()) {
        $chats[] = $row;
    }
    
    $stmt->close();
    return $chats;
}

/**
 * Get archived admin messages
 */
// Replace the getArchivedAdminMessages function with this corrected version:

/**
 * Get archived admin messages
 */
function getArchivedAdminMessages($conn, $chat_id, $user_id) {
    try {
        // Check if user has permission to view archived messages
        $check_sql = "
            SELECT ac.*, 
                   CASE 
                       WHEN ? = ac.admin_id THEN u1.username
                       ELSE u2.username
                   END as other_name,
                   CASE 
                       WHEN ? = ac.admin_id THEN u1.full_name
                       ELSE u2.full_name
                   END as other_full_name,
                   CASE 
                       WHEN ? = ac.admin_id THEN 'admin'
                       ELSE 'user'
                   END as user_type
            FROM admin_chats_archive ac
            LEFT JOIN users u1 ON ac.user_id = u1.user_id
            LEFT JOIN users u2 ON ac.admin_id = u2.user_id
            WHERE ac.chat_id = ?
            AND (? = ac.admin_id OR ? = ac.user_id)
        ";
        
        $stmt = $conn->prepare($check_sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("iiiiii", 
            $user_id,  // first parameter: for username
            $user_id,  // second parameter: for full_name
            $user_id,  // third parameter: for user_type
            $chat_id,  // fourth parameter: for chat_id
            $user_id,  // fifth parameter: for admin_id check
            $user_id   // sixth parameter: for user_id check
        );
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            return ["success" => false, "error" => "Chat not found or permission denied"];
        }
        
        $chat = $result->fetch_assoc();
        $stmt->close();
        
        // Get messages
        $messages_sql = "
            SELECT am.*, u.username as sender_name, u.full_name as sender_full_name
            FROM admin_messages_archive am
            JOIN users u ON am.sender_id = u.user_id
            WHERE am.chat_id = ?
            ORDER BY am.created_at ASC
        ";
        $stmt2 = $conn->prepare($messages_sql);
        if (!$stmt2) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt2->bind_param("i", $chat_id);
        $stmt2->execute();
        $messages_result = $stmt2->get_result();
        
        $messages = [];
        while ($row = $messages_result->fetch_assoc()) {
            $messages[] = $row;
        }
        $stmt2->close();
        
        return [
            "success" => true,
            "messages" => $messages,
            "chat_info" => $chat
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
        $get_inactive_stmt = $conn->prepare("
            SELECT chat_id 
            FROM admin_chats 
            WHERE is_archived = FALSE 
            AND last_activity < ? 
            AND chat_id IN (
                SELECT DISTINCT chat_id FROM admin_messages
            )
        ");
        $get_inactive_stmt->bind_param("s", $inactive_time);
        $get_inactive_stmt->execute();
        $inactive_result = $get_inactive_stmt->get_result();
        
        while ($chat = $inactive_result->fetch_assoc()) {
            $chat_id = $chat['chat_id'];
            
            if (archiveAdminChatImmediately($conn, $chat_id)) {
                $archived_count++;
            }
        }
        
        $get_inactive_stmt->close();
        return $archived_count;
        
    } catch (Exception $e) {
        error_log("Auto archive admin chats error: " . $e->getMessage());
        return 0;
    }
}
?>