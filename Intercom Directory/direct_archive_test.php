<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Manila');

require_once 'conn.php';

echo "<h1>Direct SQL Archive Test</h1>";

$conversation_id = 4;
echo "<h2>Testing Conversation #$conversation_id</h2>";

// Test 1: Check current state
echo "<h3>1. Current State:</h3>";
$sql1 = "SELECT conversation_id, is_archived, archived_at, last_activity FROM conversations WHERE conversation_id = ?";
$params1 = array($conversation_id);
$stmt1 = sqlsrv_query($conn, $sql1, $params1);
if ($stmt1 && $row = sqlsrv_fetch_array($stmt1, SQLSRV_FETCH_ASSOC)) {
    echo "is_archived: " . $row['is_archived'] . "<br>";
    echo "archived_at: " . (is_object($row['archived_at']) ? $row['archived_at']->format('Y-m-d H:i:s') : $row['archived_at']) . "<br>";
    echo "last_activity: " . (is_object($row['last_activity']) ? $row['last_activity']->format('Y-m-d H:i:s') : $row['last_activity']) . "<br>";
}

echo "<h3>2. Test INSERT into conversations_archive:</h3>";
$test_insert_sql = "
    INSERT INTO conversations_archive 
    (conversation_id, number_id, initiated_by, created_at, last_activity, is_archived, archived_at)
    SELECT 
        conversation_id, 
        number_id, 
        initiated_by, 
        created_at, 
        last_activity, 
        1, 
        GETDATE()
    FROM conversations 
    WHERE conversation_id = ?
";
$test_insert_params = array($conversation_id);
$test_insert_stmt = sqlsrv_query($conn, $test_insert_sql, $test_insert_params);

if ($test_insert_stmt) {
    $rows = sqlsrv_rows_affected($test_insert_stmt);
    echo "SUCCESS: Inserted $rows row(s) into conversations_archive<br>";
} else {
    $errors = sqlsrv_errors();
    echo "FAILED: " . print_r($errors, true) . "<br>";
}

echo "<h3>3. Check if conversation was inserted:</h3>";
$check_sql = "SELECT COUNT(*) as cnt FROM conversations_archive WHERE conversation_id = ?";
$check_params = array($conversation_id);
$check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
if ($check_stmt && $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC)) {
    echo "Found in archive: " . $row['cnt'] . " records<br>";
}

echo "<h3>4. Test INSERT into messages_archive:</h3>";
$test_msgs_sql = "
    INSERT INTO messages_archive 
    (message_id, sender_id, receiver_id, number_id, conversation_id, 
     message, created_at, updated_at, is_read, is_head_reply, is_archived, archived_at)
    SELECT 
        message_id, 
        sender_id, 
        receiver_id, 
        number_id, 
        conversation_id, 
        message, 
        created_at, 
        updated_at, 
        is_read, 
        is_head_reply, 
        1, 
        GETDATE()
    FROM messages 
    WHERE conversation_id = ?
";
$test_msgs_params = array($conversation_id);
$test_msgs_stmt = sqlsrv_query($conn, $test_msgs_sql, $test_msgs_params);

if ($test_msgs_stmt) {
    $rows = sqlsrv_rows_affected($test_msgs_stmt);
    echo "SUCCESS: Inserted $rows row(s) into messages_archive<br>";
} else {
    $errors = sqlsrv_errors();
    echo "FAILED: " . print_r($errors, true) . "<br>";
}

// Clean up - delete test records
echo "<h3>5. Cleanup:</h3>";
$delete_archive_sql = "DELETE FROM conversations_archive WHERE conversation_id = ?";
$delete_archive_params = array($conversation_id);
$delete_archive_stmt = sqlsrv_query($conn, $delete_archive_sql, $delete_archive_params);
echo "Cleaned up conversations_archive<br>";

$delete_msgs_sql = "DELETE FROM messages_archive WHERE conversation_id = ?";
$delete_msgs_params = array($conversation_id);
$delete_msgs_stmt = sqlsrv_query($conn, $delete_msgs_sql, $delete_msgs_params);
echo "Cleaned up messages_archive<br>";

echo "<h2>Test Complete</h2>";
?>