<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'conn.php';
require_once 'archive_functions.php';
require_once 'admin_archive_functions.php';
updateAllUsersActivity($conn);

archiveAllInactiveChatsOnLoad($conn);

if(!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

$user_id = $_SESSION['user_id'];
$is_admin = isAdmin();
$selected_chat_id = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : null;
$start_chat_with = isset($_GET['start_chat']) ? (int)$_GET['start_chat'] : null;
$selected_chat = null;
$chat_messages = [];
$error = '';
$success = '';

$view_archived = isset($_GET['view']) && $_GET['view'] === 'archived';
$archived_chats = [];
$archived_chats_count = 0;
$archived_this_load = 0;
$removed_this_load = 0;

// Archive all inactive chats immediately on page load
$archived_this_load = archiveAllInactiveChatsOnLoad($conn);

// Still need to track removed separately for display
$seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
if ($is_admin) {
    $removed_this_load = removeOldArchivedAdminChats($conn, null, null, $seven_days_ago);
} else {
    $removed_this_load = removeOldArchivedAdminChats($conn, null, $user_id, $seven_days_ago);
}

// Get archived count - SQL Server version
if ($is_admin) {
    $archived_count_sql = "SELECT COUNT(*) as archive_count FROM admin_chats_archive WHERE admin_id = ?";
    $archived_count_params = array($user_id);
} else {
    $archived_count_sql = "SELECT COUNT(*) as archive_count FROM admin_chats_archive WHERE user_id = ?";
    $archived_count_params = array($user_id);
}

$archived_count_stmt = sqlsrv_prepare($conn, $archived_count_sql, $archived_count_params);
if ($archived_count_stmt && sqlsrv_execute($archived_count_stmt)) {
    $archive_data = sqlsrv_fetch_array($archived_count_stmt, SQLSRV_FETCH_ASSOC);
    $archived_chats_count = $archive_data['archive_count'] ?? 0;
}
if ($archived_count_stmt) {
    sqlsrv_free_stmt($archived_count_stmt);
}

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
    $chats = getActiveAdminChats($conn, $user_id, $is_admin);
    foreach($chats as $chat) {
        if($chat['chat_id'] == $selected_chat_id) {
            $selected_chat = $chat;
            break;
        }
    }
    
    if($selected_chat) {
        $chat_messages = getAdminChatMessages($conn, $selected_chat_id);
        markAdminMessagesAsRead($conn, $selected_chat_id, $user_id);
        if (function_exists('updateAdminChatActivity')) {
            updateAdminChatActivity($conn, $selected_chat_id);
        }
    } else {
        $error = "Chat not found or has been archived.";
        $selected_chat_id = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$view_archived) {
    // Check if it's an AJAX request
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
                
                // Get the sent message
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
    
    // Handle other POST requests (start_chat, archive_chat) - these can still redirect
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

$unread_count = getUnreadAdminMessageCount($conn, $user_id, $is_admin);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $is_admin ? 'Admin Chat' : 'Chat with Operator'; ?></title>
<style>
* { box-sizing: border-box; margin:0; padding:0; font-family:"Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
.new-conversation-indicator { font-size: 11px; color: #718096; font-style: italic; margin-left: 5px; }
body { min-height: 100vh; display: flex; flex-direction: column; background-color: #edf4fc; }
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
    position: relative;
}
ul.nav li a:hover { 
    background-color: rgba(255,255,255,0.2); 
}
ul.nav li a.active { 
    background-color: rgba(255,255,255,0.15); 
    border: 1px solid rgba(255,255,255,0.3); 
}
ul.nav li a.active:hover { 
    background-color: rgba(255,255,255,0.15); 
    border: 1px solid rgba(255,255,255,0.3); 
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
.chat-container { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); height: calc(100vh - 160px); display: flex; }
.chat-sidebar { width: 300px; background: #f7fafc; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; }
.chat-header-bar { background: linear-gradient(135deg, #2b6cb0 0%, #1f4f8b 100%); color: white; padding: 20px; }
.chat-header-bar h2 { color: white; margin: 0; font-size: 20px; }
.chat-list { flex: 1; overflow-y: auto; padding: 15px; }
.chat-item { display: flex; align-items: center; padding: 12px; border-radius: 8px; margin-bottom: 10px; cursor: pointer; text-decoration: none; color: inherit; transition: all 0.2s; border: 1px solid transparent; position: relative; }
.chat-item:hover { background-color: white; border-color: #e2e8f0; }
.chat-item.active { background-color: #e8f4fd; border-color: #2b6cb0; }
.chat-avatar { width: 40px; height: 40px; border-radius: 50%; background: #2b6cb0; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 12px; }
.chat-info { flex: 1; }
.chat-name { font-weight: 600; color: #2d3748; margin-bottom: 3px; }
.chat-preview { font-size: 13px; color: #718096; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chat-time { font-size: 11px; color: #a0aec0; }
.chat-main { flex: 1; display: flex; flex-direction: column; }
.chat-header { background: white; padding: 20px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; }
.chat-header h3 { color: #2b6cb0; margin: 0; }
.chat-messages { flex: 1; padding: 20px; overflow-y: auto; background-color: #f7fafc; }
.message { margin-bottom: 15px; max-width: 70%; clear: both; }
.message.sent { float: right; }
.message.received { float: left; }
.message-bubble { padding: 12px 16px; border-radius: 18px; position: relative; word-wrap: break-word; }
.message.sent .message-bubble { background-color: #2b6cb0; color: white; border-bottom-right-radius: 4px; }
.message.received .message-bubble { background-color: white; color: #2d3748; border: 1px solid #e2e8f0; border-bottom-left-radius: 4px; }
.message-sender { font-size: 12px; color: #718096; margin-bottom: 4px; padding-left: 5px; position: relative; cursor: pointer; display: inline-block; }
.message-time { font-size: 11px; opacity: 0.8; margin-top: 5px; }
.chat-input-area { padding: 15px; border-top: 1px solid #e2e8f0; background: white; }
.chat-form { display: flex; gap: 10px; }
.chat-input { flex: 1; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: vertical; min-height: 60px; max-height: 120px; }
.send-btn { padding: 12px 24px; background-color: #2b6cb0; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: background-color 0.2s; }
.send-btn:hover { background-color: #1f4f8b; }
.error-message { background-color: #fed7d7; color: #742a2a; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #e53e3e; }
.success-message { background-color: #c6f6d5; color: #22543d; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #38a169; }
.no-chat-selected { display: flex; align-items: center; justify-content: center; flex: 1; color: #a0aec0; font-style: italic; text-align: center; padding: 40px; }
.admin-select-list { margin-top: 15px; }
.admin-select-item { display: flex; align-items: center; padding: 10px; border-radius: 6px; margin-bottom: 8px; text-decoration: none; color: #2d3748; transition: all 0.2s; border: 1px solid transparent; position: relative; cursor: pointer; }
.admin-select-item:hover { background-color: #f7fafc; border-color: #e2e8f0; }
.admin-select-avatar { width: 35px; height: 35px; border-radius: 50%; background: #2b6cb0; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 10px; font-size: 14px; }
.admin-select-name { font-weight: 500; font-size: 14px; }
@media(max-width: 768px){ .chat-container { flex-direction: column; } .chat-sidebar { width: 100%; height: 200px; } }
.archive-badge { background-color: #718096; color: white; font-size: 11px; padding: 2px 6px; border-radius: 4px; margin-left: 5px; }
.archive-btn { background-color: #718096; color: white; border: none; border-radius: 4px; padding: 4px 8px; font-size: 12px; cursor: pointer; transition: background-color 0.2s; margin-left: 5px; }
.archive-btn:hover { background-color: #4a5568; }
.archived-chat .message-bubble { opacity: 0.8; }
.archived-chat .message.sent .message-bubble { background-color: #4a5568; }
.archived-chat .message.received .message-bubble { background-color: #e2e8f0; color: #2d3748; }
.view-toggle { display: flex; gap: 10px; margin-bottom: 15px; padding: 10px; background: #f7fafc; border-radius: 8px; border: 1px solid #e2e8f0; }
.view-toggle-btn { padding: 8px 16px; background-color: #f7fafc; border: 2px solid #e2e8f0; border-radius: 6px; text-decoration: none; color: #4a5568; font-weight: 500; display: flex; align-items: center; gap: 8px; transition: all 0.3s; }
.view-toggle-btn:hover { background-color: #edf2f7; border-color: #cbd5e0; }
.view-toggle-btn.active { background-color: #2b6cb0; border-color: #2b6cb0; color: white; }
.archive-count-badge { background-color: #e53e3e; color: white; font-size: 12px; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; }
.auto-process-notice { background-color: #e8f4fd; border: 1px solid #b6d4fe; color: #084298; padding: 10px 15px; border-radius: 6px; font-size: 14px; text-align: center; margin-bottom: 15px; }
.archived-info-bar { background: #e8f4fd; padding: 10px 15px; border-bottom: 1px solid #b6d4fe; font-size: 13px; color: #084298; display: flex; justify-content: space-between; }
.chat-actions { display: flex; gap: 10px; margin-top: 10px; justify-content: flex-end; }
.no-archived-message { text-align: center; padding: 40px 20px; color: #a0aec0; font-style: italic; background: white; border-radius: 8px; border: 1px solid #e2e8f0; margin: 20px; }
.chat-item.archived .chat-avatar { background-color: #718096; }
.archive-system-info { background: #f0f9ff; border: 1px solid #b6d4fe; border-radius: 8px; padding: 15px; margin: 15px; font-size: 13px; color: #084298; }
.archive-system-info h4 { margin: 0 0 10px 0; color: #2b6cb0; font-size: 14px; }
.archive-system-info ul { margin: 0; padding-left: 20px; }
.archive-system-info li { margin-bottom: 5px; }
.hierarchy-tooltip { position: absolute; background: #2d3748; color: white; padding: 10px; border-radius: 6px; font-size: 12px; width: 300px; z-index: 9999; box-shadow: 0 3px 10px rgba(0,0,0,0.3); opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s; top: 100%; left: 0; margin-top: 5px; border: 1px solid #4a5568; }
.chat-item:hover .hierarchy-tooltip, .admin-select-item:hover .hierarchy-tooltip, .message-sender:hover .hierarchy-tooltip { opacity: 1; visibility: visible; }
.hierarchy-tooltip h4 { color: white; margin: 0 0 8px 0; font-size: 13px; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 5px; }
.hierarchy-info { margin: 5px 0; }
.hierarchy-row { display: flex; margin: 3px 0; align-items: flex-start; }
.hierarchy-label { width: 90px; color: #a0aec0; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; flex-shrink: 0; }
.hierarchy-value { flex: 1; color: white; font-weight: 500; font-size: 11px; line-height: 1.3; }
.hierarchy-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 5px 0; }
.chat-item, .admin-select-item, .message-sender { position: relative; }
.nav-notification-badge { 
    background-color: #e53e3e; 
    color: white; 
    font-size: 11px; 
    padding: 2px 6px; 
    border-radius: 10px; 
    min-width: 18px; 
    text-align: center; 
    margin-left: 5px; 
    animation: pulse 2s infinite; 
    display: inline-block; 
}
@keyframes pulse { 
    0% { transform: scale(1); } 
    50% { transform: scale(1.1); } 
    100% { transform: scale(1); } 
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
                <li><a href="adminpanel.php">Operator Panel</a></li>
            <?php else: ?>
                <li><a href="adminchat.php" class="active">Chat with an Operator <?php echo $unread_count > 0 ? "<span class='nav-notification-badge'>$unread_count</span>" : ""; ?></a></li>
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
                <h2><?php echo $is_admin ? 'Admin Chats' : 'Chat with Operator'; ?></h2>
            </div>
            
            <?php if ($is_admin || $archived_chats_count > 0): ?>
            <div class="view-toggle">
                <a href="adminchat.php" class="view-toggle-btn <?php echo !$view_archived ? 'active' : ''; ?>">Active Chats</a>
                <a href="adminchat.php?view=archived" class="view-toggle-btn <?php echo $view_archived ? 'active' : ''; ?>">Archived Chats
                    <?php if ($archived_chats_count > 0): ?><span class="archive-count-badge"><?php echo $archived_chats_count; ?></span><?php endif; ?>
                </a>
            </div>
            
            <?php if ($archived_this_load > 0 || $removed_this_load > 0): ?>
                <div class="auto-process-notice">
                    <?php if ($archived_this_load > 0): ?><span>📁 Auto-archived <?php echo $archived_this_load; ?> inactive chat(s)</span><?php endif; ?>
                    <?php if ($removed_this_load > 0): ?><span>🗑️ Cleaned up <?php echo $removed_this_load; ?> old archived chat(s)</span><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php endif; ?>
            
            <div class="chat-list">
                <?php if ($view_archived): ?>
                    <?php if(!empty($archived_chats)): ?>
                        <?php foreach($archived_chats as $chat): 
                            $other_user_id = $is_admin ? $chat['user_id'] : $chat['admin_id'];
                            $hierarchy_info = getUserHierarchyInfo($conn, $other_user_id);
                        ?>
                        <a href="adminchat.php?view=archived&chat_id=<?php echo $chat['chat_id']; ?>" 
                           class="chat-item archived <?php echo $selected_chat_id == $chat['chat_id'] ? 'active' : ''; ?>">
                            <div class="chat-avatar"><?php echo strtoupper(substr($chat['full_name'], 0, 1)); ?></div>
                            <div class="chat-info">
                                <div class="chat-name"><?php echo $is_admin ? htmlspecialchars($chat['full_name']) : htmlspecialchars($chat['full_name']); ?><span class="archive-badge">Archived</span></div>
                                <div class="chat-preview"><?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages', 0, 30)); ?></div>
                            </div>
                            <div class="chat-time"><?php if($chat['archived_at']): ?><?php echo date('M d', strtotime($chat['archived_at']->format('Y-m-d H:i:s'))); ?><?php endif; ?></div>
                            
                            <div class="hierarchy-tooltip">
                                <h4><?php echo htmlspecialchars($hierarchy_info['full_name']); ?></h4>
                                <div class="hierarchy-info">
                                    <div class="hierarchy-row"><div class="hierarchy-label">Email:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['email'] ?? 'N/A'); ?></div></div>
                                    <div class="hierarchy-row"><div class="hierarchy-label">Role:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['role_name'] ?? 'N/A'); ?><?php if ($hierarchy_info['is_head']): ?><span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span><?php endif; ?></div></div>
                                    <?php if ($hierarchy_info['current_unit']): ?>
                                    <div class="hierarchy-row"><div class="hierarchy-label">Current Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['current_unit']); ?><?php if ($hierarchy_info['unit_type']): ?><span style="color: #a0aec0; font-size: 10px;">(<?php echo $hierarchy_info['unit_type']; ?>)</span><?php endif; ?></div></div>
                                    <?php endif; ?>
                                    <?php if ($hierarchy_info['is_head'] && !empty($hierarchy_info['heads_contacts'])): ?>
                                    <div class="hierarchy-divider"></div>
                                    <div class="hierarchy-row"><div class="hierarchy-label">Heads:</div><div class="hierarchy-value"><?php 
                                    $head_units = [];
                                    foreach($hierarchy_info['heads_contacts'] as $contact) {
                                        $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                        $head_units[] = $unit_name . ' (' . $contact['unit_type'] . ')';
                                    }
                                    echo htmlspecialchars(implode(', ', array_slice($head_units, 0, 3)));
                                    if (count($head_units) > 3) { echo ' +' . (count($head_units) - 3) . ' more'; } ?></div></div>
                                    <?php endif; ?>
                                    <?php if ($hierarchy_info['division']): ?><div class="hierarchy-row"><div class="hierarchy-label">Division:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['division']); ?></div></div><?php endif; ?>
                                    <?php if ($hierarchy_info['department']): ?><div class="hierarchy-row"><div class="hierarchy-label">Department:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['department']); ?></div></div><?php endif; ?>
                                    <?php if ($hierarchy_info['unit']): ?><div class="hierarchy-row"><div class="hierarchy-label">Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['unit']); ?></div></div><?php endif; ?>
                                    <?php if ($hierarchy_info['office']): ?><div class="hierarchy-row"><div class="hierarchy-label">Office:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['office']); ?></div></div><?php endif; ?>
                                    <?php if ($hierarchy_info['head_info'] && isset($hierarchy_info['head_info']['head_name']) && !$hierarchy_info['is_head']): ?>
                                    <div class="hierarchy-divider"></div>
                                    <div class="hierarchy-row"><div class="hierarchy-label">Head:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['head_info']['head_name']); ?></div></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-archived-message">No archived chats found.</div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <?php 
                    $chats = getActiveAdminChats($conn, $user_id, $is_admin);
                    if(!empty($chats)):
                        foreach($chats as $chat):
                            $other_user_id = $is_admin ? $chat['user_id'] : $chat['admin_id'];
                            $hierarchy_info = getUserHierarchyInfo($conn, $other_user_id);
                    ?>
                    <a href="adminchat.php?chat_id=<?php echo $chat['chat_id']; ?>" 
                    class="chat-item <?php echo $selected_chat_id == $chat['chat_id'] ? 'active' : ''; ?>">
                        <div class="chat-avatar"><?php echo strtoupper(substr($chat['full_name'], 0, 1)); ?></div>
                        <div class="chat-info">
                            <div class="chat-name"><?php echo $is_admin ? htmlspecialchars($chat['full_name']) : htmlspecialchars($chat['full_name']); ?></div>
                            <div class="chat-preview"><?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages yet', 0, 30)); ?></div>
                        </div>
                        <div class="chat-time"><?php if($chat['last_message_time']): ?><?php echo date('H:i', strtotime($chat['last_message_time']->format('Y-m-d H:i:s'))); ?><?php endif; ?></div>
                        
                        <div class="hierarchy-tooltip">
                            <h4><?php echo htmlspecialchars($hierarchy_info['full_name']); ?></h4>
                            <div class="hierarchy-info">
                                <div class="hierarchy-row"><div class="hierarchy-label">Email:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['email'] ?? 'N/A'); ?></div></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Role:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['role_name'] ?? 'N/A'); ?><?php if ($hierarchy_info['is_head']): ?><span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span><?php endif; ?></div></div>
                                <?php if ($hierarchy_info['current_unit']): ?>
                                <div class="hierarchy-row"><div class="hierarchy-label">Current Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['current_unit']); ?><?php if ($hierarchy_info['unit_type']): ?><span style="color: #a0aec0; font-size: 10px;">(<?php echo $hierarchy_info['unit_type']; ?>)</span><?php endif; ?></div></div>
                                <?php endif; ?>
                                <?php if ($hierarchy_info['is_head'] && !empty($hierarchy_info['heads_contacts'])): ?>
                                <div class="hierarchy-divider"></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Heads:</div><div class="hierarchy-value"><?php 
                                $head_units = [];
                                foreach($hierarchy_info['heads_contacts'] as $contact) {
                                    $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                    $head_units[] = $unit_name . ' (' . $contact['unit_type'] . ')';
                                }
                                echo htmlspecialchars(implode(', ', array_slice($head_units, 0, 3)));
                                if (count($head_units) > 3) { echo ' +' . (count($head_units) - 3) . ' more'; } ?></div></div>
                                <?php endif; ?>
                                <?php if ($hierarchy_info['division']): ?><div class="hierarchy-row"><div class="hierarchy-label">Division:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['division']); ?></div></div><?php endif; ?>
                                <?php if ($hierarchy_info['department']): ?><div class="hierarchy-row"><div class="hierarchy-label">Department:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['department']); ?></div></div><?php endif; ?>
                                <?php if ($hierarchy_info['unit']): ?><div class="hierarchy-row"><div class="hierarchy-label">Unit:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['unit']); ?></div></div><?php endif; ?>
                                <?php if ($hierarchy_info['office']): ?><div class="hierarchy-row"><div class="hierarchy-label">Office:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['office']); ?></div></div><?php endif; ?>
                                <?php if ($hierarchy_info['head_info'] && isset($hierarchy_info['head_info']['head_name']) && !$hierarchy_info['is_head']): ?>
                                <div class="hierarchy-divider"></div>
                                <div class="hierarchy-row"><div class="hierarchy-label">Head:</div><div class="hierarchy-value"><?php echo htmlspecialchars($hierarchy_info['head_info']['head_name']); ?></div></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    
                    <?php if(!$is_admin): ?>
                        <div class="admin-select-list">
                            <h4 style="color: #4a5568; font-size: 14px; margin: 15px 0 10px 0; border-top: 1px solid #e2e8f0; padding-top: 10px;">Start New Chat</h4>
                            <?php
                            $admins = getAvailableAdmins($conn);
                            foreach($admins as $admin):
                                $has_active_chat = false;
                                foreach($chats as $chat) {
                                    if($chat['admin_id'] == $admin['user_id']) {
                                        $has_active_chat = true;
                                        break;
                                    }
                                }
                                if (!$has_active_chat):
                                    $has_archived = hasArchivedChatWithUser($conn, $user_id, $admin['user_id'], false);
                                    $admin_hierarchy = getUserHierarchyInfo($conn, $admin['user_id']);
                            ?>
                            <a href="adminchat.php?start_chat=<?php echo $admin['user_id']; ?>" class="admin-select-item">
                                <div class="admin-select-avatar"><?php echo strtoupper(substr($admin['full_name'], 0, 1)); ?></div>
                                <span class="admin-select-name"><?php echo htmlspecialchars($admin['full_name']); ?><?php if ($has_archived): ?><span class="new-conversation-indicator">(Start fresh)</span><?php endif; ?></span>
                                
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
                            </a>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php elseif(!$is_admin): ?>
                        <div class="admin-select-list">
                            <h4 style="color: #4a5568; font-size: 14px; margin: 15px 0 10px 0;">Select an Operator to Chat With</h4>
                            <?php
                            $admins = getAvailableAdmins($conn);
                            foreach($admins as $admin):
                                $admin_hierarchy = getUserHierarchyInfo($conn, $admin['user_id']);
                            ?>
                            <a href="adminchat.php?start_chat=<?php echo $admin['user_id']; ?>" class="admin-select-item">
                                <div class="admin-select-avatar"><?php echo strtoupper(substr($admin['full_name'], 0, 1)); ?></div>
                                <span class="admin-select-name"><?php echo htmlspecialchars($admin['full_name']); ?></span>
                                
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
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; color: #a0aec0; padding: 20px;">No active chats yet</div>
                    <?php endif; ?>
                <?php endif; ?>
                
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
                        <?php if ($view_archived): ?>📁 Archived Chat with <?php echo $is_admin ? htmlspecialchars($selected_chat['other_full_name'] ?? $selected_chat['full_name']) : htmlspecialchars($selected_chat['other_full_name'] ?? $selected_chat['full_name']); ?>
                        <?php else: ?>Chat with <?php echo $is_admin ? htmlspecialchars($selected_chat['full_name']) : 'Admin ' . htmlspecialchars($selected_chat['full_name']); ?>
                        <?php endif; ?>
                    </h3>
                </div>
                
                <?php if ($view_archived): ?>
                    <div class="archived-info-bar">
                        <div><strong>Archived:</strong> <?php echo safeDateFormat($selected_chat['archived_at'], 'M d, Y H:i'); ?></div>
                        <div><strong>Last Activity:</strong> <?php echo safeDateFormat($selected_chat['last_activity'], 'M d, Y H:i'); ?></div>
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
                            <div class="message-time"><?php echo date('H:i', strtotime($msg['created_at']->format('Y-m-d H:i:s'))); ?></div>
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
                        
                        <?php if ($is_admin): ?>
                        <div class="chat-actions">
                            <form method="POST" style="margin-top: 10px;">
                                <input type="hidden" name="chat_id" value="<?php echo $selected_chat_id; ?>">
                                <button type="submit" name="archive_chat" class="archive-btn" onclick="return confirm('Are you sure you want to archive this chat? It will be moved to archived conversations.');">📁 Archive This Chat</button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="chat-input-area" style="background-color: #f1f5f9; border-top: 1px solid #cbd5e0;">
                        <div style="text-align: center; padding: 15px; color: #64748b; font-style: italic;">This chat is archived and cannot be modified.</div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-chat-selected">
                    <div>
                        <h3>Select a chat to start messaging</h3>
                        <p>Choose from your existing chats or start a new one</p>
                        <?php if(!$is_admin): ?><p>You can chat with any available admin</p><?php endif; ?>
                        
                        <?php if ($view_archived): ?>
                            <div style="margin-top: 20px; padding: 15px; background: #f7fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                                <h4>📁 Archived Chats</h4>
                                <p style="font-size: 14px; color: #4a5568;">Archived chats appear here. Select a chat to view its messages.</p>
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
    'use strict';
    console.log('AdminChat Full AJAX Loaded');

    // ============ CONFIGURATION ============
    const chatId = <?php echo json_encode($selected_chat_id ?? 0); ?>;
    const userId = <?php echo json_encode($user_id); ?>;
    const isAdmin = <?php echo json_encode($is_admin); ?>;
    const viewArchived = <?php echo json_encode($view_archived); ?>;
    const POLL_DELAY = 3000; // 3 seconds
    
    // ============ STATE ============
    let lastMessageId = 0;
    let pollInterval = null;
    let isSending = false;
    
    // ============ INITIALIZATION ============
    function initialize() {
        // Add data-message-id to existing messages
        document.querySelectorAll('.message').forEach((msg, idx) => {
            if (!msg.hasAttribute('data-message-id')) {
                msg.setAttribute('data-message-id', idx + 1);
            }
        });
        
        lastMessageId = getLastMessageId();
        console.log('Initial lastMessageId:', lastMessageId);
        
        // Scroll to bottom
        const chatMessages = document.getElementById('chat-messages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        
        // Start polling if we have an active chat
        if (chatId && !viewArchived) {
            startPolling();
        }
    }
    
    // ============ MESSAGE POLLING ============
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
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages && data.messages.length > 0) {
                    appendMessages(data.messages);
                    lastMessageId = data.last_message_id;
                    
                    // Update unread badge
                    if (data.unread_count > 0) {
                        const badge = document.querySelector('.nav-notification-badge');
                        if (badge) badge.textContent = data.unread_count;
                    }
                }
            })
            .catch(error => console.error('Polling error:', error));
    }
    
    // ============ APPEND MESSAGES ============
    function appendMessages(messages) {
        const chatMessages = document.getElementById('chat-messages');
        if (!chatMessages) return;
        
        const wasAtBottom = isScrolledToBottom();
        let newMessagesAdded = false;
        
        messages.forEach(msg => {
            // Skip if message already exists
            if (document.querySelector(`.message[data-message-id="${msg.message_id}"]`)) {
                return;
            }
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${msg.is_sent ? 'sent' : 'received'}`;
            messageDiv.setAttribute('data-message-id', msg.message_id);
            
            messageDiv.innerHTML = `
                <div class="message-sender">${escapeHtml(msg.full_name)}</div>
                <div class="message-bubble">
                    ${escapeHtml(msg.message).replace(/\n/g, '<br>')}
                    <div class="message-time">${formatTime(msg.created_at)}</div>
                </div>
            `;
            
            chatMessages.appendChild(messageDiv);
            newMessagesAdded = true;
        });
        
        if (newMessagesAdded && wasAtBottom) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }
    
    // ============ SEND MESSAGE VIA AJAX ============
    function sendMessage(messageText) {
        if (isSending || !messageText.trim() || !chatId) return false;
        
        isSending = true;
        const sendButton = document.querySelector('.send-btn');
        const originalText = sendButton ? sendButton.textContent : 'Send';
        const textarea = document.querySelector('.chat-input');
        
        // Save the message to restore if needed
        const originalMessage = messageText;
        
        // Update button state
        if (sendButton) {
            sendButton.disabled = true;
            sendButton.textContent = 'Sending...';
        }
        
        // Clear textarea immediately for better UX
        if (textarea) {
            textarea.value = '';
            textarea.style.height = 'auto';
        }
        
        const formData = new FormData();
        formData.append('send_message', '1');
        formData.append('chat_id', chatId);
        formData.append('message', messageText);
        
        return fetch(window.location.pathname, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.message) {
                // Append the sent message
                appendMessages([data.message]);
                lastMessageId = data.message.message_id;
                console.log('✅ Message sent successfully');
                return true;
            } else {
                console.error('Failed to send message:', data.error);
                // Restore the message if failed
                if (textarea) textarea.value = originalMessage;
                alert('Failed to send message. Please try again.');
                return false;
            }
        })
        .catch(error => {
            console.error('Send error:', error);
            // Restore the message if error
            if (textarea) textarea.value = originalMessage;
            alert('Error sending message. Please try again.');
            return false;
        })
        .finally(() => {
            isSending = false;
            if (sendButton) {
                sendButton.disabled = false;
                sendButton.textContent = originalText;
            }
            if (textarea) textarea.focus();
        });
    }
    
    // ============ UTILITY FUNCTIONS ============
    function getLastMessageId() {
        const messages = document.querySelectorAll('.message');
        if (messages.length === 0) return 0;
        const last = messages[messages.length - 1];
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
        const date = new Date(datetime);
        return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }
    
    // ============ EVENT LISTENERS ============
    function setupEventListeners() {
        const chatForm = document.querySelector('.chat-form');
        if (!chatForm || viewArchived) return;
        
        const textarea = chatForm.querySelector('textarea');
        const sendButton = chatForm.querySelector('button[type="submit"]');
        
        if (!textarea || !sendButton) return;
        
        // Prevent default form submission
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            return false;
        });
        
        // Send button click
        sendButton.addEventListener('click', function(e) {
            e.preventDefault();
            if (textarea.value.trim()) {
                sendMessage(textarea.value.trim());
            }
        });
        
        // Enter key
        textarea.addEventListener('keydown', function(e) {
            if ((e.key === 'Enter' || e.which === 13 || e.keyCode === 13) && !e.shiftKey) {
                e.preventDefault();
                if (this.value.trim()) {
                    sendMessage(this.value.trim());
                }
            }
        });
        
        // Auto-resize textarea
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
        
        setTimeout(() => textarea.focus(), 100);
    }
    
    // ============ ACTIVE NAVIGATION LINK ============
    function setActiveNavLink() {
        const currentPage = window.location.pathname.split('/').pop();
        const navLinks = document.querySelectorAll('.nav a');
        navLinks.forEach(link => {
            const linkHref = link.getAttribute('href');
            if (linkHref === currentPage || 
                (currentPage === 'adminchat.php' && linkHref === 'adminchat.php') ||
                (currentPage === '' && linkHref === 'homepage.php')) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    }
    
    // ============ PAGE VISIBILITY API ============
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
        } else {
            if (chatId && !viewArchived && !pollInterval) {
                startPolling();
            }
        }
    });
    
    // ============ CLEANUP ============
    window.addEventListener('beforeunload', function() {
        stopPolling();
    });
    
    // ============ NOTIFICATION PERMISSION ============
    if (Notification && Notification.permission === 'default') {
        Notification.requestPermission();
    }
    
    // ============ START EVERYTHING ============
    initialize();
    setupEventListeners();
    setActiveNavLink();
});
</script>
</body>
</html>