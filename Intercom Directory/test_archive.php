<?php
// test_conversation_12.php
require_once 'conn.php';

date_default_timezone_set('Asia/Manila');

// Set MySQL timezone to match PHP
if ($conn) {
    $conn->query("SET time_zone = '+08:00'");
    
    // Debug: Check timezone sync (remove this after testing)
    $php_time = date('Y-m-d H:i:s');
    $mysql_time_result = $conn->query("SELECT NOW() as mysql_time");
    if ($mysql_time_result) {
        $mysql_time_row = $mysql_time_result->fetch_assoc();
        $mysql_time = $mysql_time_row['mysql_time'];
        error_log("Timezone Debug - PHP: $php_time, MySQL: $mysql_time");
        
        // Also add as HTML comment for debugging
        echo "<!-- Timezone Debug - PHP: $php_time, MySQL: $mysql_time -->\n";
    }
}

echo "<pre>";
echo "=== Detailed Debug for Conversation 13 ===\n\n";

$conversation_id = 13;

// 1. Get full details of conversation 12
$sql = "SELECT * FROM conversations WHERE conversation_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $conversation_id);
$stmt->execute();
$result = $stmt->get_result();
$conv = $result->fetch_assoc();

if (!$conv) {
    die("Conversation 12 not found!\n");
}

echo "1. Conversation Details:\n";
echo "   - ID: " . $conv['conversation_id'] . "\n";
echo "   - Number ID: " . $conv['number_id'] . "\n";
echo "   - Created: " . $conv['created_at'] . "\n";
echo "   - Last Activity: " . $conv['last_activity'] . "\n";
echo "   - Is Archived: " . $conv['is_archived'] . "\n";

// Calculate inactivity
$last_activity = strtotime($conv['last_activity']);
$now = time();
$minutes_inactive = round(($now - $last_activity) / 60);

echo "   - Minutes Inactive: " . $minutes_inactive . " minutes\n";
echo "   - Should be archived (>30 min): " . ($minutes_inactive > 30 ? "YES" : "NO") . "\n";

// 2. Test the archive query directly
echo "\n2. Testing Archive Query:\n";
$thirty_minutes_ago = date('Y-m-d H:i:s', strtotime('-30 minutes'));
echo "   Thirty minutes ago: $thirty_minutes_ago\n";
echo "   Last Activity: " . $conv['last_activity'] . "\n";

// Check if it meets the criteria
$meets_criteria = false;
if ($conv['is_archived'] == 0 || $conv['is_archived'] === null) {
    echo "   ✓ Not archived\n";
    if ($conv['last_activity'] < $thirty_minutes_ago) {
        echo "   ✓ Last activity < 30 minutes ago\n";
        $meets_criteria = true;
    } else {
        echo "   ✗ Last activity NOT < 30 minutes ago\n";
        echo "   Comparison: " . $conv['last_activity'] . " < " . $thirty_minutes_ago . " = " . 
             (($conv['last_activity'] < $thirty_minutes_ago) ? "TRUE" : "FALSE") . "\n";
    }
} else {
    echo "   ✗ Already archived\n";
}

// 3. Check timezone issues
echo "\n3. Timezone Check:\n";
echo "   PHP time: " . date('Y-m-d H:i:s') . "\n";
$mysql_time = $conn->query("SELECT NOW() as mysql_time")->fetch_assoc()['mysql_time'];
echo "   MySQL time: " . $mysql_time . "\n";

// 4. Check the exact query that runs
echo "\n4. Exact Archive Query Test:\n";
$test_sql = "
    SELECT conversation_id 
    FROM conversations 
    WHERE number_id = ? 
    AND (is_archived = 0 OR is_archived IS NULL)
    AND last_activity < ?
";
$test_stmt = $conn->prepare($test_sql);
$test_stmt->bind_param("is", $conv['number_id'], $thirty_minutes_ago);
$test_stmt->execute();
$test_result = $test_stmt->get_result();

if ($test_result->num_rows > 0) {
    echo "   Query returns results:\n";
    while ($row = $test_result->fetch_assoc()) {
        echo "   - Conversation ID: " . $row['conversation_id'] . "\n";
    }
} else {
    echo "   Query returns NO results\n";
    
    // Check why
    echo "\n   Debugging why:\n";
    $debug_sql = "
        SELECT conversation_id, last_activity, is_archived,
               last_activity < ? as condition_met
        FROM conversations 
        WHERE number_id = ?
        AND conversation_id = ?
    ";
    $debug_stmt = $conn->prepare($debug_sql);
    $debug_stmt->bind_param("sii", $thirty_minutes_ago, $conv['number_id'], $conversation_id);
    $debug_stmt->execute();
    $debug_result = $debug_stmt->get_result();
    $debug_row = $debug_result->fetch_assoc();
    
    echo "   For conversation 12:\n";
    echo "   - last_activity < '$thirty_minutes_ago' = " . $debug_row['condition_met'] . "\n";
    echo "   - last_activity = " . $debug_row['last_activity'] . "\n";
    echo "   - is_archived = " . $debug_row['is_archived'] . "\n";
}

echo "</pre>";
?>