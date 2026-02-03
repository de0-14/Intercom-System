<?php
require_once 'conn.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$number_id = isset($_GET['number_id']) ? (int)$_GET['number_id'] : 0;

// Check if user is head of this number
$is_head = false;
if ($number_id > 0) {
    $head_sql = "SELECT head_user_id FROM numbers WHERE number_id = ?";
    $head_stmt = $conn->prepare($head_sql);
    $head_stmt->bind_param("i", $number_id);
    $head_stmt->execute();
    $head_result = $head_stmt->get_result();
    $head_row = $head_result->fetch_assoc();
    $head_stmt->close();
    
    $is_head = ($head_row['head_user_id'] == $user_id);
}

// Handle restore request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_conversation'])) {
    if (!$is_head) {
        // Only head can restore conversations
        echo "<script>alert('Only the head can restore conversations.'); window.history.back();</script>";
        exit;
    }
    
    $conversation_id = (int)$_POST['conversation_id'];
    
    // Restore the conversation
    $result = restoreArchivedConversation($conn, $conversation_id, $number_id, $user_id);
    
    if ($result['success']) {
        header("Location: view_archived.php?number_id=$number_id&success=" . urlencode($result['message']));
        exit;
    } else {
        header("Location: view_archived.php?number_id=$number_id&error=" . urlencode($result['error']));
        exit;
    }
}

// Get archived conversations
if ($is_head) {
    // Head can see all archived conversations for this number
    $sql = "SELECT ca.*, u.username as initiator_name, u.full_name as initiator_full_name
            FROM conversations_archive ca
            JOIN users u ON ca.initiated_by = u.user_id
            WHERE ca.number_id = ?
            ORDER BY ca.archived_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $number_id);
} else {
    // Regular user can only see their own
    $sql = "SELECT ca.*, u.username as initiator_name, u.full_name as initiator_full_name
            FROM conversations_archive ca
            JOIN users u ON ca.initiated_by = u.user_id
            WHERE ca.number_id = ? AND ca.initiated_by = ?
            ORDER BY ca.archived_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $number_id, $user_id);
}

$stmt->execute();
$result = $stmt->get_result();
$archived_conversations = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get contact info for display
$contact_query = "SELECT numbers, head, description FROM numbers WHERE number_id = ?";
$contact_stmt = $conn->prepare($contact_query);
$contact_stmt->bind_param("i", $number_id);
$contact_stmt->execute();
$contact_result = $contact_stmt->get_result();
$contact = $contact_result->fetch_assoc();
$contact_stmt->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>View Archived Conversations - <?php echo htmlspecialchars($contact['numbers'] ?? 'Unknown'); ?></title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; 
            padding: 20px; 
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .back-btn { 
            display: inline-block; 
            margin: 10px 0; 
            padding: 8px 15px; 
            background: #6c757d; 
            color: white; 
            text-decoration: none; 
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        .back-btn:hover {
            background: #5a6268;
        }
        .conversation { 
            border: 1px solid #ddd; 
            padding: 20px; 
            margin: 15px 0; 
            border-radius: 8px;
            background: white;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .conversation:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .message { 
            background: #f8f9fa; 
            padding: 12px; 
            margin: 8px 0; 
            border-left: 4px solid #007bff; 
            border-radius: 4px;
        }
        .conversation-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        .conversation-info {
            flex: 1;
        }
        .conversation-actions {
            display: flex;
            gap: 10px;
        }
        .view-messages-btn, .restore-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.3s;
        }
        .view-messages-btn {
            background: #17a2b8;
            color: white;
        }
        .view-messages-btn:hover {
            background: #138496;
        }
        .restore-btn {
            background: #28a745;
            color: white;
        }
        .restore-btn:hover {
            background: #218838;
        }
        .messages-container {
            margin-top: 15px;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 5px;
            padding: 10px;
            background: #f8f9fa;
        }
        .message-header {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        .message-time {
            color: #6c757d;
            font-size: 0.9em;
        }
        .no-archived {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-style: italic;
        }
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📁 Archived Conversations</h1>
            <p>Contact: <?php echo htmlspecialchars($contact['numbers'] ?? 'Unknown'); ?> - <?php echo htmlspecialchars($contact['description'] ?? ''); ?></p>
            <a href="numpage.php?id=<?php echo $number_id; ?>" class="back-btn">← Back to Contact Page</a>
        </div>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="success-message"><?php echo htmlspecialchars($_GET['success']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="error-message"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>
        
        <?php if (empty($archived_conversations)): ?>
            <div class="no-archived">
                <h3>No Archived Conversations Found</h3>
                <p>You don't have any archived conversations for this contact.</p>
            </div>
        <?php else: ?>
            <p>Found <?php echo count($archived_conversations); ?> archived conversation(s)</p>
            
            <?php foreach ($archived_conversations as $conv): ?>
                <div class="conversation" id="conv-<?php echo $conv['conversation_id']; ?>">
                    <div class="conversation-header">
                        <div class="conversation-info">
                            <h3>Conversation #<?php echo $conv['conversation_id']; ?></h3>
                            <p><strong>With:</strong> <?php echo htmlspecialchars($conv['initiator_name']); ?> (<?php echo htmlspecialchars($conv['initiator_full_name']); ?>)</p>
                            <p><strong>Archived:</strong> <?php echo date('M d, Y h:i A', strtotime($conv['archived_at'])); ?></p>
                            <p><strong>Last Active:</strong> <?php echo date('M d, Y h:i A', strtotime($conv['last_activity'])); ?></p>
                        </div>
                        <div class="conversation-actions">
                            <button class="view-messages-btn" onclick="toggleMessages(<?php echo $conv['conversation_id']; ?>)">
                                View Messages
                            </button>
                            <?php if ($is_head): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="conversation_id" value="<?php echo $conv['conversation_id']; ?>">
                                    <button type="submit" name="restore_conversation" class="restore-btn" 
                                            onclick="return confirm('Are you sure you want to restore this conversation? It will become active again.');">
                                        Restore
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="messages-container" id="messages-<?php echo $conv['conversation_id']; ?>" style="display: none;">
                        <h4>Messages:</h4>
                        <?php
                        // Get messages for this conversation
                        $msg_sql = "SELECT m.*, u.username as sender_name 
                                   FROM messages_archive m
                                   JOIN users u ON m.sender_id = u.user_id
                                   WHERE m.conversation_id = ?
                                   ORDER BY m.created_at ASC";
                        $msg_stmt = $conn->prepare($msg_sql);
                        $msg_stmt->bind_param("i", $conv['conversation_id']);
                        $msg_stmt->execute();
                        $msg_result = $msg_stmt->get_result();
                        $messages = $msg_result->fetch_all(MYSQLI_ASSOC);
                        $msg_stmt->close();
                        
                        if (empty($messages)): ?>
                            <p>No messages found in this conversation.</p>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): ?>
                                <div class="message">
                                    <div class="message-header">
                                        <?php echo htmlspecialchars($msg['sender_name']); ?>
                                    </div>
                                    <div class="message-text">
                                        <?php echo htmlspecialchars($msg['message']); ?>
                                    </div>
                                    <div class="message-time">
                                        <?php echo date('M d, Y h:i A', strtotime($msg['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
    function toggleMessages(conversationId) {
        const messagesDiv = document.getElementById('messages-' + conversationId);
        const button = event.target;
        
        if (messagesDiv.style.display === 'none') {
            messagesDiv.style.display = 'block';
            button.textContent = 'Hide Messages';
        } else {
            messagesDiv.style.display = 'none';
            button.textContent = 'View Messages';
        }
    }
    </script>
</body>
</html>