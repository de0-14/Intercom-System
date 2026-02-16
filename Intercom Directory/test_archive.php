<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

echo "<h1>Archive System Debug</h1>";
echo "<p>Current time: " . date('Y-m-d H:i:s') . "</p>";

// Include all files
require_once 'conn.php';
require_once 'archive_functions.php';
require_once 'admin_archive_functions.php';

echo "<h2>1. File Inclusion Check</h2>";
echo "<pre>";
echo "conn.php included: " . (file_exists('conn.php') ? 'YES' : 'NO') . "\n";
echo "archive_functions.php included: " . (file_exists('archive_functions.php') ? 'YES' : 'NO') . "\n";
echo "admin_archive_functions.php included: " . (file_exists('admin_archive_functions.php') ? 'YES' : 'NO') . "\n";
echo "</pre>";

echo "<h2>2. Function Existence Check</h2>";
echo "<pre>";
echo "archiveAllInactiveChatsOnLoad: " . (function_exists('archiveAllInactiveChatsOnLoad') ? 'YES' : 'NO') . "\n";
echo "autoArchiveInactiveAdminChats: " . (function_exists('autoArchiveInactiveAdminChats') ? 'YES' : 'NO') . "\n";
echo "archiveConversationImmediately: " . (function_exists('archiveConversationImmediately') ? 'YES' : 'NO') . "\n";
echo "removeOldArchivedAdminChats: " . (function_exists('removeOldArchivedAdminChats') ? 'YES' : 'NO') . "\n";
echo "</pre>";

echo "<h2>3. Check Database Connection</h2>";
if ($conn) {
    echo "Database connection: <span style='color:green;'>SUCCESS</span><br>";
    
    // Check if tables exist
    $tables = ['conversations', 'conversations_archive', 'messages', 'messages_archive', 'admin_chats', 'admin_chats_archive'];
    foreach ($tables as $table) {
        $check_sql = "SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?";
        $check_params = array($table);
        $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
        
        if ($check_stmt && $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC)) {
            echo "Table '$table' exists: " . ($row['cnt'] > 0 ? 'YES' : 'NO') . "<br>";
        } else {
            echo "Table '$table': ERROR checking<br>";
        }
        sqlsrv_free_stmt($check_stmt);
    }
} else {
    echo "Database connection: <span style='color:red;'>FAILED</span><br>";
}

echo "<h2>4. Check for Inactive Conversations</h2>";
$inactive_time = date('Y-m-d H:i:s', strtotime('-30 minutes'));
echo "<p>Looking for conversations inactive since: $inactive_time</p>";

// Check regular conversations
$sql = "SELECT 
            c.conversation_id, 
            c.number_id,
            c.initiated_by,
            c.last_activity,
            DATEDIFF(MINUTE, c.last_activity, GETDATE()) as minutes_inactive,
            (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.conversation_id AND m.is_archived = 0) as message_count
        FROM conversations c
        WHERE c.is_archived = 0 
        AND c.last_activity < ?
        ORDER BY c.last_activity ASC";

$params = array($inactive_time);
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt) {
    $count = 0;
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Number ID</th><th>Initiated By</th><th>Last Activity</th><th>Minutes Inactive</th><th>Messages</th><th>Status</th></tr>";
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $count++;
        $last_activity = $row['last_activity'];
        if (is_object($last_activity)) {
            $last_activity = $last_activity->format('Y-m-d H:i:s');
        }
        
        echo "<tr>";
        echo "<td>{$row['conversation_id']}</td>";
        echo "<td>{$row['number_id']}</td>";
        echo "<td>{$row['initiated_by']}</td>";
        echo "<td>$last_activity</td>";
        echo "<td>{$row['minutes_inactive']}</td>";
        echo "<td>{$row['message_count']}</td>";
        echo "<td>";
        
        // Try to archive this one
        if (function_exists('archiveConversationImmediately')) {
            $result = archiveConversationImmediately($conn, $row['conversation_id']);
            echo $result ? "ARCHIVED SUCCESS" : "ARCHIVE FAILED";
        } else {
            echo "Function not available";
        }
        
        echo "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    if ($count == 0) {
        echo "<p style='color:orange;'>No inactive regular conversations found.</p>";
    } else {
        echo "<p>Found $count inactive regular conversation(s).</p>";
    }
    
    sqlsrv_free_stmt($stmt);
} else {
    echo "<p style='color:red;'>Error querying conversations: " . print_r(sqlsrv_errors(), true) . "</p>";
}

echo "<h2>5. Check for Inactive Admin Chats</h2>";
if (function_exists('autoArchiveInactiveAdminChats')) {
    echo "<p>Testing autoArchiveInactiveAdminChats function...</p>";
    $result = autoArchiveInactiveAdminChats($conn, 30);
    echo "<p>Result: Archived $result admin chat(s)</p>";
} else {
    echo "<p style='color:red;'>autoArchiveInactiveAdminChats function not available</p>";
}

echo "<h2>6. Manual Archive Test</h2>";
// Create a test conversation if none exist
$test_sql = "SELECT TOP 1 conversation_id FROM conversations WHERE is_archived = 0 ORDER BY last_activity ASC";
$test_stmt = sqlsrv_query($conn, $test_sql);

if ($test_stmt && $row = sqlsrv_fetch_array($test_stmt, SQLSRV_FETCH_ASSOC)) {
    $test_id = $row['conversation_id'];
    echo "<p>Found conversation #$test_id to test with</p>";
    
    // Update it to be inactive (set last_activity to 31 minutes ago)
    $old_time = date('Y-m-d H:i:s', strtotime('-31 minutes'));
    $update_sql = "UPDATE conversations SET last_activity = ? WHERE conversation_id = ?";
    $update_params = array($old_time, $test_id);
    $update_stmt = sqlsrv_query($conn, $update_sql, $update_params);
    
    if ($update_stmt) {
        echo "<p>Updated conversation #$test_id last_activity to $old_time</p>";
        
        // Now run archive function
        echo "<p>Running archive function...</p>";
        $archived = archiveAllInactiveChatsOnLoad($conn);
        echo "<p>Archive function result: $archived item(s) archived</p>";
        
        // Check if it was archived
        $check_sql = "SELECT is_archived FROM conversations WHERE conversation_id = ?";
        $check_params = array($test_id);
        $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
        
        if ($check_stmt && $check_row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC)) {
            echo "<p>Conversation #$test_id is_archived status: " . $check_row['is_archived'] . "</p>";
            if ($check_row['is_archived'] == 1) {
                echo "<p style='color:green;'>SUCCESS: Conversation was archived!</p>";
            } else {
                echo "<p style='color:red;'>FAILED: Conversation was NOT archived</p>";
            }
        }
    } else {
        echo "<p style='color:red;'>Failed to update conversation time</p>";
    }
} else {
    echo "<p style='color:orange;'>No conversations found to test with</p>";
}

echo "<h2>7. Server Time vs Database Time</h2>";
echo "<p>PHP Server Time: " . date('Y-m-d H:i:s') . "</p>";

$time_sql = "SELECT GETDATE() as db_time";
$time_stmt = sqlsrv_query($conn, $time_sql);
if ($time_stmt && $time_row = sqlsrv_fetch_array($time_stmt, SQLSRV_FETCH_ASSOC)) {
    $db_time = $time_row['db_time'];
    if (is_object($db_time)) {
        $db_time = $db_time->format('Y-m-d H:i:s');
    }
    echo "<p>Database Time: $db_time</p>";
    
    // Calculate difference
    $php_time = strtotime(date('Y-m-d H:i:s'));
    $db_timestamp = strtotime($db_time);
    $diff = abs($php_time - $db_timestamp);
    
    echo "<p>Time difference: $diff seconds</p>";
    if ($diff > 60) {
        echo "<p style='color:red;'>WARNING: Server and database times differ by more than 1 minute!</p>";
    }
}

echo "<h2>Debug Complete</h2>";
?>