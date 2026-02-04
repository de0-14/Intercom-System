<?php
// archive_functions.php - CORRECTED VERSION

/**
 * Archive a conversation immediately (copy to archive tables)
 */
function archiveConversationImmediately($conn, $conversation_id) {
    try {
        $conn->begin_transaction();
        
        // 1. First check if already archived
        $check_stmt = $conn->prepare("SELECT conversation_id FROM conversations_archive WHERE conversation_id = ?");
        $check_stmt->bind_param("i", $conversation_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $check_stmt->close();
            $conn->commit();
            return false; // Already archived
        }
        $check_stmt->close();
        
        // 2. Get conversation data
        $get_conv = $conn->prepare("SELECT * FROM conversations WHERE conversation_id = ?");
        $get_conv->bind_param("i", $conversation_id);
        $get_conv->execute();
        $conv_data = $get_conv->get_result()->fetch_assoc();
        $get_conv->close();
        
        if (!$conv_data) {
            throw new Exception("Conversation not found");
        }
        
        // 3. Insert into conversations_archive - FIXED: include is_archived column
        $insert_conv = $conn->prepare("
            INSERT INTO conversations_archive (
                conversation_id, number_id, initiated_by, 
                created_at, last_activity, is_archived, archived_at
            ) VALUES (?, ?, ?, ?, ?, 1, NOW())
        ");
        $insert_conv->bind_param(
            "iiiss", 
            $conv_data['conversation_id'],
            $conv_data['number_id'],
            $conv_data['initiated_by'],
            $conv_data['created_at'],
            $conv_data['last_activity']
        );
        
        if (!$insert_conv->execute()) {
            throw new Exception("Failed to insert conversation into archive: " . $insert_conv->error);
        }
        $insert_conv->close();
        
        // 4. Copy messages to messages_archive - EXPLICIT COLUMN LIST
        // Get messages from source
        $get_msgs = $conn->prepare("
            SELECT 
                message_id, sender_id, receiver_id, number_id, conversation_id,
                message, created_at, updated_at, is_read, is_head_reply, is_archived
            FROM messages 
            WHERE conversation_id = ?
        ");
        $get_msgs->bind_param("i", $conversation_id);
        $get_msgs->execute();
        $messages_result = $get_msgs->get_result();
        
        // Prepare insert statement for messages_archive
        $insert_msg = $conn->prepare("
            INSERT INTO messages_archive (
                message_id, sender_id, receiver_id, number_id, conversation_id,
                message, created_at, updated_at, is_read, is_head_reply, is_archived, archived_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $copied_count = 0;
        while ($msg = $messages_result->fetch_assoc()) {
            $insert_msg->bind_param(
                "iiiiisssiii",
                $msg['message_id'],
                $msg['sender_id'],
                $msg['receiver_id'],
                $msg['number_id'],
                $msg['conversation_id'],
                $msg['message'],
                $msg['created_at'],
                $msg['updated_at'],
                $msg['is_read'],
                $msg['is_head_reply'],
                $msg['is_archived']
            );
            
            if (!$insert_msg->execute()) {
                throw new Exception("Failed to insert message into archive: " . $insert_msg->error);
            }
            $copied_count++;
        }
        
        $get_msgs->close();
        $insert_msg->close();
        
        echo "Copied $copied_count messages to archive\n";
        
        // 5. Delete messages from main table (optional - only if you want to remove them)
        $delete_msgs = $conn->prepare("DELETE FROM messages WHERE conversation_id = ?");
        $delete_msgs->bind_param("i", $conversation_id);
        if (!$delete_msgs->execute()) {
            throw new Exception("Failed to delete messages: " . $delete_msgs->error);
        }
        $delete_msgs->close();
        
        // 6. Mark conversation as archived in main table
        $mark_archived = $conn->prepare("
            UPDATE conversations 
            SET is_archived = 1, archived_at = NOW() 
            WHERE conversation_id = ?
        ");
        $mark_archived->bind_param("i", $conversation_id);
        
        if (!$mark_archived->execute()) {
            throw new Exception("Failed to mark conversation as archived: " . $mark_archived->error);
        }
        $mark_archived->close();
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Archive error: " . $e->getMessage());
        return false;
    }
}

/**
 * Remove old archived conversations (archived for 7+ days)
 * This should only remove from archive tables, not main tables
 */
function removeOldArchivedConversations($conn, $number_id, $user_id = null, $cutoff_date = null) {
    $removed_count = 0;
    
    if ($cutoff_date === null) {
        $cutoff_date = date('Y-m-d H:i:s', strtotime('-7 days'));
    }
    
    try {
        $conn->begin_transaction();
        
        if ($user_id) {
            // For regular users: only their own conversations
            // First get conversation IDs
            $get_ids_sql = "
                SELECT conversation_id 
                FROM conversations_archive 
                WHERE number_id = ? 
                AND initiated_by = ?
                AND archived_at < ?
            ";
            $get_stmt = $conn->prepare($get_ids_sql);
            $get_stmt->bind_param("iis", $number_id, $user_id, $cutoff_date);
        } else {
            // For heads: all conversations for this number
            $get_ids_sql = "
                SELECT conversation_id 
                FROM conversations_archive 
                WHERE number_id = ? 
                AND archived_at < ?
            ";
            $get_stmt = $conn->prepare($get_ids_sql);
            $get_stmt->bind_param("is", $number_id, $cutoff_date);
        }
        
        $get_stmt->execute();
        $result = $get_stmt->get_result();
        $conversation_ids = [];
        
        while ($row = $result->fetch_assoc()) {
            $conversation_ids[] = $row['conversation_id'];
        }
        $get_stmt->close();
        
        if (empty($conversation_ids)) {
            $conn->commit();
            return 0;
        }
        
        // Delete messages from messages_archive
        $placeholders = str_repeat('?,', count($conversation_ids) - 1) . '?';
        $delete_msgs_sql = "DELETE FROM messages_archive WHERE conversation_id IN ($placeholders)";
        $delete_msgs = $conn->prepare($delete_msgs_sql);
        $delete_msgs->bind_param(str_repeat('i', count($conversation_ids)), ...$conversation_ids);
        $delete_msgs->execute();
        $delete_msgs->close();
        
        // Delete conversations from conversations_archive
        $delete_conv_sql = "DELETE FROM conversations_archive WHERE conversation_id IN ($placeholders)";
        $delete_conv = $conn->prepare($delete_conv_sql);
        $delete_conv->bind_param(str_repeat('i', count($conversation_ids)), ...$conversation_ids);
        $delete_conv->execute();
        $removed_count = $delete_conv->affected_rows;
        $delete_conv->close();
        
        $conn->commit();
        return $removed_count;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Remove old conversations error: " . $e->getMessage());
        return 0;
    }
}
?>