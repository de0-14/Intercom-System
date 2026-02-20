<?php
require_once 'conn.php';
require_once 'admin_archive_functions.php';

// Start session to get current user
session_start();

echo "<h1>Online Users Debug</h1>";

// Test 1: Check current user session
echo "<h2>1. Current User Session</h2>";
if (isset($_SESSION['user_id'])) {
    echo "Current User ID: " . $_SESSION['user_id'] . "<br>";
    
    // Get current user details
    $sql = "SELECT user_id, username, full_name, last_activity FROM users WHERE user_id = ?";
    $stmt = sqlsrv_query($conn, $sql, array($_SESSION['user_id']));
    if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        echo "Current User: " . $row['full_name'] . " (" . $row['username'] . ")<br>";
        echo "Last Activity: " . ($row['last_activity'] instanceof DateTime ? $row['last_activity']->format('Y-m-d H:i:s') : $row['last_activity']) . "<br>";
    }
    sqlsrv_free_stmt($stmt);
} else {
    echo "No user logged in<br>";
}

echo "<hr>";

// Test 2: Check all users in database
echo "<h2>2. All Users in Database</h2>";
$sql = "SELECT user_id, username, full_name, last_activity, role_id FROM users ORDER BY last_activity DESC";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    echo "SQL Error: " . print_r(sqlsrv_errors(), true);
} else {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Last Activity</th><th>Status</th></tr>";
    
    $timeout = 300; // 5 minutes
    $onlineTime = time() - $timeout;
    $onlineDateTime = date('Y-m-d H:i:s', $onlineTime);
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $lastActivity = $row['last_activity'] instanceof DateTime ? 
                        $row['last_activity']->format('Y-m-d H:i:s') : 
                        $row['last_activity'];
        
        // Check if online
        $isOnline = ($lastActivity > $onlineDateTime) ? "✅ ONLINE" : "❌ OFFLINE";
        
        echo "<tr>";
        echo "<td>" . $row['user_id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['username']) . "</td>";
        echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
        echo "<td>" . $lastActivity . "</td>";
        echo "<td>" . $isOnline . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    sqlsrv_free_stmt($stmt);
}

echo "<hr>";

// Test 3: Check getOnlineUsers() function
echo "<h2>3. getOnlineUsers() Function Output</h2>";
$online_users = getOnlineUsers($conn);
echo "Function returned: " . count($online_users) . " users<br>";

if (!empty($online_users)) {
    echo "<ul>";
    foreach ($online_users as $user) {
        echo "<li>" . htmlspecialchars($user['full_name']) . " (Last: " . 
             ($user['last_activity'] instanceof DateTime ? $user['last_activity']->format('H:i:s') : $user['last_activity']) . ")</li>";
    }
    echo "</ul>";
} else {
    echo "No online users found by function<br>";
}

echo "<hr>";

// Test 4: Try to update current user's activity
echo "<h2>4. Update Current User Activity</h2>";
if (isset($_SESSION['user_id'])) {
    $update_sql = "UPDATE users SET last_activity = GETDATE() WHERE user_id = ?";
    $update_stmt = sqlsrv_query($conn, $update_sql, array($_SESSION['user_id']));
    
    if ($update_stmt) {
        echo "✅ User activity updated successfully<br>";
        sqlsrv_free_stmt($update_stmt);
    } else {
        echo "❌ Failed to update user activity: " . print_r(sqlsrv_errors(), true) . "<br>";
    }
    
    // Check updated time
    $check_sql = "SELECT last_activity FROM users WHERE user_id = ?";
    $check_stmt = sqlsrv_query($conn, $check_sql, array($_SESSION['user_id']));
    if ($check_stmt && $row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC)) {
        $newTime = $row['last_activity'] instanceof DateTime ? 
                   $row['last_activity']->format('Y-m-d H:i:s') : 
                   $row['last_activity'];
        echo "New last_activity: " . $newTime . "<br>";
    }
    sqlsrv_free_stmt($check_stmt);
}

echo "<hr>";

// Test 5: Check online threshold
echo "<h2>5. Online Threshold Settings</h2>";
$timeout = 300; // 5 minutes
echo "Online threshold: " . $timeout . " seconds (5 minutes)<br>";
$onlineTime = time() - $timeout;
echo "Users active after: " . date('Y-m-d H:i:s', $onlineTime) . " are considered online<br>";

echo "<hr>";

// Test 6: Look specifically for "Nursing Head"
echo "<h2>6. Search for 'Nursing Head'</h2>";
$search_sql = "SELECT user_id, username, full_name, last_activity FROM users WHERE full_name LIKE '%Nursing%' OR username LIKE '%Nursing%'";
$search_stmt = sqlsrv_query($conn, $search_sql);

if ($search_stmt === false) {
    echo "Search error: " . print_r(sqlsrv_errors(), true);
} else {
    $found = false;
    while ($row = sqlsrv_fetch_array($search_stmt, SQLSRV_FETCH_ASSOC)) {
        $found = true;
        $lastActivity = $row['last_activity'] instanceof DateTime ? 
                        $row['last_activity']->format('Y-m-d H:i:s') : 
                        $row['last_activity'];
        echo "Found: " . htmlspecialchars($row['full_name']) . " (ID: " . $row['user_id'] . ")<br>";
        echo "Last Activity: " . $lastActivity . "<br>";
        
        // Check if they should be online
        $isOnline = ($lastActivity > date('Y-m-d H:i:s', time() - 300)) ? "✅ Should be ONLINE" : "❌ Should be OFFLINE";
        echo "Status: " . $isOnline . "<br><br>";
    }
    
    if (!$found) {
        echo "No user named 'Nursing Head' found in database<br>";
    }
    sqlsrv_free_stmt($search_stmt);
}

echo "<hr>";

// Test 7: Fix suggestion
echo "<h2>7. Quick Fix Suggestion</h2>";
echo "To fix immediately, run this SQL query to update all users' last_activity:<br>";
echo "<code>UPDATE users SET last_activity = GETDATE() WHERE user_id IN (SELECT user_id FROM users)</code><br>";
echo "<br>Or click here to run it: <a href='debug_online_users.php?fix=1'>Run Quick Fix</a>";

if (isset($_GET['fix']) && $_GET['fix'] == 1 && isset($_SESSION['user_id'])) {
    $fix_sql = "UPDATE users SET last_activity = GETDATE() WHERE user_id IN (SELECT user_id FROM users)";
    $fix_stmt = sqlsrv_query($conn, $fix_sql);
    
    if ($fix_stmt) {
        echo "<br><br>✅ All users updated! <a href='debug_online_users.php'>Refresh</a>";
        sqlsrv_free_stmt($fix_stmt);
    } else {
        echo "<br><br>❌ Fix failed: " . print_r(sqlsrv_errors(), true);
    }
}
?>
<br><br>
<a href="adminpanel.php">Back to Admin Panel</a>