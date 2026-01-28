<?php
require_once 'conn.php';
require_once 'admin_archive_functions.php'; // Add this line
updateAllUsersActivity($conn);

if(!isLoggedIn()) {
    header('Location: login.php');
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
$is_admin = isAdmin();
$selected_chat_id = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : null;
$start_chat_with = isset($_GET['start_chat']) ? (int)$_GET['start_chat'] : null;
$selected_chat = null;
$chat_messages = [];
$error = '';
$success = '';

// ==========================================
// ARCHIVE SYSTEM FOR ADMIN CHAT
// ==========================================
$view_archived = isset($_GET['view']) && $_GET['view'] === 'archived';
$archived_chats = [];
$archived_chats_count = 0;
$archived_this_load = 0;
$removed_this_load = 0;

// Auto-archive inactive chats (5+ minutes) - only for admin users
if ($is_admin && !$view_archived) {
    $archived_this_load = autoArchiveInactiveAdminChats($conn, 30);
}

// Remove old archived chats (7+ days)
$seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
if ($is_admin) {
    $removed_this_load = removeOldArchivedAdminChats($conn, null, null, $seven_days_ago);
} else {
    $removed_this_load = removeOldArchivedAdminChats($conn, null, $user_id, $seven_days_ago);
}

// Get archived chats count
if ($is_admin) {
    $archived_count_stmt = $conn->prepare("
        SELECT COUNT(*) as archive_count 
        FROM admin_chats_archive 
        WHERE admin_id = ?
    ");
    $archived_count_stmt->bind_param("i", $user_id);
} else {
    $archived_count_stmt = $conn->prepare("
        SELECT COUNT(*) as archive_count 
        FROM admin_chats_archive 
        WHERE user_id = ?
    ");
    $archived_count_stmt->bind_param("i", $user_id);
}

$archived_count_stmt->execute();
$archive_result = $archived_count_stmt->get_result();
$archive_data = $archive_result->fetch_assoc();
$archived_chats_count = $archive_data['archive_count'] ?? 0;
$archived_count_stmt->close();

// Get archived chats if in archived view
if ($view_archived) {
    $archived_chats = getArchivedAdminChats($conn, $user_id, $is_admin);
    
    if ($selected_chat_id) {
        $archived_messages_result = getArchivedAdminMessages($conn, $selected_chat_id, $user_id);
        if ($archived_messages_result['success']) {
            $chat_messages = $archived_messages_result['messages'];
            $selected_chat = $archived_messages_result['chat_info'];
        } else {
            $selected_chat_id = null;
            $error = $archived_messages_result['error'] ?? "Failed to load archived chat";
        }
    }
}

// Handle regular chat operations (only if not in archived view)
if($start_chat_with && !$is_admin && !$view_archived) {
    $selected_chat_id = createAdminChatConversation($conn, $start_chat_with, $user_id);
    if($selected_chat_id) {
        header("Location: adminchat.php?chat_id=$selected_chat_id");
        exit();
    } else {
        $error = "Failed to create chat.";
    }
}

if($selected_chat_id && !$view_archived) {
    $chats = getActiveAdminChats($conn, $user_id, $is_admin); // Use getActiveAdminChats instead
    foreach($chats as $chat) {
        if($chat['chat_id'] == $selected_chat_id) {
            $selected_chat = $chat;
            break;
        }
    }
    
    if($selected_chat) {
        $chat_messages = getAdminChatMessages($conn, $selected_chat_id);
        markAdminMessagesAsRead($conn, $selected_chat_id, $user_id);
        
        // Update chat activity
        if (function_exists('updateAdminChatActivity')) {
            updateAdminChatActivity($conn, $selected_chat_id);
        }
    } else {
        $error = "Chat not found or has been archived.";
        $selected_chat_id = null;
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && !$view_archived) {
    if(isset($_POST['send_message'])) {
        $chat_id = (int)$_POST['chat_id'];
        $message = trim($_POST['message']);
        
        if(empty($message)) {
            $error = "Please enter a message.";
        } else { 
            if(sendAdminMessage($conn, $chat_id, $user_id, $message)) {
                // Update chat activity
                if (function_exists('updateAdminChatActivity')) {
                    updateAdminChatActivity($conn, $chat_id);
                }
                
                // Check if this is a fresh start after archive
                $check_fresh_sql = "SELECT COUNT(*) as archived_count FROM admin_chats_archive 
                                WHERE ((admin_id = ? AND user_id = ?) OR (admin_id = ? AND user_id = ?))";
                $check_stmt = $conn->prepare($check_fresh_sql);
                $check_stmt->bind_param("iiii", 
                    $is_admin ? $user_id : $selected_chat['admin_id'],
                    $is_admin ? $selected_chat['user_id'] : $user_id,
                    $is_admin ? $selected_chat['user_id'] : $user_id,
                    $is_admin ? $user_id : $selected_chat['admin_id']
                );
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $archived_data = $check_result->fetch_assoc();
                $check_stmt->close();
                
                if (($archived_data['archived_count'] ?? 0) > 0 && empty($chat_messages)) {
                    // Add a system message for fresh start
                    $system_message = "📁 New conversation started (previous chats are archived)";
                    $system_sql = "INSERT INTO admin_messages (chat_id, sender_id, message, created_at, is_read) 
                                VALUES (?, ?, ?, NOW(), 1)";
                    $system_stmt = $conn->prepare($system_sql);
                    $system_user_id = $is_admin ? $user_id : $selected_chat['admin_id'];
                    $system_stmt->bind_param("iis", $chat_id, $system_user_id, $system_message);
                    $system_stmt->execute();
                    $system_stmt->close();
                }
                
                $success = "Message sent!";
                header("Location: adminchat.php?chat_id=$chat_id");
                exit();
            } else {
                $error = "Failed to send message.";
            }
        }
    }
    
    // Manual archive button (for admins only)
    if(isset($_POST['archive_chat']) && $is_admin) {
        $chat_id = (int)$_POST['chat_id'];
        if(archiveAdminChatImmediately($conn, $chat_id)) {
            $success = "Chat archived successfully!";
            header("Location: adminchat.php");
            exit();
        } else {
            $error = "Failed to archive chat.";
        }
    }
}

$unread_count = getUnreadAdminMessageCount($conn, $user_id, $is_admin);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $is_admin ? 'Admin Chat' : 'Chat with Admin'; ?></title>
<style>
* {
    box-sizing: border-box;
    margin:0;
    padding:0;
    font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
}
.new-conversation-indicator {
    font-size: 11px;
    color: #718096;
    font-style: italic;
    margin-left: 5px;
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
    max-width: 1200px;
    margin-left: auto;
    margin-right: auto;
    width: 100%;
}

.chat-container {
    background: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    height: calc(100vh - 160px);
    display: flex;
}

.chat-sidebar {
    width: 300px;
    background: #f7fafc;
    border-right: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
}

.chat-header-bar {
    background: linear-gradient(135deg, #2b6cb0 0%, #1f4f8b 100%);
    color: white;
    padding: 20px;
}

.chat-header-bar h2 {
    color: white;
    margin: 0;
    font-size: 20px;
}

.chat-list {
    flex: 1;
    overflow-y: auto;
    padding: 15px;
}

.chat-item {
    display: flex;
    align-items: center;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 10px;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
    transition: all 0.2s;
    border: 1px solid transparent;
}

.chat-item:hover {
    background-color: white;
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
    margin-bottom: 3px;
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

.chat-main {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.chat-header {
    background: white;
    padding: 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
}

.chat-header h3 {
    color: #2b6cb0;
    margin: 0;
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

.message-time {
    font-size: 11px;
    opacity: 0.8;
    margin-top: 5px;
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

.error-message {
    background-color: #fed7d7;
    color: #742a2a;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    border-left: 4px solid #e53e3e;
}

.success-message {
    background-color: #c6f6d5;
    color: #22543d;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    border-left: 4px solid #38a169;
}

.no-chat-selected {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 1;
    color: #a0aec0;
    font-style: italic;
    text-align: center;
    padding: 40px;
}

.admin-select-list {
    margin-top: 15px;
}

.admin-select-item {
    display: flex;
    align-items: center;
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 8px;
    text-decoration: none;
    color: #2d3748;
    transition: all 0.2s;
    border: 1px solid transparent;
}

.admin-select-item:hover {
    background-color: #f7fafc;
    border-color: #e2e8f0;
}

.admin-select-avatar {
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

.admin-select-name {
    font-weight: 500;
    font-size: 14px;
}

@media(max-width: 768px){
    .chat-container {
        flex-direction: column;
    }
    .chat-sidebar {
        width: 100%;
        height: 200px;
    }
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
    margin-bottom: 15px;
    padding: 10px;
    background: #f7fafc;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
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
    margin-bottom: 15px;
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
    justify-content: flex-end;
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
    margin: 20px;
}

/* Archived chat item */
.chat-item.archived .chat-avatar {
    background-color: #718096;
}

/* Archive system info */
.archive-system-info {
    background: #f0f9ff;
    border: 1px solid #b6d4fe;
    border-radius: 8px;
    padding: 15px;
    margin: 15px;
    font-size: 13px;
    color: #084298;
}

.archive-system-info h4 {
    margin: 0 0 10px 0;
    color: #2b6cb0;
    font-size: 14px;
}

.archive-system-info ul {
    margin: 0;
    padding-left: 20px;
}

.archive-system-info li {
    margin-bottom: 5px;
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
                <li><a href="adminchat.php" class="active">Chat with Admin <?php echo $unread_count > 0 ? "($unread_count)" : ""; ?></a></li>
            <?php endif; ?>
            <li><a href="profilepage.php">Profile</a></li>
            <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
        <?php endif; ?>
    </ul>
</div>

<div class="content">
    <?php if($error): ?>
        <div class="error-message"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <?php if($success): ?>
        <div class="success-message"><?php echo $success; ?></div>
    <?php endif; ?>
    
    <div class="chat-container">
        <div class="chat-sidebar">
            <div class="chat-header-bar">
                <h2><?php echo $is_admin ? 'Admin Chats' : 'Chat with Admin'; ?></h2>
            </div>
            
            <!-- View Toggle for Archive -->
            <?php if ($is_admin || $archived_chats_count > 0): ?>
            <div class="view-toggle">
                <a href="adminchat.php" class="view-toggle-btn <?php echo !$view_archived ? 'active' : ''; ?>">
                    Active Chats
                </a>
                <a href="adminchat.php?view=archived" class="view-toggle-btn <?php echo $view_archived ? 'active' : ''; ?>">
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
            <?php endif; ?>
            
            <div class="chat-list">
                <?php if ($view_archived): ?>
                    <!-- ARCHIVED CHATS VIEW -->
                    <?php if(!empty($archived_chats)): ?>
                        <?php foreach($archived_chats as $chat): ?>
                        <a href="adminchat.php?view=archived&chat_id=<?php echo $chat['chat_id']; ?>" 
                           class="chat-item archived <?php echo $selected_chat_id == $chat['chat_id'] ? 'active' : ''; ?>">
                            <div class="chat-avatar">
                                <?php echo strtoupper(substr($chat['full_name'], 0, 1)); ?>
                            </div>
                            <div class="chat-info">
                                <div class="chat-name">
                                    <?php echo $is_admin ? htmlspecialchars($chat['full_name']) : 'Admin ' . htmlspecialchars($chat['full_name']); ?>
                                    <span class="archive-badge">Archived</span>
                                </div>
                                <div class="chat-preview"><?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages', 0, 30)); ?></div>
                            </div>
                            <div class="chat-time">
                                <?php if($chat['archived_at']): ?>
                                    <?php echo date('M d', strtotime($chat['archived_at'])); ?>
                                <?php endif; ?>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-archived-message">
                            No archived chats found.
                        </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <?php 
                    $chats = getActiveAdminChats($conn, $user_id, $is_admin); // Use the new function
                    if(!empty($chats)):
                        foreach($chats as $chat):
                    ?>
                    <a href="adminchat.php?chat_id=<?php echo $chat['chat_id']; ?>" 
                    class="chat-item <?php echo $selected_chat_id == $chat['chat_id'] ? 'active' : ''; ?>">
                        <div class="chat-avatar">
                            <?php echo strtoupper(substr($chat['full_name'], 0, 1)); ?>
                        </div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo $is_admin ? htmlspecialchars($chat['full_name']) : 'Admin ' . htmlspecialchars($chat['full_name']); ?></div>
                            <div class="chat-preview"><?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages yet', 0, 30)); ?></div>
                        </div>
                        <div class="chat-time">
                            <?php if($chat['last_message_time']): ?>
                                <?php echo date('H:i', strtotime($chat['last_message_time'])); ?>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    
                    <?php if(!$is_admin): ?>
                        <div class="admin-select-list">
                            <h4 style="color: #4a5568; font-size: 14px; margin: 15px 0 10px 0; border-top: 1px solid #e2e8f0; padding-top: 10px;">Start New Chat</h4>
                            <?php
                            $admins = getAvailableAdmins($conn);
                            foreach($admins as $admin):
                                // Check if there's an active chat with this admin
                                $has_active_chat = false;
                                foreach($chats as $chat) {
                                    if($chat['admin_id'] == $admin['user_id']) {
                                        $has_active_chat = true;
                                        break;
                                    }
                                }
                                
                                // Only show admin if there's no active chat
                                if (!$has_active_chat):
                                    // Check if there's an archived chat
                                    $has_archived = hasArchivedChatWithUser($conn, $user_id, $admin['user_id'], false);
                            ?>
                            <a href="adminchat.php?start_chat=<?php echo $admin['user_id']; ?>" class="admin-select-item">
                                <div class="admin-select-avatar">
                                    <?php echo strtoupper(substr($admin['full_name'], 0, 1)); ?>
                                </div>
                                <span class="admin-select-name">
                                    <?php echo htmlspecialchars($admin['full_name']); ?>
                                    <?php if ($has_archived): ?>
                                        <span style="font-size: 11px; color: #718096; font-style: italic;">(Start fresh)</span>
                                    <?php endif; ?>
                                </span>
                            </a>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php elseif(!$is_admin): ?>
                        <div class="admin-select-list">
                            <h4 style="color: #4a5568; font-size: 14px; margin: 15px 0 10px 0;">Select an Admin to Chat With</h4>
                            <?php
                            $admins = getAvailableAdmins($conn);
                            foreach($admins as $admin):
                            ?>
                            <a href="adminchat.php?start_chat=<?php echo $admin['user_id']; ?>" class="admin-select-item">
                                <div class="admin-select-avatar">
                                    <?php echo strtoupper(substr($admin['full_name'], 0, 1)); ?>
                                </div>
                                <span class="admin-select-name"><?php echo htmlspecialchars($admin['full_name']); ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; color: #a0aec0; padding: 20px;">
                            No active chats yet
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <!-- Archive System Info Box -->
                <?php if ($is_admin): ?>
                <div class="archive-system-info">
                    <h4>📁 Archive System</h4>
                    <ul>
                        <li>Chats auto-archive after 60 minutes of inactivity</li>
                        <li>Archived chats auto-delete after 7 days</li>
                        <li>View archived chats using the toggle above</li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="chat-main">
            <?php if($selected_chat): ?>
                <div class="chat-header <?php echo $view_archived ? 'archived-chat' : ''; ?>" style="<?php echo $view_archived ? 'background: #f1f5f9; border-color: #cbd5e0;' : ''; ?>">
                    <h3 style="<?php echo $view_archived ? 'color: #4a5568;' : ''; ?>">
                        <?php if ($view_archived): ?>
                            📁 Archived Chat with <?php echo $is_admin ? htmlspecialchars($selected_chat['other_full_name'] ?? $selected_chat['full_name']) : 'Admin ' . htmlspecialchars($selected_chat['other_full_name'] ?? $selected_chat['full_name']); ?>
                        <?php else: ?>
                            Chat with <?php echo $is_admin ? htmlspecialchars($selected_chat['full_name']) : 'Admin ' . htmlspecialchars($selected_chat['full_name']); ?>
                        <?php endif; ?>
                    </h3>
                </div>
                
                <?php if ($view_archived): ?>
                    <div class="archived-info-bar">
                        <div>
                            <strong>Archived:</strong> <?php echo date('M d, Y H:i', strtotime($selected_chat['archived_at'] ?? 'N/A')); ?>
                        </div>
                        <div>
                            <strong>Last Activity:</strong> <?php echo date('M d, Y H:i', strtotime($selected_chat['last_activity'] ?? 'N/A')); ?>
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
                            <div class="message-time">
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
                        
                        <?php if ($is_admin): ?>
                        <div class="chat-actions">
                            <form method="POST" style="margin-top: 10px;">
                                <input type="hidden" name="chat_id" value="<?php echo $selected_chat_id; ?>">
                                <button type="submit" name="archive_chat" class="archive-btn" 
                                        onclick="return confirm('Are you sure you want to archive this chat? It will be moved to archived conversations.');">
                                    📁 Archive This Chat
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="chat-input-area" style="background-color: #f1f5f9; border-top: 1px solid #cbd5e0;">
                        <div style="text-align: center; padding: 15px; color: #64748b; font-style: italic;">
                            This chat is archived and cannot be modified.
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-chat-selected">
                    <div>
                        <h3>Select a chat to start messaging</h3>
                        <p>Choose from your existing chats or start a new one</p>
                        <?php if(!$is_admin): ?>
                            <p>You can chat with any available admin</p>
                        <?php endif; ?>
                        
                        <?php if ($view_archived): ?>
                            <div style="margin-top: 20px; padding: 15px; background: #f7fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                                <h4>📁 Archived Chats</h4>
                                <p style="font-size: 14px; color: #4a5568;">
                                    Archived chats appear here. Select a chat to view its messages.
                                </p>
                            </div>
                        <?php endif; ?>
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
        
        // Save textarea content to localStorage before refresh
        if (textarea) {
            // Load saved text from localStorage
            const savedText = localStorage.getItem('chat_draft_' + <?php echo $selected_chat_id ?? 0; ?>);
            if (savedText) {
                textarea.value = savedText;
            }
            
            // Save text on input
            textarea.addEventListener('input', function() {
                localStorage.setItem('chat_draft_' + <?php echo $selected_chat_id ?? 0; ?>, this.value);
            });
            
            // Clear saved text on form submit
            chatForm.addEventListener('submit', function() {
                localStorage.removeItem('chat_draft_' + <?php echo $selected_chat_id ?? 0; ?>);
            });
            
            // Handle Enter key
            textarea.addEventListener('keydown', function(e) {
                if(e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    localStorage.removeItem('chat_draft_' + <?php echo $selected_chat_id ?? 0; ?>);
                    chatForm.submit();
                }
            });
            
            textarea.focus();
        }
    }
    
    // Auto-refresh for active chats only - but check if user is typing
    <?php if (!$view_archived && $selected_chat_id): ?>
    let lastUserActivity = Date.now();
    let isTyping = false;
    
    // Track user activity
    document.addEventListener('keydown', function() {
        lastUserActivity = Date.now();
        isTyping = true;
    });
    
    document.addEventListener('mousemove', function() {
        lastUserActivity = Date.now();
    });
    
    setInterval(function() {
        if (Date.now() - lastUserActivity > 30000 && !isTyping) {
            // Check if we should refresh (only if not in the middle of something)
            const textarea = document.querySelector('.chat-form textarea');
            if (!textarea || textarea.value.trim() === '') {
                location.reload();
            }
        }
        isTyping = false;
    }, 30000);
    <?php endif; ?>
});
</script>
</body>
</html>