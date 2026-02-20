<?php
require_once 'conn.php';
require_once 'admin_archive_functions.php';

// Define is_ajax at the VERY TOP
$is_ajax = isset($_POST['ajax']) && $_POST['ajax'] === 'true';

$is_admin = isAdmin(); // Add this line

// Handle AJAX requests
if ($is_ajax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Handle message sending
    if (isset($_POST['send_message'])) {
        // Get user_id from session
        $user_id = $_SESSION['user_id'] ?? null;

        if (!$user_id) {
            echo json_encode(['success' => false, 'error' => "You must be logged in to send a message."]);
            exit;
        }

        $message = isset($_POST['message']) ? trim($_POST['message']) : '';
        $chat_id = isset($_POST['chat_id']) ? (int) $_POST['chat_id'] : 0;

        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => "Please enter a message."]);
            exit;
        }

        if (strlen($message) > 1000) {
            echo json_encode(['success' => false, 'error' => "Message is too long (max 1000 characters)."]);
            exit;
        }

        if (!$chat_id) {
            echo json_encode(['success' => false, 'error' => "Chat ID is missing."]);
            exit;
        }

        if (sendAdminMessage($conn, $chat_id, $user_id, $message)) {
            if (function_exists('updateAdminChatActivity')) {
                updateAdminChatActivity($conn, $chat_id);
            }

            // Get the message we just sent
            $new_message_sql = "
                SELECT TOP 1 m.*, u.full_name as sender_name, u.user_id as sender_user_id
                FROM admin_messages m
                JOIN users u ON m.sender_id = u.user_id
                WHERE m.chat_id = ?
                ORDER BY m.created_at DESC
            ";
            $new_message_stmt = sqlsrv_query($conn, $new_message_sql, array($chat_id));
            $new_message = sqlsrv_fetch_array($new_message_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($new_message_stmt);

            echo json_encode([
                'success' => true,
                'message' => 'Message sent!',
                'conversation_id' => $chat_id,
                'new_message' => $new_message
            ]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => "Failed to send message. Please try again."]);
            exit;
        }
    }

    // Archive chat handler (in the AJAX section)
    if (isset($_POST['archive_chat'])) {
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $chat_id = (int) $_POST['chat_id'];
        if (archiveAdminChatImmediately($conn, $chat_id)) {
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to archive chat.']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

// Rest of your code continues...
updateAllUsersActivity($conn);

function getArrayValue($array, $key, $default = '')
{
    return isset($array[$key]) ? $array[$key] : $default;
}

if (!isAdmin()) {
    header('Location: homepage.php');
    exit();
}

date_default_timezone_set('Asia/Manila');

$user_id = $_SESSION['user_id'];
$online_users = getOnlineUsers($conn);
$available_admins = getAdminsWithChatStatus($conn, $user_id);
$admin_chats = getAdminChats($conn, $user_id, true);
$selected_chat_id = isset($_GET['chat_id']) ? (int) $_GET['chat_id'] : null;
$selected_chat = null;
$chat_messages = [];
$error = '';
$success = '';

$admin_notifications_count = 0;
$admin_chat_requests = [];

if ($user_id) {
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

$archived_count_sql = "SELECT COUNT(*) as archive_count FROM admin_chats_archive WHERE admin_id = ?";
$archived_count_params = array($user_id);
$archived_count_stmt = sqlsrv_query($conn, $archived_count_sql, $archived_count_params);

if ($archived_count_stmt && sqlsrv_fetch($archived_count_stmt)) {
    $archive_data = sqlsrv_fetch_array($archived_count_stmt, SQLSRV_FETCH_ASSOC);
    $archived_chats_count = $archive_data['archive_count'] ?? 0;
}
if ($archived_count_stmt)
    sqlsrv_free_stmt($archived_count_stmt);

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

// Handle regular POST requests (non-AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    if (isset($_POST['start_chat'])) {
        $target_user_id = (int) $_POST['user_id'];
        $chat_id = createAdminChatConversation($conn, $target_user_id, $user_id);
        if ($chat_id) {
            header("Location: adminpanel.php?chat_id=$chat_id");
            exit();
        } else {
            $error = "Failed to start chat.";
        }
    }
}

if (isset($_GET['start_chat']) && !$view_archived) {
    $target_user_id = (int) $_GET['start_chat'];
    $chat_id = createAdminChatConversation($conn, $target_user_id, $user_id);
    if ($chat_id) {
        header("Location: adminpanel.php?chat_id=$chat_id");
        exit();
    } else {
        $error = "Failed to start chat.";
    }
}

if ($selected_chat_id && !$view_archived) {
    $chat_messages = getAdminChatMessages($conn, $selected_chat_id);

    foreach ($admin_chats as $chat) {
        if ($chat['chat_id'] == $selected_chat_id) {
            $selected_chat = $chat;
            break;
        }
    }

    if ($selected_chat) {
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
    <title>Operator Panel - Chat System</title>
    <style>
        /* Keep all your existing CSS exactly the same */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
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
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
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
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
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
            background-color: rgba(255, 255, 255, 0.2);
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
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .message-time {
            font-size: 11px;
            opacity: 0.8;
            margin-top: 5px;
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
            position: relative;
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

        .main-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
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
            position: relative;
            cursor: pointer;
            display: inline-block;
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
            position: relative;
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
            position: relative;
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

        @media(max-width: 900px) {
            .container {
                flex-direction: column;
            }

            .sidebar,
            .main-chat {
                width: 100%;
                height: auto;
            }
        }

        .archive-badge {
            background-color: #718096;
            color: white;
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: 5px;
        }

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

        .archived-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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

        .archived-info-bar {
            background: #e8f4fd;
            padding: 10px 15px;
            border-bottom: 1px solid #b6d4fe;
            font-size: 13px;
            color: #084298;
            display: flex;
            justify-content: space-between;
        }

        .chat-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .no-archived-message {
            text-align: center;
            padding: 40px 20px;
            color: #a0aec0;
            font-style: italic;
            background: white;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .admin-notification-container {
            position: relative;
            display: inline-block;
            margin-left: 10px;
        }

        .admin-notification-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #e53e3e, #c53030);
            color: white;
            border: 3px solid white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 20px;
            box-shadow: 0 3px 10px rgba(229, 62, 62, 0.3);
            transition: all 0.3s;
            position: relative;
        }

        .admin-notification-btn:hover {
            background: linear-gradient(135deg, #c53030, #9b2c2c);
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(229, 62, 62, 0.4);
        }

        .notification-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            margin-top: 15px;
            padding: 0;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s;
        }

        .admin-notification-container:hover .notification-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notification-header {
            padding: 15px;
            background: #2b6cb0;
            color: white;
            border-radius: 8px 8px 0 0;
        }

        .notification-header h4 {
            margin: 0;
            color: white;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notification-list {
            max-height: 400px;
            overflow-y: auto;
            padding: 10px;
        }

        .notification-item {
            display: flex;
            align-items: center;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 8px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            text-decoration: none;
            color: inherit;
            position: relative;
        }

        .notification-item:hover {
            background-color: #f7fafc;
            border-color: #cbd5e0;
        }

        .notification-avatar {
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
            font-size: 16px;
        }

        .notification-info {
            flex: 1;
        }

        .notification-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 3px;
            font-size: 14px;
        }

        .notification-meta {
            font-size: 12px;
            color: #718096;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notification-time {
            color: #a0aec0;
        }

        .message-count-badge {
            background-color: #e53e3e;
            color: white;
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
            font-weight: bold;
        }

        .no-notifications {
            text-align: center;
            padding: 30px 20px;
            color: #a0aec0;
            font-style: italic;
        }

        .notification-footer {
            padding: 12px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
        }

        .view-all-btn {
            display: inline-block;
            padding: 8px 16px;
            background-color: #2b6cb0;
            color: white;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: background-color 0.2s;
        }

        .view-all-btn:hover {
            background-color: #1f4f8b;
        }

        .notification-bell-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #e53e3e;
            color: white;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 10px;
            min-width: 24px;
            text-align: center;
            font-weight: bold;
            border: 2px solid white;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
            }
        }

        .notification-indicator {
            position: relative;
        }

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

        .hierarchy-tooltip {
            position: absolute;
            background: #2d3748;
            color: white;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            width: 300px;
            z-index: 9999;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
            top: 100%;
            left: 0;
            margin-top: 5px;
            border: 1px solid #4a5568;
        }

        .chat-item:hover .hierarchy-tooltip,
        .user-item:hover .hierarchy-tooltip,
        .admin-item:hover .hierarchy-tooltip,
        .message-sender:hover .hierarchy-tooltip,
        .notification-item:hover .hierarchy-tooltip {
            opacity: 1;
            visibility: visible;
        }

        .hierarchy-tooltip h4 {
            color: white;
            margin: 0 0 8px 0;
            font-size: 13px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 5px;
        }

        .hierarchy-info {
            margin: 5px 0;
        }

        .hierarchy-row {
            display: flex;
            margin: 3px 0;
            align-items: flex-start;
        }

        .hierarchy-label {
            width: 90px;
            color: #a0aec0;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }

        .hierarchy-value {
            flex: 1;
            color: white;
            font-weight: 500;
            font-size: 11px;
            line-height: 1.3;
        }

        .hierarchy-divider {
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
            margin: 5px 0;
        }

        .chat-item,
        .user-item,
        .admin-item,
        .notification-item,
        .message-sender {
            position: relative;
        }

        .user-item {
            cursor: pointer;
            transition: all 0.2s;
        }

        .user-item:hover {
            background-color: #f0f8ff;
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        /* New styles for real-time notifications */
        .chat-toast-container {
            position: fixed;
            top: 120px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 350px;
        }

        .chat-toast {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            border-left: 4px solid #2b6cb0;
            animation: slideIn 0.3s ease-out;
            cursor: pointer;
            border: 1px solid #e2e8f0;
            transition: transform 0.2s;
        }

        .chat-toast:hover {
            transform: translateX(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        }

        .loading-spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 3px solid #e2e8f0;
            border-top: 3px solid #2b6cb0;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-top: 20px;
        }

        /* Unread indicator styles */
        .unread-dot {
            animation: pulse 1.5s infinite;
            z-index: 10;
        }

        .unread-badge {
            display: inline-block;
            background-color: #e53e3e;
            color: white;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
            animation: pulse 1.5s infinite;
        }

        .chat-item .chat-preview {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        @keyframes pulse {
            0% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: 0.8;
                transform: scale(1.1);
            }

            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        .chat-avatar {
            position: relative;
        }

        /* New message indicator in preview */
        .chat-preview .new-message-indicator {
            color: #e53e3e;
            font-size: 12px;
            margin-right: 4px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        @keyframes highlightNew {
            0% {
                background-color: #ebf8ff;
                transform: translateY(-2px);
            }

            50% {
                background-color: #d4e6ff;
                transform: translateY(-1px);
            }

            100% {
                background-color: transparent;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .new-chat {
            border-left: 3px solid #2b6cb0 !important;
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
                    <li><a href="adminpanel.php" class="notification-indicator">Operator Panel
                            <?php if ($admin_notifications_count > 0): ?><span
                                    class="nav-notification-badge"><?php echo $admin_notifications_count; ?></span><?php endif; ?></a>
                    </li>
                <?php else: ?>
                    <li><a href="adminchat.php">Chat with Admin</a></li>
                <?php endif; ?>
                <li><a href="profilepage.php">Profile</a></li>
                <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
            <?php else: ?>
                <li><a href="login.php">Login</a></li>
            <?php endif; ?>
        </ul>

        <?php if ($user_id): ?>
            <div class="admin-notification-container">
                <button class="admin-notification-btn"
                    id="adminNotificationBtn">🔔<?php if ($admin_notifications_count > 0): ?><span
                            class="notification-bell-badge"><?php echo $admin_notifications_count; ?></span><?php endif; ?></button>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <h4>📨 New Chat Requests (<?php echo $admin_notifications_count; ?>)</h4>
                    </div>
                    <div class="notification-list">
                        <?php if (!empty($admin_chat_requests)): ?>
                            <?php foreach ($admin_chat_requests as $request):
                                $chat_id = getAdminChatWithUser($conn, $user_id, $request['user_id']);
                                $chat_link = $chat_id ? "adminpanel.php?chat_id=$chat_id" : "adminpanel.php?start_chat=" . $request['user_id'];
                                $user_hierarchy = getUserHierarchyInfo($conn, $request['user_id']);
                                ?>
                                <a href="<?php echo $chat_link; ?>" class="notification-item">
                                    <div class="notification-avatar"><?php echo strtoupper(substr($request['full_name'], 0, 1)); ?>
                                    </div>
                                    <div class="notification-info">
                                        <div class="notification-name"><?php echo htmlspecialchars($request['full_name']); ?></div>
                                        <div class="notification-meta">
                                            <span
                                                class="notification-time"><?php echo time_ago($request['first_message_time']); ?></span>
                                            <?php if ($request['message_count'] > 1): ?><span
                                                    class="message-count-badge"><?php echo $request['message_count']; ?> messages</span>
                                            <?php else: ?><span class="message-count-badge">New message</span><?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="hierarchy-tooltip">
                                        <h4><?php echo htmlspecialchars($user_hierarchy['full_name']); ?></h4>
                                        <div class="hierarchy-info">
                                            <div class="hierarchy-row">
                                                <div class="hierarchy-label">Email:</div>
                                                <div class="hierarchy-value">
                                                    <?php echo htmlspecialchars($user_hierarchy['email'] ?? 'N/A'); ?>
                                                </div>
                                            </div>
                                            <div class="hierarchy-row">
                                                <div class="hierarchy-label">Role:</div>
                                                <div class="hierarchy-value">
                                                    <?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?>
                                                    <?php if ($user_hierarchy['is_head']): ?><span
                                                            style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span><?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($user_hierarchy['current_unit']): ?>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Current Unit:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($user_hierarchy['current_unit']); ?>
                                                        <?php if ($user_hierarchy['unit_type']): ?><span
                                                                style="color: #a0aec0; font-size: 10px;">(<?php echo $user_hierarchy['unit_type']; ?>)</span><?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($user_hierarchy['is_head'] && !empty($user_hierarchy['heads_contacts'])): ?>
                                                <div class="hierarchy-divider"></div>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Heads:</div>
                                                    <div class="hierarchy-value"><?php
                                                    $head_units = [];
                                                    foreach ($user_hierarchy['heads_contacts'] as $contact) {
                                                        $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                                        $head_units[] = $unit_name . ' (' . $contact['unit_type'] . ')';
                                                    }
                                                    echo htmlspecialchars(implode(', ', array_slice($head_units, 0, 3)));
                                                    if (count($head_units) > 3) {
                                                        echo ' +' . (count($head_units) - 3) . ' more';
                                                    } ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($user_hierarchy['division']): ?>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Division:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($user_hierarchy['division']); ?>
                                                    </div>
                                                </div><?php endif; ?>
                                            <?php if ($user_hierarchy['department']): ?>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Department:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($user_hierarchy['department']); ?>
                                                    </div>
                                                </div><?php endif; ?>
                                            <?php if ($user_hierarchy['unit']): ?>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Unit:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($user_hierarchy['unit']); ?>
                                                    </div>
                                                </div><?php endif; ?>
                                            <?php if ($user_hierarchy['office']): ?>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Office:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($user_hierarchy['office']); ?>
                                                    </div>
                                                </div><?php endif; ?>
                                            <?php if ($user_hierarchy['head_info'] && isset($user_hierarchy['head_info']['head_name']) && !$user_hierarchy['is_head']): ?>
                                                <div class="hierarchy-divider"></div>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Head:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($user_hierarchy['head_info']['head_name']); ?>
                                                    </div>
                                                </div>
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
                <h3>Online Users (<?php echo count($online_users); ?>)</h3>
                <div class="online-users-list">
                    <?php if (empty($online_users)): ?>
                        <div style="text-align: center; color: #a0aec0; padding: 20px;">No users online</div>
                    <?php else: ?>
                        <?php
                        $online_count = 0;
                        foreach ($online_users as $user):
                            if ($user['user_id'] == $user_id)
                                continue; // Skip current user
                            $online_count++;
                            $user_hierarchy = getUserHierarchyInfo($conn, $user['user_id']);
                            ?>
                            <div class="user-item" data-user-id="<?php echo $user['user_id']; ?>" style="cursor: pointer;">
                                <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                                <div class="user-info">
                                    <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                    <span class="user-role"><?php echo htmlspecialchars($user['role_name'] ?? 'User'); ?></span>
                                </div>
                                <div class="online-indicator"></div>

                                <!-- HIERARCHY TOOLTIP -->
                                <div class="hierarchy-tooltip">
                                    <h4><?php echo htmlspecialchars($user_hierarchy['full_name'] ?? $user['full_name']); ?></h4>
                                    <div class="hierarchy-info">
                                        <div class="hierarchy-row">
                                            <div class="hierarchy-label">Email:</div>
                                            <div class="hierarchy-value">
                                                <?php echo htmlspecialchars($user_hierarchy['email'] ?? 'N/A'); ?>
                                            </div>
                                        </div>
                                        <div class="hierarchy-row">
                                            <div class="hierarchy-label">Role:</div>
                                            <div class="hierarchy-value">
                                                <?php echo htmlspecialchars($user_hierarchy['role_name'] ?? $user['role_name'] ?? 'User'); ?>
                                                <?php if (!empty($user_hierarchy['is_head'])): ?>
                                                    <span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($user_hierarchy['division'])): ?>
                                            <div class="hierarchy-row">
                                                <div class="hierarchy-label">Division:</div>
                                                <div class="hierarchy-value">
                                                    <?php echo htmlspecialchars($user_hierarchy['division']); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($user_hierarchy['department'])): ?>
                                            <div class="hierarchy-row">
                                                <div class="hierarchy-label">Department:</div>
                                                <div class="hierarchy-value">
                                                    <?php echo htmlspecialchars($user_hierarchy['department']); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($user_hierarchy['unit'])): ?>
                                            <div class="hierarchy-row">
                                                <div class="hierarchy-label">Unit:</div>
                                                <div class="hierarchy-value">
                                                    <?php echo htmlspecialchars($user_hierarchy['unit']); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($user_hierarchy['office'])): ?>
                                            <div class="hierarchy-row">
                                                <div class="hierarchy-label">Office:</div>
                                                <div class="hierarchy-value">
                                                    <?php echo htmlspecialchars($user_hierarchy['office']); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($user_hierarchy['current_unit'])): ?>
                                            <div class="hierarchy-row">
                                                <div class="hierarchy-label">Current Unit:</div>
                                                <div class="hierarchy-value">
                                                    <?php echo htmlspecialchars($user_hierarchy['current_unit']); ?>
                                                    <?php if (!empty($user_hierarchy['unit_type'])): ?>
                                                        <span
                                                            style="color: #a0aec0; font-size: 10px;">(<?php echo $user_hierarchy['unit_type']; ?>)</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($user_hierarchy['head_info']) && !empty($user_hierarchy['head_info']['head_name']) && empty($user_hierarchy['is_head'])): ?>
                                            <div class="hierarchy-divider"></div>
                                            <div class="hierarchy-row">
                                                <div class="hierarchy-label">Reports to:</div>
                                                <div class="hierarchy-value">
                                                    <?php echo htmlspecialchars($user_hierarchy['head_info']['head_name']); ?>
                                                    <?php if (!empty($user_hierarchy['head_info']['head_email'])): ?>
                                                        <br><small><?php echo htmlspecialchars($user_hierarchy['head_info']['head_email']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($user_hierarchy['is_head']) && !empty($user_hierarchy['heads_contacts'])): ?>
                                            <div class="hierarchy-divider"></div>
                                            <div class="hierarchy-row">
                                                <div class="hierarchy-label">Heads:</div>
                                                <div class="hierarchy-value">
                                                    <?php
                                                    $head_units = [];
                                                    foreach ($user_hierarchy['heads_contacts'] as $contact) {
                                                        $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                                        $head_units[] = $unit_name . ' (' . ($contact['unit_type'] ?? 'Unknown') . ')';
                                                    }
                                                    echo htmlspecialchars(implode(', ', array_slice($head_units, 0, 3)));
                                                    if (count($head_units) > 3) {
                                                        echo ' +' . (count($head_units) - 3) . ' more';
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($online_count == 0): ?>
                            <div style="text-align: center; color: #a0aec0; padding: 20px;">No other users online</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <h3>Your Chats (<span id="chat-count"><?php echo count($admin_chats); ?></span>)</h3>
                <div class="chat-list" id="chat-list">
                    <?php if (!$view_archived): ?>
                        <?php foreach ($admin_chats as $chat):
                            $other_user_id = getArrayValue($chat, 'user_id');
                            $user_hierarchy = getUserHierarchyInfo($conn, $other_user_id);
                            ?>
                            <!-- Replace the existing chat-item with this version -->
                            <a href="adminpanel.php?chat_id=<?php echo getArrayValue($chat, 'chat_id'); ?>"
                                class="chat-item <?php echo $selected_chat_id == getArrayValue($chat, 'chat_id') ? 'active' : ''; ?>"
                                data-chat-id="<?php echo getArrayValue($chat, 'chat_id'); ?>">
                                <div class="chat-avatar" style="position: relative;">
                                    <?php echo strtoupper(substr(getArrayValue($chat, 'full_name', ''), 0, 1)); ?>
                                    <span class="unread-dot"
                                        style="display: none; position: absolute; top: 0; right: 0; width: 10px; height: 10px; background-color: #e53e3e; border-radius: 50%; border: 2px solid white; animation: pulse 1.5s infinite;"></span>
                                </div>
                                <div class="chat-info">
                                    <div class="chat-name" style="display: flex; align-items: center;">
                                        <?php echo htmlspecialchars(getArrayValue($chat, 'full_name', 'Unknown User')); ?>
                                        <span class="unread-badge"
                                            style="display: none; background-color: #e53e3e; color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: 5px;"></span>
                                    </div>
                                    <div class="chat-preview">
                                        <?php echo htmlspecialchars(substr(getArrayValue($chat, 'last_message', 'No messages yet'), 0, 30)); ?>
                                    </div>
                                </div>
                                <div class="chat-time">
                                    <?php
                                    $last_time = getArrayValue($chat, 'last_message_time');
                                    if ($last_time) {
                                        if ($last_time instanceof DateTime) {
                                            echo $last_time->format('H:i');
                                        } else {
                                            echo date('H:i', strtotime($last_time));
                                        }
                                    }
                                    ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($archived_chats as $chat):
                            $other_user_id = getArrayValue($chat, 'user_id');
                            $user_hierarchy = getUserHierarchyInfo($conn, $other_user_id);
                            ?>
                            <a href="adminpanel.php?view=archived&chat_id=<?php echo getArrayValue($chat, 'chat_id'); ?>"
                                class="chat-item <?php echo $selected_chat_id == getArrayValue($chat, 'chat_id') ? 'active' : ''; ?>">
                                <div class="chat-avatar" style="background-color: #718096;">
                                    <?php echo strtoupper(substr(getArrayValue($chat, 'full_name', ''), 0, 1)); ?>
                                </div>
                                <div class="chat-info">
                                    <div class="chat-name">
                                        <?php echo htmlspecialchars(getArrayValue($chat, 'full_name', 'Unknown User')); ?> <span
                                            class="archive-badge">Archived</span>
                                    </div>
                                    <div class="chat-preview">
                                        <?php echo htmlspecialchars(substr(getArrayValue($chat, 'last_message', 'No messages'), 0, 30)); ?>
                                    </div>
                                </div>
                                <div class="chat-time"><?php echo safeDateFormat(getArrayValue($chat, 'archived_at'), 'M d'); ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="main-chat">
                <div class="view-toggle">
                    <a href="adminpanel.php"
                        class="view-toggle-btn <?php echo !$view_archived ? 'active' : ''; ?>">Active Chats</a>
                    <a href="adminpanel.php?view=archived"
                        class="view-toggle-btn <?php echo $view_archived ? 'active' : ''; ?>">Archived
                        Chats<?php if ($archived_chats_count > 0): ?><span
                                class="archive-count-badge"><?php echo $archived_chats_count; ?></span><?php endif; ?></a>
                </div>

                <?php if ($archived_this_load > 0 || $removed_this_load > 0): ?>
                    <div class="auto-process-notice">
                        <?php if ($archived_this_load > 0): ?><span>📁 Auto-archived <?php echo $archived_this_load; ?>
                                inactive chat(s)</span><?php endif; ?>
                        <?php if ($removed_this_load > 0): ?><span>🗑️ Cleaned up <?php echo $removed_this_load; ?> old
                                archived chat(s)</span><?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="error-message"><?php echo $error; ?></div><?php endif; ?>
                <?php if ($success): ?>
                    <div class="success-message"><?php echo $success; ?></div><?php endif; ?>

                <?php if ($selected_chat): ?>
                    <div class="chat-header <?php echo $view_archived ? 'archived-chat' : ''; ?>"
                        style="<?php echo $view_archived ? 'background: linear-gradient(135deg, #718096 0%, #4a5568 100%);' : ''; ?>">
                        <h3><?php if ($view_archived): ?>📁 Archived Chat with
                                <?php echo htmlspecialchars($selected_chat['other_full_name']); ?>     <?php else: ?>Chat with
                                <?php echo htmlspecialchars($selected_chat['full_name']); ?>     <?php endif; ?>
                        </h3>
                    </div>

                    <?php if ($view_archived): ?>
                        <div class="archived-info-bar">
                            <div><strong>Archived:</strong>
                                <?php echo safeDateFormat($selected_chat['archived_at'] ?? '', 'M d, Y H:i'); ?></div>
                            <div><strong>Last Activity:</strong>
                                <?php echo safeDateFormat($selected_chat['last_activity'] ?? '', 'M d, Y H:i'); ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="chat-messages <?php echo $view_archived ? 'archived-chat' : ''; ?>" id="chat-messages">
                        <?php foreach ($chat_messages as $msg):
                            $is_sent = ($msg['sender_id'] == $user_id);
                            $sender_hierarchy = getUserHierarchyInfo($conn, $msg['sender_id']);
                            ?>
                            <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>"
                                data-message-id="<?php echo $msg['message_id']; ?>">
                                <div class="message-sender">
                                    <?php
                                    $sender_name = $msg['full_name'] ?? $msg['sender_name'] ?? 'Unknown User';
                                    echo htmlspecialchars($sender_name);
                                    ?>
                                    <div class="hierarchy-tooltip">
                                        <h4><?php echo htmlspecialchars($sender_hierarchy['full_name'] ?? $sender_name); ?></h4>
                                        <div class="hierarchy-info">
                                            <div class="hierarchy-row">
                                                <div class="hierarchy-label">Email:</div>
                                                <div class="hierarchy-value">
                                                    <?php echo htmlspecialchars($sender_hierarchy['email'] ?? 'N/A'); ?>
                                                </div>
                                            </div>
                                            <div class="hierarchy-row">
                                                <div class="hierarchy-label">Role:</div>
                                                <div class="hierarchy-value">
                                                    <?php echo htmlspecialchars($sender_hierarchy['role_name'] ?? 'N/A'); ?>
                                                    <?php if ($sender_hierarchy['is_head'] ?? false): ?><span
                                                            style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span><?php endif; ?>
                                                </div>
                                            </div>
                                            <?php if ($sender_hierarchy['current_unit'] ?? false): ?>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Current Unit:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($sender_hierarchy['current_unit']); ?>
                                                        <?php if ($sender_hierarchy['unit_type'] ?? false): ?><span
                                                                style="color: #a0aec0; font-size: 10px;">(<?php echo $sender_hierarchy['unit_type']; ?>)</span><?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (($sender_hierarchy['is_head'] ?? false) && !empty($sender_hierarchy['heads_contacts'] ?? [])): ?>
                                                <div class="hierarchy-divider"></div>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Heads:</div>
                                                    <div class="hierarchy-value"><?php
                                                    $head_units = [];
                                                    foreach ($sender_hierarchy['heads_contacts'] as $contact) {
                                                        $unit_name = $contact['unit_name'] ?? $contact['division_name'] ?? $contact['department_name'] ?? $contact['office_name'] ?? 'Unit';
                                                        $head_units[] = $unit_name . ' (' . ($contact['unit_type'] ?? 'Unknown') . ')';
                                                    }
                                                    echo htmlspecialchars(implode(', ', array_slice($head_units, 0, 3)));
                                                    if (count($head_units) > 3) {
                                                        echo ' +' . (count($head_units) - 3) . ' more';
                                                    } ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($sender_hierarchy['division'] ?? false): ?>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Division:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($sender_hierarchy['division']); ?>
                                                    </div>
                                                </div><?php endif; ?>
                                            <?php if ($sender_hierarchy['department'] ?? false): ?>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Department:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($sender_hierarchy['department']); ?>
                                                    </div>
                                                </div><?php endif; ?>
                                            <?php if ($sender_hierarchy['unit'] ?? false): ?>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Unit:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($sender_hierarchy['unit']); ?>
                                                    </div>
                                                </div><?php endif; ?>
                                            <?php if ($sender_hierarchy['office'] ?? false): ?>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Office:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($sender_hierarchy['office']); ?>
                                                    </div>
                                                </div><?php endif; ?>
                                            <?php if (($sender_hierarchy['head_info'] ?? false) && isset($sender_hierarchy['head_info']['head_name']) && !($sender_hierarchy['is_head'] ?? false)): ?>
                                                <div class="hierarchy-divider"></div>
                                                <div class="hierarchy-row">
                                                    <div class="hierarchy-label">Head:</div>
                                                    <div class="hierarchy-value">
                                                        <?php echo htmlspecialchars($sender_hierarchy['head_info']['head_name']); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="message-bubble">
                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                    <div class="message-time">
                                        <?php
                                        if ($msg['created_at'] instanceof DateTime) {
                                            echo $msg['created_at']->format('H:i');
                                        } else {
                                            echo date('H:i', strtotime($msg['created_at']));
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($chat_messages)): ?>
                            <div style="text-align: center; color: #a0aec0; font-style: italic; padding: 40px;">No messages
                                found.</div>
                        <?php endif; ?>
                    </div>

                    <?php if (!$view_archived): ?>
                        <div class="chat-input-area">
                            <form method="POST" class="chat-form" id="chatForm">
                                <input type="hidden" name="chat_id" value="<?php echo $selected_chat_id; ?>">
                                <input type="hidden" name="ajax" value="true">
                                <textarea name="message" class="chat-input" placeholder="Type your message here..."
                                    required></textarea>
                                <button type="submit" name="send_message" class="send-btn">Send</button>
                            </form>
                            <div class="chat-actions">
                                <form method="POST" style="margin-top: 10px;" id="archiveForm">
                                    <input type="hidden" name="chat_id" value="<?php echo $selected_chat_id; ?>">
                                    <input type="hidden" name="ajax" value="true">
                                    <input type="hidden" name="archive_chat" value="1">
                                    <button type="submit" class="archive-btn">📁 Archive This Chat</button>
                                </form>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="chat-input-area" style="background-color: #f1f5f9; border-top: 1px solid #cbd5e0;">
                            <div style="text-align: center; padding: 15px; color: #64748b; font-style: italic;"><i
                                    class="fas fa-lock"></i> This chat is archived and cannot be modified.</div>
                        </div>
                    <?php endif; ?>
                <?php elseif ($view_archived && empty($selected_chat_id)): ?>
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
                                <?php foreach ($archived_chats as $chat):
                                    $other_user_id = $chat['user_id'];
                                    $user_hierarchy = getUserHierarchyInfo($conn, $other_user_id);

                                    $msg_count_sql = "SELECT COUNT(*) as msg_count FROM admin_messages_archive WHERE chat_id = ?";
                                    $msg_count_params = array(getArrayValue($chat, 'chat_id'));
                                    $msg_count_stmt = sqlsrv_query($conn, $msg_count_sql, $msg_count_params);
                                    $msg_count = 0;

                                    if ($msg_count_stmt && sqlsrv_fetch($msg_count_stmt)) {
                                        $msg_data = sqlsrv_get_field($msg_count_stmt, 0);
                                        $msg_count = $msg_data ?: 0;
                                    }
                                    if ($msg_count_stmt)
                                        sqlsrv_free_stmt($msg_count_stmt);
                                    ?>
                                    <tr>
                                        <td style="font-weight: 500; color: #2d3748;">
                                            <?php echo htmlspecialchars($chat['full_name']); ?><br><small
                                                style="color: #718096; font-size: 11px;"><?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?><?php if ($user_hierarchy['current_unit'] ?? ''): ?>
                                                    •
                                                    <?php echo htmlspecialchars($user_hierarchy['current_unit']); ?>
                                                <?php endif; ?></small>
                                        </td>
                                        <td style="color: #718096; font-size: 13px;">
                                            <?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages', 0, 50)); ?>
                                        </td>
                                        <td><span
                                                style="background-color: #e2e8f0; color: #4a5568; padding: 2px 8px; border-radius: 12px; font-size: 12px;"><?php echo $msg_count; ?>
                                                messages</span></td>
                                        <td style="color: #718096; font-size: 13px;">
                                            <?php echo safeDateFormat(getArrayValue($chat, 'archived_at'), 'M d, Y H:i'); ?>
                                        </td>
                                        <td><a href="adminpanel.php?view=archived&chat_id=<?php echo $chat['chat_id']; ?>"
                                                class="view-btn">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-archived-message">No archived chats found. Archived chats will appear here after being
                            inactive for 5+ minutes.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-chat-selected">
                        <div>
                            <h3>Welcome to Operator Chat</h3>
                            <div
                                style="margin-top: 20px; padding: 15px; background: #f7fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                                <h4>Archive System</h4>
                                <p style="font-size: 14px; color: #4a5568;">• Chats are auto-archived after 30 minutes of
                                    inactivity<br>• Archived chats are cleaned up after 7 days<br>• You can manually archive
                                    chats using the "Archive" button<br>• View archived chats using the toggle above</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let userId = <?php echo $user_id; ?>;
        let isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;

        // ===========================================
        // GLOBAL UTILITY FUNCTIONS
        // ===========================================

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatTime(datetime) {
            if (!datetime) return '';
            try {
                const date = new Date(datetime);
                return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            } catch (e) {
                return '';
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            // ===========================================
            // FIXED: Online Users Click Handler - Goes to existing chat
            // ===========================================

            // Make online users clickable - checks for existing chat first
            document.querySelectorAll('.user-item').forEach(item => {
                item.addEventListener('click', function (e) {
                    e.preventDefault();
                    const userId = this.dataset.userId;
                    if (userId) {
                        // Check if there's an existing chat with this user
                        const existingChat = findExistingChatForUser(userId);

                        if (existingChat) {
                            // Go to existing chat
                            window.location.href = `adminpanel.php?chat_id=${existingChat}`;
                        } else {
                            // Start new chat
                            window.location.href = `adminpanel.php?start_chat=${userId}`;
                        }
                    }
                });
            });

            // Function to find existing chat ID for a user
            function findExistingChatForUser(userId) {
                const chatItems = document.querySelectorAll('.chat-item');

                for (let item of chatItems) {
                    const href = item.getAttribute('href');
                    if (href && href.includes('chat_id=')) {
                        // Get the chat name from the item
                        const chatName = item.querySelector('.chat-name')?.textContent || '';

                        // Get the user name from the online users list
                        const userItem = document.querySelector(`.user-item[data-user-id="${userId}"]`);
                        if (userItem) {
                            const userName = userItem.querySelector('.user-name')?.textContent || '';
                            // If the chat name matches the user name, this is the chat
                            if (chatName.includes(userName)) {
                                const match = href.match(/chat_id=(\d+)/);
                                return match ? match[1] : null;
                            }
                        }
                    }
                }
                return null;
            }

            const chatMessages = document.getElementById('chat-messages');
            if (chatMessages) chatMessages.scrollTop = chatMessages.scrollHeight;

            let lastMessageId = 0;

            // Get initial last message ID
            const messages = document.querySelectorAll('.message');
            if (messages.length > 0) {
                const lastMessage = messages[messages.length - 1];
                lastMessageId = lastMessage.dataset.messageId || 0;
            }

            // ===========================================
            // SEND MESSAGES TO send_admin_messages.php
            // ===========================================

            const chatForm = document.getElementById('chatForm');
            if (chatForm) {
                chatForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    const formData = new FormData();
                    formData.append('chat_id', this.querySelector('input[name="chat_id"]').value);
                    formData.append('message', this.querySelector('textarea').value.trim());

                    const sendBtn = this.querySelector('button[type="submit"]');
                    const originalHtml = sendBtn.innerHTML;
                    const textarea = this.querySelector('textarea');
                    const messageText = textarea.value.trim();

                    if (messageText === '') return;

                    sendBtn.disabled = true;
                    sendBtn.innerHTML = '⏳';

                    fetch('send_admin_messages.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                textarea.value = '';

                                if (data.message) {
                                    addMessageToChat(data.message, true);
                                    if (data.message.message_id) {
                                        lastMessageId = data.message.message_id;
                                    }
                                }

                                sendBtn.style.backgroundColor = '#38a169';
                                setTimeout(() => sendBtn.style.backgroundColor = '', 200);
                            } else {
                                console.error('Send error:', data.error);
                                sendBtn.style.backgroundColor = '#e53e3e';
                                setTimeout(() => sendBtn.style.backgroundColor = '', 200);
                            }

                            sendBtn.disabled = false;
                            sendBtn.innerHTML = originalHtml;
                        })
                        .catch(error => {
                            console.error('Fetch error:', error);
                            sendBtn.disabled = false;
                            sendBtn.innerHTML = originalHtml;
                            sendBtn.style.backgroundColor = '#e53e3e';
                            setTimeout(() => sendBtn.style.backgroundColor = '', 200);
                        });
                });
            }

            // ===========================================
            // POLL FOR NEW MESSAGES FROM get_admin_messages.php
            // ===========================================

            function checkNewMessages() {
                const urlParams = new URLSearchParams(window.location.search);
                const currentChatId = urlParams.get('chat_id');

                if (!currentChatId) return;

                fetch(`get_admin_messages.php?chat_id=${currentChatId}&last_message_id=${lastMessageId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.messages && data.messages.length > 0) {
                            const existingMessages = document.querySelectorAll('.message');
                            const existingIds = new Set();
                            existingMessages.forEach(msg => {
                                if (msg.dataset.messageId) {
                                    existingIds.add(parseInt(msg.dataset.messageId));
                                }
                            });

                            let hasNewFromUser = false;
                            data.messages.forEach(msg => {
                                if (!existingIds.has(msg.message_id)) {
                                    addMessageToChat(msg, msg.is_sent);
                                    if (!msg.is_sent) { // Message from user
                                        hasNewFromUser = true;
                                    }
                                }
                            });

                            lastMessageId = data.last_message_id;

                            // If new messages from user arrived, update unread indicators
                            if (hasNewFromUser && isAdmin) {
                                setTimeout(updateAdminUnreadIndicators, 500);
                            }

                            if (data.unread_count !== undefined) {
                                updateUnreadBadge(data.unread_count);
                            }
                        }
                    })
                    .catch(error => console.error('Polling error:', error));
            }

            setInterval(checkNewMessages, 3000);

            // ===========================================
            // ADD MESSAGE TO CHAT
            // ===========================================

            function addMessageToChat(message, isSent) {
                const chatMessages = document.getElementById('chat-messages');
                if (!chatMessages) return;

                const messageDiv = document.createElement('div');
                messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
                if (message.message_id) {
                    messageDiv.dataset.messageId = message.message_id;
                }

                // If admin is sending a message, clear unread indicators for this chat
                if (isAdmin && isSent) {
                    const currentChatId = new URLSearchParams(window.location.search).get('chat_id');
                    if (currentChatId) {
                        const chatItem = document.querySelector(`.chat-item[data-chat-id="${currentChatId}"]`);
                        if (chatItem) {
                            const dot = chatItem.querySelector('.unread-dot');
                            const badge = chatItem.querySelector('.unread-badge');
                            if (dot) dot.style.display = 'none';
                            if (badge) badge.style.display = 'none';

                            const preview = chatItem.querySelector('.chat-preview');
                            if (preview) {
                                preview.innerHTML = preview.innerHTML.replace('🔴 ', '');
                            }
                        }
                    }
                }

                let timeStr = 'Just now';
                if (message.created_at) {
                    try {
                        const date = new Date(message.created_at);
                        if (!isNaN(date.getTime())) {
                            timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                        }
                    } catch (e) { }
                }

                const senderName = message.full_name || (isSent ? 'You' : 'User');

                messageDiv.innerHTML = `
                    <div class="message-sender">${escapeHtml(senderName)}</div>
                    <div class="message-bubble">
                        ${escapeHtml(message.message || '').replace(/\n/g, '<br>')}
                        <div class="message-time">${timeStr}</div>
                    </div>
                `;

                chatMessages.appendChild(messageDiv);
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            function updateUnreadBadge(count) {
                const badge = document.querySelector('.nav-notification-badge');
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            }

            // ===========================================
            // ADMIN UNREAD INDICATORS
            // ===========================================

            function updateAdminUnreadIndicators() {
                // Only for admin users
                if (!isAdmin) return;

                console.log('Updating admin unread indicators...');

                // Update each chat item
                document.querySelectorAll('.chat-item[data-chat-id]').forEach(item => {
                    const chatId = item.getAttribute('data-chat-id');
                    if (!chatId) return;

                    fetch(`check_user_unread.php?chat_id=${chatId}&t=${Date.now()}`)
                        .then(response => {
                            // Check if response is OK
                            if (!response.ok) {
                                throw new Error(`HTTP error! status: ${response.status}`);
                            }
                            return response.text().then(text => {
                                console.log(`Raw response for chat ${chatId}:`, text.substring(0, 200));
                                try {
                                    return JSON.parse(text);
                                } catch (e) {
                                    console.error(`JSON parse error for chat ${chatId}:`, e);
                                    console.error('Full response:', text);
                                    return { success: false, error: 'Invalid JSON' };
                                }
                            });
                        })
                        .then(data => {
                            if (data && data.success) {
                                const avatar = item.querySelector('.chat-avatar');
                                const chatName = item.querySelector('.chat-name');
                                const dotSpan = avatar ? avatar.querySelector('.unread-dot') : null;
                                const badgeSpan = chatName ? chatName.querySelector('.unread-badge') : null;

                                if (data.unread > 0) {
                                    console.log(`Chat ${chatId} has ${data.unread} unread messages from user`);

                                    // Show red dot on avatar
                                    if (avatar) {
                                        if (!dotSpan) {
                                            const newDot = document.createElement('span');
                                            newDot.className = 'unread-dot';
                                            newDot.style.cssText = 'position: absolute; top: 0; right: 0; width: 10px; height: 10px; background-color: #e53e3e; border-radius: 50%; border: 2px solid white; animation: pulse 1.5s infinite;';
                                            avatar.style.position = 'relative';
                                            avatar.appendChild(newDot);
                                        } else {
                                            dotSpan.style.display = 'block';
                                        }
                                    }

                                    // Show count badge
                                    if (chatName) {
                                        if (!badgeSpan) {
                                            const newBadge = document.createElement('span');
                                            newBadge.className = 'unread-badge';
                                            newBadge.style.cssText = 'background-color: #e53e3e; color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px; margin-left: 5px;';
                                            newBadge.textContent = data.unread;
                                            chatName.appendChild(newBadge);
                                        } else {
                                            badgeSpan.textContent = data.unread;
                                            badgeSpan.style.display = 'inline-block';
                                        }
                                    }

                                    // Also update the chat preview to indicate new messages
                                    const preview = item.querySelector('.chat-preview');
                                    if (preview && !preview.innerHTML.includes('🔴')) {
                                        preview.innerHTML = '🔴 ' + preview.innerHTML;
                                    }
                                } else {
                                    // Hide indicators
                                    if (dotSpan) dotSpan.style.display = 'none';
                                    if (badgeSpan) badgeSpan.style.display = 'none';

                                    // Remove red dot from preview
                                    const preview = item.querySelector('.chat-preview');
                                    if (preview) {
                                        preview.innerHTML = preview.innerHTML.replace('🔴 ', '');
                                    }
                                }
                            } else {
                                console.error('Error from server for chat', chatId, ':', data?.error || 'Unknown error');
                            }
                        })
                        .catch(error => {
                            console.error('Error checking unread for chat', chatId, ':', error);
                        });
                });
            }

            // Archive form handler
            const archiveForm = document.getElementById('archiveForm');
            if (archiveForm) {
                archiveForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    if (!confirm('Are you sure you want to archive this chat? It will be moved to archived conversations.')) {
                        return;
                    }

                    const formData = new FormData();
                    formData.append('chat_id', this.querySelector('input[name="chat_id"]').value);
                    formData.append('archive_chat', '1');
                    formData.append('ajax', 'true');

                    const archiveBtn = this.querySelector('.archive-btn');
                    const originalText = archiveBtn.textContent;

                    archiveBtn.disabled = true;
                    archiveBtn.innerHTML = '⏳';

                    fetch('adminpanel.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                window.location.href = 'adminpanel.php';
                            } else {
                                alert(data.error || 'Failed to archive chat');
                                archiveBtn.disabled = false;
                                archiveBtn.innerHTML = originalText;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Failed to archive chat. Please try again.');
                            archiveBtn.disabled = false;
                            archiveBtn.innerHTML = originalText;
                        });
                });
            }

            // Enter key handler
            document.querySelectorAll('.chat-input').forEach(textarea => {
                textarea.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        if (this.value.trim() !== '') {
                            const form = this.closest('form');
                            if (form) {
                                form.requestSubmit();
                            }
                        }
                    }
                });
            });

            // Start unread indicator updates for admin
            if (isAdmin) {
                // Initial update after a short delay
                setTimeout(updateAdminUnreadIndicators, 1000);
                // Then update every 10 seconds
                setInterval(updateAdminUnreadIndicators, 10000);
            }

            // Initial check
            setTimeout(checkNewMessages, 1000);

            // Initialize real-time new chat detection
            initChatMonitoring();
        });

        // ===========================================
        // REAL-TIME NEW CHAT DETECTION
        // ===========================================

        // Store current chat IDs to detect new ones
        let currentChatIds = new Set();
        let chatPollingInterval = null;

        // Initialize chat monitoring
        function initChatMonitoring() {
            // Get initial chat IDs
            updateCurrentChatIds();

            // Start polling for new chats every 5 seconds
            chatPollingInterval = setInterval(checkForNewChats, 5000);

            // Clean up on page unload
            window.addEventListener('beforeunload', function () {
                if (chatPollingInterval) {
                    clearInterval(chatPollingInterval);
                }
            });
        }

        // Update the set of current chat IDs
        function updateCurrentChatIds() {
            currentChatIds.clear();
            document.querySelectorAll('.chat-item[data-chat-id]').forEach(item => {
                const chatId = item.getAttribute('data-chat-id');
                if (chatId) {
                    currentChatIds.add(chatId);
                }
            });
        }

        // Check for new chats
        function checkForNewChats() {
            fetch('ajax_check_new_chats.php?t=' + Date.now())
                .then(response => {
                    return response.text().then(text => {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('JSON Parse Error:', e);
                            return null;
                        }
                    });
                })
                .then(data => {
                    if (data && data.success && data.chats && data.chats.length > 0) {
                        let hasNewChat = false;

                        data.chats.forEach(chat => {
                            if (!currentChatIds.has(chat.chat_id.toString())) {
                                hasNewChat = true;
                                addNewChatToSidebar(chat);
                                currentChatIds.add(chat.chat_id.toString());
                                showNewChatNotification(chat);
                            }
                        });

                        if (hasNewChat) {
                            updateChatsCount();
                        }
                    }
                })
                .catch(error => {
                    console.error('Error checking for new chats:', error);
                });
        }

        // Add new chat to sidebar
        function addNewChatToSidebar(chat) {
            const chatList = document.getElementById('chat-list');
            if (!chatList) return;

            // Check if chat already exists
            if (document.querySelector(`.chat-item[data-chat-id="${chat.chat_id}"]`)) {
                return;
            }

            const chatItem = document.createElement('a');
            chatItem.href = `adminpanel.php?chat_id=${chat.chat_id}`;
            chatItem.className = 'chat-item new-chat';
            chatItem.setAttribute('data-chat-id', chat.chat_id);
            chatItem.style.animation = 'highlightNew 2s ease';

            // Format time
            let timeDisplay = 'Just now';
            if (chat.last_message_time) {
                try {
                    const msgDate = new Date(chat.last_message_time);
                    const now = new Date();
                    const diffMinutes = Math.floor((now - msgDate) / 60000);

                    if (diffMinutes < 1) {
                        timeDisplay = 'Just now';
                    } else if (diffMinutes < 60) {
                        timeDisplay = diffMinutes + ' min ago';
                    } else {
                        timeDisplay = msgDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    }
                } catch (e) {
                    timeDisplay = 'Just now';
                }
            }

            const avatarLetter = chat.full_name ? chat.full_name.charAt(0).toUpperCase() : 'U';

            chatItem.innerHTML = `
                <div class="chat-avatar">${escapeHtml(avatarLetter)}</div>
                <div class="chat-info">
                    <div class="chat-name">${escapeHtml(chat.full_name)}</div>
                    <div class="chat-preview">${escapeHtml(chat.last_message || 'New conversation started')}</div>
                </div>
                <div class="chat-time">${escapeHtml(timeDisplay)}</div>
            `;

            // Add click handler to load chat via AJAX
            chatItem.addEventListener('click', function (e) {
                e.preventDefault();
                loadChatViaAjax(chat.chat_id);
            });

            // Insert at the top of the list
            const firstChat = chatList.firstChild;
            if (firstChat) {
                chatList.insertBefore(chatItem, firstChat);
            } else {
                chatList.appendChild(chatItem);
            }
        }

        // Show notification for new chat
        function showNewChatNotification(chat) {
            let toastContainer = document.getElementById('chat-toast-container');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.id = 'chat-toast-container';
                toastContainer.className = 'chat-toast-container';
                document.body.appendChild(toastContainer);
            }

            // Limit to 3 toasts
            if (toastContainer.children.length >= 3) {
                toastContainer.removeChild(toastContainer.firstChild);
            }

            const toast = document.createElement('div');
            toast.className = 'chat-toast';

            toast.innerHTML = `
                <div style="font-weight: bold; color: #2d3748; margin-bottom: 5px; font-size: 14px;">
                    📨 New Chat Request
                </div>
                <div style="color: #4a5568; font-size: 13px;">
                    <strong>${escapeHtml(chat.full_name)}</strong> wants to chat
                </div>
                <div style="color: #718096; font-size: 12px; margin-top: 8px;">
                    ${escapeHtml(chat.last_message || 'Click to view conversation')}
                </div>
                <div style="font-size: 11px; color: #a0aec0; margin-top: 5px; text-align: right;">
                    Just now
                </div>
            `;

            toast.addEventListener('click', () => {
                loadChatViaAjax(chat.chat_id);
                toast.remove();
            });

            toastContainer.appendChild(toast);

            // Remove after 5 seconds
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.style.animation = 'slideOut 0.3s ease-out';
                    setTimeout(() => {
                        if (toast.parentNode) {
                            toast.remove();
                        }
                    }, 300);
                }
            }, 5000);
        }

        // Update chats count in sidebar header
        function updateChatsCount() {
            const chatCount = document.querySelectorAll('.chat-item').length;
            const chatsHeader = document.querySelector('.sidebar h3');
            if (chatsHeader) {
                chatsHeader.innerHTML = `Your Chats (<span id="chat-count">${chatCount}</span>)`;
            }
        }

        // Load chat via AJAX (without refresh)
        function loadChatViaAjax(chatId) {
            // Show loading
            const mainChat = document.querySelector('.main-chat');
            if (mainChat) {
                mainChat.innerHTML = `
                    <div class="no-chat-selected">
                        <div>
                            <h3>Loading chat...</h3>
                            <div class="loading-spinner"></div>
                        </div>
                    </div>
                `;
            }

            fetch(`ajax_load_chat.php?chat_id=${chatId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update URL
                        const url = new URL(window.location);
                        url.searchParams.set('chat_id', chatId);
                        window.history.pushState({}, '', url);

                        // Update UI
                        updateChatInterface(data);

                        // Update active state
                        document.querySelectorAll('.chat-item').forEach(item => {
                            item.classList.remove('active');
                            if (item.getAttribute('href').includes(`chat_id=${chatId}`)) {
                                item.classList.add('active');
                            }
                        });
                    } else {
                        console.error('Failed to load chat:', data.error);
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error loading chat:', error);
                    location.reload();
                });
        }

        // Update chat interface
        function updateChatInterface(data) {
            const mainChat = document.querySelector('.main-chat');
            if (!mainChat) return;

            let messagesHtml = '';
            if (data.messages && data.messages.length > 0) {
                data.messages.forEach(msg => {
                    const isSent = (msg.sender_id == userId);
                    messagesHtml += `
                        <div class="message ${isSent ? 'sent' : 'received'}" data-message-id="${msg.message_id}">
                            <div class="message-sender">${escapeHtml(msg.full_name)}</div>
                            <div class="message-bubble">
                                ${escapeHtml(msg.message).replace(/\n/g, '<br>')}
                                <div class="message-time">${formatTime(msg.created_at)}</div>
                            </div>
                        </div>
                    `;
                });
            } else {
                messagesHtml = '<div style="text-align: center; color: #a0aec0; font-style: italic; padding: 40px;">No messages yet. Start the conversation!</div>';
            }

            mainChat.innerHTML = `
                <div class="chat-header">
                    <h3>Chat with ${escapeHtml(data.chat.full_name)}</h3>
                </div>
                <div class="chat-messages" id="chat-messages">
                    ${messagesHtml}
                </div>
                <div class="chat-input-area">
                    <form method="POST" class="chat-form" id="chatForm">
                        <input type="hidden" name="chat_id" value="${data.chat_id}">
                        <input type="hidden" name="ajax" value="true">
                        <textarea name="message" class="chat-input" placeholder="Type your message here..." required></textarea>
                        <button type="submit" name="send_message" class="send-btn">Send</button>
                    </form>
                    <div class="chat-actions">
                        <form method="POST" style="margin-top: 10px;" id="archiveForm">
                            <input type="hidden" name="chat_id" value="${data.chat_id}">
                            <input type="hidden" name="ajax" value="true">
                            <input type="hidden" name="archive_chat" value="1">
                            <button type="submit" class="archive-btn">📁 Archive This Chat</button>
                        </form>
                    </div>
                </div>
            `;

            // Re-attach form submit handlers
            attachChatFormHandler();

            // Scroll to bottom
            const chatMessages = document.getElementById('chat-messages');
            if (chatMessages) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            // Reset lastMessageId for new chat
            lastMessageId = 0;
            const messages = document.querySelectorAll('.message');
            if (messages.length > 0) {
                const lastMessage = messages[messages.length - 1];
                lastMessageId = lastMessage.dataset.messageId || 0;
            }
        }

        // Attach chat form handler
        function attachChatFormHandler() {
            const chatForm = document.getElementById('chatForm');
            if (chatForm) {
                // Remove existing listeners by cloning
                const newChatForm = chatForm.cloneNode(true);
                chatForm.parentNode.replaceChild(newChatForm, chatForm);

                newChatForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    const formData = new FormData();
                    formData.append('chat_id', this.querySelector('input[name="chat_id"]').value);
                    formData.append('message', this.querySelector('textarea').value.trim());

                    const sendBtn = this.querySelector('button[type="submit"]');
                    const originalHtml = sendBtn.innerHTML;
                    const textarea = this.querySelector('textarea');
                    const messageText = textarea.value.trim();

                    if (messageText === '') return;

                    sendBtn.disabled = true;
                    sendBtn.innerHTML = '⏳';

                    fetch('send_admin_messages.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                textarea.value = '';

                                if (data.message) {
                                    addMessageToChat(data.message, true);
                                    if (data.message.message_id) {
                                        lastMessageId = data.message.message_id;
                                    }
                                }

                                sendBtn.style.backgroundColor = '#38a169';
                                setTimeout(() => sendBtn.style.backgroundColor = '', 200);
                            } else {
                                console.error('Send error:', data.error);
                                sendBtn.style.backgroundColor = '#e53e3e';
                                setTimeout(() => sendBtn.style.backgroundColor = '', 200);
                            }

                            sendBtn.disabled = false;
                            sendBtn.innerHTML = originalHtml;
                        })
                        .catch(error => {
                            console.error('Fetch error:', error);
                            sendBtn.disabled = false;
                            sendBtn.innerHTML = originalHtml;
                            sendBtn.style.backgroundColor = '#e53e3e';
                            setTimeout(() => sendBtn.style.backgroundColor = '', 200);
                        });
                });
            }

            // Re-attach archive form handler
            const archiveForm = document.getElementById('archiveForm');
            if (archiveForm) {
                const newArchiveForm = archiveForm.cloneNode(true);
                archiveForm.parentNode.replaceChild(newArchiveForm, archiveForm);

                newArchiveForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    if (!confirm('Are you sure you want to archive this chat? It will be moved to archived conversations.')) {
                        return;
                    }

                    const formData = new FormData();
                    formData.append('chat_id', this.querySelector('input[name="chat_id"]').value);
                    formData.append('archive_chat', '1');
                    formData.append('ajax', 'true');

                    const archiveBtn = this.querySelector('.archive-btn');
                    const originalText = archiveBtn.textContent;

                    archiveBtn.disabled = true;
                    archiveBtn.innerHTML = '⏳';

                    fetch('adminpanel.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                window.location.href = 'adminpanel.php';
                            } else {
                                alert(data.error || 'Failed to archive chat');
                                archiveBtn.disabled = false;
                                archiveBtn.innerHTML = originalText;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Failed to archive chat. Please try again.');
                            archiveBtn.disabled = false;
                            archiveBtn.innerHTML = originalText;
                        });
                });
            }
        }
    </script>
</body>

</html>