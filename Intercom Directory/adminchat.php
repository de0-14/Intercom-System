<?php
require_once 'conn.php';
updateAllUsersActivity($conn);

if(!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$is_admin = isAdmin();
$selected_chat_id = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : null;
$start_chat_with = isset($_GET['start_chat']) ? (int)$_GET['start_chat'] : null;
$selected_chat = null;
$chat_messages = [];
$error = '';
$success = '';

if($start_chat_with && !$is_admin) {
    $selected_chat_id = createAdminChatConversation($conn, $start_chat_with, $user_id);
    if($selected_chat_id) {
        header("Location: adminchat.php?chat_id=$selected_chat_id");
        exit();
    } else {
        $error = "Failed to create chat.";
    }
}

if($selected_chat_id) {
    $chats = getAdminChats($conn, $user_id, $is_admin);
    foreach($chats as $chat) {
        if($chat['chat_id'] == $selected_chat_id) {
            $selected_chat = $chat;
            break;
        }
    }
    
    if($selected_chat) {
        $chat_messages = getAdminChatMessages($conn, $selected_chat_id);
        markAdminMessagesAsRead($conn, $selected_chat_id, $user_id);
    } else {
        $error = "Chat not found or no access.";
        $selected_chat_id = null;
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(isset($_POST['send_message'])) {
        $chat_id = (int)$_POST['chat_id'];
        $message = trim($_POST['message']);
        
        if(empty($message)) {
            $error = "Please enter a message.";
        } else {
            if(sendAdminMessage($conn, $chat_id, $user_id, $message)) {
                $success = "Message sent!";
                header("Location: adminchat.php?chat_id=$chat_id");
                exit();
            } else {
                $error = "Failed to send message.";
            }
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
                <li><a href="adminpanel.php">Admin Chat <?php echo $unread_count > 0 ? "($unread_count)" : ""; ?></a></li>
            <?php else: ?>
                <li><a href="adminchat.php">Chat with Admin <?php echo $unread_count > 0 ? "($unread_count)" : ""; ?></a></li>
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
            <div class="chat-list">
                <?php 
                $chats = getAdminChats($conn, $user_id, $is_admin);
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
                            $has_chat = false;
                            foreach($chats as $chat) {
                                if($chat['admin_id'] == $admin['user_id']) {
                                    $has_chat = true;
                                    break;
                                }
                            }
                            if(!$has_chat):
                        ?>
                        <a href="adminchat.php?start_chat=<?php echo $admin['user_id']; ?>" class="admin-select-item">
                            <div class="admin-select-avatar">
                                <?php echo strtoupper(substr($admin['full_name'], 0, 1)); ?>
                            </div>
                            <span class="admin-select-name"><?php echo htmlspecialchars($admin['full_name']); ?></span>
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
                        No chats yet
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="chat-main">
            <?php if($selected_chat): ?>
                <div class="chat-header">
                    <h3>Chat with <?php echo $is_admin ? htmlspecialchars($selected_chat['full_name']) : 'Admin ' . htmlspecialchars($selected_chat['full_name']); ?></h3>
                </div>
                
                <div class="chat-messages" id="chat-messages">
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
                            No messages yet. Start the conversation!
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="chat-input-area">
                    <form method="POST" class="chat-form">
                        <input type="hidden" name="chat_id" value="<?php echo $selected_chat_id; ?>">
                        <textarea name="message" class="chat-input" placeholder="Type your message here..." required></textarea>
                        <button type="submit" name="send_message" class="send-btn">Send</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="no-chat-selected">
                    <div>
                        <h3>Select a chat to start messaging</h3>
                        <p>Choose from your existing chats or start a new one</p>
                        <?php if(!$is_admin): ?>
                            <p>You can chat with any available admin</p>
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
        textarea.addEventListener('keydown', function(e) {
            if(e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                chatForm.submit();
            }
        });
        
        if(textarea) textarea.focus();
    }
    
    setInterval(function() {
        if(window.location.href.includes('chat_id=')) {
            location.reload();
        }
    }, 5000);
});
</script>
</body>
</html>