<?php
require_once 'conn.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

$current_script = basename($_SERVER['PHP_SELF']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: homepage.php');
    exit;
}

$number_id = (int)$_GET['id'];
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$edit_feedback_id = isset($_GET['edit_feedback']) ? (int)$_GET['edit_feedback'] : null;
$current_edit_feedback = null;

$contact_query = "
    SELECT 
        n.*,
        n.head,
        d.division_name,
        dept.department_name,
        un.unit_name,
        o.office_name,
        d.status as division_status,
        dept.status as department_status,
        un.status as unit_status,
        o.status as office_status,
        CASE 
            WHEN n.division_id IS NOT NULL THEN 'Division'
            WHEN n.department_id IS NOT NULL THEN 'Department'
            WHEN n.unit_id IS NOT NULL THEN 'Unit'
            WHEN n.office_id IS NOT NULL THEN 'Office'
            ELSE 'Unknown'
        END as unit_type,
        CASE 
            WHEN n.division_id IS NOT NULL THEN d.division_name
            WHEN n.department_id IS NOT NULL THEN d2.division_name
            WHEN n.unit_id IS NOT NULL THEN d3.division_name
            WHEN n.office_id IS NOT NULL THEN d4.division_name
            ELSE NULL
        END as parent_division
    FROM numbers n
    LEFT JOIN divisions d ON n.division_id = d.division_id
    LEFT JOIN departments dept ON n.department_id = dept.department_id
    LEFT JOIN divisions d2 ON dept.division_id = d2.division_id
    LEFT JOIN units un ON n.unit_id = un.unit_id
    LEFT JOIN departments dept2 ON un.department_id = dept2.department_id
    LEFT JOIN divisions d3 ON dept2.division_id = d3.division_id
    LEFT JOIN offices o ON n.office_id = o.office_id
    LEFT JOIN units u2 ON o.unit_id = u2.unit_id
    LEFT JOIN departments dept3 ON u2.department_id = dept3.department_id
    LEFT JOIN divisions d4 ON dept3.division_id = d4.division_id
    WHERE n.number_id = ?
";

$stmt = $conn->prepare($contact_query);
$stmt->bind_param("i", $number_id);
$stmt->execute();
$contact_result = $stmt->get_result();

if ($contact_result->num_rows === 0) {
    header('Location: homepage.php');
    exit;
}

$contact = $contact_result->fetch_assoc();
$stmt->close();

$org_name = '';
$org_type = '';
$org_status = '';

if (!empty($contact['division_name'])) {
    $org_name = $contact['division_name'];
    $org_type = 'Division';
    $org_status = $contact['division_status'];
} elseif (!empty($contact['department_name'])) {
    $org_name = $contact['department_name'];
    $org_type = 'Department';
    $org_status = $contact['department_status'];
} elseif (!empty($contact['unit_name'])) {
    $org_name = $contact['unit_name'];
    $org_type = 'Unit';
    $org_status = $contact['unit_status'];
} elseif (!empty($contact['office_name'])) {
    $org_name = $contact['office_name'];
    $org_type = 'Office';
    $org_status = $contact['office_status'];
}

$feedback_error = '';
$feedback_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_feedback'])) {
        if (!$user_id) {
            $feedback_error = "You must be logged in to submit feedback.";
        } else {
            $rating = (int)$_POST['rating'];
            $comment = trim($_POST['comment']);
            
            $check_feedback = $conn->prepare("SELECT feedback_id FROM feedback WHERE number_id = ? AND user_id = ?");
            $check_feedback->bind_param("ii", $number_id, $user_id);
            $check_feedback->execute();
            $check_feedback->store_result();
            
            if ($check_feedback->num_rows > 0) {
                $feedback_error = "You have already submitted feedback for this contact.";
            } elseif ($rating < 1 || $rating > 5) {
                $feedback_error = "Please select a valid rating (1-5 stars).";
            } elseif (empty($comment)) {
                $feedback_error = "Please enter a comment.";
            } else {
                $insert_feedback = $conn->prepare("INSERT INTO feedback (number_id, user_id, rating, comment, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                $insert_feedback->bind_param("iiis", $number_id, $user_id, $rating, $comment);
                
                if ($insert_feedback->execute()) {
                    $feedback_success = "Thank you for your feedback!";
                    $_POST = array();
                } else {
                    $feedback_error = "Failed to submit feedback. Please try again.";
                }
                $insert_feedback->close();
            }
            $check_feedback->close();
        }
    }
    
    if (isset($_POST['update_feedback'])) {
        if (!$user_id) {
            $feedback_error = "You must be logged in to update feedback.";
        } else {
            $feedback_id = (int)$_POST['feedback_id'];
            $rating = (int)$_POST['rating'];
            $comment = trim($_POST['comment']);
            
            $verify_feedback = $conn->prepare("SELECT feedback_id FROM feedback WHERE feedback_id = ? AND user_id = ?");
            $verify_feedback->bind_param("ii", $feedback_id, $user_id);
            $verify_feedback->execute();
            $verify_feedback->store_result();
            
            if ($verify_feedback->num_rows === 0) {
                $feedback_error = "You can only edit your own feedback.";
            } elseif ($rating < 1 || $rating > 5) {
                $feedback_error = "Please select a valid rating (1-5 stars).";
            } elseif (empty($comment)) {
                $feedback_error = "Please enter a comment.";
            } else {
                $update_feedback = $conn->prepare("UPDATE feedback SET rating = ?, comment = ?, updated_at = NOW() WHERE feedback_id = ? AND user_id = ?");
                $update_feedback->bind_param("isii", $rating, $comment, $feedback_id, $user_id);
                
                if ($update_feedback->execute()) {
                    $feedback_success = "Feedback updated successfully!";
                    $edit_feedback_id = null;
                } else {
                    $feedback_error = "Failed to update feedback. Please try again.";
                }
                $update_feedback->close();
            }
            $verify_feedback->close();
        }
    }
    
    if (isset($_POST['delete_feedback'])) {
        if (!$user_id) {
            $feedback_error = "You must be logged in to delete feedback.";
        } else {
            $feedback_id = (int)$_POST['feedback_id'];
            
            $verify_feedback = $conn->prepare("SELECT feedback_id FROM feedback WHERE feedback_id = ? AND user_id = ?");
            $verify_feedback->bind_param("ii", $feedback_id, $user_id);
            $verify_feedback->execute();
            $verify_feedback->store_result();
            
            if ($verify_feedback->num_rows === 0) {
                $feedback_error = "You can only delete your own feedback.";
            } else {
                $delete_feedback = $conn->prepare("DELETE FROM feedback WHERE feedback_id = ? AND user_id = ?");
                $delete_feedback->bind_param("ii", $feedback_id, $user_id);
                
                if ($delete_feedback->execute()) {
                    $feedback_success = "Feedback deleted successfully!";
                } else {
                    $feedback_error = "Failed to delete feedback. Please try again.";
                }
                $delete_feedback->close();
            }
            $verify_feedback->close();
        }
    }
}

$message_error = '';
$message_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_message'])) {
        if (!$user_id) {
            $message_error = "You must be logged in to send a message.";
        } else {
            $message = isset($_POST['message']) ? trim($_POST['message']) : '';
            
            if (empty($message)) {
                $message_error = "Please enter a message.";
            } elseif (strlen($message) > 1000) {
                $message_error = "Message is too long (max 1000 characters).";
            } else {
                $head_user_id = getHeadUserId($conn, $number_id);
                $head_name = getHeadName($conn, $number_id);
                
                if (!$head_user_id) {
                    $message_error = "Cannot send message: Head user not found for this contact.";
                } else {
                    $check_conversation = $conn->prepare("
                        SELECT conversation_id FROM conversations 
                        WHERE number_id = ? AND initiated_by = ?
                        LIMIT 1
                    ");
                    $check_conversation->bind_param("ii", $number_id, $user_id);
                    $check_conversation->execute();
                    $check_result = $check_conversation->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $conv_row = $check_result->fetch_assoc();
                        $conversation_id = $conv_row['conversation_id'];
                        $check_conversation->close();
                    } else {
                        $create_conversation = $conn->prepare("
                            INSERT INTO conversations (number_id, initiated_by) 
                            VALUES (?, ?)
                        ");
                        $create_conversation->bind_param("ii", $number_id, $user_id);
                        
                        if ($create_conversation->execute()) {
                            $conversation_id = $conn->insert_id;
                        } else {
                            $message_error = "Failed to start conversation. Please try again.";
                            $conversation_id = null;
                        }
                        $create_conversation->close();
                    }
                    
                    if ($conversation_id) {
                        $insert_message = $conn->prepare("
                            INSERT INTO messages (sender_id, receiver_id, number_id, conversation_id, message, created_at) 
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        $insert_message->bind_param("iiisi", $user_id, $head_user_id, $number_id, $conversation_id, $message);
                        
                        if ($insert_message->execute()) {
                            $message_success = "Message sent to " . htmlspecialchars($contact['head']) . "!";
                            $_POST['message'] = '';
                            header("Location: $current_script?id=$number_id&conversation_id=$conversation_id");
                            exit;
                        } else {
                            $message_error = "Failed to send message. Error: " . $conn->error;
                        }
                        $insert_message->close();
                    }
                }
            }
        }
    }
    
    if (isset($_POST['send_reply'])) {
        if (!$user_id) {
            $message_error = "You must be logged in to reply.";
        } else {
            $conversation_id = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;
            $reply_message = isset($_POST['reply_message']) ? trim($_POST['reply_message']) : '';
            
            if (empty($reply_message)) {
                $message_error = "Please enter a reply.";
            } elseif (strlen($reply_message) > 1000) {
                $message_error = "Reply is too long (max 1000 characters).";
            } elseif (!$conversation_id) {
                $message_error = "Conversation ID is missing.";
            } else {
                $conv_info = $conn->prepare("SELECT initiated_by FROM conversations WHERE conversation_id = ?");
                $conv_info->bind_param("i", $conversation_id);
                $conv_info->execute();
                $conv_result = $conv_info->get_result();
                $conv_data = $conv_result->fetch_assoc();
                $conv_info->close();
                
                if ($conv_data) {
                    $receiver_id = $conv_data['initiated_by'];
                    $sender_id = $user_id;
                    
                    $insert_reply = $conn->prepare("
                        INSERT INTO messages (sender_id, receiver_id, number_id, conversation_id, message, is_head_reply, created_at) 
                        VALUES (?, ?, ?, ?, ?, 1, NOW())
                    ");
                    $insert_reply->bind_param("iiisi", $sender_id, $receiver_id, $number_id, $conversation_id, $reply_message);
                    
                    if ($insert_reply->execute()) {
                        $message_success = "Reply sent successfully!";
                        header("Location: $current_script?id=$number_id&conversation_id=$conversation_id");
                        exit;
                    } else {
                        $message_error = "Failed to send reply. Error: " . $conn->error;
                    }
                    $insert_reply->close();
                } else {
                    $message_error = "Conversation not found.";
                }
            }
        }
    }
}

if ($edit_feedback_id && $user_id) {
    $edit_feedback_stmt = $conn->prepare("SELECT * FROM feedback WHERE feedback_id = ? AND user_id = ?");
    $edit_feedback_stmt->bind_param("ii", $edit_feedback_id, $user_id);
    $edit_feedback_stmt->execute();
    $edit_feedback_result = $edit_feedback_stmt->get_result();
    
    if ($edit_feedback_result->num_rows === 1) {
        $current_edit_feedback = $edit_feedback_result->fetch_assoc();
    } else {
        $feedback_error = "You can only edit your own feedback.";
        $edit_feedback_id = null;
    }
    $edit_feedback_stmt->close();
}

$feedback_query = "
    SELECT f.*, u.username 
    FROM feedback f 
    JOIN users u ON f.user_id = u.user_id 
    WHERE f.number_id = ? 
    ORDER BY f.updated_at DESC, f.created_at DESC
    LIMIT 10
";
$feedback_stmt = $conn->prepare($feedback_query);
$feedback_stmt->bind_param("i", $number_id);
$feedback_stmt->execute();
$feedback_result = $feedback_stmt->get_result();
$feedbacks = $feedback_result->fetch_all(MYSQLI_ASSOC);

$avg_rating_query = "SELECT AVG(rating) as avg_rating, COUNT(*) as total_feedbacks FROM feedback WHERE number_id = ?";
$avg_stmt = $conn->prepare($avg_rating_query);
$avg_stmt->bind_param("i", $number_id);
$avg_stmt->execute();
$avg_result = $avg_stmt->get_result();
$avg_data = $avg_result->fetch_assoc();
$avg_rating = $avg_data['avg_rating'] ? round($avg_data['avg_rating'], 1) : 0;
$total_feedbacks = $avg_data['total_feedbacks'];

$user_feedback = null;
if ($user_id) {
    $user_feedback_stmt = $conn->prepare("SELECT * FROM feedback WHERE number_id = ? AND user_id = ?");
    $user_feedback_stmt->bind_param("ii", $number_id, $user_id);
    $user_feedback_stmt->execute();
    $user_feedback_result = $user_feedback_stmt->get_result();
    $user_feedback = $user_feedback_result->fetch_assoc();
    $user_feedback_stmt->close();
}

$conversations = [];
$selected_conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : null;
$conversation_messages = [];
$selected_conversation_info = null;

if ($user_id) {
    $head_user_id = getHeadUserId($conn, $number_id);
    $is_head = ($head_user_id == $user_id);
    
    if ($is_head) {
        $conversations_query = "
            SELECT 
                c.conversation_id,
                c.number_id,
                c.initiated_by,
                c.created_at as conversation_start,
                u.username as initiator_name,
                u.full_name as initiator_full_name,
                COUNT(m.message_id) as message_count,
                MAX(m.created_at) as last_message_time
            FROM conversations c
            JOIN users u ON c.initiated_by = u.user_id
            LEFT JOIN messages m ON c.conversation_id = m.conversation_id
            WHERE c.number_id = ?
            GROUP BY c.conversation_id
            ORDER BY last_message_time DESC
        ";
        $conv_stmt = $conn->prepare($conversations_query);
        $conv_stmt->bind_param("i", $number_id);
    } else {
        $conversations_query = "
            SELECT 
                c.conversation_id,
                c.number_id,
                c.initiated_by,
                c.created_at as conversation_start,
                u.username as initiator_name,
                u.full_name as initiator_full_name,
                COUNT(m.message_id) as message_count,
                MAX(m.created_at) as last_message_time
            FROM conversations c
            JOIN users u ON c.initiated_by = u.user_id
            LEFT JOIN messages m ON c.conversation_id = m.conversation_id
            WHERE c.number_id = ? AND c.initiated_by = ?
            GROUP BY c.conversation_id
            ORDER BY last_message_time DESC
        ";
        $conv_stmt = $conn->prepare($conversations_query);
        $conv_stmt->bind_param("ii", $number_id, $user_id);
    }
    
    $conv_stmt->execute();
    $conversations_result = $conv_stmt->get_result();
    $conversations = $conversations_result->fetch_all(MYSQLI_ASSOC);
    $conv_stmt->close();
    
    if ($selected_conversation_id) {
        $access_check = $conn->prepare("
            SELECT c.*, u.username as initiator_name, u.full_name as initiator_full_name 
            FROM conversations c
            JOIN users u ON c.initiated_by = u.user_id
            WHERE c.conversation_id = ? AND (
                c.initiated_by = ? 
                OR ? = (SELECT head_user_id FROM numbers WHERE number_id = c.number_id)
            )
        ");
        $access_check->bind_param("iii", $selected_conversation_id, $user_id, $user_id);
        $access_check->execute();
        $access_result = $access_check->get_result();
        
        if ($access_result->num_rows === 1) {
            $selected_conversation_info = $access_result->fetch_assoc();
            
            $messages_query = "
                SELECT m.*, u.username as sender_name, u.user_id as sender_user_id
                FROM messages m
                JOIN users u ON m.sender_id = u.user_id
                WHERE m.conversation_id = ?
                ORDER BY m.created_at ASC
            ";
            $messages_stmt = $conn->prepare($messages_query);
            $messages_stmt->bind_param("i", $selected_conversation_id);
            $messages_stmt->execute();
            $messages_result = $messages_stmt->get_result();
            $conversation_messages = $messages_result->fetch_all(MYSQLI_ASSOC);
            $messages_stmt->close();
        } else {
            $selected_conversation_id = null;
        }
        $access_check->close();
    }
}

$head_user_id = getHeadUserId($conn, $number_id);
$is_head = ($head_user_id == $user_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($contact['numbers']); ?> - Contact Details</title>
    <link rel="stylesheet" href="numstyle.css">
    <style>
    .chat-messages {
        height: 400px;
        overflow-y: auto;
        padding: 20px;
        background-color: #f7fafc;
        border-bottom: 1px solid #e2e8f0;
    }
    
    .chat-input-area {
        padding: 15px;
        background: white;
        border-top: 1px solid #e2e8f0;
    }
    
    .chat-input-form {
        display: flex;
        gap: 10px;
        align-items: flex-end;
    }
    
    .chat-input {
        flex: 1;
        min-height: 60px;
        max-height: 120px;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
        resize: vertical;
        font-family: inherit;
    }
    
    .chat-send-btn {
        width: 50px;
        height: 50px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        padding: 0;
        background-color: #2b6cb0;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    
    .chat-send-btn:hover {
        background-color: #1f4f8b;
    }
    
    .message-bubble {
        max-width: 70%;
        margin-bottom: 15px;
        padding: 12px 16px;
        border-radius: 18px;
        position: relative;
        clear: both;
        word-wrap: break-word;
    }
    
    .message-sent {
        background-color: #2b6cb0;
        color: white;
        float: right;
        border-bottom-right-radius: 4px;
    }
    
    .message-received {
        background-color: white;
        color: #2d3748;
        float: left;
        border: 1px solid #e2e8f0;
        border-bottom-left-radius: 4px;
    }
    
    .message-text {
        line-height: 1.5;
        margin-bottom: 5px;
    }
    
    .message-info {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        opacity: 0.8;
    }
    
    .message-sent .message-info {
        color: rgba(255,255,255,0.8);
    }
    
    .message-received .message-info {
        color: #718096;
    }
    
    .chat-header {
        background-color: #2b6cb0;
        color: white;
        padding: 15px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .chat-header h3 {
        margin: 0;
        color: white;
        font-size: 16px;
    }
    
    .chat-back-btn {
        color: white;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
    }
    
    .chat-back-btn:hover {
        text-decoration: underline;
    }
    
    .no-messages {
        text-align: center;
        color: #a0aec0;
        font-style: italic;
        padding: 40px 20px;
    }
    
    .chat-status-info {
        background: #f8f9fa;
        padding: 10px 15px;
        border-radius: 5px;
        margin-bottom: 15px;
        font-size: 13px;
        color: #4a5568;
    }
    
    .conversation-item {
        display: block;
        padding: 15px;
        background-color: white;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        text-decoration: none;
        color: inherit;
        transition: all 0.2s;
        margin-bottom: 10px;
    }
    
    .conversation-item:hover,
    .conversation-item.active {
        background-color: #f7fafc;
        border-color: #2b6cb0;
    }
    
    .conversation-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }
    
    .conversation-with {
        font-weight: 600;
        color: #2b6cb0;
    }
    
    .conversation-date {
        color: #718096;
        font-size: 13px;
    }
    
    .conversation-preview {
        color: #4a5568;
        font-size: 14px;
        margin-bottom: 8px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    .conversation-meta {
        display: flex;
        justify-content: space-between;
        color: #a0aec0;
        font-size: 12px;
    }
    
    .conversations-list {
        max-height: 300px;
        overflow-y: auto;
        margin-bottom: 20px;
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
            <?php endif; ?>
            <li><a href="profilepage.php">Profile</a></li>
            <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
        <?php endif; ?>
    </ul>
</div>

<div class="content">
    <div class="contact-card">
        <div class="contact-header">
            <div class="contact-title">
                <div class="contact-icon">
                    <?php echo substr($contact['description'], 0, 1); ?>
                </div>
                <div class="contact-title-text">
                    <h1><?php echo htmlspecialchars($contact['numbers']); ?></h1>
                    <div class="description"><?php echo htmlspecialchars($contact['description']); ?></div>
                </div>
            </div>
            <a href="homepage.php" class="back-button">← Back to Directory</a>
        </div>

        <div class="contact-details">
            <div class="detail-section">
                <h3>Contact Information</h3>
                <div class="detail-item">
                    <div class="detail-label">Contact Number</div>
                    <div class="detail-value contact-number">
                        <?php echo htmlspecialchars($contact['numbers']); ?>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Type</div>
                    <div class="detail-value"><?php echo htmlspecialchars($contact['description']); ?></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <?php if (isset($contact['status'])): ?>
                            <span class="status-badge <?php echo $contact['status'] === 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                <?php echo ucfirst($contact['status']); ?>
                            </span>
                        <?php else: ?>
                            <span class="status-badge status-decommissioned">Unknown</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <h3>Head Information</h3>
                <div class="detail-item">
                    <div class="detail-label">Head of Department/Unit</div>
                    <div class="detail-value head-value"><?php echo htmlspecialchars($contact['head']); ?></div>
                </div>
            </div>

            <div class="detail-section">
                <h3>Organizational Information</h3>
                <?php if ($org_name): ?>
                    <div class="detail-item">
                        <div class="detail-label">Organization</div>
                        <div class="detail-value">
                            <span class="org-badge"><?php echo $org_type; ?></span>
                            <?php echo htmlspecialchars($org_name); ?>
                            <?php if ($org_status): ?>
                                <span class="status-badge <?php echo $org_status === 'active' ? 'status-active' : 'status-decommissioned'; ?>">
                                    <?php echo ucfirst($org_status); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($contact['parent_division'] && $org_type !== 'Division'): ?>
                        <div class="detail-item">
                            <div class="detail-label">Parent Division</div>
                            <div class="detail-value"><?php echo htmlspecialchars($contact['parent_division']); ?></div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="detail-item">
                        <div class="detail-value no-org">
                            No organization assigned
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="feedback-section">
        <div class="section-header">
            <h2>User Feedback</h2>
            <div class="rating-summary">
                <div class="average-rating"><?php echo $avg_rating; ?>/5</div>
                <div>
                    <div class="star-rating readonly">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <input type="radio" id="avg-star-<?php echo $i; ?>" value="<?php echo $i; ?>" <?php echo round($avg_rating) == $i ? 'checked' : ''; ?> disabled>
                            <label for="avg-star-<?php echo $i; ?>">★</label>
                        <?php endfor; ?>
                    </div>
                    <div class="total-feedbacks">Based on <?php echo $total_feedbacks; ?> reviews</div>
                </div>
            </div>
        </div>

        <?php if ($feedback_error): ?>
            <div class="error-message"><?php echo $feedback_error; ?></div>
        <?php endif; ?>
        
        <?php if ($feedback_success): ?>
            <div class="success-message"><?php echo $feedback_success; ?></div>
        <?php endif; ?>

        <?php if ($edit_feedback_id && $current_edit_feedback): ?>
            <div class="feedback-form editing">
                <h3>Edit Your Feedback</h3>
                <form method="POST">
                    <input type="hidden" name="feedback_id" value="<?php echo $current_edit_feedback['feedback_id']; ?>">
                    
                    <div class="form-group">
                        <label>Rating</label>
                        <div class="star-rating">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" id="edit-star-<?php echo $i; ?>" name="rating" 
                                       value="<?php echo $i; ?>" 
                                       <?php echo $current_edit_feedback['rating'] == $i ? 'checked' : ''; ?> required>
                                <label for="edit-star-<?php echo $i; ?>">★</label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit-comment">Your Feedback</label>
                        <textarea id="edit-comment" name="comment" placeholder="Update your feedback..." required><?php echo htmlspecialchars($current_edit_feedback['comment']); ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="update_feedback" class="update-btn">Update Feedback</button>
                        <button type="submit" name="delete_feedback" class="delete-btn" 
                                onclick="return confirm('Are you sure you want to delete your feedback? This action cannot be undone.');">
                            Delete Feedback
                        </button>
                        <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>" class="cancel-btn">Cancel</a>
                    </div>
                </form>
            </div>
        <?php elseif (!$user_feedback && $user_id): ?>
            <div class="feedback-form">
                <h3>Leave Your Feedback</h3>
                <form method="POST">
                    <div class="form-group">
                        <label>Rating</label>
                        <div class="star-rating">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" id="star-<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" required>
                                <label for="star-<?php echo $i; ?>">★</label>
                            <?php endfor; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="comment">Your Feedback</label>
                        <textarea id="comment" name="comment" placeholder="Share your experience with this contact..." required><?php echo isset($_POST['comment']) ? htmlspecialchars($_POST['comment']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="submit_feedback" class="submit-btn">Submit Feedback</button>
                    </div>
                </form>
            </div>
        <?php elseif ($user_feedback && !$edit_feedback_id): ?>
            <div class="info-message">
                You have already submitted feedback for this contact. 
                <div class="star-rating readonly inline-rating">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <input type="radio" id="user-star-<?php echo $i; ?>" value="<?php echo $i; ?>" <?php echo $user_feedback['rating'] == $i ? 'checked' : ''; ?> disabled>
                        <label for="user-star-<?php echo $i; ?>">★</label>
                    <?php endfor; ?>
                </div>
            </div>
        <?php elseif (!$user_id): ?>
            <div class="info-message">
                Please <a href="login.php">login</a> to leave feedback for this contact.
            </div>
        <?php endif; ?>

        <?php if (!empty($feedbacks)): ?>
            <h3 class="recent-feedback-title">Recent Feedback</h3>
            <div class="feedback-list">
                <?php foreach ($feedbacks as $feedback): 
                    $is_own_feedback = ($user_id && $feedback['user_id'] == $user_id);
                ?>
                    <div class="feedback-item <?php echo $is_own_feedback ? 'own-feedback' : ''; ?>">
                        <div class="feedback-header">
                            <div class="feedback-user">
                                <?php echo htmlspecialchars($feedback['username']); ?>
                                <?php if ($is_own_feedback): ?>
                                    <span class="own-badge">Your Feedback</span>
                                <?php endif; ?>
                            </div>
                            <div class="feedback-date">
                                <?php echo date('M d, Y', strtotime($feedback['updated_at'])); ?>
                                <?php if ($feedback['updated_at'] != $feedback['created_at']): ?>
                                    <span class="edited-badge">(edited)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="star-rating readonly" style="margin-bottom: 10px;">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" id="fb-<?php echo $feedback['feedback_id']; ?>-star-<?php echo $i; ?>" 
                                       value="<?php echo $i; ?>" <?php echo $feedback['rating'] == $i ? 'checked' : ''; ?> disabled>
                                <label for="fb-<?php echo $feedback['feedback_id']; ?>-star-<?php echo $i; ?>">★</label>
                            <?php endfor; ?>
                        </div>
                        <div class="feedback-comment"><?php echo nl2br(htmlspecialchars($feedback['comment'])); ?></div>
                        
                        <?php if ($is_own_feedback && !$edit_feedback_id): ?>
                            <div class="feedback-actions-small">
                                <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>&edit_feedback=<?php echo $feedback['feedback_id']; ?>" class="edit-btn">Edit</a>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="feedback_id" value="<?php echo $feedback['feedback_id']; ?>">
                                    <button type="submit" name="delete_feedback" class="delete-btn small-btn" 
                                            onclick="return confirm('Are you sure you want to delete your feedback? This action cannot be undone.');">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif (!$user_feedback && !$edit_feedback_id): ?>
            <div class="info-message centered-message">
                No feedback yet. Be the first to share your experience!
            </div>
        <?php endif; ?>
    </div>

    <div class="messaging-section">
        <div class="section-header">
            <h2>Chat with <?php echo htmlspecialchars($contact['head']); ?></h2>
        </div>

        <?php if ($message_error): ?>
            <div class="error-message"><?php echo $message_error; ?></div>
        <?php endif; ?>
        
        <?php if ($message_success): ?>
            <div class="success-message"><?php echo $message_success; ?></div>
        <?php endif; ?>

        <?php if ($user_id): ?>
            <div class="chat-status-info">
                <strong>Chat Status:</strong> 
                You are <?php echo $is_head ? 'the HEAD' : 'a USER'; ?> of this contact.
                <?php if ($selected_conversation_id): ?>
                    Viewing conversation #<?php echo $selected_conversation_id; ?>
                <?php endif; ?>
            </div>

            <?php if ($selected_conversation_id && $selected_conversation_info): ?>
                <div class="chat-container">
                    <div class="chat-header">
                        <h3>
                            <?php if ($user_id == $selected_conversation_info['initiated_by']): ?>
                                Chat with <?php echo htmlspecialchars($contact['head']); ?>
                            <?php else: ?>
                                Chat with <?php echo htmlspecialchars($selected_conversation_info['initiator_name']); ?>
                            <?php endif; ?>
                        </h3>
                        <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>" class="chat-back-btn">← Back to Conversations</a>
                    </div>
                    
                    <div class="chat-messages" id="chat-messages">
                        <?php foreach ($conversation_messages as $msg): 
                            $is_sent = ($msg['sender_id'] == $user_id);
                        ?>
                            <div class="message-bubble <?php echo $is_sent ? 'message-sent' : 'message-received'; ?>">
                                <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                <div class="message-info">
                                    <span class="message-sender"><?php echo htmlspecialchars($msg['sender_name']); ?></span>
                                    <span class="message-time"><?php echo date('M d, Y H:i', strtotime($msg['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($conversation_messages)): ?>
                            <div class="no-messages">No messages yet. Start the conversation!</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="chat-input-area">
                        <?php if ($user_id == $selected_conversation_info['initiated_by']): ?>
                            <form method="POST" class="chat-input-form" id="chatForm">
                                <input type="hidden" name="conversation_id" value="<?php echo $selected_conversation_id; ?>">
                                <textarea class="chat-input" name="message" id="messageInput" placeholder="Type your message here..." required></textarea>
                                <button type="submit" name="send_message" class="chat-send-btn">📤</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" class="chat-input-form" id="chatForm">
                                <input type="hidden" name="conversation_id" value="<?php echo $selected_conversation_id; ?>">
                                <textarea class="chat-input" name="reply_message" id="replyInput" placeholder="Type your reply here..." required></textarea>
                                <button type="submit" name="send_reply" class="chat-send-btn">↩️</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif (!empty($conversations)): ?>
                <h3 class="conversations-title">Your Conversations</h3>
                <div class="conversations-list">
                    <?php foreach ($conversations as $conv): ?>
                        <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>&conversation_id=<?php echo $conv['conversation_id']; ?>" 
                           class="conversation-item <?php echo ($selected_conversation_id == $conv['conversation_id']) ? 'active' : ''; ?>">
                            <div class="conversation-header">
                                <div class="conversation-with">
                                    <?php if ($user_id == $conv['initiated_by']): ?>
                                        Chat with <?php echo htmlspecialchars($contact['head']); ?>
                                    <?php else: ?>
                                        Chat with <?php echo htmlspecialchars($conv['initiator_name']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="conversation-date">
                                    <?php echo date('M d', strtotime($conv['last_message_time'] ?: $conv['conversation_start'])); ?>
                                </div>
                            </div>
                            <?php 
                            $preview_stmt = $conn->prepare("
                                SELECT message FROM messages 
                                WHERE conversation_id = ? 
                                ORDER BY created_at DESC LIMIT 1
                            ");
                            $preview_stmt->bind_param("i", $conv['conversation_id']);
                            $preview_stmt->execute();
                            $preview_result = $preview_stmt->get_result();
                            $last_message = $preview_result->fetch_assoc();
                            $preview_stmt->close();
                            ?>
                            <div class="conversation-preview">
                                <?php echo htmlspecialchars($last_message['message'] ?? 'No messages yet'); ?>
                            </div>
                            <div class="conversation-meta">
                                <span><?php echo $conv['message_count']; ?> messages</span>
                                <span>Started <?php echo date('M d, Y', strtotime($conv['conversation_start'])); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                
                <?php if (!$selected_conversation_id): ?>
                    <div class="message-form">
                        <h3>Start New Conversation</h3>
                        <form method="POST">
                            <div class="form-group">
                                <label for="message">Message to <?php echo htmlspecialchars($contact['head']); ?></label>
                                <textarea id="message" name="message" placeholder="Type your message here..." required><?php echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="send_message" class="submit-btn">Start Conversation</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="message-form">
                    <h3>Start New Conversation</h3>
                    <form method="POST">
                        <div class="form-group">
                            <label for="message">Message to <?php echo htmlspecialchars($contact['head']); ?></label>
                            <textarea id="message" name="message" placeholder="Type your message here..." required></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="send_message" class="submit-btn">Start Conversation</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="info-message">
                Please <a href="login.php">login</a> to chat with <?php echo htmlspecialchars($contact['head']); ?>.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="footer">
    © 2026 Intercom Directory. All rights reserved.<br>
    Developed by TNTS Programming Students JT.DP.RR
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chat-messages');
    
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    const chatForm = document.getElementById('chatForm');
    
    if (chatForm) {
        const messageInput = document.getElementById('messageInput') || document.getElementById('replyInput');
        
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const message = messageInput.value.trim();
            
            if (message) {
                fetch(this.action, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.redirected) {
                        window.location.href = response.url;
                    } else {
                        location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    location.reload();
                });
            }
        });
        
        if (messageInput) {
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    chatForm.dispatchEvent(new Event('submit'));
                }
            });
            
            messageInput.focus();
        }
    }
    
    if (window.location.hash === '#chat') {
        const chatSection = document.querySelector('.messaging-section');
        if (chatSection) {
            chatSection.scrollIntoView({ behavior: 'smooth' });
        }
    }
    
    setInterval(function() {
        if (window.location.href.includes('conversation_id=')) {
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newMessages = doc.querySelectorAll('.message-bubble');
                    const currentMessages = document.querySelectorAll('.message-bubble');
                    
                    if (newMessages.length > currentMessages.length) {
                        location.reload();
                    }
                })
                .catch(error => console.error('Error checking for new messages:', error));
        }
    }, 3000);
});
</script>
</body>
</html>