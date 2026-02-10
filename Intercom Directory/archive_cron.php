<?php
require_once 'conn.php';

echo "=== Starting 5-minute auto-archive cron job ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";

// Archive conversations inactive for 5 minutes or more
$five_minutes_ago = date('Y-m-d H:i:s', strtotime('-5 minutes'));
echo "Looking for conversations older than: $five_minutes_ago\n";

// Get inactive conversations that are NOT archived
$inactive_sql = "SELECT conversation_id, number_id, initiated_by 
                 FROM conversations 
                 WHERE is_archived = 0 
                 AND last_activity < ?";
$inactive_stmt = $conn->prepare($inactive_sql);
$inactive_stmt->bind_param("s", $five_minutes_ago);
$inactive_stmt->execute();
$inactive_result = $inactive_stmt->get_result();

$archived_count = 0;
$errors = 0;

if ($inactive_result->num_rows > 0) {
    echo "Found " . $inactive_result->num_rows . " inactive conversation(s)\n";
    
    while ($conv = $inactive_result->fetch_assoc()) {
        $conversation_id = $conv['conversation_id'];
        $number_id = $conv['number_id'];
        $initiated_by = $conv['initiated_by'];
        
        echo "Processing conversation #$conversation_id (User: $initiated_by, Number: $number_id)... ";
        
        try {
            // Start transaction
            $conn->begin_transaction();
            
            // 1. Copy conversation to archive table
            $copy_conv = $conn->prepare("
                INSERT INTO conversations_archive 
                SELECT * FROM conversations 
                WHERE conversation_id = ?
            ");
            $copy_conv->bind_param("i", $conversation_id);
            $copy_conv->execute();
            $copy_conv->close();
            
            // 2. Copy messages to archive table
            $copy_msgs = $conn->prepare("
                INSERT INTO messages_archive 
                SELECT * FROM messages 
                WHERE conversation_id = ?
            ");
            $copy_msgs->bind_param("i", $conversation_id);
            $copy_msgs->execute();
            $copy_msgs->close();
            
            // 3. Update original conversation as archived
            $mark_conv = $conn->prepare("
                UPDATE conversations 
                SET is_archived = 1, archived_at = NOW() 
                WHERE conversation_id = ?
            ");
            $mark_conv->bind_param("i", $conversation_id);
            $mark_conv->execute();
            $mark_conv->close();
            
            // 4. Mark messages as archived
            $mark_msgs = $conn->prepare("
                UPDATE messages 
                SET is_archived = 1, archived_at = NOW() 
                WHERE conversation_id = ?
            ");
            $mark_msgs->bind_param("i", $conversation_id);
            $mark_msgs->execute();
            $mark_msgs->close();
            
            // Commit transaction
            $conn->commit();
            
            $archived_count++;
            echo "✓ Archived successfully\n";
            
        } catch (Exception $e) {
            // Rollback on error
            $conn->rollback();
            $errors++;
            echo "✗ Error: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "No inactive conversations found.\n";
}

$inactive_stmt->close();

// ==========================================
// Optional: Clean up old archive records (older than 30 days)
// ==========================================
$thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
$cleanup_sql = "DELETE FROM conversations_archive WHERE archived_at < ?";
$cleanup_stmt = $conn->prepare($cleanup_sql);
$cleanup_stmt->bind_param("s", $thirty_days_ago);
$cleanup_stmt->execute();
$deleted_conversations = $cleanup_stmt->affected_rows;
$cleanup_stmt->close();

// Also cleanup messages_archive
$cleanup_msgs_sql = "DELETE FROM messages_archive WHERE archived_at < ?";
$cleanup_msgs_stmt = $conn->prepare($cleanup_msgs_sql);
$cleanup_msgs_stmt->bind_param("s", $thirty_days_ago);
$cleanup_msgs_stmt->execute();
$deleted_messages = $cleanup_msgs_stmt->affected_rows;
$cleanup_msgs_stmt->close();

echo "\n=== Summary ===\n";
echo "Archived conversations: $archived_count\n";
echo "Errors: $errors\n";
echo "Cleaned up old conversations: $deleted_conversations\n";
echo "Cleaned up old messages: $deleted_messages\n";
echo "=== Cron job completed ===\n";
?>