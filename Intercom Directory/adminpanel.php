<?php
ob_start(); // Add output buffering at the very top
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

// MODIFIED: Get only NON-ADMIN online users (role_id != 1)
$online_users_sql = "SELECT u.user_id, u.full_name, u.email, r.role_name
                     FROM users u
                     LEFT JOIN roles r ON u.role_id = r.role_id
                     WHERE u.is_active = 1 
                     AND u.role_id != 1  -- Exclude admins
                     AND u.user_id != ?   -- Exclude current user
                     ORDER BY u.full_name";
$online_users_params = array($user_id);
$online_users_stmt = sqlsrv_query($conn, $online_users_sql, $online_users_params);
$online_users = [];
if ($online_users_stmt) {
    while ($row = sqlsrv_fetch_array($online_users_stmt, SQLSRV_FETCH_ASSOC)) {
        $online_users[] = $row;
    }
    sqlsrv_free_stmt($online_users_stmt);
}

// REMOVED: No more admin list
$available_admins = []; // Empty array

// MODIFIED: Get only chats with NON-ADMIN users
$admin_chats_sql = "SELECT 
                        ac.chat_id,
                        ac.admin_id,
                        ac.user_id,
                        ac.created_at,
                        ac.last_activity,
                        u.full_name,
                        u.email,
                        u.role_id,
                        (SELECT TOP 1 message FROM admin_messages WHERE chat_id = ac.chat_id AND is_archived = 0 ORDER BY created_at DESC) as last_message,
                        (SELECT TOP 1 created_at FROM admin_messages WHERE chat_id = ac.chat_id AND is_archived = 0 ORDER BY created_at DESC) as last_message_time
                    FROM admin_chats ac
                    JOIN users u ON (CASE WHEN ac.admin_id = ? THEN ac.user_id ELSE ac.admin_id END) = u.user_id
                    WHERE (ac.admin_id = ? OR ac.user_id = ?)
                    AND u.role_id != 1  -- Exclude chats with admins
                    AND ac.is_archived = 0
                    ORDER BY ac.last_activity DESC";
$admin_chats_params = array($user_id, $user_id, $user_id);
$admin_chats_stmt = sqlsrv_query($conn, $admin_chats_sql, $admin_chats_params);
$admin_chats = [];
if ($admin_chats_stmt) {
    while ($row = sqlsrv_fetch_array($admin_chats_stmt, SQLSRV_FETCH_ASSOC)) {
        $admin_chats[] = $row;
    }
    sqlsrv_free_stmt($admin_chats_stmt);
}

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
    // MODIFIED: Get only archived chats with NON-ADMIN users
    $archived_chats = getArchivedAdminChats($conn, $user_id, true);
    // Filter out archived chats with admins
    $archived_chats = array_filter($archived_chats, function($chat) use ($conn) {
        // Check if the other user is not an admin
        $other_user_id = $chat['user_id'];
        $check_sql = "SELECT role_id FROM users WHERE user_id = ?";
        $check_stmt = sqlsrv_query($conn, $check_sql, array($other_user_id));
        if ($check_stmt) {
            if ($row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC)) {
                sqlsrv_free_stmt($check_stmt);
                return ($row['role_id'] != 1); // Return true if not admin
            }
            sqlsrv_free_stmt($check_stmt);
        }
        return false;
    });
    
    if ($selected_chat_id) {
        // Check if selected archived chat is with a non-admin
        $check_user_sql = "SELECT user_id FROM admin_chats_archive WHERE chat_id = ? AND admin_id = ?";
        $check_user_stmt = sqlsrv_query($conn, $check_user_sql, array($selected_chat_id, $user_id));
        if ($check_user_stmt) {
            if ($row = sqlsrv_fetch_array($check_user_stmt, SQLSRV_FETCH_ASSOC)) {
                $other_user_id = $row['user_id'];
                $check_admin_sql = "SELECT role_id FROM users WHERE user_id = ?";
                $check_admin_stmt = sqlsrv_query($conn, $check_admin_sql, array($other_user_id));
                if ($check_admin_stmt) {
                    if ($admin_row = sqlsrv_fetch_array($check_admin_stmt, SQLSRV_FETCH_ASSOC)) {
                        if ($admin_row['role_id'] != 1) {
                            // Only load messages if not admin
                            $archived_messages_result = getArchivedAdminMessages($conn, $selected_chat_id, $user_id);
                            if ($archived_messages_result['success']) {
                                $chat_messages = $archived_messages_result['messages'];
                                $selected_chat = $archived_messages_result['chat_info'];
                            } else {
                                $selected_chat_id = null;
                                $error = $archived_messages_result['error'];
                            }
                        } else {
                            $selected_chat_id = null;
                            $error = "Cannot view archived chats with other admins.";
                        }
                    }
                    sqlsrv_free_stmt($check_admin_stmt);
                }
            }
            sqlsrv_free_stmt($check_user_stmt);
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
    
    if(isset($_POST['archive_chat']) && isAdmin()) {
        $chat_id = (int)$_POST['chat_id'];
        
        // Check if this chat is with a non-admin before archiving
        $check_user_sql = "SELECT user_id, admin_id FROM admin_chats WHERE chat_id = ?";
        $check_user_stmt = sqlsrv_query($conn, $check_user_sql, array($chat_id));
        if ($check_user_stmt) {
            if ($row = sqlsrv_fetch_array($check_user_stmt, SQLSRV_FETCH_ASSOC)) {
                // Determine the other user
                if ($row['user_id'] == $user_id) {
                    $other_user_id = $row['admin_id'];
                } else {
                    $other_user_id = $row['user_id'];
                }
                
                $check_admin_sql = "SELECT role_id FROM users WHERE user_id = ?";
                $check_admin_stmt = sqlsrv_query($conn, $check_admin_sql, array($other_user_id));
                if ($check_admin_stmt) {
                    if ($admin_row = sqlsrv_fetch_array($check_admin_stmt, SQLSRV_FETCH_ASSOC)) {
                        if ($admin_row['role_id'] != 1) {
                            // Only archive if not admin
                            if(archiveAdminChatImmediately($conn, $chat_id)) {
                                $success = "Chat archived successfully!";
                                header("Location: " . basename($_SERVER['PHP_SELF']));
                                exit();
                            }
                        } else {
                            $error = "Cannot archive admin-to-admin chats.";
                        }
                    }
                    sqlsrv_free_stmt($check_admin_stmt);
                }
            }
            sqlsrv_free_stmt($check_user_stmt);
        }
        $error = "Failed to archive chat.";
    }
}

// DISABLED: Admin-to-admin chat is completely disabled
if(isset($_GET['start_chat']) && !$view_archived) {
    $target_user_id = (int)$_GET['start_chat'];
    
    // Check if target user is an admin
    $check_admin_sql = "SELECT role_id FROM users WHERE user_id = ?";
    $check_admin_stmt = sqlsrv_query($conn, $check_admin_sql, array($target_user_id));
    $is_target_admin = false;
    if ($check_admin_stmt) {
        if ($row = sqlsrv_fetch_array($check_admin_stmt, SQLSRV_FETCH_ASSOC)) {
            $is_target_admin = ($row['role_id'] == 1);
        }
        sqlsrv_free_stmt($check_admin_stmt);
    }
    
    if ($is_target_admin) {
        $error = "Chatting with other operators is disabled. You can only chat with regular users.";
    } else {
        // Only allow chat with non-admins
        // Check if chat already exists
        $check_sql = "SELECT TOP 1 chat_id 
                      FROM admin_chats 
                      WHERE (admin_id = ? AND user_id = ?) 
                         OR (admin_id = ? AND user_id = ?)";
        $check_params = array($user_id, $target_user_id, $target_user_id, $user_id);
        $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
        
        $chat_id = false;
        if ($check_stmt) {
            if ($row = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC)) {
                $chat_id = $row['chat_id'];
            }
            sqlsrv_free_stmt($check_stmt);
        }
        
        if (!$chat_id) {
            // Create new chat
            $insert_sql = "INSERT INTO admin_chats (admin_id, user_id, created_at) 
                           VALUES (?, ?, GETDATE())";
            $insert_params = array($user_id, $target_user_id);
            $insert_stmt = sqlsrv_query($conn, $insert_sql, $insert_params);
            
            if ($insert_stmt) {
                $identity_sql = "SELECT SCOPE_IDENTITY() as chat_id";
                $identity_stmt = sqlsrv_query($conn, $identity_sql);
                if ($identity_stmt) {
                    if ($row = sqlsrv_fetch_array($identity_stmt, SQLSRV_FETCH_ASSOC)) {
                        $chat_id = $row['chat_id'];
                        
                        // Add welcome message
                        $welcome_sql = "INSERT INTO admin_messages (chat_id, sender_id, message, created_at, is_read, is_archived) 
                                        VALUES (?, ?, 'Chat started', GETDATE(), 0, 0)";
                        $welcome_params = array($chat_id, $user_id);
                        $welcome_stmt = sqlsrv_query($conn, $welcome_sql, $welcome_params);
                        if ($welcome_stmt) {
                            sqlsrv_free_stmt($welcome_stmt);
                        }
                    }
                    sqlsrv_free_stmt($identity_stmt);
                }
                sqlsrv_free_stmt($insert_stmt);
            }
        }
        
        if ($chat_id) {
            header("Location: adminpanel.php?chat_id=" . $chat_id);
            exit();
        } else {
            $error = "Failed to start chat. Please try again.";
        }
    }
}

// FIXED: Get both user_id and admin_id to avoid undefined array key warning
if($selected_chat_id && !$view_archived) {
    // Get both user_id and admin_id from the chat
    $check_user_sql = "SELECT user_id, admin_id FROM admin_chats WHERE chat_id = ?";
    $check_user_stmt = sqlsrv_query($conn, $check_user_sql, array($selected_chat_id));
    if ($check_user_stmt) {
        if ($row = sqlsrv_fetch_array($check_user_stmt, SQLSRV_FETCH_ASSOC)) {
            // Determine who is the other user
            if ($row['user_id'] == $user_id) {
                $other_user_id = $row['admin_id'];
            } else {
                $other_user_id = $row['user_id'];
            }
            
            $check_admin_sql = "SELECT role_id FROM users WHERE user_id = ?";
            $check_admin_stmt = sqlsrv_query($conn, $check_admin_sql, array($other_user_id));
            if ($check_admin_stmt) {
                if ($admin_row = sqlsrv_fetch_array($check_admin_stmt, SQLSRV_FETCH_ASSOC)) {
                    if ($admin_row['role_id'] != 1) {
                        // Only load messages if not admin
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
                    } else {
                        $selected_chat_id = null;
                        $error = "Cannot view chats with other operators.";
                    }
                }
                sqlsrv_free_stmt($check_admin_stmt);
            }
        }
        sqlsrv_free_stmt($check_user_stmt);
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
.user-item { display: flex; align-items: center; padding: 10px; border-radius: 8px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s; border: 1px solid transparent; position: relative; text-decoration: none; color: inherit; }
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

/* REMOVED: Admin section styles are now hidden */
.admin-section, .admin-item, .admin-avatar, .admin-name { display: none; }

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
.chat-item:hover .hierarchy-tooltip, .user-item:hover .hierarchy-tooltip, .message-sender:hover .hierarchy-tooltip, .notification-item:hover .hierarchy-tooltip { opacity: 1; visibility: visible; }
.hierarchy-tooltip h4 { color: white; margin: 0 0 8px 0; font-size: 13px; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 5px; }
.hierarchy-info { margin: 5px 0; }
.hierarchy-row { display: flex; margin: 3px 0; align-items: flex-start; }
.hierarchy-label { width: 90px; color: #a0aec0; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; flex-shrink: 0; }
.hierarchy-value { flex: 1; color: white; font-weight: 500; font-size: 11px; line-height: 1.3; }
.hierarchy-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 5px 0; }
.chat-item, .user-item, .notification-item, .message-sender { position: relative; }
</style>
</head>
<body>

<!-- ============ HEADER ============ -->
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
            
            <!-- REMOVED: Admin section completely removed -->
            <!-- No more "Available Operators" or "Chat with Other Operators" -->
            
            <h3>Online Users (<?php echo count($online_users); ?>)</h3>
            <div class="online-users-list">
                <?php 
                $has_online_users = false;
                foreach($online_users as $user): 
                    $has_online_users = true;
                    $user_hierarchy = getUserHierarchyInfo($conn, $user['user_id']);
                ?>
                <a href="?start_chat=<?php echo $user['user_id']; ?>" class="user-item">
                    <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                    <div class="user-info">
                        <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                        <span class="user-role"><?php echo htmlspecialchars($user['role_name']); ?></span>
                    </div>
                    <div class="online-indicator"></div>
                    
                    <!-- USER ITEM TOOLTIP -->
                    <div class="hierarchy-tooltip">
                        <h4><?php echo htmlspecialchars($user_hierarchy['full_name'] ?? 'Unknown User'); ?></h4>
                        <div class="hierarchy-info">
                            <div class="hierarchy-row">
                                <div class="hierarchy-label">Email:</div>
                                <div class="hierarchy-value">
                                    <?php echo htmlspecialchars(!empty($user_hierarchy['email']) ? $user_hierarchy['email'] : 'N/A'); ?>
                                </div>
                            </div>
                            <div class="hierarchy-row">
                                <div class="hierarchy-label">Role:</div>
                                <div class="hierarchy-value">
                                    <?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?>
                                    <?php if ($user_hierarchy['is_head'] ?? false): ?>
                                        <span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($user_hierarchy['current_unit'] ?? '')): ?>
                            <div class="hierarchy-row">
                                <div class="hierarchy-label">Current Unit:</div>
                                <div class="hierarchy-value">
                                    <?php echo htmlspecialchars($user_hierarchy['current_unit']); ?>
                                    <?php if (!empty($user_hierarchy['unit_type'] ?? '')): ?>
                                        <span style="color: #a0aec0; font-size: 10px;">(<?php echo htmlspecialchars($user_hierarchy['unit_type']); ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php if(!$has_online_users): ?>
                    <div style="color: #a0aec0; padding: 10px; text-align: center;">No online users</div>
                <?php endif; ?>
            </div>
            
            <h3>Your Chats (with users only)</h3>
            <div class="chat-list">
                <?php if (!$view_archived): ?>
                    <?php if(!empty($admin_chats)): ?>
                        <?php foreach($admin_chats as $chat): 
                            $other_user_id = getArrayValue($chat, 'user_id');
                            $user_hierarchy = getUserHierarchyInfo($conn, $other_user_id);
                        ?>
                        <a href="?chat_id=<?php echo getArrayValue($chat, 'chat_id'); ?>" class="chat-item <?php echo $selected_chat_id == getArrayValue($chat, 'chat_id') ? 'active' : ''; ?>">
                            <div class="chat-avatar"><?php echo strtoupper(substr(getArrayValue($chat, 'full_name', ''), 0, 1)); ?></div>
                            <div class="chat-info">
                                <div class="chat-name"><?php echo htmlspecialchars(getArrayValue($chat, 'full_name', 'Unknown User')); ?></div>
                                <div class="chat-preview"><?php echo htmlspecialchars(substr(getArrayValue($chat, 'last_message', 'No messages yet'), 0, 30)); ?></div>
                            </div>
                            <div class="chat-time"><?php echo safeDateFormat(getArrayValue($chat, 'last_message_time'), 'H:i'); ?></div>
                            
                            <!-- ACTIVE CHAT ITEM TOOLTIP -->
                            <div class="hierarchy-tooltip">
                                <h4><?php echo htmlspecialchars($user_hierarchy['full_name'] ?? 'Unknown User'); ?></h4>
                                <div class="hierarchy-info">
                                    <div class="hierarchy-row">
                                        <div class="hierarchy-label">Email:</div>
                                        <div class="hierarchy-value">
                                            <?php echo htmlspecialchars(!empty($user_hierarchy['email']) ? $user_hierarchy['email'] : 'N/A'); ?>
                                        </div>
                                    </div>
                                    <div class="hierarchy-row">
                                        <div class="hierarchy-label">Role:</div>
                                        <div class="hierarchy-value">
                                            <?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?>
                                            <?php if ($user_hierarchy['is_head'] ?? false): ?>
                                                <span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($user_hierarchy['current_unit'] ?? '')): ?>
                                    <div class="hierarchy-row">
                                        <div class="hierarchy-label">Current Unit:</div>
                                        <div class="hierarchy-value">
                                            <?php echo htmlspecialchars($user_hierarchy['current_unit']); ?>
                                            <?php if (!empty($user_hierarchy['unit_type'] ?? '')): ?>
                                                <span style="color: #a0aec0; font-size: 10px;">(<?php echo htmlspecialchars($user_hierarchy['unit_type']); ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                         </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="color: #a0aec0; padding: 10px; text-align: center;">No active chats with users</div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if(!empty($archived_chats)): ?>
                        <?php foreach($archived_chats as $chat): 
                            $other_user_id = getArrayValue($chat, 'user_id');
                            $user_hierarchy = getUserHierarchyInfo($conn, $other_user_id);
                        ?>
                        <a href="?view=archived&chat_id=<?php echo getArrayValue($chat, 'chat_id'); ?>" class="chat-item <?php echo $selected_chat_id == getArrayValue($chat, 'chat_id') ? 'active' : ''; ?>">
                            <div class="chat-avatar" style="background-color: #718096;"><?php echo strtoupper(substr(getArrayValue($chat, 'full_name', ''), 0, 1)); ?></div>
                            <div class="chat-info">
                                <div class="chat-name"><?php echo htmlspecialchars(getArrayValue($chat, 'full_name', 'Unknown User')); ?> <span class="archive-badge">Archived</span></div>
                                <div class="chat-preview"><?php echo htmlspecialchars(substr(getArrayValue($chat, 'last_message', 'No messages'), 0, 30)); ?></div>
                            </div>
                            <div class="chat-time"><?php echo safeDateFormat(getArrayValue($chat, 'archived_at'), 'M d'); ?></div>
                            
                            <!-- ARCHIVED CHAT ITEM TOOLTIP -->
                            <div class="hierarchy-tooltip">
                                <h4><?php echo htmlspecialchars($user_hierarchy['full_name'] ?? 'Unknown User'); ?></h4>
                                <div class="hierarchy-info">
                                    <div class="hierarchy-row">
                                        <div class="hierarchy-label">Email:</div>
                                        <div class="hierarchy-value">
                                            <?php echo htmlspecialchars(!empty($user_hierarchy['email']) ? $user_hierarchy['email'] : 'N/A'); ?>
                                        </div>
                                    </div>
                                    <div class="hierarchy-row">
                                        <div class="hierarchy-label">Role:</div>
                                        <div class="hierarchy-value">
                                            <?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?>
                                            <?php if ($user_hierarchy['is_head'] ?? false): ?>
                                                <span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($user_hierarchy['current_unit'] ?? '')): ?>
                                    <div class="hierarchy-row">
                                        <div class="hierarchy-label">Current Unit:</div>
                                        <div class="hierarchy-value">
                                            <?php echo htmlspecialchars($user_hierarchy['current_unit']); ?>
                                            <?php if (!empty($user_hierarchy['unit_type'] ?? '')): ?>
                                                <span style="color: #a0aec0; font-size: 10px;">(<?php echo htmlspecialchars($user_hierarchy['unit_type']); ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="color: #a0aec0; padding: 10px; text-align: center;">No archived chats with users</div>
                    <?php endif; ?>
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
            
            <?php if($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <?php if($selected_chat): ?>
                <div class="chat-header <?php echo $view_archived ? 'archived-chat' : ''; ?>" style="<?php echo $view_archived ? 'background: linear-gradient(135deg, #718096 0%, #4a5568 100%);' : ''; ?>">
                    <h3><?php if ($view_archived): ?>📁 Archived Chat with <?php echo htmlspecialchars($selected_chat['other_full_name']); ?><?php else: ?>Chat with <?php echo htmlspecialchars($selected_chat['full_name']); ?><?php endif; ?></h3>
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
                    <div class="message <?php echo $is_sent ? 'sent' : 'received'; ?>" data-message-id="<?php echo $msg['message_id']; ?>">
                        <div class="message-sender"><?php echo htmlspecialchars($msg['full_name']); ?>
                            <!-- MESSAGE SENDER TOOLTIP -->
                            <div class="hierarchy-tooltip">
                                <h4><?php echo htmlspecialchars($sender_hierarchy['full_name'] ?? 'Unknown User'); ?></h4>
                                <div class="hierarchy-info">
                                    <div class="hierarchy-row">
                                        <div class="hierarchy-label">Email:</div>
                                        <div class="hierarchy-value">
                                            <?php echo htmlspecialchars(!empty($sender_hierarchy['email']) ? $sender_hierarchy['email'] : 'N/A'); ?>
                                        </div>
                                    </div>
                                    <div class="hierarchy-row">
                                        <div class="hierarchy-label">Role:</div>
                                        <div class="hierarchy-value">
                                            <?php echo htmlspecialchars($sender_hierarchy['role_name'] ?? 'N/A'); ?>
                                            <?php if ($sender_hierarchy['is_head'] ?? false): ?>
                                                <span style="color: #38a169; font-weight: bold; margin-left: 5px;">(Head)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($sender_hierarchy['current_unit'] ?? '')): ?>
                                    <div class="hierarchy-row">
                                        <div class="hierarchy-label">Current Unit:</div>
                                        <div class="hierarchy-value">
                                            <?php echo htmlspecialchars($sender_hierarchy['current_unit']); ?>
                                            <?php if (!empty($sender_hierarchy['unit_type'] ?? '')): ?>
                                                <span style="color: #a0aec0; font-size: 10px;">(<?php echo htmlspecialchars($sender_hierarchy['unit_type']); ?>)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="message-bubble">
                            <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                            <div style="font-size: 11px; opacity: 0.8; margin-top: 5px;"><?php echo safeDateFormat($msg['created_at'] ?? null, 'H:i'); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if(empty($chat_messages)): ?>
                        <div style="text-align: center; color: #a0aec0; font-style: italic; padding: 40px;">No messages found.</div>
                    <?php endif; ?>
                </div>
                
                <?php if (!$view_archived): ?>
                    <div class="chat-input-area">
                        <form method="POST" class="chat-form" id="chatForm">
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
                        <div style="text-align: center; padding: 15px; color: #64748b; font-style: italic;">🔒 This chat is archived and cannot be modified.</div>
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
                                
                                // Get message count for archived chat
                                $msg_count_sql = "SELECT COUNT(*) as msg_count FROM admin_messages_archive WHERE chat_id = ?";
                                $msg_count_params = array(getArrayValue($chat, 'chat_id'));
                                $msg_count_stmt = sqlsrv_query($conn, $msg_count_sql, $msg_count_params);
                                $msg_count = 0;

                                if ($msg_count_stmt && sqlsrv_fetch($msg_count_stmt)) {
                                    $msg_data = sqlsrv_get_field($msg_count_stmt, 0);
                                    $msg_count = $msg_data ?: 0;
                                }
                                if ($msg_count_stmt) sqlsrv_free_stmt($msg_count_stmt);
                            ?>
                                <tr>
                                    <td style="font-weight: 500; color: #2d3748;"><?php echo htmlspecialchars($chat['full_name']); ?><br><small style="color: #718096; font-size: 11px;"><?php echo htmlspecialchars($user_hierarchy['role_name'] ?? 'N/A'); ?><?php if ($user_hierarchy['current_unit'] ?? ''): ?> • <?php echo htmlspecialchars($user_hierarchy['current_unit']); ?><?php endif; ?></small></td>
                                    <td style="color: #718096; font-size: 13px;"><?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages', 0, 50)); ?></td>
                                    <td><span style="background-color: #e2e8f0; color: #4a5568; padding: 2px 8px; border-radius: 12px; font-size: 12px;"><?php echo $msg_count; ?> messages</span></td>
                                    <td style="color: #718096; font-size: 13px;"><?php echo safeDateFormat(getArrayValue($chat, 'archived_at'), 'M d, Y H:i'); ?></td>
                                    <td><a href="?view=archived&chat_id=<?php echo $chat['chat_id']; ?>" class="view-btn">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-archived-message">No archived chats with users found.</div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-chat-selected">
                    <div>
                        <h3>Welcome to Admin Chat</h3>
                        <p>Select a user from the online users list to start chatting</p>
                        <p>You can only chat with regular users, not other operators.</p>
                        <ul style="text-align: left; margin-top: 10px;">
                            <li>Online users</li>
                            <li>Or select from existing chats</li>
                        </ul>
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

<!-- ============ CHAT CONFIGURATION ============ -->
<script id="chat-config" type="application/json">
<?php
$config = [
    'chatId' => $selected_chat_id ?? 0,
    'userId' => $user_id,
    'isAdmin' => $is_admin,
    'viewArchived' => $view_archived
];
echo json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
</script>

<!-- ============ MAIN JAVASCRIPT ============ -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    console.log('AdminPanel Loaded');

    // ----- LOAD CONFIG FROM JSON -----
    let config;
    try {
        const configElement = document.getElementById('chat-config');
        if (!configElement) {
            console.error('Chat config element not found');
            return;
        }
        config = JSON.parse(configElement.textContent);
        console.log('Config loaded:', config);
    } catch (e) {
        console.error('Failed to load chat config:', e);
        return;
    }

    const chatId = config.chatId;
    const userId = config.userId;
    const viewArchived = config.viewArchived;
    const POLL_DELAY = 3000;

    // ----- STATE -----
    let lastMessageId = 0;
    let pollInterval = null;
    let isSending = false;

    // ----- INITIALIZATION -----
    function initialize() {
        // Get the last message ID from existing messages
        const messages = document.querySelectorAll('.message');
        if (messages.length > 0) {
            const lastMsg = messages[messages.length - 1];
            const msgId = lastMsg.getAttribute('data-message-id');
            if (msgId) {
                lastMessageId = parseInt(msgId, 10);
            }
        }
        console.log('Initial lastMessageId:', lastMessageId);
        
        const chatMessages = document.getElementById('chat-messages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        
        if (chatId && chatId > 0 && !viewArchived) {
            startPolling();
        }
    }

    // ----- POLLING -----
    function startPolling() {
        if (pollInterval) clearInterval(pollInterval);
        fetchNewMessages();
        pollInterval = setInterval(fetchNewMessages, POLL_DELAY);
        console.log('✅ Polling started');
    }
    
    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
            console.log('⏸️ Polling stopped');
        }
    }
    
    function fetchNewMessages() {
        if (!chatId || chatId === 0 || viewArchived) return;
        
        fetch(`get_admin_messages.php?chat_id=${chatId}&last_message_id=${lastMessageId}`)
            .then(r => {
                if (!r.ok) throw new Error('Network response not ok');
                return r.json();
            })
            .then(data => {
                if (data.success && data.messages && data.messages.length > 0) {
                    appendMessages(data.messages);
                    lastMessageId = data.last_message_id;
                }
            })
            .catch(err => console.error('Error fetching messages:', err));
    }

    // ----- APPEND MESSAGES -----
    function appendMessages(messages) {
        const chatMessages = document.getElementById('chat-messages');
        if (!chatMessages) return;
        
        const wasAtBottom = isScrolledToBottom();
        let added = false;
        
        messages.forEach(msg => {
            // Check if message already exists
            if (document.querySelector(`.message[data-message-id="${msg.message_id}"]`)) {
                return;
            }
            
            const div = document.createElement('div');
            div.className = `message ${msg.is_sent ? 'sent' : 'received'}`;
            div.setAttribute('data-message-id', msg.message_id);
            
            const messageContent = escapeHtml(msg.message).replace(/\n/g, '<br>');
            const timeString = formatTime(msg.created_at);
            
            div.innerHTML = `
                <div class="message-sender">${escapeHtml(msg.full_name)}</div>
                <div class="message-bubble">
                    ${messageContent}
                    <div style="font-size:11px; opacity:0.8; margin-top:5px;">${timeString}</div>
                </div>
            `;
            
            chatMessages.appendChild(div);
            added = true;
        });
        
        if (added && wasAtBottom) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }

    // ----- SEND MESSAGE (AJAX) -----
    function sendMessage(messageText) {
        if (isSending || !messageText.trim() || !chatId || chatId === 0) {
            return false;
        }
        
        isSending = true;
        const sendButton = document.querySelector('.send-btn');
        const originalText = sendButton ? sendButton.textContent : 'Send';
        const textarea = document.querySelector('.chat-input');
        const originalMessage = messageText;
        
        if (sendButton) { 
            sendButton.disabled = true; 
            sendButton.textContent = 'Sending...'; 
        }
        if (textarea) { 
            textarea.value = ''; 
            textarea.style.height = 'auto'; 
        }

        const formData = new FormData();
        formData.append('chat_id', chatId);
        formData.append('message', messageText);

        return fetch('send_admin_messages.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => {
            if (!r.ok) {
                throw new Error('Network response was not ok: ' + r.status);
            }
            return r.json();
        })
        .then(data => {
            if (data.success && data.message) {
                appendMessages([data.message]);
                lastMessageId = data.message.message_id;
                console.log('✅ Message sent successfully');
                return true;
            } else {
                console.error('Send failed:', data.error);
                if (textarea) textarea.value = originalMessage;
                alert('Failed to send message: ' + (data.error || 'Unknown error'));
                return false;
            }
        })
        .catch(err => {
            console.error('Send error:', err);
            if (textarea) textarea.value = originalMessage;
            alert('Error sending message. Please check your connection.');
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
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function formatTime(datetime) {
        try {
            const d = new Date(datetime);
            return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return datetime;
        }
    }

    // ----- EVENT LISTENERS -----
    function setupEventListeners() {
        const chatForm = document.getElementById('chatForm');
        if (!chatForm || viewArchived) return;
        
        const textarea = chatForm.querySelector('textarea');
        const sendButton = chatForm.querySelector('button[type="submit"]');
        
        if (!textarea || !sendButton) return;
        
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            if (textarea.value.trim()) {
                sendMessage(textarea.value.trim());
            }
        });
        
        textarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (this.value.trim()) {
                    sendMessage(this.value.trim());
                }
            }
        });
        
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
        
        setTimeout(() => textarea.focus(), 100);
    }

    // ----- NOTIFICATION DROPDOWN -----
    function setupNotifications() {
        const btn = document.getElementById('adminNotificationBtn');
        const dd = document.getElementById('notificationDropdown');
        
        if (btn && dd) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                e.preventDefault();
                
                const isVisible = dd.style.visibility === 'visible';
                dd.style.opacity = isVisible ? '0' : '1';
                dd.style.visibility = isVisible ? 'hidden' : 'visible';
                dd.style.transform = isVisible ? 'translateY(-10px)' : 'translateY(0)';
            });
            
            document.addEventListener('click', function(e) {
                if (!btn.contains(e.target) && !dd.contains(e.target)) {
                    dd.style.opacity = '0';
                    dd.style.visibility = 'hidden';
                    dd.style.transform = 'translateY(-10px)';
                }
            });
        }
    }

    // ----- VISIBILITY API -----
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
        } else if (chatId && chatId > 0 && !viewArchived && !pollInterval) {
            startPolling();
        }
    });

    // ----- CLEANUP -----
    window.addEventListener('beforeunload', function() {
        stopPolling();
    });

    // ----- START -----
    initialize();
    setupEventListeners();
    setupNotifications();
});
</script>

<!-- ============ ADMIN NOTIFICATION CHECKER ============ -->
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
                    if (bellBadge) { 
                        bellBadge.textContent = data.count; 
                        bellBadge.style.display = 'inline-block'; 
                    }
                    if (navBadge) { 
                        navBadge.textContent = data.count; 
                        navBadge.style.display = 'inline-block'; 
                    }
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
})();
</script>
<?php endif; ?>

<?php ob_end_flush(); ?>
</body>
</html>