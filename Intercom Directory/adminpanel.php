<?php
require_once 'conn.php';
require_once 'admin_archive_functions.php';
updateAllUsersActivity($conn);

if(!isAdmin()) {
    header('Location: homepage.php');
    exit();
}

date_default_timezone_set('Asia/Manila');

if ($conn) {
    $conn->query("SET time_zone = '+08:00'");
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

$admin_notifications_count = 0;
$admin_chat_requests = [];

if($user_id) {
    $admin_notifications_count = getAdminUnreadChatsCount($conn, $user_id);
    $admin_chat_requests = getAdminChatRequests($conn, $user_id);
}

$view_archived = isset($_GET['view']) && $_GET['view'] === 'archived';
$archived_chats = [];
$archived_chats_count = 0;
$archived_this_load = 0;
$removed_this_load = 0;

if (!$view_archived) {
    $archived_this_load = autoArchiveInactiveAdminChats($conn, 30);
}

$seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
$removed_this_load = removeOldArchivedAdminChats($conn, $user_id, null, $seven_days_ago);

$archived_count_stmt = $conn->prepare("SELECT COUNT(*) as archive_count FROM admin_chats_archive WHERE admin_id = ?");
$archived_count_stmt->bind_param("i", $user_id);
$archived_count_stmt->execute();
$archive_result = $archived_count_stmt->get_result();
$archive_data = $archive_result->fetch_assoc();
$archived_chats_count = $archive_data['archive_count'] ?? 0;
$archived_count_stmt->close();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$view_archived) {
    if(isset($_POST['send_message'])) {
        $chat_id = (int)$_POST['chat_id'];
        $message = trim($_POST['message']);
        
        if(empty($message)) {
            $error = "Please enter a message.";
        } else {
            if(sendAdminMessage($conn, $chat_id, $user_id, $message)) {
                updateAdminChatActivity($conn, $chat_id);
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
        updateAdminChatActivity($conn, $selected_chat_id);
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
* { box-sizing: border-box; margin:0; padding:0; font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
body { min-height: 100vh; display: flex; flex-direction: column; background-color: #edf4fc; }
.header { position: fixed; top: 0; left: 0; width: 100%; background-color: #07417f; color: white; padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-bottom: 3px solid #2b6cb0; }
.header .logo { display: flex; align-items: center; gap: 15px; }
.header .logo img { width: 55px; height: 55px; object-fit: contain; }
.header .logo span { font-size: 1.5rem; font-weight: 700; color: white; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
ul.nav { display: flex; list-style: none; gap: 8px; }
ul.nav li a { display: block; color: white; text-decoration: none; padding: 10px 18px; font-weight: 600; border-radius: 6px; transition: all 0.2s; }
ul.nav li a:hover { background-color: rgba(255,255,255,0.2); }
.content { flex: 1; margin-top: 100px; padding: 20px; }
.container { display: flex; gap: 20px; height: calc(100vh - 140px); }
.sidebar { width: 300px; background: white; border-radius: 10px; padding: 20px; display: flex; flex-direction: column; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
.sidebar h3 { color: #2b6cb0; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; }
.online-users-list { flex: 1; overflow-y: auto; margin-bottom: 20px; }
.user-item { display: flex; align-items: center; padding: 10px; border-radius: 8px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; position: relative; }
.user-item:hover { background-color: #f7fafc; border-color: #e2e8f0; }
.user-item.active { background-color: #e8f4fd; border-color: #2b6cb0; }
.user-avatar { width: 40px; height: 40px; border-radius: 50%; background: #2b6cb0; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 12px; }
.user-info { flex: 1; }
.user-name { font-weight: 600; color: #2d3748; margin-bottom: 2px; }
.user-role { font-size: 12px; color: #718096; background: #e2e8f0; padding: 2px 8px; border-radius: 4px; display: inline-block; }
.online-indicator { width: 10px; height: 10px; border-radius: 50%; background-color: #38a169; border: 2px solid white; box-shadow: 0 0 0 1px #38a169; }
.main-chat { flex: 1; display: flex; flex-direction: column; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
.chat-header { background: linear-gradient(135deg, #2b6cb0 0%, #1f4f8b 100%); color: white; padding: 15px 20px; }
.chat-header h3 { color: white; margin: 0; font-size: 18px; }
.chat-messages { flex: 1; padding: 20px; overflow-y: auto; background-color: #f7fafc; }
.message { margin-bottom: 15px; max-width: 70%; clear: both; }
.message.sent { float: right; }
.message.received { float: left; }
.message-bubble { padding: 12px 16px; border-radius: 18px; position: relative; word-wrap: break-word; }
.message.sent .message-bubble { background-color: #2b6cb0; color: white; border-bottom-right-radius: 4px; }
.message.received .message-bubble { background-color: white; color: #2d3748; border: 1px solid #e2e8f0; border-bottom-left-radius: 4px; }
.message-sender { font-size: 12px; color: #718096; margin-bottom: 4px; padding-left: 5px; position: relative; cursor: pointer; display: inline-block; }
.chat-input-area { padding: 15px; border-top: 1px solid #e2e8f0; background: white; }
.chat-form { display: flex; gap: 10px; }
.chat-input { flex: 1; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: vertical; min-height: 60px; max-height: 120px; }
.send-btn { padding: 12px 24px; background-color: #2b6cb0; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: background-color 0.2s; }
.send-btn:hover { background-color: #1f4f8b; }
.chat-list { flex: 1; overflow-y: auto; }
.chat-item { display: flex; align-items: center; padding: 12px; border-radius: 8px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; text-decoration: none; color: inherit; position: relative; }
.chat-item:hover { background-color: #f7fafc; border-color: #e2e8f0; }
.chat-item.active { background-color: #e8f4fd; border-color: #2b6cb0; }
.chat-avatar { width: 40px; height: 40px; border-radius: 50%; background: #2b6cb0; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 12px; }
.chat-info { flex: 1; }
.chat-name { font-weight: 600; color: #2d3748; margin-bottom: 2px; }
.chat-preview { font-size: 13px; color: #718096; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chat-time { font-size: 11px; color: #a0aec0; }
.unread-badge { background-color: #e53e3e; color: white; font-size: 11px; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; }
.no-chat-selected { display: flex; align-items: center; justify-content: center; height: 100%; color: #a0aec0; font-style: italic; text-align: center; padding: 40px; }
.error-message { background-color: #fed7d7; color: #742a2a; padding: 10px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #e53e3e; }
.success-message { background-color: #c6f6d5; color: #22543d; padding: 10px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #38a169; }
.admin-section { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
.admin-section h4 { color: #4a5568; margin-bottom: 10px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }
.admin-item { display: flex; align-items: center; padding: 8px; border-radius: 6px; margin-bottom: 5px; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; position: relative; }
.admin-item:hover { background-color: #f7fafc; border-color: #e2e8f0; }
.admin-avatar { width: 35px; height: 35px; border-radius: 50%; background: #2b6cb0; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 10px; font-size: 14px; }
.admin-name { flex: 1; font-weight: 500; color: #2d3748; font-size: 14px; }
.start-chat-btn { background-color: #38a169; color: white; border: none; border-radius: 4px; padding: 4px 8px; font-size: 12px; cursor: pointer; transition: background-color 0.2s; }
.start-chat-btn:hover { background-color: #2f855a; }
@media(max-width: 900px){ .container { flex-direction: column; } .sidebar, .main-chat { width: 100%; height: auto; } }
.archive-badge { background-color: #718096; color: white; font-size: 11px; padding: 2px 6px; border-radius: 4px; margin-left: 5px; }
.archive-btn { background-color: #718096; color: white; border: none; border-radius: 4px; padding: 4px 8px; font-size: 12px; cursor: pointer; transition: background-color 0.2s; margin-left: 5px; }
.archive-btn:hover { background-color: #4a5568; }
.archived-chat .message-bubble { opacity: 0.8; }
.archived-chat .message.sent .message-bubble { background-color: #4a5568; }
.archived-chat .message.received .message-bubble { background-color: #e2e8f0; color: #2d3748; }
.view-toggle { display: flex; gap: 10px; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #e2e8f0; }
.view-toggle-btn { padding: 8px 16px; background-color: #f7fafc; border: 2px solid #e2e8f0; border-radius: 6px; text-decoration: none; color: #4a5568; font-weight: 500; display: flex; align-items: center; gap: 8px; transition: all 0.3s; }
.view-toggle-btn:hover { background-color: #edf2f7; border-color: #cbd5e0; }
.view-toggle-btn.active { background-color: #2b6cb0; border-color: #2b6cb0; color: white; }
.archive-count-badge { background-color: #e53e3e; color: white; font-size: 12px; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; }
.auto-process-notice { background-color: #e8f4fd; border: 1px solid #b6d4fe; color: #084298; padding: 10px 15px; border-radius: 6px; font-size: 14px; text-align: center; margin-bottom: 20px; }
.archived-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
.archived-table th { background-color: #2b6cb0; color: white; padding: 12px 15px; text-align: left; font-weight: 600; font-size: 14px; }
.archived-table td { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
.archived-table tr:last-child td { border-bottom: none; }
.archived-table tr:hover { background-color: #f7fafc; }
.view-btn { display: inline-block; padding: 4px 10px; background-color: #e2e8f0; color: #4a5568; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 500; transition: all 0.2s; }
.view-btn:hover { background-color: #cbd5e0; color: #2d3748; }
.archived-info-bar { background: #e8f4fd; padding: 10px 15px; border-bottom: 1px solid #b6d4fe; font-size: 13px; color: #084298; display: flex; justify-content: space-between; }
.chat-actions { display: flex; gap: 10px; margin-top: 10px; }
.no-archived-message { text-align: center; padding: 40px 20px; color: #a0aec0; font-style: italic; background: white; border-radius: 8px; border: 1px solid #e2e8f0; }
.admin-notification-container { position: relative; display: inline-block; margin-left: 10px; }
.admin-notification-btn { width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, #e53e3e, #c53030); color: white; border: 3px solid white; cursor: pointer; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 20px; box-shadow: 0 3px 10px rgba(229, 62, 62, 0.3); transition: all 0.3s; position: relative; }
.admin-notification-btn:hover { background: linear-gradient(135deg, #c53030, #9b2c2c); transform: scale(1.05); box-shadow: 0 5px 15px rgba(229, 62, 62, 0.4); }
.notification-dropdown { position: absolute; top: 100%; right: 0; width: 350px; background: white; border-radius: 8px; box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15); margin-top: 15px; padding: 0; z-index: 1000; opacity: 0; visibility: hidden; transform: translateY(-10px); transition: all 0.3s; }
.admin-notification-container:hover .notification-dropdown { opacity: 1; visibility: visible; transform: translateY(0); }
.notification-header { padding: 15px; background: #2b6cb0; color: white; border-radius: 8px 8px 0 0; }
.notification-header h4 { margin: 0; color: white; font-size: 16px; display: flex; align-items: center; gap: 8px; }
.notification-list { max-height: 400px; overflow-y: auto; padding: 10px; }
.notification-item { display: flex; align-items: center; padding: 12px; border-radius: 8px; margin-bottom: 8px; border: 1px solid #e2e8f0; transition: all 0.2s; text-decoration: none; color: inherit; position: relative; }
.notification-item:hover { background-color: #f7fafc; border-color: #cbd5e0; }
.notification-avatar { width: 40px; height: 40px; border-radius: 50%; background: #2b6cb0; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 12px; font-size: 16px; }
.notification-info { flex: 1; }
.notification-name { font-weight: 600; color: #2d3748; margin-bottom: 3px; font-size: 14px; }
.notification-meta { font-size: 12px; color: #718096; display: flex; align-items: center; gap: 8px; }
.notification-time { color: #a0aec0; }
.message-count-badge { background-color: #e53e3e; color: white; font-size: 11px; padding: 2px 6px; border-radius: 10px; min-width: 20px; text-align: center; font-weight: bold; }
.no-notifications { text-align: center; padding: 30px 20px; color: #a0aec0; font-style: italic; }
.notification-footer { padding: 12px; border-top: 1px solid #e2e8f0; text-align: center; }
.view-all-btn { display: inline-block; padding: 8px 16px; background-color: #2b6cb0; color: white; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 500; transition: background-color 0.2s; }
.view-all-btn:hover { background-color: #1f4f8b; }
.notification-bell-badge { position: absolute; top: -5px; right: -5px; background-color: #e53e3e; color: white; font-size: 12px; padding: 3px 8px; border-radius: 10px; min-width: 24px; text-align: center; font-weight: bold; border: 2px solid white; animation: pulse 1.5s infinite; }
@keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }
.notification-indicator { position: relative; }
.nav-notification-badge { background-color: #e53e3e; color: white; font-size: 11px; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; margin-left: 5px; animation: pulse 2s infinite; display: inline-block; }
.hierarchy-tooltip { position: absolute; background: #2d3748; color: white; padding: 10px; border-radius: 6px; font-size: 12px; width: 300px; z-index: 9999; box-shadow: 0 3px 10px rgba(0,0,0,0.3); opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s; top: 100%; left: 0; margin-top: 5px; border: 1px solid #4a5568; }
.chat-item:hover .hierarchy-tooltip, .user-item:hover .hierarchy-tooltip, .admin-item:hover .hierarchy-tooltip, .message-sender:hover .hierarchy-tooltip, .notification-item:hover .hierarchy-tooltip { opacity: 1; visibility: visible; }
.hierarchy-tooltip h4 { color: white; margin: 0 0 8px 0; font-size: 13px; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 5px; }
.hierarchy-info { margin: 5px 0; }
.hierarchy-row { display: flex; margin: 3px 0; align-items: flex-start; }
.hierarchy-label { width: 90px; color: #a0aec0; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; flex-shrink: 0; }
.hierarchy-value { flex: 1; color: white; font-weight: 500; font-size: 11px; line-height: 1.3; }
.hierarchy-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 5px 0; }
.chat-item, .user-item, .admin-item, .notification-item, .message-sender { position: relative; }
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
                <li><a href="adminpanel.php" class="notification-indicator">Admin Panel <?php if ($admin_notifications_count > 0): ?><span class="nav-notification-badge"><?php echo $admin_notifications_count; ?></span><?php endif; ?></a></li>
            <?php else: ?>
                <li><a href="adminchat.php">Chat with Admin</a></li>
            <?php endif; ?>
            <li><a href="profilepage.php">Profile</a></li>
            <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
        <?php endif; ?>
    </ul>
    
    <?php if($user_id): ?>
    <div class="admin-notification-container">
        <button class="admin-notification-btn" id="adminNotificationBtn">🔔<?php if($admin_notifications_count > 0): ?><span class="notification-bell-badge"><?php echo $admin_notifications_count; ?></span><?php endif; ?></button>
        <div class="notification-dropdown" id="notificationDropdown">
            <div class="notification-header"><h4>📨 New Chat Requests (<?php echo $admin_notifications_count; ?>)</h4></div>
            <div class="notification-list">
                <?php if(!empty($admin_chat_requests)): ?>
                    <?php foreach($admin_chat_requests as $request): 
                        $chat_id = getAdminChatWithUser($conn, $user_id, $request['user_id']);
                        $chat_link = $chat_id ? "adminpanel.php?chat_id=$chat_id" : "adminpanel.php?start_chat=" . $request['user_id'];
                        $user_hierarchy = getUserHierarchyInfo($conn, $request['user_id']);
                    ?>
                    <a href="<?php echo $chat_link; ?>" class="notification-item">
                        <div class="notification-avatar"><?php echo strtoupper(substr($request['full_name'], 0, 1)); ?></div>
                        <div class="notification-info">
                            <div class="notification-name"><?php echo htmlspecialchars($request['full_name']); ?></div>
                            <div class="notification-meta">
                                <span class="notification-time"><?php echo time_ago($request['first_message_time']); ?></span>
                                <?php if($request['message_count'] > 1): ?><span class="message-count-badge"><?php echo $request['message_count']; ?> messages</span>
                                <?php else: ?><span class="message-count-badge">New message</span><?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="hierarchy-tooltip">
                            <h4><?php echo htmlspecialchars($user_hierarchy['full_name']); ?></h4>
                            <div class="hierarchy-info">
                                <div class="hierarchy-row"><div class="hierarchy-label">Email:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['email'] ?? 'N/A'); ?></div></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Role:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?><?php if ($user_hierarchy['is_head']): ?><span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span><?php endif; ?></div></div>
                                <?php if ($user_hierarchy['current_unit']): ?>
                                <div class="hierarchy-row"><div class="hierarchy-label">Current Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['current_unit']); ?><?php if ($user_hierarchy['unit_type']): ?><span style="color: #a0aec0; font-size: 10px;">(<?php echo $user_hierarchy['unit_type']; ?>)</span><?php endif; ?></div></div>
                                <?php endif; ?>
                                <?php if ($user_hierarchy['is_head'] && !empty($user_hierarchy['heads_contacts'])): ?>
                                <div class="hierarchy-divider"></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Heads:</div><div class="hierarchy-value"><?php 
                                $head_units = [];
                                foreach($user_hierarchy['heads_contacts'] as $contact) {
                                    $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                    $head_units[] = $unit_name . ' (' . $contact['unit_type'] . ')';
                                }
                                echo htmlspecialchars(implode(', ', array_slice($head_units, 0, 3)));
                                if (count($head_units) > 3) { echo ' +' . (count($head_units) - 3) . ' more'; } ?></div></div>
                                <?php endif; ?>
                                <?php if ($user_hierarchy['division']): ?><div class="hierarchy-row"><div class="hierarchy-label">Division:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['division']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['department']): ?><div class="hierarchy-row"><div class="hierarchy-label">Department:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['department']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['unit']): ?><div class="hierarchy-row"><div class="hierarchy-label">Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['unit']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['office']): ?><div class="hierarchy-row"><div class="hierarchy-label">Office:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['office']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['head_info'] && isset($user_hierarchy['head_info']['head_name']) && !$user_hierarchy['is_head']): ?>
                                <div class="hierarchy-divider"></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Head:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['head_info']['head_name']); ?></div></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-notifications">No new chat requests</div>
                <?php endif; ?>
            </div>
            <div class="notification-footer"><a href="adminpanel.php" class="view-all-btn">View All Chats</a></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="content">
    <div class="container">
        <div class="sidebar">
            <h3>Available Admins</h3>
            <div class="admin-section">
                <h4>Chat with Other Admins</h4>
                <?php foreach($available_admins as $admin): 
                    $admin_hierarchy = getUserHierarchyInfo($conn, $admin['user_id']);
                ?>
                <div class="admin-item" onclick="location.href='adminpanel.php?start_chat=<?php echo $admin['user_id']; ?>'">
                    <div class="admin-avatar"><?php echo strtoupper(substr($admin['full_name'], 0, 1)); ?></div>
                    <div class="admin-name"><?php echo htmlspecialchars($admin['full_name']); ?></div>
                    <?php if($admin['unread_count'] > 0): ?><span class="unread-badge"><?php echo $admin['unread_count']; ?></span><?php endif; ?>
                    
                    <div class="hierarchy-tooltip">
                        <h4><?php echo htmlspecialchars($admin_hierarchy['full_name']); ?></h4>
                        <div class="hierarchy-info">
                            <div class="hierarchy-row"><div class="hierarchy-label">Email:</div><div class="hierarchy-value"><?php echo htmlspecialchars($admin_hierarchy['email'] ?? 'N/A'); ?></div></div>
                            <div class="hierarchy-row"><div class="hierarchy-label">Role:</div><div class="hierarchy-value"><?php echo htmlspecialchars($admin_hierarchy['role_name'] ?? 'Admin'); ?><?php if ($admin_hierarchy['is_head']): ?><span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span><?php endif; ?></div></div>
                            <?php if ($admin_hierarchy['current_unit']): ?>
                            <div class="hierarchy-row"><div class="hierarchy-label">Current Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($admin_hierarchy['current_unit']); ?><?php if ($admin_hierarchy['unit_type']): ?><span style="color: #a0aec0; font-size: 10px;">(<?php echo $admin_hierarchy['unit_type']; ?>)</span><?php endif; ?></div></div>
                            <?php endif; ?>
                            <?php if ($admin_hierarchy['is_head'] && !empty($admin_hierarchy['heads_contacts'])): ?>
                            <div class="hierarchy-divider"></div>
                            <div class="hierarchy-row"><div class="hierarchy-label">Heads:</div><div class="hierarchy-value"><?php 
                            $head_units = [];
                            foreach($admin_hierarchy['heads_contacts'] as $contact) {
                                $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                $head_units[] = $unit_name . ' (' . $contact['unit_type'] . ')';
                            }
                            echo htmlspecialchars(implode(', ', array_slice($head_units, 0, 3)));
                            if (count($head_units) > 3) { echo ' +' . (count($head_units) - 3) . ' more'; } ?></div></div>
                            <?php endif; ?>
                            <?php if ($admin_hierarchy['division']): ?><div class="hierarchy-row"><div class="hierarchy-label">Division:</div><div class="hierarchy-value"><?php echo htmlspecialchars($admin_hierarchy['division']); ?></div></div><?php endif; ?>
                            <?php if ($admin_hierarchy['department']): ?><div class="hierarchy-row"><div class="hierarchy-label">Department:</div><div class="hierarchy-value"><?php echo htmlspecialchars($admin_hierarchy['department']); ?></div></div><?php endif; ?>
                            <?php if ($admin_hierarchy['unit']): ?><div class="hierarchy-row"><div class="hierarchy-label">Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($admin_hierarchy['unit']); ?></div></div><?php endif; ?>
                            <?php if ($admin_hierarchy['office']): ?><div class="hierarchy-row"><div class="hierarchy-label">Office:</div><div class="hierarchy-value"><?php echo htmlspecialchars($admin_hierarchy['office']); ?></div></div><?php endif; ?>
                            <?php if ($admin_hierarchy['head_info'] && isset($admin_hierarchy['head_info']['head_name']) && !$admin_hierarchy['is_head']): ?>
                            <div class="hierarchy-divider"></div>
                            <div class="hierarchy-row"><div class="hierarchy-label">Head:</div><div class="hierarchy-value"><?php echo htmlspecialchars($admin_hierarchy['head_info']['head_name']); ?></div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <h3>Online Users (<?php echo count($online_users); ?>)</h3>
            <div class="online-users-list">
                <?php foreach($online_users as $user): 
                    if($user['user_id'] == $user_id) continue;
                    $user_hierarchy = getUserHierarchyInfo($conn, $user['user_id']);
                ?>
                <div class="user-item" onclick="location.href='adminpanel.php?start_chat=<?php echo $user['user_id']; ?>'">
                    <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                        <span class="user-role"><?php echo htmlspecialchars($user['role_name']); ?></span>
                    </div>
                    <div class="online-indicator"></div>
                    
                    <div class="hierarchy-tooltip">
                        <h4><?php echo htmlspecialchars($user_hierarchy['full_name']); ?></h4>
                        <div class="hierarchy-info">
                            <div class="hierarchy-row"><div class="hierarchy-label">Email:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['email'] ?? 'N/A'); ?></div></div>
                            <div class="hierarchy-row"><div class="hierarchy-label">Role:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?><?php if ($user_hierarchy['is_head']): ?><span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span><?php endif; ?></div></div>
                            <?php if ($user_hierarchy['current_unit']): ?>
                            <div class="hierarchy-row"><div class="hierarchy-label">Current Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['current_unit']); ?><?php if ($user_hierarchy['unit_type']): ?><span style="color: #a0aec0; font-size: 10px;">(<?php echo $user_hierarchy['unit_type']; ?>)</span><?php endif; ?></div></div>
                            <?php endif; ?>
                            <?php if ($user_hierarchy['is_head'] && !empty($user_hierarchy['heads_contacts'])): ?>
                            <div class="hierarchy-divider"></div>
                            <div class="hierarchy-row"><div class="hierarchy-label">Heads:</div><div class="hierarchy-value"><?php 
                            $head_units = [];
                            foreach($user_hierarchy['heads_contacts'] as $contact) {
                                $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                $head_units[] = $unit_name . ' (' . $contact['unit_type'] . ')';
                            }
                            echo htmlspecialchars(implode(', ', array_slice($head_units, 0, 3)));
                            if (count($head_units) > 3) { echo ' +' . (count($head_units) - 3) . ' more'; } ?></div></div>
                            <?php endif; ?>
                            <?php if ($user_hierarchy['division']): ?><div class="hierarchy-row"><div class="hierarchy-label">Division:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['division']); ?></div></div><?php endif; ?>
                            <?php if ($user_hierarchy['department']): ?><div class="hierarchy-row"><div class="hierarchy-label">Department:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['department']); ?></div></div><?php endif; ?>
                            <?php if ($user_hierarchy['unit']): ?><div class="hierarchy-row"><div class="hierarchy-label">Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['unit']); ?></div></div><?php endif; ?>
                            <?php if ($user_hierarchy['office']): ?><div class="hierarchy-row"><div class="hierarchy-label">Office:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['office']); ?></div></div><?php endif; ?>
                            <?php if ($user_hierarchy['head_info'] && isset($user_hierarchy['head_info']['head_name']) && !$user_hierarchy['is_head']): ?>
                            <div class="hierarchy-divider"></div>
                            <div class="hierarchy-row"><div class="hierarchy-label">Head:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['head_info']['head_name']); ?></div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <h3>Your Chats</h3>
            <div class="chat-list">
                <?php if (!$view_archived): ?>
                    <?php foreach($admin_chats as $chat): 
                        $other_user_id = $chat['user_id'];
                        $user_hierarchy = getUserHierarchyInfo($conn, $other_user_id);
                    ?>
                    <a href="adminpanel.php?chat_id=<?php echo $chat['chat_id']; ?>" class="chat-item <?php echo $selected_chat_id == $chat['chat_id'] ? 'active' : ''; ?>">
                        <div class="chat-avatar"><?php echo strtoupper(substr($chat['full_name'], 0, 1)); ?></div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo htmlspecialchars($chat['full_name']); ?></div>
                            <div class="chat-preview"><?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages yet', 0, 30)); ?></div>
                        </div>
                        <div class="chat-time"><?php if($chat['last_message_time']): ?><?php echo date('H:i', strtotime($chat['last_message_time'])); ?><?php endif; ?></div>
                        
                        <div class="hierarchy-tooltip">
                            <h4><?php echo htmlspecialchars($user_hierarchy['full_name']); ?></h4>
                            <div class="hierarchy-info">
                                <div class="hierarchy-row"><div class="hierarchy-label">Email:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['email'] ?? 'N/A'); ?></div></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Role:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?><?php if ($user_hierarchy['is_head']): ?><span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span><?php endif; ?></div></div>
                                <?php if ($user_hierarchy['current_unit']): ?>
                                <div class="hierarchy-row"><div class="hierarchy-label">Current Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['current_unit']); ?><?php if ($user_hierarchy['unit_type']): ?><span style="color: #a0aec0; font-size: 10px;">(<?php echo $user_hierarchy['unit_type']; ?>)</span><?php endif; ?></div></div>
                                <?php endif; ?>
                                <?php if ($user_hierarchy['is_head'] && !empty($user_hierarchy['heads_contacts'])): ?>
                                <div class="hierarchy-divider"></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Heads:</div><div class="hierarchy-value"><?php 
                                $head_units = [];
                                foreach($user_hierarchy['heads_contacts'] as $contact) {
                                    $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                    $head_units[] = $unit_name . ' (' . $contact['unit_type'] . ')';
                                }
                                echo htmlspecialchars(implode(', ', array_slice($head_units, 0, 3)));
                                if (count($head_units) > 3) { echo ' +' . (count($head_units) - 3) . ' more'; } ?></div></div>
                                <?php endif; ?>
                                <?php if ($user_hierarchy['division']): ?><div class="hierarchy-row"><div class="hierarchy-label">Division:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['division']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['department']): ?><div class="hierarchy-row"><div class="hierarchy-label">Department:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['department']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['unit']): ?><div class="hierarchy-row"><div class="hierarchy-label">Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['unit']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['office']): ?><div class="hierarchy-row"><div class="hierarchy-label">Office:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['office']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['head_info'] && isset($user_hierarchy['head_info']['head_name']) && !$user_hierarchy['is_head']): ?>
                                <div class="hierarchy-divider"></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Head:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['head_info']['head_name']); ?></div></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach($archived_chats as $chat): 
                        $other_user_id = $chat['user_id'];
                        $user_hierarchy = getUserHierarchyInfo($conn, $other_user_id);
                    ?>
                    <a href="adminpanel.php?view=archived&chat_id=<?php echo $chat['chat_id']; ?>" class="chat-item <?php echo $selected_chat_id == $chat['chat_id'] ? 'active' : ''; ?>">
                        <div class="chat-avatar" style="background-color: #718096;"><?php echo strtoupper(substr($chat['full_name'], 0, 1)); ?></div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo htmlspecialchars($chat['full_name']); ?> <span class="archive-badge">Archived</span></div>
                            <div class="chat-preview"><?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages', 0, 30)); ?></div>
                        </div>
                        <div class="chat-time"><?php if($chat['archived_at']): ?><?php echo date('M d', strtotime($chat['archived_at'])); ?><?php endif; ?></div>
                        
                        <div class="hierarchy-tooltip">
                            <h4><?php echo htmlspecialchars($user_hierarchy['full_name']); ?></h4>
                            <div class="hierarchy-info">
                                <div class="hierarchy-row"><div class="hierarchy-label">Email:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['email'] ?? 'N/A'); ?></div></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Role:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?><?php if ($user_hierarchy['is_head']): ?><span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span><?php endif; ?></div></div>
                                <?php if ($user_hierarchy['current_unit']): ?>
                                <div class="hierarchy-row"><div class="hierarchy-label">Current Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['current_unit']); ?><?php if ($user_hierarchy['unit_type']): ?><span style="color: #a0aec0; font-size: 10px;">(<?php echo $user_hierarchy['unit_type']; ?>)</span><?php endif; ?></div></div>
                                <?php endif; ?>
                                <?php if ($user_hierarchy['is_head'] && !empty($user_hierarchy['heads_contacts'])): ?>
                                <div class="hierarchy-divider"></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Heads:</div><div class="hierarchy-value"><?php 
                                $head_units = [];
                                foreach($user_hierarchy['heads_contacts'] as $contact) {
                                    $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                    $head_units[] = $unit_name . ' (' . $contact['unit_type'] . ')';
                                }
                                echo htmlspecialchars(implode(', ', array_slice($head_units, 0, 3)));
                                if (count($head_units) > 3) { echo ' +' . (count($head_units) - 3) . ' more'; } ?></div></div>
                                <?php endif; ?>
                                <?php if ($user_hierarchy['division']): ?><div class="hierarchy-row"><div class="hierarchy-label">Division:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['division']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['department']): ?><div class="hierarchy-row"><div class="hierarchy-label">Department:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['department']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['unit']): ?><div class="hierarchy-row"><div class="hierarchy-label">Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['unit']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['office']): ?><div class="hierarchy-row"><div class="hierarchy-label">Office:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['office']); ?></div></div><?php endif; ?>
                                <?php if ($user_hierarchy['head_info'] && isset($user_hierarchy['head_info']['head_name']) && !$user_hierarchy['is_head']): ?>
                                <div class="hierarchy-divider"></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Head:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['head_info']['head_name']); ?></div></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="main-chat">
            <div class="view-toggle">
                <a href="adminpanel.php" class="view-toggle-btn <?php echo !$view_archived ? 'active' : ''; ?>">Active Chats</a>
                <a href="adminpanel.php?view=archived" class="view-toggle-btn <?php echo $view_archived ? 'active' : ''; ?>">Archived Chats<?php if ($archived_chats_count > 0): ?><span class="archive-count-badge"><?php echo $archived_chats_count; ?></span><?php endif; ?></a>
            </div>
            
            <?php if ($archived_this_load > 0 || $removed_this_load > 0): ?>
                <div class="auto-process-notice">
                    <?php if ($archived_this_load > 0): ?><span>📁 Auto-archived <?php echo $archived_this_load; ?> inactive chat(s)</span><?php endif; ?>
                    <?php if ($removed_this_load > 0): ?><span>🗑️ Cleaned up <?php echo $removed_this_load; ?> old archived chat(s)</span><?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if($error): ?><div class="error-message"><?php echo $error; ?></div><?php endif; ?>
            <?php if($success): ?><div class="success-message"><?php echo $success; ?></div><?php endif; ?>
            
            <?php if($selected_chat): ?>
                <div class="chat-header <?php echo $view_archived ? 'archived-chat' : ''; ?>" style="<?php echo $view_archived ? 'background: linear-gradient(135deg, #718096 0%, #4a5568 100%);' : ''; ?>">
                    <h3><?php if ($view_archived): ?>📁 Archived Chat with <?php echo htmlspecialchars($selected_chat['other_full_name']); ?><?php else: ?>Chat with <?php echo htmlspecialchars($selected_chat['full_name']); ?><?php endif; ?></h3>
                </div>
                
                <?php if ($view_archived): ?>
                    <div class="archived-info-bar">
                        <div><strong>Archived:</strong> <?php echo date('M d, Y H:i', strtotime($selected_chat['archived_at'])); ?></div>
                        <div><strong>Last Activity:</strong> <?php echo date('M d, Y H:i', strtotime($selected_chat['last_activity'])); ?></div>
                    </div>
                <?php endif; ?>
                
                <div class="chat-messages <?php echo $view_archived ? 'archived-chat' : ''; ?>" id="chat-messages">
                    <?php foreach($chat_messages as $msg): 
                        $is_sent = ($msg['sender_id'] == $user_id);
                        $sender_hierarchy = getUserHierarchyInfo($conn, $msg['sender_id']);
                    ?>
                    <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>">
                        <div class="message-sender"><?php echo htmlspecialchars($msg['full_name']); ?>
                            <div class="hierarchy-tooltip">
                                <h4><?php echo htmlspecialchars($sender_hierarchy['full_name']); ?></h4>
                                <div class="hierarchy-info">
                                    <div class="hierarchy-row"><div class="hierarchy-label">Email:</div><div class="hierarchy-value"><?php echo htmlspecialchars($sender_hierarchy['email'] ?? 'N/A'); ?></div></div>
                                    <div class="hierarchy-row"><div class="hierarchy-label">Role:</div><div class="hierarchy-value"><?php echo htmlspecialchars($sender_hierarchy['role_name'] ?? 'N/A'); ?><?php if ($sender_hierarchy['is_head']): ?><span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span><?php endif; ?></div></div>
                                    <?php if ($sender_hierarchy['current_unit']): ?>
                                    <div class="hierarchy-row"><div class="hierarchy-label">Current Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($sender_hierarchy['current_unit']); ?><?php if ($sender_hierarchy['unit_type']): ?><span style="color: #a0aec0; font-size: 10px;">(<?php echo $sender_hierarchy['unit_type']; ?>)</span><?php endif; ?></div></div>
                                    <?php endif; ?>
                                    <?php if ($sender_hierarchy['is_head'] && !empty($sender_hierarchy['heads_contacts'])): ?>
                                    <div class="hierarchy-divider"></div>
                                    <div class="hierarchy-row"><div class="hierarchy-label">Heads:</div><div class="hierarchy-value"><?php 
                                    $head_units = [];
                                    foreach($sender_hierarchy['heads_contacts'] as $contact) {
                                        $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                        $head_units[] = $unit_name . ' (' . $contact['unit_type'] . ')';
                                    }
                                    echo htmlspecialchars(implode(', ', array_slice($head_units, 0, 3)));
                                    if (count($head_units) > 3) { echo ' +' . (count($head_units) - 3) . ' more'; } ?></div></div>
                                    <?php endif; ?>
                                    <?php if ($sender_hierarchy['division']): ?><div class="hierarchy-row"><div class="hierarchy-label">Division:</div><div class="hierarchy-value"><?php echo htmlspecialchars($sender_hierarchy['division']); ?></div></div><?php endif; ?>
                                    <?php if ($sender_hierarchy['department']): ?><div class="hierarchy-row"><div class="hierarchy-label">Department:</div><div class="hierarchy-value"><?php echo htmlspecialchars($sender_hierarchy['department']); ?></div></div><?php endif; ?>
                                    <?php if ($sender_hierarchy['unit']): ?><div class="hierarchy-row"><div class="hierarchy-label">Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($sender_hierarchy['unit']); ?></div></div><?php endif; ?>
                                    <?php if ($sender_hierarchy['office']): ?><div class="hierarchy-row"><div class="hierarchy-label">Office:</div><div class="hierarchy-value"><?php echo htmlspecialchars($sender_hierarchy['office']); ?></div></div><?php endif; ?>
                                    <?php if ($sender_hierarchy['head_info'] && isset($sender_hierarchy['head_info']['head_name']) && !$sender_hierarchy['is_head']): ?>
                                    <div class="hierarchy-divider"></div>
                                    <div class="hierarchy-row"><div class="hierarchy-label">Head:</div><div class="hierarchy-value"><?php echo htmlspecialchars($sender_hierarchy['head_info']['head_name']); ?></div></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="message-bubble">
                            <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                            <div style="font-size: 11px; opacity: 0.8; margin-top: 5px;"><?php echo date('H:i', strtotime($msg['created_at'])); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if(empty($chat_messages)): ?>
                        <div style="text-align: center; color: #a0aec0; font-style: italic; padding: 40px;">No messages found.</div>
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
                                <button type="submit" name="archive_chat" class="archive-btn" onclick="return confirm('Are you sure you want to archive this chat? It will be moved to archived conversations.');">📁 Archive This Chat</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="chat-input-area" style="background-color: #f1f5f9; border-top: 1px solid #cbd5e0;">
                        <div style="text-align: center; padding: 15px; color: #64748b; font-style: italic;"><i class="fas fa-lock"></i> This chat is archived and cannot be modified.</div>
                    </div>
                <?php endif; ?>
            <?php elseif ($view_archived && empty($selected_chat_id)): ?>
                <?php if (!empty($archived_chats)): ?>
                    <table class="archived-table">
                        <thead><tr><th>With</th><th>Last Message</th><th>Messages</th><th>Archived</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach($archived_chats as $chat): 
                                $other_user_id = $chat['user_id'];
                                $user_hierarchy = getUserHierarchyInfo($conn, $other_user_id);
                            ?>
                                <tr>
                                    <td style="font-weight: 500; color: #2d3748;"><?php echo htmlspecialchars($chat['full_name']); ?><br><small style="color: #718096; font-size: 11px;"><?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?><?php if ($user_hierarchy['current_unit'] ?? ''): ?> • <?php echo htmlspecialchars($user_hierarchy['current_unit']); ?><?php endif; ?></small></td>
                                    <td style="color: #718096; font-size: 13px;"><?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages', 0, 50)); ?></td>
                                    <td><span style="background-color: #e2e8f0; color: #4a5568; padding: 2px 8px; border-radius: 12px; font-size: 12px;"><?php 
                                        $msg_count_stmt = $conn->prepare("SELECT COUNT(*) as msg_count FROM admin_messages_archive WHERE chat_id = ?");
                                        $msg_count_stmt->bind_param("i", $chat['chat_id']);
                                        $msg_count_stmt->execute();
                                        $msg_result = $msg_count_stmt->get_result();
                                        $msg_data = $msg_result->fetch_assoc();
                                        $msg_count_stmt->close();
                                        echo $msg_data['msg_count'] ?? 0; ?> messages</span></td>
                                    <td style="color: #718096; font-size: 13px;"><?php echo date('M d, Y H:i', strtotime($chat['archived_at'])); ?></td>
                                    <td><a href="adminpanel.php?view=archived&chat_id=<?php echo $chat['chat_id']; ?>" class="view-btn">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-archived-message">No archived chats found. Archived chats will appear here after being inactive for 5+ minutes.</div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-chat-selected">
                    <div>
                        <h3>Welcome to Admin Chat</h3>
                        <p>Select a user or admin to start chatting</p>
                        <p>You can chat with:</p>
                        <ul style="text-align: left; margin-top: 10px;"><li>Other admins</li><li>Online users</li><li>Or select from existing chats</li></ul>
                        <div style="margin-top: 20px; padding: 15px; background: #f7fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                            <h4>Archive System</h4>
                            <p style="font-size: 14px; color: #4a5568;">• Chats are auto-archived after 60 minutes of inactivity<br>• Archived chats are cleaned up after 7 days<br>• You can manually archive chats using the "Archive" button<br>• View archived chats using the toggle above</p>
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
    if(chatMessages) chatMessages.scrollTop = chatMessages.scrollHeight;
    
    const chatForm = document.querySelector('.chat-form');
    if(chatForm) {
        const textarea = chatForm.querySelector('textarea');
        const sendButton = chatForm.querySelector('button[type="submit"]');
        
        if(textarea && sendButton) {
            textarea.addEventListener('keydown', function(e) {
                if ((e.key === 'Enter' || e.which === 13 || e.keyCode === 13) && !e.shiftKey) {
                    e.preventDefault();
                    if (this.value.trim() !== '') {
                        const originalBg = sendButton.style.backgroundColor;
                        this.style.borderColor = '#38a169';
                        sendButton.style.backgroundColor = '#38a169';
                        setTimeout(() => sendButton.click(), 10);
                        setTimeout(() => {
                            this.style.borderColor = '';
                            sendButton.style.backgroundColor = originalBg;
                        }, 200);
                    }
                }
            });
            setTimeout(() => textarea.focus(), 300);
        }
    }
    
    const notificationBtn = document.getElementById('adminNotificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    if(notificationBtn && notificationDropdown) {
        notificationBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if(notificationDropdown.style.opacity === '1') {
                notificationDropdown.style.opacity = '0';
                notificationDropdown.style.visibility = 'hidden';
                notificationDropdown.style.transform = 'translateY(-10px)';
            } else {
                notificationDropdown.style.opacity = '1';
                notificationDropdown.style.visibility = 'visible';
                notificationDropdown.style.transform = 'translateY(0)';
            }
        });
        
        document.addEventListener('click', function(e) {
            if(notificationBtn && notificationDropdown) {
                if(!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                    notificationDropdown.style.opacity = '0';
                    notificationDropdown.style.visibility = 'hidden';
                    notificationDropdown.style.transform = 'translateY(-10px)';
                }
            }
        });
    }
    
    <?php if (!$view_archived && $selected_chat_id): ?>
    setInterval(() => { location.reload(); }, 60000);
    <?php endif; ?>
    
    function checkAdminNotifications() {
        fetch('check_admin_notifications.php')
            .then(response => response.json())
            .then(data => {
                const badge = document.querySelector('.notification-bell-badge');
                const headerBadge = document.querySelector('.nav-notification-badge');
                const headerCount = document.querySelector('.notification-header h4');
                
                if(data.count > 0) {
                    if(badge) {
                        badge.textContent = data.count;
                        badge.style.display = 'inline-block';
                    }
                    if(headerBadge) {
                        headerBadge.textContent = data.count;
                        headerBadge.style.display = 'inline-block';
                    }
                    if(headerCount) {
                        headerCount.textContent = `📨 New Chat Requests (${data.count})`;
                    }
                    
                    if(data.count > parseInt(badge?.textContent || 0)) {
                        showNotificationToast(data.latest?.user_name || 'New chat request');
                    }
                } else {
                    if(badge) badge.style.display = 'none';
                    if(headerBadge) headerBadge.style.display = 'none';
                    if(headerCount) headerCount.textContent = '📨 New Chat Requests (0)';
                }
            })
            .catch(error => console.error('Error checking notifications:', error));
    }
    
    function showNotificationToast(userName) {
        if(Notification.permission === "granted") {
            new Notification("New Chat Request", {
                body: `${userName} wants to chat with you`,
                icon: "hospitalLogo.png"
            });
        }
    }
    
    if (Notification.permission === "default") {
        Notification.requestPermission();
    }
    
    setInterval(checkAdminNotifications, 10000);
});
</script>
</body>
</html>