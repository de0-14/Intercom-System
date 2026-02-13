<?php
// archive_functions.php - CONVERTED TO SQL SERVER VERSION

/**
 * Archive a conversation immediately (copy to archive tables)
 */
function archiveConversationImmediately($conn, $conversation_id) {
    try {
        // 1. Copy conversation to archive - now includes conversation_id!
        $copy_conv_sql = "
            INSERT INTO conversations_archive 
            (conversation_id, number_id, initiated_by, created_at, last_activity, is_archived, archived_at)
            SELECT 
                conversation_id,  -- Now you CAN insert this directly
                number_id, 
                initiated_by, 
                created_at, 
                last_activity, 
                1 as is_archived, 
                GETDATE() as archived_at
            FROM conversations 
            WHERE conversation_id = ?
        ";
        
        $copy_conv_params = array($conversation_id);
        $copy_conv_stmt = sqlsrv_query($conn, $copy_conv_sql, $copy_conv_params);
        
        if (!$copy_conv_stmt) {
            error_log("ERROR copying conversation: " . print_r(sqlsrv_errors(), true));
            return false;
        }
        
        // 2. Copy messages to archive - now includes message_id!
        $copy_msgs_sql = "
            INSERT INTO messages_archive 
            (message_id, sender_id, receiver_id, number_id, conversation_id, 
             message, created_at, updated_at, is_read, is_head_reply, is_archived, archived_at)
            SELECT 
                message_id,  -- Now you CAN insert this directly
                sender_id, 
                receiver_id, 
                number_id, 
                conversation_id, 
                message, 
                created_at, 
                updated_at, 
                is_read, 
                is_head_reply, 
                1 as is_archived, 
                GETDATE() as archived_at
            FROM messages 
            WHERE conversation_id = ?
        ";
        
        $copy_msgs_params = array($conversation_id);
        $copy_msgs_stmt = sqlsrv_query($conn, $copy_msgs_sql, $copy_msgs_params);
        
        if (!$copy_msgs_stmt) {
            error_log("ERROR copying messages: " . print_r(sqlsrv_errors(), true));
            return false;
        }
        
        // 3. Update original conversation
        $update_sql = "UPDATE conversations SET is_archived = 1, archived_at = GETDATE() WHERE conversation_id = ?";
        $update_params = array($conversation_id);
        sqlsrv_query($conn, $update_sql, $update_params);
        
        // 4. Delete original messages (optional)
        $delete_sql = "DELETE FROM messages WHERE conversation_id = ?";
        $delete_params = array($conversation_id);
        sqlsrv_query($conn, $delete_sql, $delete_params);
        
        return true;
        
    } catch (Exception $e) {
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