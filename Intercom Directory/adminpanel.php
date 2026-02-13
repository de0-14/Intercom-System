<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'conn.php';
require_once 'archive_functions.php';
require_once 'admin_archive_functions.php';
updateAllUsersActivity($conn);

archiveAllInactiveChatsOnLoad($conn);

function getArrayValue($array, $key, $default = '') {
    return isset($array[$key]) ? $array[$key] : $default;
}

if(!isAdmin()) {
    header('Location: homepage.php');
    exit();
}

date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

$user_id = $_SESSION['user_id'];
$is_admin = isAdmin();
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

$archived_this_load = archiveAllInactiveChatsOnLoad($conn);
$seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
$removed_this_load = removeOldArchivedAdminChats($conn, $user_id, null, $seven_days_ago);

$archived_count_sql = "SELECT COUNT(*) as archive_count FROM admin_chats_archive WHERE admin_id = ?";
$archived_count_params = array($user_id);
$archived_count_stmt = sqlsrv_query($conn, $archived_count_sql, $archived_count_params);
if ($archived_count_stmt && sqlsrv_fetch($archived_count_stmt)) {
    $archive_data = sqlsrv_fetch_array($archived_count_stmt, SQLSRV_FETCH_ASSOC);
    $archived_chats_count = $archive_data['archive_count'] ?? 0;
}
if ($archived_count_stmt) sqlsrv_free_stmt($archived_count_stmt);

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
    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    if(isset($_POST['send_message'])) {
        $chat_id = (int)$_POST['chat_id'];
        $message = trim($_POST['message']);
        
        if(empty($message)) {
            if ($is_ajax) {
                echo json_encode(['success' => false, 'error' => 'Empty message']);
                exit();
            }
            $error = "Please enter a message.";
        } else {
            if(sendAdminMessage($conn, $chat_id, $user_id, $message)) {
                updateAdminChatActivity($conn, $chat_id);
                $sql = "SELECT TOP 1 am.*, u.full_name, u.username 
                        FROM admin_messages am 
                        JOIN users u ON am.sender_id = u.user_id 
                        WHERE am.chat_id = ? 
                        ORDER BY am.created_at DESC";
                $params = array($chat_id);
                $stmt = sqlsrv_query($conn, $sql, $params);
                
                if ($stmt && $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    if ($is_ajax) {
                        echo json_encode([
                            'success' => true,
                            'message' => [
                                'message_id' => $row['message_id'],
                                'sender_id' => $row['sender_id'],
                                'full_name' => $row['full_name'],
                                'message' => $row['message'],
                                'created_at' => $row['created_at'] instanceof DateTime 
                                    ? $row['created_at']->format('Y-m-d H:i:s') 
                                    : $row['created_at'],
                                'is_sent' => true
                            ]
                        ]);
                        exit();
                    } else {
                        $success = "Message sent!";
                        header("Location: " . basename($_SERVER['PHP_SELF']) . "?chat_id=$chat_id");
                        exit();
                    }
                }
            }
            if ($is_ajax) {
                echo json_encode(['success' => false, 'error' => 'Failed to send message']);
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
            header("Location: " . basename($_SERVER['PHP_SELF']) . "?chat_id=$chat_id");
            exit();
        } else {
            $error = "Failed to start chat.";
        }
    }
    
    if(isset($_POST['archive_chat']) && isAdmin()) {
        $chat_id = (int)$_POST['chat_id'];
        if(archiveAdminChatImmediately($conn, $chat_id)) {
            $success = "Chat archived successfully!";
            header("Location: " . basename($_SERVER['PHP_SELF']));
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
/* ============ YOUR EXISTING CSS (keep as is) ============ */
* { box-sizing: border-box; margin:0; padding:0; font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
body { min-height: 100vh; display: flex; flex-direction: column; background-color: #edf4fc; }
.header { position: fixed; top: 0; left: 0; width: 100%; background-color: #07417f; color: white; padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-bottom: 3px solid #2b6cb0; }
.header .logo { display: flex; align-items: center; gap: 15px; }
.header .logo img { width: 55px; height: 55px; object-fit: contain; }
.header .logo span { font-size: 1.5rem; font-weight: 700; color: white; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
ul.nav { display: flex; list-style: none; gap: 8px; }
ul.nav li a { display: block; color: white; text-decoration: none; padding: 10px 18px; font-weight: 600; border-radius: 6px; transition: all 0.2s; }
ul.nav li a:hover { background-color: rgba(255,255,255,0.2); }
ul.nav li a.active { background-color: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); }
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

<!-- ============ HEADER (same as before) ============ -->
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
                <li><a href="adminpanel.php" class="notification-indicator <?php echo basename($_SERVER['PHP_SELF']) == 'adminpanel.php' ? 'active' : ''; ?>">Operator Panel <?php if ($admin_notifications_count > 0): ?><span class="nav-notification-badge"><?php echo $admin_notifications_count; ?></span><?php endif; ?></a></li>
            <?php else: ?>
                <li><a href="adminchat.php">Chat with an Operator</a></li>
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
                        
                        <!-- NOTIFICATION ITEM TOOLTIP -->
                        <div class="hierarchy-tooltip">
                            <h4><?php echo htmlspecialchars($user_hierarchy['full_name'] ?? 'Unknown User'); ?></h4>
                            <div class="hierarchy-info">
                                <div class="hierarchy-row"><div class="hierarchy-label">Email:</div><div class="hierarchy-value"><?php echo htmlspecialchars(!empty($user_hierarchy['email']) ? $user_hierarchy['email'] : 'N/A'); ?></div></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Role:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?><?php if ($user_hierarchy['is_head'] ?? false): ?><span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span><?php endif; ?></div></div>
                                <?php if (!empty($user_hierarchy['current_unit'] ?? '')): ?>
                                <div class="hierarchy-row"><div class="hierarchy-label">Current Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['current_unit']); ?><?php if (!empty($user_hierarchy['unit_type'] ?? '')): ?><span style="color: #a0aec0; font-size: 10px;">(<?php echo htmlspecialchars($user_hierarchy['unit_type']); ?>)</span><?php endif; ?></div></div>
                                <?php endif; ?>
                                <?php if (($user_hierarchy['is_head'] ?? false) && !empty($user_hierarchy['heads_contacts']) && is_array($user_hierarchy['heads_contacts'])): ?>
                                <div class="hierarchy-divider"></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Heads:</div><div class="hierarchy-value"><?php 
                                    $head_units = [];
                                    foreach($user_hierarchy['heads_contacts'] as $contact) {
                                        if (is_array($contact)) {
                                            $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                            $contact_unit_type = $contact['unit_type'] ?? '';
                                            $head_units[] = trim($unit_name . ' (' . $contact_unit_type . ')');
                                        }
                                    }
                                    if (!empty($head_units)) {
                                        $display_units = array_slice($head_units, 0, 3);
                                        echo htmlspecialchars(implode(', ', $display_units));
                                        if (count($head_units) > 3) echo ' +' . (count($head_units) - 3) . ' more';
                                    } else echo 'None';
                                ?></div></div>
                                <?php endif; ?>
                                <?php if (!empty($user_hierarchy['division'] ?? '')): ?><div class="hierarchy-row"><div class="hierarchy-label">Division:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['division']); ?></div></div><?php endif; ?>
                                <?php if (!empty($user_hierarchy['department'] ?? '')): ?><div class="hierarchy-row"><div class="hierarchy-label">Department:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['department']); ?></div></div><?php endif; ?>
                                <?php if (!empty($user_hierarchy['unit'] ?? '')): ?><div class="hierarchy-row"><div class="hierarchy-label">Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['unit']); ?></div></div><?php endif; ?>
                                <?php if (!empty($user_hierarchy['office'] ?? '')): ?><div class="hierarchy-row"><div class="hierarchy-label">Office:</div><div class="hierarchy-value"><?php echo htmlspecialchars($user_hierarchy['office']); ?></div></div><?php endif; ?>
                                <?php if (!($user_hierarchy['is_head'] ?? false) && !empty($user_hierarchy['head_info']) && is_array($user_hierarchy['head_info']) && isset($user_hierarchy['head_info']['head_name'])): ?>
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

<!-- ============ MAIN CONTENT (same as before, with all hierarchy tooltips fixed) ============ -->
<div class="content">
    <div class="container">
        <!-- SIDEBAR (keep your existing sidebar code) -->
        <!-- ... -->
        <!-- For brevity, keep your existing sidebar code – it's fine because it's HTML/PHP, not inside <script> -->
    </div>
</div>

<!-- ============ CHAT CONFIGURATION – NO PHP INSIDE SCRIPT ============ -->
<script id="chat-config" type="application/json">
{
    "chatId": <?php echo json_encode($selected_chat_id ?? 0); ?>,
    "userId": <?php echo json_encode($user_id); ?>,
    "isAdmin": <?php echo json_encode($is_admin); ?>,
    "viewArchived": <?php echo json_encode($view_archived); ?>
}
</script>

<!-- ============ MAIN JAVASCRIPT – PURE JS, NO PHP ============ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    console.log('AdminPanel Full AJAX Loaded');

    // ----- LOAD CONFIG FROM JSON -----
    let config;
    try {
        config = JSON.parse(document.getElementById('chat-config').textContent);
    } catch (e) {
        console.error('Failed to load chat config:', e);
        return;
    }

    const chatId = config.chatId;
    const userId = config.userId;
    const isAdmin = config.isAdmin;
    const viewArchived = config.viewArchived;
    const POLL_DELAY = 3000;

    // ----- STATE -----
    let lastMessageId = 0;
    let pollInterval = null;
    let isSending = false;

    // ----- INITIALIZATION -----
    function initialize() {
        document.querySelectorAll('.message').forEach((msg, idx) => {
            if (!msg.hasAttribute('data-message-id')) {
                msg.setAttribute('data-message-id', idx + 1);
            }
        });
        lastMessageId = getLastMessageId();
        console.log('Initial lastMessageId:', lastMessageId);
        const chatMessages = document.getElementById('chat-messages');
        if (chatMessages) chatMessages.scrollTop = chatMessages.scrollHeight;
        if (chatId && !viewArchived) startPolling();
    }

    // ----- POLLING -----
    function startPolling() {
        if (pollInterval) clearInterval(pollInterval);
        fetchNewMessages();
        pollInterval = setInterval(fetchNewMessages, POLL_DELAY);
        console.log('✅ Polling started - interval:', POLL_DELAY + 'ms');
    }
    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
            console.log('⏸️ Polling stopped');
        }
    }
    function fetchNewMessages() {
        if (!chatId || viewArchived) return;
        fetch(`get_admin_messages.php?chat_id=${chatId}&last_message_id=${lastMessageId}`)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.messages && data.messages.length > 0) {
                    appendMessages(data.messages);
                    lastMessageId = data.last_message_id;
                    if (data.unread_count > 0) {
                        const badge = document.querySelector('.nav-notification-badge');
                        if (badge) badge.textContent = data.unread_count;
                    }
                }
            })
            .catch(console.error);
    }

    // ----- APPEND MESSAGES -----
    function appendMessages(messages) {
        const chatMessages = document.getElementById('chat-messages');
        if (!chatMessages) return;
        const wasAtBottom = isScrolledToBottom();
        let added = false;
        messages.forEach(msg => {
            if (document.querySelector(`.message[data-message-id="${msg.message_id}"]`)) return;
            const div = document.createElement('div');
            div.className = `message ${msg.is_sent ? 'sent' : 'received'}`;
            div.setAttribute('data-message-id', msg.message_id);
            div.innerHTML = `
                <div class="message-sender">${escapeHtml(msg.full_name)}</div>
                <div class="message-bubble">
                    ${escapeHtml(msg.message).replace(/\n/g, '<br>')}
                    <div style="font-size:11px; opacity:0.8; margin-top:5px;">${formatTime(msg.created_at)}</div>
                </div>
            `;
            chatMessages.appendChild(div);
            added = true;
        });
        if (added && wasAtBottom) chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // ----- SEND MESSAGE (AJAX) -----
    function sendMessage(messageText) {
        if (isSending || !messageText.trim() || !chatId) return false;
        isSending = true;
        const sendButton = document.querySelector('.send-btn');
        const originalText = sendButton ? sendButton.textContent : 'Send';
        const textarea = document.querySelector('.chat-input');
        const originalMessage = messageText;
        if (sendButton) { sendButton.disabled = true; sendButton.textContent = 'Sending...'; }
        if (textarea) { textarea.value = ''; textarea.style.height = 'auto'; }

        const formData = new FormData();
        formData.append('send_message', '1');
        formData.append('chat_id', chatId);
        formData.append('message', messageText);

        return fetch(window.location.pathname, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.message) {
                appendMessages([data.message]);
                lastMessageId = data.message.message_id;
                console.log('✅ Message sent');
                return true;
            } else {
                console.error('Send failed:', data.error);
                if (textarea) textarea.value = originalMessage;
                alert('Failed to send message.');
                return false;
            }
        })
        .catch(err => {
            console.error('Send error:', err);
            if (textarea) textarea.value = originalMessage;
            alert('Error sending message.');
            return false;
        })
        .finally(() => {
            isSending = false;
            if (sendButton) { sendButton.disabled = false; sendButton.textContent = originalText; }
            if (textarea) textarea.focus();
        });
    }

    // ----- UTILITIES -----
    function getLastMessageId() {
        const msgs = document.querySelectorAll('.message');
        if (!msgs.length) return 0;
        const last = msgs[msgs.length - 1];
        const id = last.getAttribute('data-message-id');
        return id ? parseInt(id, 10) : 0;
    }
    function isScrolledToBottom() {
        const el = document.getElementById('chat-messages');
        if (!el) return false;
        const threshold = 50;
        return (el.scrollHeight - el.scrollTop - el.clientHeight) < threshold;
    }
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    function formatTime(datetime) {
        const d = new Date(datetime);
        return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }

    // ----- EVENT LISTENERS -----
    function setupEventListeners() {
        const chatForm = document.querySelector('.chat-form');
        if (!chatForm || viewArchived) return;
        const textarea = chatForm.querySelector('textarea');
        const sendButton = chatForm.querySelector('button[type="submit"]');
        if (!textarea || !sendButton) return;
        chatForm.addEventListener('submit', e => { e.preventDefault(); return false; });
        sendButton.addEventListener('click', e => {
            e.preventDefault();
            if (textarea.value.trim()) sendMessage(textarea.value.trim());
        });
        textarea.addEventListener('keydown', function(e) {
            if ((e.key === 'Enter' || e.which === 13) && !e.shiftKey) {
                e.preventDefault();
                if (this.value.trim()) sendMessage(this.value.trim());
            }
        });
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
        setTimeout(() => textarea.focus(), 100);
    }

    // ----- NOTIFICATION DROPDOWN (no PHP) -----
    function setupNotifications() {
        const btn = document.getElementById('adminNotificationBtn');
        const dd = document.getElementById('notificationDropdown');
        if (btn && dd) {
            btn.addEventListener('click', e => {
                e.stopPropagation();
                const visible = dd.style.opacity === '1';
                dd.style.opacity = visible ? '0' : '1';
                dd.style.visibility = visible ? 'hidden' : 'visible';
                dd.style.transform = visible ? 'translateY(-10px)' : 'translateY(0)';
            });
            document.addEventListener('click', e => {
                if (!btn.contains(e.target) && !dd.contains(e.target)) {
                    dd.style.opacity = '0';
                    dd.style.visibility = 'hidden';
                    dd.style.transform = 'translateY(-10px)';
                }
            });
        }
    }

    // ----- VISIBILITY API -----
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stopPolling();
        else if (chatId && !viewArchived && !pollInterval) startPolling();
    });

    // ----- CLEANUP -----
    window.addEventListener('beforeunload', stopPolling);

    // ----- START -----
    initialize();
    setupEventListeners();
    setupNotifications();
});
</script>

<!-- ============ SEPARATE SCRIPT FOR ADMIN NOTIFICATION CHECKER (PHP OK HERE) ============ -->
<?php if (isset($is_admin) && $is_admin): ?>
<script>
(function() {
    function checkNotifications() {
        fetch('check_admin_notifications.php')
            .then(r => r.json())
            .then(data => {
                const bellBadge = document.querySelector('.notification-bell-badge');
                const navBadge = document.querySelector('.nav-notification-badge');
                const header = document.querySelector('.notification-header h4');
                if (data.count > 0) {
                    if (bellBadge) { bellBadge.textContent = data.count; bellBadge.style.display = 'inline-block'; }
                    if (navBadge) { navBadge.textContent = data.count; navBadge.style.display = 'inline-block'; }
                    if (header) header.textContent = `📨 New Chat Requests (${data.count})`;
                } else {
                    if (bellBadge) bellBadge.style.display = 'none';
                    if (navBadge) navBadge.style.display = 'none';
                    if (header) header.textContent = '📨 New Chat Requests (0)';
                }
            })
            .catch(console.error);
    }
    setInterval(checkNotifications, 10000);
    if (Notification.permission === 'default') Notification.requestPermission();
})();
</script>
<?php endif; ?>

</body>
</html>