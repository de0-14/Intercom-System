<?php
require_once 'conn.php';
require_once 'admin_archive_functions.php'; // Add this line
updateAllUsersActivity($conn);

if(!isAdmin()) {
    header('Location: homepage.php');
    exit();
}

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

$user_id = $_SESSION['user_id'];
$online_users = getOnlineUsers($conn);
$available_admins = getAdminsWithChatStatus($conn, $user_id);
$admin_chats = getAdminChats($conn, $user_id, true);
$selected_chat_id = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : null;
$selected_chat = null;
$chat_messages = [];
$error = '';
$success = '';

// ==========================================
// ARCHIVE SYSTEM FOR ADMIN PANEL
// ==========================================
$view_archived = isset($_GET['view']) && $_GET['view'] === 'archived';
$archived_chats = [];
$archived_chats_count = 0;
$archived_this_load = 0;
$removed_this_load = 0;

// Auto-archive inactive chats (5+ minutes)
if (!$view_archived) {
    $archived_this_load = autoArchiveInactiveAdminChats($conn, 30);
}

// Remove old archived chats (7+ days)
$seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
$removed_this_load = removeOldArchivedAdminChats($conn, $user_id, null, $seven_days_ago);

// Get archived chats count
$archived_count_stmt = $conn->prepare("
    SELECT COUNT(*) as archive_count 
    FROM admin_chats_archive 
    WHERE admin_id = ?
");
$archived_count_stmt->bind_param("i", $user_id);
$archived_count_stmt->execute();
$archive_result = $archived_count_stmt->get_result();
$archive_data = $archive_result->fetch_assoc();
$archived_chats_count = $archive_data['archive_count'] ?? 0;
$archived_count_stmt->close();

// Get archived chats if in archived view
if ($view_archived) {
    $archived_chats = getArchivedAdminChats($conn, $user_id, true);
    
    if ($selected_chat_id) {
        $archived_messages_result = getArchivedAdminMessages($conn, $selected_chat_id, $user_id);
        if ($archived_messages_result['success']) {
            $chat_messages = $archived_messages_result['messages'];
            $selected_chat = $archived_messages_result['chat_info'];
        } else {
            $selected_chat_id = null;
            $error = $archived_messages_result['error'];
        }
    }
}

// Handle POST requests for active chats
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$view_archived) {
    if(isset($_POST['send_message'])) {
        $chat_id = (int)$_POST['chat_id'];
        $message = trim($_POST['message']);
        
        if(empty($message)) {
            $error = "Please enter a message.";
        } else {
            if(sendAdminMessage($conn, $chat_id, $user_id, $message)) {
                updateAdminChatActivity($conn, $chat_id); // Update activity
                $success = "Message sent!";
                header("Location: adminpanel.php?chat_id=$chat_id");
                exit();
            } else {
                $error = "Failed to send message.";
            }
        }
    }
    
    if(isset($_POST['start_chat'])) {
        $target_user_id = (int)$_POST['user_id'];
        $chat_id = createAdminChatConversation($conn, $target_user_id, $user_id);
        if($chat_id) {
            header("Location: adminpanel.php?chat_id=$chat_id");
            exit();
        } else {
            $error = "Failed to start chat.";
        }
    }
    
    // Manual archive button
    if(isset($_POST['archive_chat'])) {
        $chat_id = (int)$_POST['chat_id'];
        if(archiveAdminChatImmediately($conn, $chat_id)) {
            $success = "Chat archived successfully!";
            header("Location: adminpanel.php");
            exit();
        } else {
            $error = "Failed to archive chat.";
        }
    }
}

// Handle GET requests
if(isset($_GET['start_chat']) && !$view_archived) {
    $target_user_id = (int)$_GET['start_chat'];
    $chat_id = createAdminChatConversation($conn, $target_user_id, $user_id);
    if($chat_id) {
        header("Location: adminpanel.php?chat_id=$chat_id");
        exit();
    } else {
        $error = "Failed to start chat.";
    }
}

// Load active chat if selected and not in archived view
if($selected_chat_id && !$view_archived) {
    $chat_messages = getAdminChatMessages($conn, $selected_chat_id);
    
    foreach($admin_chats as $chat) {
        if($chat['chat_id'] == $selected_chat_id) {
            $selected_chat = $chat;
            break;
        }
    }
    
    if($selected_chat) {
        markAdminMessagesAsRead($conn, $selected_chat_id, $user_id);
        updateAdminChatActivity($conn, $selected_chat_id); // Update activity on view
    }
}

$unread_count = getUnreadAdminMessageCount($conn, $user_id, true);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel - Chat System</title>
<style>
* {
    box-sizing: border-box;
    margin:0;
    padding:0;
    font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
}

body {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    background-color: #edf4fc;
}

.header {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    background-color: #07417f;
    color: white;
    padding: 20px 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-bottom: 3px solid #2b6cb0;
}

.header .logo {
    display: flex;
    align-items: center;
    gap: 15px;
}

.header .logo img {
    width: 55px;
    height: 55px;
    object-fit: contain;
}

.header .logo span {
    font-size: 1.5rem;
    font-weight: 700;
    color: white;
    text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}

ul.nav {
    display: flex;
    list-style: none;
    gap: 8px;
}

ul.nav li a {
    display: block;
    color: white;
    text-decoration: none;
    padding: 10px 18px;
    font-weight: 600;
    border-radius: 6px;
    transition: all 0.2s;
}

ul.nav li a:hover {
    background-color: rgba(255,255,255,0.2);
}

.content {
    flex: 1;
    margin-top: 100px;
    padding: 20px;
}

.container {
    display: flex;
    gap: 20px;
    height: calc(100vh - 140px);
}

.sidebar {
    width: 300px;
    background: white;
    border-radius: 10px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.sidebar h3 {
    color: #2b6cb0;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e2e8f0;
}

.online-users-list {
    flex: 1;
    overflow-y: auto;
    margin-bottom: 20px;
}

.user-item {
    display: flex;
    align-items: center;
    padding: 10px;
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.2s;
    border: 1px solid transparent;
}

.user-item:hover {
    background-color: #f7fafc;
    border-color: #e2e8f0;
}

.user-item.active {
    background-color: #e8f4fd;
    border-color: #2b6cb0;
}

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #2b6cb0;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-right: 12px;
}

.user-info {
    flex: 1;
}

.user-name {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 2px;
}

.user-role {
    font-size: 12px;
    color: #718096;
    background: #e2e8f0;
    padding: 2px 8px;
    border-radius: 4px;
    display: inline-block;
}

.online-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background-color: #38a169;
    border: 2px solid white;
    box-shadow: 0 0 0 1px #38a169;
}

.offline-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background-color: #a0aec0;
    border: 2px solid white;
    box-shadow: 0 0 0 1px #a0aec0;
}

.main-chat {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.chat-header {
    background: linear-gradient(135deg, #2b6cb0 0%, #1f4f8b 100%);
    color: white;
    padding: 15px 20px;
}

.chat-header h3 {
    color: white;
    margin: 0;
    font-size: 18px;
}

.chat-messages {
    flex: 1;
    padding: 20px;
    overflow-y: auto;
    background-color: #f7fafc;
}

.message {
    margin-bottom: 15px;
    max-width: 70%;
    clear: both;
}

.message.sent {
    float: right;
}

.message.received {
    float: left;
}

.message-bubble {
    padding: 12px 16px;
    border-radius: 18px;
    position: relative;
    word-wrap: break-word;
}

.message.sent .message-bubble {
    background-color: #2b6cb0;
    color: white;
    border-bottom-right-radius: 4px;
}

.message.received .message-bubble {
    background-color: white;
    color: #2d3748;
    border: 1px solid #e2e8f0;
    border-bottom-left-radius: 4px;
}

.message-sender {
    font-size: 12px;
    color: #718096;
    margin-bottom: 4px;
    padding-left: 5px;
}

.chat-input-area {
    padding: 15px;
    border-top: 1px solid #e2e8f0;
    background: white;
}

.chat-form {
    display: flex;
    gap: 10px;
}

.chat-input {
    flex: 1;
    padding: 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    resize: vertical;
    min-height: 60px;
    max-height: 120px;
}

.send-btn {
    padding: 12px 24px;
    background-color: #2b6cb0;
    color: white;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.2s;
}

.send-btn:hover {
    background-color: #1f4f8b;
}

.chat-list {
    flex: 1;
    overflow-y: auto;
}

.chat-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: all 0.2s;
    border: 1px solid transparent;
    text-decoration: none;
    color: inherit;
}

.chat-item:hover {
    background-color: #f7fafc;
    border-color: #e2e8f0;
}

.chat-item.active {
    background-color: #e8f4fd;
    border-color: #2b6cb0;
}

.chat-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #2b6cb0;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-right: 12px;
}

.chat-info {
    flex: 1;
}

.chat-name {
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 2px;
}

.chat-preview {
    font-size: 13px;
    color: #718096;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.chat-time {
    font-size: 11px;
    color: #a0aec0;
}

.unread-badge {
    background-color: #e53e3e;
    color: white;
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 10px;
    min-width: 18px;
    text-align: center;
}

.no-chat-selected {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #a0aec0;
    font-style: italic;
    text-align: center;
    padding: 40px;
}

.error-message {
    background-color: #fed7d7;
    color: #742a2a;
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 15px;
    border-left: 4px solid #e53e3e;
}

.success-message {
    background-color: #c6f6d5;
    color: #22543d;
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 15px;
    border-left: 4px solid #38a169;
}

.admin-section {
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e2e8f0;
}

.admin-section h4 {
    color: #4a5568;
    margin-bottom: 10px;
    font-size: 14px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.admin-item {
    display: flex;
    align-items: center;
    padding: 8px;
    border-radius: 6px;
    margin-bottom: 5px;
    cursor: pointer;
    transition: all 0.2s;
    border: 1px solid transparent;
}

.admin-item:hover {
    background-color: #f7fafc;
    border-color: #e2e8f0;
}

.admin-avatar {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    background: #2b6cb0;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-right: 10px;
    font-size: 14px;
}

.admin-name {
    flex: 1;
    font-weight: 500;
    color: #2d3748;
    font-size: 14px;
}

.start-chat-btn {
    background-color: #38a169;
    color: white;
    border: none;
    border-radius: 4px;
    padding: 4px 8px;
    font-size: 12px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.start-chat-btn:hover {
    background-color: #2f855a;
}

@media(max-width: 900px){
    .container { flex-direction: column; }
    .sidebar, .main-chat { width: 100%; height: auto; }
}

/* Archive badge */
.archive-badge {
    background-color: #718096;
    color: white;
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: 5px;
}

/* Archive button */
.archive-btn {
    background-color: #718096;
    color: white;
    border: none;
    border-radius: 4px;
    padding: 4px 8px;
    font-size: 12px;
    cursor: pointer;
    transition: background-color 0.2s;
    margin-left: 5px;
}

.archive-btn:hover {
    background-color: #4a5568;
}

/* Archived view styles */
.archived-chat .message-bubble {
    opacity: 0.8;
}

.archived-chat .message.sent .message-bubble {
    background-color: #4a5568;
}

.archived-chat .message.received .message-bubble {
    background-color: #e2e8f0;
    color: #2d3748;
}

/* View toggle */
.view-toggle {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid #e2e8f0;
}

.view-toggle-btn {
    padding: 8px 16px;
    background-color: #f7fafc;
    border: 2px solid #e2e8f0;
    border-radius: 6px;
    text-decoration: none;
    color: #4a5568;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}

.view-toggle-btn:hover {
    background-color: #edf2f7;
    border-color: #cbd5e0;
}

.view-toggle-btn.active {
    background-color: #2b6cb0;
    border-color: #2b6cb0;
    color: white;
}

.archive-count-badge {
    background-color: #e53e3e;
    color: white;
    font-size: 12px;
    padding: 2px 6px;
    border-radius: 10px;
    min-width: 18px;
    text-align: center;
}

/* Auto-process notice */
.auto-process-notice {
    background-color: #e8f4fd;
    border: 1px solid #b6d4fe;
    color: #084298;
    padding: 10px 15px;
    border-radius: 6px;
    font-size: 14px;
    text-align: center;
    margin-bottom: 20px;
}

/* Archived table */
.archived-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.archived-table th {
    background-color: #2b6cb0;
    color: white;
    padding: 12px 15px;
    text-align: left;
    font-weight: 600;
    font-size: 14px;
}

.archived-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 14px;
}

.archived-table tr:last-child td {
    border-bottom: none;
}

.archived-table tr:hover {
    background-color: #f7fafc;
}

.view-btn {
    display: inline-block;
    padding: 4px 10px;
    background-color: #e2e8f0;
    color: #4a5568;
    border-radius: 4px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
    transition: all 0.2s;
}

.view-btn:hover {
    background-color: #cbd5e0;
    color: #2d3748;
}

/* Archived info bar */
.archived-info-bar {
    background: #e8f4fd;
    padding: 10px 15px;
    border-bottom: 1px solid #b6d4fe;
    font-size: 13px;
    color: #084298;
    display: flex;
    justify-content: space-between;
}

/* Chat actions */
.chat-actions {
    display: flex;
    gap: 10px;
    margin-top: 10px;
}

/* No archived message */
.no-archived-message {
    text-align: center;
    padding: 40px 20px;
    color: #a0aec0;
    font-style: italic;
    background: white;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}
</style>
</head>
<body>

<div class="header">
    <div class="logo">
        <img src="hospitalLogo.png" alt="Hospital Logo">
        <span>DAVAO REGIONAL MEDICAL CENTER</span>
    </div>
    <ul class="nav">
        <li><a href="homepage.php">Homepage</a></li>
        <?php if (isLoggedIn()): ?>
            <?php if (isAdmin()): ?>
                <li><a href="createpage.php">Create page</a></li>
                <li><a href="editpage.php">Edit page</a></li>
                <li><a href="adminpanel.php">Admin Panel</a></li>
            <?php else: ?>
                <li><a href="adminchat.php">Chat with Admin <?php echo $user_unread > 0 ? "($user_unread)" : ""; ?></a></li>
            <?php endif; ?>
            <li><a href="profilepage.php">Profile</a></li>
            <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
        <?php endif; ?>
    </ul>
</div>

<div class="content">
    <div class="container">
        <div class="sidebar">
            <h3>Available Admins</h3>
            <div class="admin-section">
                <h4>Chat with Other Admins</h4>
                <?php foreach($available_admins as $admin): ?>
                <div class="admin-item" onclick="location.href='adminpanel.php?start_chat=<?php echo $admin['user_id']; ?>'">
                    <div class="admin-avatar">
                        <?php echo strtoupper(substr($admin['full_name'], 0, 1)); ?>
                    </div>
                    <div class="admin-name"><?php echo htmlspecialchars($admin['full_name']); ?></div>
                    <?php if($admin['unread_count'] > 0): ?>
                        <span class="unread-badge"><?php echo $admin['unread_count']; ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            
            <h3>Online Users (<?php echo count($online_users); ?>)</h3>
            <div class="online-users-list">
                <?php foreach($online_users as $user): 
                    if($user['user_id'] == $user_id) continue;
                ?>
                <div class="user-item" onclick="location.href='adminpanel.php?start_chat=<?php echo $user['user_id']; ?>'">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                        <span class="user-role"><?php echo htmlspecialchars($user['role_name']); ?></span>
                    </div>
                    <div class="online-indicator"></div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <h3>Your Chats</h3>
            <div class="chat-list">
                <?php if (!$view_archived): ?>
                    <?php foreach($admin_chats as $chat): ?>
                    <a href="adminpanel.php?chat_id=<?php echo $chat['chat_id']; ?>" class="chat-item <?php echo $selected_chat_id == $chat['chat_id'] ? 'active' : ''; ?>">
                        <div class="chat-avatar">
                            <?php echo strtoupper(substr($chat['full_name'], 0, 1)); ?>
                        </div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo htmlspecialchars($chat['full_name']); ?></div>
                            <div class="chat-preview"><?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages yet', 0, 30)); ?></div>
                        </div>
                        <div class="chat-time">
                            <?php if($chat['last_message_time']): ?>
                                <?php echo date('H:i', strtotime($chat['last_message_time'])); ?>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach($archived_chats as $chat): ?>
                    <a href="adminpanel.php?view=archived&chat_id=<?php echo $chat['chat_id']; ?>" class="chat-item <?php echo $selected_chat_id == $chat['chat_id'] ? 'active' : ''; ?>">
                        <div class="chat-avatar" style="background-color: #718096;">
                            <?php echo strtoupper(substr($chat['full_name'], 0, 1)); ?>
                        </div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo htmlspecialchars($chat['full_name']); ?> <span class="archive-badge">Archived</span></div>
                            <div class="chat-preview"><?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages', 0, 30)); ?></div>
                        </div>
                        <div class="chat-time">
                            <?php if($chat['archived_at']): ?>
                                <?php echo date('M d', strtotime($chat['archived_at'])); ?>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="main-chat">
            <!-- View Toggle Section -->
            <div class="view-toggle">
                <a href="adminpanel.php" class="view-toggle-btn <?php echo !$view_archived ? 'active' : ''; ?>">
                    Active Chats
                </a>
                <a href="adminpanel.php?view=archived" class="view-toggle-btn <?php echo $view_archived ? 'active' : ''; ?>">
                    Archived Chats
                    <?php if ($archived_chats_count > 0): ?>
                        <span class="archive-count-badge"><?php echo $archived_chats_count; ?></span>
                    <?php endif; ?>
                </a>
            </div>
            
            <?php if ($archived_this_load > 0 || $removed_this_load > 0): ?>
                <div class="auto-process-notice">
                    <?php if ($archived_this_load > 0): ?>
                        <span>📁 Auto-archived <?php echo $archived_this_load; ?> inactive chat(s)</span>
                    <?php endif; ?>
                    <?php if ($removed_this_load > 0): ?>
                        <span>🗑️ Cleaned up <?php echo $removed_this_load; ?> old archived chat(s)</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if($error): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if($success): ?>
                <div class="success-message"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php if($selected_chat): ?>
                <div class="chat-header <?php echo $view_archived ? 'archived-chat' : ''; ?>" style="<?php echo $view_archived ? 'background: linear-gradient(135deg, #718096 0%, #4a5568 100%);' : ''; ?>">
                    <h3>
                        <?php if ($view_archived): ?>
                            📁 Archived Chat with <?php echo htmlspecialchars($selected_chat['other_full_name']); ?>
                        <?php else: ?>
                            Chat with <?php echo htmlspecialchars($selected_chat['full_name']); ?>
                        <?php endif; ?>
                    </h3>
                    <?php if ($view_archived): ?>
                        <a href="adminpanel.php?view=archived" class="chat-back-btn">← Back to Archive</a>
                    <?php else: ?>
                        <a href="adminpanel.php" class="chat-back-btn">← Back to Chats</a>
                    <?php endif; ?>
                </div>
                
                <?php if ($view_archived): ?>
                    <div class="archived-info-bar">
                        <div>
                            <strong>Archived:</strong> <?php echo date('M d, Y H:i', strtotime($selected_chat['archived_at'])); ?>
                        </div>
                        <div>
                            <strong>Last Activity:</strong> <?php echo date('M d, Y H:i', strtotime($selected_chat['last_activity'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="chat-messages <?php echo $view_archived ? 'archived-chat' : ''; ?>" id="chat-messages">
                    <?php foreach($chat_messages as $msg): 
                        $is_sent = ($msg['sender_id'] == $user_id);
                    ?>
                    <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>">
                        <div class="message-sender"><?php echo htmlspecialchars($msg['full_name']); ?></div>
                        <div class="message-bubble">
                            <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                            <div style="font-size: 11px; opacity: 0.8; margin-top: 5px;">
                                <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if(empty($chat_messages)): ?>
                        <div style="text-align: center; color: #a0aec0; font-style: italic; padding: 40px;">
                            No messages found.
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!$view_archived): ?>
                    <div class="chat-input-area">
                        <form method="POST" class="chat-form">
                            <input type="hidden" name="chat_id" value="<?php echo $selected_chat_id; ?>">
                            <textarea name="message" class="chat-input" placeholder="Type your message here..." required></textarea>
                            <button type="submit" name="send_message" class="send-btn">Send</button>
                        </form>
                        <div class="chat-actions">
                            <form method="POST" style="margin-top: 10px;">
                                <input type="hidden" name="chat_id" value="<?php echo $selected_chat_id; ?>">
                                <button type="submit" name="archive_chat" class="archive-btn" 
                                        onclick="return confirm('Are you sure you want to archive this chat? It will be moved to archived conversations.');">
                                    📁 Archive This Chat
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="chat-input-area" style="background-color: #f1f5f9; border-top: 1px solid #cbd5e0;">
                        <div style="text-align: center; padding: 15px; color: #64748b; font-style: italic;">
                            <i class="fas fa-lock"></i> This chat is archived and cannot be modified.
                        </div>
                    </div>
                <?php endif; ?>
            <?php elseif ($view_archived && empty($selected_chat_id)): ?>
                <!-- Archived Chats Table View -->
                <?php if (!empty($archived_chats)): ?>
                    <table class="archived-table">
                        <thead>
                            <tr>
                                <th>With</th>
                                <th>Last Message</th>
                                <th>Messages</th>
                                <th>Archived</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($archived_chats as $chat): ?>
                                <tr>
                                    <td style="font-weight: 500; color: #2d3748;">
                                        <?php echo htmlspecialchars($chat['full_name']); ?>
                                    </td>
                                    <td style="color: #718096; font-size: 13px;">
                                        <?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages', 0, 50)); ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $msg_count_stmt = $conn->prepare("
                                            SELECT COUNT(*) as msg_count 
                                            FROM admin_messages_archive 
                                            WHERE chat_id = ?
                                        ");
                                        $msg_count_stmt->bind_param("i", $chat['chat_id']);
                                        $msg_count_stmt->execute();
                                        $msg_result = $msg_count_stmt->get_result();
                                        $msg_data = $msg_result->fetch_assoc();
                                        $msg_count_stmt->close();
                                        ?>
                                        <span style="background-color: #e2e8f0; color: #4a5568; padding: 2px 8px; border-radius: 12px; font-size: 12px;">
                                            <?php echo $msg_data['msg_count'] ?? 0; ?> messages
                                        </span>
                                    </td>
                                    <td style="color: #718096; font-size: 13px;">
                                        <?php echo date('M d, Y H:i', strtotime($chat['archived_at'])); ?>
                                    </td>
                                    <td>
                                        <a href="adminpanel.php?view=archived&chat_id=<?php echo $chat['chat_id']; ?>" class="view-btn">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-archived-message">
                        No archived chats found. Archived chats will appear here after being inactive for 5+ minutes.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-chat-selected">
                    <div>
                        <h3>Welcome to Admin Chat</h3>
                        <p>Select a user or admin to start chatting</p>
                        <p>You can chat with:</p>
                        <ul style="text-align: left; margin-top: 10px;">
                            <li>Other admins</li>
                            <li>Online users</li>
                            <li>Or select from existing chats</li>
                        </ul>
                        <div style="margin-top: 20px; padding: 15px; background: #f7fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <h4>Archive System</h4>
                            <p style="font-size: 14px; color: #4a5568;">
                                • Chats are auto-archived after 60 minutes of inactivity<br>
                                • Archived chats are cleaned up after 7 days<br>
                                • You can manually archive chats using the "Archive" button<br>
                                • View archived chats using the toggle above
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chat-messages');
    if(chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    const chatForm = document.querySelector('.chat-form');
    if(chatForm) {
        const textarea = chatForm.querySelector('textarea');
        textarea.addEventListener('keydown', function(e) {
            if(e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                chatForm.submit();
            }
        });
        
        if(textarea) textarea.focus();
    }
    
    // Auto-refresh for active chats only
    <?php if (!$view_archived && $selected_chat_id): ?>
    setInterval(function() {
        location.reload();
    }, 5000);
    <?php endif; ?>
});
</script>
</body>
</html>