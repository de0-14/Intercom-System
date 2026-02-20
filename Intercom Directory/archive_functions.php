<?php
// archive_functions.php - CONVERTED TO SQL SERVER VERSION

/**
 * Archive a conversation immediately (copy to archive tables)
 */
function archiveConversationImmediately($conn, $conversation_id) {
    try {
        // Begin transaction
        sqlsrv_begin_transaction($conn);
        
        // 1. First check if already archived
        $check_sql = "SELECT conversation_id FROM conversations_archive WHERE conversation_id = ?";
        $check_params = array($conversation_id);
        $check_stmt = sqlsrv_prepare($conn, $check_sql, $check_params);
        
        if (!$check_stmt || !sqlsrv_execute($check_stmt)) {
            throw new Exception("Check archive failed: " . print_r(sqlsrv_errors(), true));
        }
        
        if (sqlsrv_has_rows($check_stmt)) {
            sqlsrv_free_stmt($check_stmt);
            sqlsrv_commit($conn);
            return false; // Already archived
        }
        sqlsrv_free_stmt($check_stmt);
        
        // 2. Get conversation data
        $get_conv_sql = "SELECT * FROM conversations WHERE conversation_id = ?";
        $get_conv_params = array($conversation_id);
        $get_conv_stmt = sqlsrv_query($conn, $get_conv_sql, $get_conv_params);
        
        if (!$get_conv_stmt) {
            throw new Exception("Get conversation failed: " . print_r(sqlsrv_errors(), true));
        }
        
        $conv_data = sqlsrv_fetch_array($get_conv_stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($get_conv_stmt);
        
        if (!$conv_data) {
            throw new Exception("Conversation not found");
        }
        
        // 3. Insert into conversations_archive
        $insert_conv_sql = "
            INSERT INTO conversations_archive (
                conversation_id, number_id, initiated_by, 
                created_at, last_activity, is_archived, archived_at
            ) VALUES (?, ?, ?, ?, ?, 1, GETDATE())
        ";
        $insert_conv_params = array(
            $conv_data['conversation_id'],
            $conv_data['number_id'],
            $conv_data['initiated_by'],
            $conv_data['created_at']->format('Y-m-d H:i:s'), // Convert DateTime to string
            $conv_data['last_activity']->format('Y-m-d H:i:s') // Convert DateTime to string
        );
        
        $insert_conv_stmt = sqlsrv_prepare($conn, $insert_conv_sql, $insert_conv_params);
        if (!$insert_conv_stmt || !sqlsrv_execute($insert_conv_stmt)) {
            throw new Exception("Failed to insert conversation into archive: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($insert_conv_stmt);
        
        // 4. Copy messages to messages_archive
        // Get messages from source
        $get_msgs_sql = "
            SELECT 
                message_id, sender_id, receiver_id, number_id, conversation_id,
                message, created_at, updated_at, is_read, is_head_reply, is_archived
            FROM messages 
            WHERE conversation_id = ?
        ";
        $get_msgs_params = array($conversation_id);
        $get_msgs_stmt = sqlsrv_query($conn, $get_msgs_sql, $get_msgs_params);
        
        if (!$get_msgs_stmt) {
            throw new Exception("Get messages failed: " . print_r(sqlsrv_errors(), true));
        }
        
        // Prepare insert statement for messages_archive
        $insert_msg_sql = "
            INSERT INTO messages_archive (
                message_id, sender_id, receiver_id, number_id, conversation_id,
                message, created_at, updated_at, is_read, is_head_reply, is_archived, archived_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())
        ";
        
        $copied_count = 0;
        while ($msg = sqlsrv_fetch_array($get_msgs_stmt, SQLSRV_FETCH_ASSOC)) {
            // Convert DateTime objects to strings if needed
            $created_at = is_object($msg['created_at']) ? $msg['created_at']->format('Y-m-d H:i:s') : $msg['created_at'];
            $updated_at = is_object($msg['updated_at']) ? $msg['updated_at']->format('Y-m-d H:i:s') : $msg['updated_at'];
            
            $insert_msg_params = array(
                $msg['message_id'],
                $msg['sender_id'],
                $msg['receiver_id'],
                $msg['number_id'],
                $msg['conversation_id'],
                $msg['message'],
                $created_at,
                $updated_at,
                $msg['is_read'],
                $msg['is_head_reply'],
                $msg['is_archived']
            );
            
            $insert_msg_stmt = sqlsrv_prepare($conn, $insert_msg_sql, $insert_msg_params);
            if (!$insert_msg_stmt || !sqlsrv_execute($insert_msg_stmt)) {
                throw new Exception("Failed to insert message into archive: " . print_r(sqlsrv_errors(), true));
            }
            sqlsrv_free_stmt($insert_msg_stmt);
            $copied_count++;
        }
        
        sqlsrv_free_stmt($get_msgs_stmt);
        
        error_log("Copied $copied_count messages to archive");
        
        // 5. Delete messages from main table (optional - only if you want to remove them)
        $delete_msgs_sql = "DELETE FROM messages WHERE conversation_id = ?";
        $delete_msgs_params = array($conversation_id);
        $delete_msgs_stmt = sqlsrv_prepare($conn, $delete_msgs_sql, $delete_msgs_params);
        if (!$delete_msgs_stmt || !sqlsrv_execute($delete_msgs_stmt)) {
            throw new Exception("Failed to delete messages: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_msgs_stmt);
        
        // 6. Mark conversation as archived in main table
        $mark_archived_sql = "
            UPDATE conversations 
            SET is_archived = 1, archived_at = GETDATE() 
            WHERE conversation_id = ?
        ";
        $mark_archived_params = array($conversation_id);
        $mark_archived_stmt = sqlsrv_prepare($conn, $mark_archived_sql, $mark_archived_params);
        
        if (!$mark_archived_stmt || !sqlsrv_execute($mark_archived_stmt)) {
            throw new Exception("Failed to mark conversation as archived: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($mark_archived_stmt);
        
        // Commit transaction
        sqlsrv_commit($conn);
        return true;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        sqlsrv_rollback($conn);
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
        // Begin transaction
        sqlsrv_begin_transaction($conn);
        
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
            $get_params = array($number_id, $user_id, $cutoff_date);
        } else {
            // For heads: all conversations for this number
            $get_ids_sql = "
                SELECT conversation_id 
                FROM conversations_archive 
                WHERE number_id = ? 
                AND archived_at < ?
            ";
            $get_params = array($number_id, $cutoff_date);
        }
        
        $get_stmt = sqlsrv_prepare($conn, $get_ids_sql, $get_params);
        if (!$get_stmt || !sqlsrv_execute($get_stmt)) {
            throw new Exception("Get old conversations failed: " . print_r(sqlsrv_errors(), true));
        }
        
        $conversation_ids = array();
        while ($row = sqlsrv_fetch_array($get_stmt, SQLSRV_FETCH_ASSOC)) {
            $conversation_ids[] = $row['conversation_id'];
        }
        sqlsrv_free_stmt($get_stmt);
        
        if (empty($conversation_ids)) {
            sqlsrv_commit($conn);
            return 0;
        }
        
        // Delete messages from messages_archive
        // Build parameter placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($conversation_ids), '?'));
        $delete_msgs_sql = "DELETE FROM messages_archive WHERE conversation_id IN ($placeholders)";
        $delete_msgs_stmt = sqlsrv_prepare($conn, $delete_msgs_sql, $conversation_ids);
        
        if (!$delete_msgs_stmt || !sqlsrv_execute($delete_msgs_stmt)) {
            throw new Exception("Delete messages failed: " . print_r(sqlsrv_errors(), true));
        }
        sqlsrv_free_stmt($delete_msgs_stmt);
        
        // Delete conversations from conversations_archive
        $delete_conv_sql = "DELETE FROM conversations_archive WHERE conversation_id IN ($placeholders)";
        $delete_conv_stmt = sqlsrv_prepare($conn, $delete_conv_sql, $conversation_ids);
        
        if (!$delete_conv_stmt || !sqlsrv_execute($delete_conv_stmt)) {
            throw new Exception("Delete conversations failed: " . print_r(sqlsrv_errors(), true));
        }
        
        // Get the number of rows affected
        $removed_count = sqlsrv_rows_affected($delete_conv_stmt);
        sqlsrv_free_stmt($delete_conv_stmt);
        
        sqlsrv_commit($conn);
        return $removed_count;
        
    } catch (Exception $e) {
        sqlsrv_rollback($conn);
        error_log("Remove old conversations error: " . $e->getMessage());
        return 0;
    }
}
?>