<?php
require_once 'conn.php';
require_once 'archive_functions.php';

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

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

$current_script = basename($_SERVER['PHP_SELF']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: homepage.php');
    exit;
}

// Initialize all variables to prevent undefined errors
$message_error = '';
$message_success = '';
$feedback_error = '';
$feedback_success = '';
$avg_rating = 0;
$total_feedbacks = 0;
$feedbacks = [];
$user_feedback = null;
$selected_conversation_id = null;
$conversation_messages = [];
$selected_conversation_info = null;
$conversations = [];
$archived_conversations_count = 0;
$archived_this_load = 0;
$removed_this_load = 0;

$number_id = (int)$_GET['id'];
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$edit_feedback_id = isset($_GET['edit_feedback']) ? (int)$_GET['edit_feedback'] : null;
$current_edit_feedback = null;

// View toggle parameter
$view_archived = isset($_GET['view']) && $_GET['view'] === 'archived';

// Get head user ID
$head_user_id = getHeadUserId($conn, $number_id);
$is_head = ($head_user_id == $user_id);

// ==========================================
// ARCHIVE SYSTEM - SIMPLIFIED VERSION
// ==========================================
if ($user_id) {
    // Only check for archiving if NOT viewing archived conversations
    if (!$view_archived) {
        // Check for old conversations to archive (30+ minutes inactive)
        $thirty_minutes_ago = date('Y-m-d H:i:s', strtotime('-30 minutes'));
        
        $old_convs_sql = "
            SELECT conversation_id 
            FROM conversations 
            WHERE number_id = ? 
            AND is_archived = 0
            AND last_activity < ?
        ";
        
        $old_stmt = $conn->prepare($old_convs_sql);
        $old_stmt->bind_param("is", $number_id, $thirty_minutes_ago);
        $old_stmt->execute();
        $old_result = $old_stmt->get_result();
        
        while ($conv = $old_result->fetch_assoc()) {
            $conversation_id = $conv['conversation_id'];
            
            // Archive the conversation
            if (archiveConversationImmediately($conn, $conversation_id)) {
                $archived_this_load++;
            }
        }
        $old_stmt->close();
    }
    
    // Always clean up old archived conversations (7 days old)
    $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
    
    if ($is_head) {
        $removed_this_load = removeOldArchivedConversations($conn, $number_id, null, $seven_days_ago);
    } else {
        $removed_this_load = removeOldArchivedConversations($conn, $number_id, $user_id, $seven_days_ago);
    }
}

// Check archived count for toggle button
if ($user_id) {
    if ($is_head) {
        $archived_count_stmt = $conn->prepare("
            SELECT COUNT(*) as archive_count 
            FROM conversations_archive 
            WHERE number_id = ?
        ");
        $archived_count_stmt->bind_param("i", $number_id);
    } else {
        $archived_count_stmt = $conn->prepare("
            SELECT COUNT(*) as archive_count 
            FROM conversations_archive 
            WHERE number_id = ? AND initiated_by = ?
        ");
        $archived_count_stmt->bind_param("ii", $number_id, $user_id);
    }
    
    $archived_count_stmt->execute();
    $archive_result = $archived_count_stmt->get_result();
    $archive_data = $archive_result->fetch_assoc();
    $archived_conversations_count = $archive_data['archive_count'] ?? 0;
    $archived_count_stmt->close();
}

// Load contact information
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

// Organization info
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

// Load conversations based on view
$selected_conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : null;



if ($user_id) {
    if ($view_archived) {
        // VIEW ARCHIVED CONVERSATIONS (TABLE FORMAT)
        $conversations_query = "
            SELECT 
                ca.conversation_id,
                ca.number_id,
                ca.initiated_by,
                ca.created_at as conversation_start,
                ca.last_activity,
                ca.archived_at,
                u.username as initiator_name,
                u.full_name as initiator_full_name,
                (
                    SELECT COUNT(*) 
                    FROM messages_archive 
                    WHERE conversation_id = ca.conversation_id
                ) as message_count,
                (
                    SELECT MAX(created_at) 
                    FROM messages_archive 
                    WHERE conversation_id = ca.conversation_id
                ) as last_message_time
            FROM conversations_archive ca
            JOIN users u ON ca.initiated_by = u.user_id
            WHERE ca.number_id = ? 
            " . (!$is_head ? "AND ca.initiated_by = ?" : "") . "
            ORDER BY ca.archived_at DESC
        ";
        
        $conv_stmt = $conn->prepare($conversations_query);
        if (!$is_head) {
            $conv_stmt->bind_param("ii", $number_id, $user_id);
        } else {
            $conv_stmt->bind_param("i", $number_id);
        }
    } else {
        // VIEW ACTIVE CONVERSATIONS
        $conversations_query = "
            SELECT 
                c.conversation_id,
                c.number_id,
                c.initiated_by,
                c.created_at as conversation_start,
                c.last_activity,
                u.username as initiator_name,
                u.full_name as initiator_full_name,
                (
                    SELECT COUNT(*) 
                    FROM messages 
                    WHERE conversation_id = c.conversation_id
                    AND is_archived = 0
                ) as message_count,
                (
                    SELECT MAX(created_at) 
                    FROM messages 
                    WHERE conversation_id = c.conversation_id
                    AND is_archived = 0
                ) as last_message_time
            FROM conversations c
            JOIN users u ON c.initiated_by = u.user_id
            WHERE c.number_id = ? 
            AND c.is_archived = 0
            " . (!$is_head ? "AND c.initiated_by = ?" : "") . "
            ORDER BY c.last_activity DESC
        ";
        
        $conv_stmt = $conn->prepare($conversations_query);
        if (!$is_head) {
            $conv_stmt->bind_param("ii", $number_id, $user_id);
        } else {
            $conv_stmt->bind_param("i", $number_id);
        }
    }
    
    $conv_stmt->execute();
    $conversations_result = $conv_stmt->get_result();
    $conversations = $conversations_result->fetch_all(MYSQLI_ASSOC);
    $conv_stmt->close();
    
    // Load selected conversation (only active ones)
    if ($selected_conversation_id && !$view_archived) {
        $access_check = $conn->prepare("
            SELECT c.*, u.username as initiator_name, u.full_name as initiator_full_name 
            FROM conversations c
            JOIN users u ON c.initiated_by = u.user_id
            WHERE c.conversation_id = ? 
            AND c.is_archived = 0
            AND (
                c.initiated_by = ? 
                OR ? = (SELECT head_user_id FROM numbers WHERE number_id = c.number_id)
            )
        ");
        $access_check->bind_param("iii", $selected_conversation_id, $user_id, $user_id);
        $access_check->execute();
        $access_result = $access_check->get_result();
        
        if ($access_result->num_rows === 1) {
            $selected_conversation_info = $access_result->fetch_assoc();

            // Mark messages as read
            $mark_read_sql = "
                UPDATE messages 
                SET is_read = 1 
                WHERE conversation_id = ? 
                AND receiver_id = ?
                AND is_read = 0
                AND is_archived = 0
            ";
            $mark_read_stmt = $conn->prepare($mark_read_sql);
            $mark_read_stmt->bind_param("ii", $selected_conversation_id, $user_id);
            $mark_read_stmt->execute();
            $mark_read_stmt->close();
            
            // Load messages
            $messages_query = "
                SELECT m.*, u.username as sender_name, u.user_id as sender_user_id
                FROM messages m
                JOIN users u ON m.sender_id = u.user_id
                WHERE m.conversation_id = ?
                AND m.is_archived = 0
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

// Load feedback data
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
$feedback_stmt->close();

// Get average rating - FIXED to handle NULL values
$avg_rating_query = "SELECT AVG(rating) as avg_rating, COUNT(*) as total_feedbacks FROM feedback WHERE number_id = ?";
$avg_stmt = $conn->prepare($avg_rating_query);
$avg_stmt->bind_param("i", $number_id);
$avg_stmt->execute();
$avg_result = $avg_stmt->get_result();
$avg_data = $avg_result->fetch_assoc();

// Check if data exists and avg_rating is not null
if ($avg_data && $avg_data['avg_rating'] !== null) {
    $avg_rating = round($avg_data['avg_rating'], 1);
} else {
    $avg_rating = 0;
}

$total_feedbacks = $avg_data['total_feedbacks'] ?? 0;

// Get user feedback if logged in
if ($user_id) {
    $user_feedback_stmt = $conn->prepare("SELECT * FROM feedback WHERE number_id = ? AND user_id = ?");
    $user_feedback_stmt->bind_param("ii", $number_id, $user_id);
    $user_feedback_stmt->execute();
    $user_feedback_result = $user_feedback_stmt->get_result();
    $user_feedback = $user_feedback_result->fetch_assoc();
    $user_feedback_stmt->close();
}

// Load selected archived conversation for viewing
if ($selected_conversation_id && $view_archived) {
    $access_check = $conn->prepare("
        SELECT ca.*, u.username as initiator_name, u.full_name as initiator_full_name 
        FROM conversations_archive ca
        JOIN users u ON ca.initiated_by = u.user_id
        WHERE ca.conversation_id = ? 
        " . (!$is_head ? "AND ca.initiated_by = ?" : "") . "
    ");
    
    if (!$is_head) {
        $access_check->bind_param("ii", $selected_conversation_id, $user_id);
    } else {
        $access_check->bind_param("i", $selected_conversation_id);
    }
    
    $access_check->execute();
    $access_result = $access_check->get_result();
    
    if ($access_result->num_rows === 1) {
        $selected_conversation_info = $access_result->fetch_assoc();
        
        // Load archived messages
        $messages_query = "
            SELECT ma.*, u.username as sender_name, u.user_id as sender_user_id
            FROM messages_archive ma
            JOIN users u ON ma.sender_id = u.user_id
            WHERE ma.conversation_id = ?
            ORDER BY ma.created_at ASC
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

// Handle POST requests for feedback and messages
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle feedback submission
    if (isset($_POST['submit_feedback'])) {
        if (!$user_id) {
            $feedback_error = "You must be logged in to submit feedback.";
        } else {
            $rating = (int)($_POST['rating'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            
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
    
    // Handle feedback update
    if (isset($_POST['update_feedback'])) {
        if (!$user_id) {
            $feedback_error = "You must be logged in to update feedback.";
        } else {
            $feedback_id = (int)($_POST['feedback_id'] ?? 0);
            $rating = (int)($_POST['rating'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            
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
    
    // Handle feedback deletion
    if (isset($_POST['delete_feedback'])) {
        if (!$user_id) {
            $feedback_error = "You must be logged in to delete feedback.";
        } else {
            $feedback_id = (int)($_POST['feedback_id'] ?? 0);
            
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
    
    // Handle message sending - SIMPLIFIED VERSION (like your working code)
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
                // Get head user ID for this contact
                $head_user_id = getHeadUserId($conn, $number_id);
                
                if (!$head_user_id) {
                    $message_error = "Cannot send message: Head user not found for this contact.";
                } else {
                    // Check if conversation already exists
                    $check_conversation = $conn->prepare("
                        SELECT conversation_id FROM conversations 
                        WHERE number_id = ? AND initiated_by = ?
                        AND is_archived = 0
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
                        // Create new conversation
                        $create_conversation = $conn->prepare("
                            INSERT INTO conversations (number_id, initiated_by, created_at, last_activity) 
                            VALUES (?, ?, NOW(), NOW())
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
                        if (empty($head_user_id) || $head_user_id <= 0) {
                            $message_error = "Invalid head user ID: $head_user_id";
                        } else {
                            // Insert the message
                            $insert_message = $conn->prepare("
                                INSERT INTO messages (sender_id, receiver_id, number_id, conversation_id, message, created_at) 
                                VALUES (?, ?, ?, ?, ?, NOW())
                            ");
                            $insert_message->bind_param("iiiis", $user_id, $head_user_id, $number_id, $conversation_id, $message);
                            
                            if ($insert_message->execute()) {
                                // Update conversation activity
                                updateConversationActivity($conn, $conversation_id);
                                
                                $message_success = "Message sent to " . htmlspecialchars($contact['head']) . "!";
                                $_POST['message'] = '';
                                header("Location: $current_script?id=$number_id&conversation_id=$conversation_id");
                                // Update conversation activity
                                if (updateConversationActivity($conn, $conversation_id)) {
                                    error_log("Successfully updated last_activity for conversation $conversation_id");
                                } else {
                                    error_log("FAILED to update last_activity for conversation $conversation_id");
                                }

                                exit;
                            } else {
                                $message_error = "Failed to send message. SQL Error: " . $conn->error;
                            }
                            $insert_message->close();
                        }
                    }   
                }
            }
        }
    }
    
    // Handle reply sending
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
                // Get conversation info
                $conv_info = $conn->prepare("
                    SELECT c.*, u.username as initiator_name, u.full_name as initiator_full_name 
                    FROM conversations c
                    JOIN users u ON c.initiated_by = u.user_id
                    WHERE c.conversation_id = ?
                    AND c.is_archived = 0
                ");
                $conv_info->bind_param("i", $conversation_id);
                $conv_info->execute();
                $conv_result = $conv_info->get_result();
                $conv_data = $conv_result->fetch_assoc();
                $conv_info->close();
                
                if ($conv_data) {
                    // Determine receiver
                    if ($user_id == $conv_data['initiated_by']) {
                        // User replying to head
                        $head_user_id = getHeadUserId($conn, $number_id);
                        $receiver_id = $head_user_id;
                    } else {
                        // Head replying to user
                        $receiver_id = $conv_data['initiated_by'];
                    }
                    
                    $insert_reply = $conn->prepare("
                        INSERT INTO messages (sender_id, receiver_id, number_id, conversation_id, message, created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $insert_reply->bind_param("iiiis", $user_id, $receiver_id, $number_id, $conversation_id, $reply_message);
                    
                    if ($insert_reply->execute()) {
                        updateConversationActivity($conn, $conversation_id);
                        
                        $message_success = "Reply sent successfully!";
                        header("Location: $current_script?id=$number_id&conversation_id=$conversation_id");
                        exit;
                    } else {
                        $message_error = "Failed to send reply. Error: " . $conn->error;
                    }
                    $insert_reply->close();
                } else {
                    $message_error = "Conversation not found or archived.";
                }
            }
        }
    }
}

// Check if editing feedback
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($contact['numbers']); ?> - Contact Details</title>
    <style>
    /* ===== BASE STYLES ===== */
    * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    body {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        background-color: #edf4fc;
    }

    /* ===== HEADER STYLES ===== */
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

    /* ===== CONTENT AREA ===== */
    .content {
        flex: 1;
        margin-top: 100px;
        padding: 20px;
        max-width: 1200px;
        margin-left: auto;
        margin-right: auto;
        width: 100%;
    }

    /* ===== CONTACT CARD STYLES ===== */
    .contact-card {
        background: white;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        margin-bottom: 30px;
        overflow: hidden;
    }

    .contact-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 25px 30px;
        background: linear-gradient(135deg, #2b6cb0 0%, #1f4f8b 100%);
        color: white;
    }

    .contact-title {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .contact-icon {
        width: 70px;
        height: 70px;
        background: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        font-weight: bold;
        color: #2b6cb0;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }

    .contact-title-text h1 {
        font-size: 32px;
        margin-bottom: 5px;
        color: white;
    }

    .description {
        font-size: 16px;
        opacity: 0.9;
    }

    .back-button {
        color: white;
        text-decoration: none;
        padding: 10px 20px;
        background: rgba(255,255,255,0.2);
        border-radius: 6px;
        font-weight: 600;
        transition: all 0.3s;
    }

    .back-button:hover {
        background: rgba(255,255,255,0.3);
        transform: translateY(-2px);
    }

    .contact-details {
        padding: 30px;
    }

    .detail-section {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 1px solid #e2e8f0;
    }

    .detail-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }

    .detail-section h3 {
        color: #2b6cb0;
        margin-bottom: 20px;
        font-size: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #e2e8f0;
    }

    .detail-item {
        display: flex;
        margin-bottom: 15px;
        align-items: center;
    }

    .detail-label {
        width: 200px;
        font-weight: 600;
        color: #4a5568;
        font-size: 15px;
    }

    .detail-value {
        flex: 1;
        color: #2d3748;
        font-size: 16px;
    }

    .contact-number {
        font-size: 24px;
        font-weight: 700;
        color: #2b6cb0;
    }

    .head-value {
        font-size: 20px;
        font-weight: 600;
        color: #2b6cb0;
    }

    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        margin-left: 10px;
    }

    .status-active {
        background-color: #c6f6d5;
        color: #22543d;
    }

    .status-decommissioned {
        background-color: #fed7d7;
        color: #742a2a;
    }

    .org-badge {
        display: inline-block;
        padding: 4px 12px;
        background-color: #e2e8f0;
        color: #4a5568;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        margin-right: 10px;
    }

    .no-org {
        color: #a0aec0;
        font-style: italic;
    }

    /* ===== FEEDBACK SECTION STYLES ===== */
    .feedback-section {
        background: white;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        margin-bottom: 30px;
        padding: 30px;
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 2px solid #e2e8f0;
    }

    .section-header h2 {
        color: #2b6cb0;
        font-size: 24px;
    }

    .rating-summary {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .average-rating {
        font-size: 48px;
        font-weight: 700;
        color: #2b6cb0;
    }

    .star-rating {
        display: flex;
        flex-direction: row-reverse;
        justify-content: flex-end;
    }

    .star-rating input {
        display: none;
    }

    .star-rating label {
        font-size: 24px;
        color: #e2e8f0;
        cursor: pointer;
        transition: color 0.2s;
    }

    .star-rating input:checked ~ label,
    .star-rating label:hover,
    .star-rating label:hover ~ label {
        color: #ffc107;
    }

    .star-rating.readonly label {
        cursor: default;
    }

    .star-rating.readonly input:checked ~ label {
        color: #ffc107;
    }

    .total-feedbacks {
        color: #718096;
        font-size: 14px;
        margin-top: 5px;
    }

    /* Error and Success Messages */
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

    .info-message {
        background-color: #e8f4fd;
        color: #084298;
        padding: 15px;
        border-radius: 6px;
        margin-bottom: 20px;
        text-align: center;
    }

    .info-message a {
        color: #2b6cb0;
        font-weight: 600;
        text-decoration: none;
    }

    .info-message a:hover {
        text-decoration: underline;
    }

    /* Feedback Form */
    .feedback-form {
        background-color: #f7fafc;
        padding: 25px;
        border-radius: 8px;
        margin-bottom: 30px;
        border: 1px solid #e2e8f0;
    }

    .feedback-form.editing {
        background-color: #fff5f5;
        border: 1px solid #fed7d7;
    }

    .feedback-form h3 {
        color: #2b6cb0;
        margin-bottom: 20px;
        font-size: 20px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #4a5568;
    }

    textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 16px;
        resize: vertical;
        min-height: 120px;
        font-family: inherit;
    }

    textarea:focus {
        outline: none;
        border-color: #2b6cb0;
        box-shadow: 0 0 0 3px rgba(43, 108, 176, 0.1);
    }

    .inline-rating {
        display: inline-flex;
        margin-left: 10px;
        vertical-align: middle;
    }

    .form-actions {
        display: flex;
        gap: 10px;
        margin-top: 20px;
    }

    .submit-btn, .update-btn, .delete-btn, .cancel-btn {
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        font-size: 14px;
        transition: all 0.2s;
    }

    .submit-btn, .update-btn {
        background-color: #2b6cb0;
        color: white;
    }

    .submit-btn:hover, .update-btn:hover {
        background-color: #1f4f8b;
    }

    .delete-btn {
        background-color: #e53e3e;
        color: white;
    }

    .delete-btn:hover {
        background-color: #c53030;
    }

    .cancel-btn {
        background-color: #718096;
        color: white;
        padding: 10px 20px;
        display: inline-block;
    }

    .cancel-btn:hover {
        background-color: #4a5568;
    }

    .small-btn {
        padding: 4px 10px !important;
        font-size: 12px !important;
    }

    /* Feedback List */
    .recent-feedback-title {
        color: #2b6cb0;
        margin: 25px 0 15px 0;
        font-size: 18px;
        padding-bottom: 10px;
        border-bottom: 1px solid #e2e8f0;
    }

    .feedback-list {
        margin-top: 20px;
    }

    .feedback-item {
        background-color: #f7fafc;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 15px;
        border: 1px solid #e2e8f0;
    }

    .feedback-item.own-feedback {
        background-color: #f0f9ff;
        border-color: #b6d4fe;
    }

    .feedback-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .feedback-user {
        font-weight: 600;
        color: #2d3748;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .own-badge {
        background-color: #2b6cb0;
        color: white;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 12px;
    }

    .feedback-date {
        color: #718096;
        font-size: 13px;
    }

    .edited-badge {
        color: #a0aec0;
        font-size: 12px;
        font-style: italic;
    }

    .feedback-comment {
        color: #4a5568;
        line-height: 1.6;
        font-size: 15px;
    }

    .feedback-actions-small {
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        gap: 10px;
    }

    .edit-btn {
        color: #2b6cb0;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
    }

    .edit-btn:hover {
        text-decoration: underline;
    }

    .centered-message {
        text-align: center;
        padding: 30px !important;
    }

    /* ===== MESSAGING SECTION ===== */
    .messaging-section {
        background: white;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        padding: 30px;
    }

    /* Chat styles */
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
    
    /* Archive Table Styles */
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
    
    .archived-conversation-id {
        color: #4a5568;
        font-weight: 500;
    }
    
    .archived-conversation-with {
        color: #2b6cb0;
        font-weight: 500;
    }
    
    .archived-message-count {
        background-color: #e2e8f0;
        color: #4a5568;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .archived-date {
        color: #718096;
        font-size: 13px;
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
    
    /* View Toggle Styles */
    .view-toggle-section {
        margin-bottom: 20px;
    }
    
    .view-toggle-buttons {
        display: flex;
        gap: 10px;
        margin-bottom: 10px;
    }
    
    .view-toggle-btn {
        padding: 10px 20px;
        background-color: #f7fafc;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
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
        padding: 2px 8px;
        border-radius: 12px;
        min-width: 20px;
        text-align: center;
    }
    
    /* FOOTER STYLES */
    .footer {
        background-color: #07417f;
        color: #fff;
        text-align: center;
        padding: 18px 10px;
        font-size: 14px;
        margin-top: auto;
    }

    /* RESPONSIVE STYLES */
    @media (max-width: 768px) {
        .header {
            flex-direction: column;
            padding: 15px;
            text-align: center;
        }
        
        .header .logo span {
            font-size: 1.3rem;
        }
        
        .content {
            margin-top: 140px;
            padding: 15px;
        }
        
        .contact-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .contact-title {
            flex-direction: column;
            text-align: center;
        }
        
        .detail-item {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .detail-label {
            width: 100%;
            margin-bottom: 5px;
        }
        
        .section-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        
        .rating-summary {
            width: 100%;
            justify-content: space-between;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .view-toggle-buttons {
            flex-direction: column;
        }
        
        .archived-table {
            display: block;
            overflow-x: auto;
        }
        /* Archived View Styles */
.chat-container.archived-view .message-bubble {
    opacity: 0.9;
}

.chat-container.archived-view .message-sent {
    background-color: #4a5568;
}

.chat-container.archived-view .message-received {
    background-color: #e2e8f0;
    color: #2d3748;
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

.archived-table tr {
    cursor: pointer;
}

.archived-table tr:hover {
    background-color: #f7fafc;
}

.archived-table tr:hover .view-btn {
    background-color: #2b6cb0;
    color: white;
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
        <?php if ($user_id): ?>
            <?php 
            // Simple admin check - you need to implement your own logic
            $is_admin = ($user_id == 1); // Replace with your actual admin check
            if ($is_admin): ?>
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
    <!-- Contact Card -->
    <div class="contact-card">
        <div class="contact-header">
            <div class="contact-title">
                <div class="contact-icon">
                    <?php echo !empty($contact['description']) ? substr($contact['description'], 0, 1) : '#'; ?>
                </div>
                <div class="contact-title-text">
                    <h1><?php echo htmlspecialchars($contact['numbers'] ?? 'Unknown'); ?></h1>
                    <div class="description"><?php echo htmlspecialchars($contact['description'] ?? 'No description'); ?></div>
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
                        <?php echo htmlspecialchars($contact['numbers'] ?? 'N/A'); ?>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Type</div>
                    <div class="detail-value"><?php echo htmlspecialchars($contact['description'] ?? 'N/A'); ?></div>
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
                    <div class="detail-value head-value"><?php echo htmlspecialchars($contact['head'] ?? 'Not assigned'); ?></div>
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

    <!-- Feedback Section -->
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

        <!-- Feedback Form -->
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
                        <textarea id="comment" name="comment" placeholder="Share your experience with this contact..." required></textarea>
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

        <!-- Feedback List -->
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

    <!-- Messaging Section -->
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

            <!-- View Toggle Section -->
            <div class="view-toggle-section">
                <div class="view-toggle-buttons">
                    <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>" 
                       class="view-toggle-btn <?php echo !$view_archived ? 'active' : ''; ?>">
                        Active Conversations
                    </a>
                    <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>&view=archived" 
                       class="view-toggle-btn <?php echo $view_archived ? 'active' : ''; ?>">
                        Archived Conversations
                        <?php if ($archived_conversations_count > 0): ?>
                            <span class="archive-count-badge"><?php echo $archived_conversations_count; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <?php if ($archived_this_load > 0 || $removed_this_load > 0): ?>
                    <div class="auto-process-notice">
                        <?php if ($archived_this_load > 0): ?>
                            <span>📁 Archived <?php echo $archived_this_load; ?> conversation(s)</span>
                        <?php endif; ?>
                        <?php if ($removed_this_load > 0): ?>
                            <span>🗑️ Cleaned up <?php echo $removed_this_load; ?> old conversation(s)</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($view_archived): ?>
                <!-- ARCHIVED CONVERSATIONS VIEW -->
                <?php if ($selected_conversation_id && $selected_conversation_info): ?>
                    <!-- VIEW SINGLE ARCHIVED CONVERSATION -->
                    <div class="chat-container archived-view">
                        <div class="chat-header" style="background-color: #718096;">
                            <h3>
                                📁 Archived Conversation
                                <?php if ($user_id == $selected_conversation_info['initiated_by']): ?>
                                    with <?php echo htmlspecialchars($contact['head']); ?>
                                <?php else: ?>
                                    with <?php echo htmlspecialchars($selected_conversation_info['initiator_name']); ?>
                                <?php endif; ?>
                                <small style="font-size: 12px; opacity: 0.8;">
                                    (Archived: <?php echo date('M d, Y H:i', strtotime($selected_conversation_info['archived_at'])); ?>)
                                </small>
                            </h3>
                            <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>&view=archived" class="chat-back-btn">← Back to Archive</a>
                        </div>
                        
                        <div class="archive-info-bar" style="background: #e8f4fd; padding: 10px 15px; border-bottom: 1px solid #b6d4fe;">
                            <div style="display: flex; justify-content: space-between; font-size: 13px;">
                                <div>
                                    <strong>Started:</strong> <?php echo date('M d, Y H:i', strtotime($selected_conversation_info['created_at'])); ?>
                                    | <strong>Last Activity:</strong> <?php echo date('M d, Y H:i', strtotime($selected_conversation_info['last_activity'])); ?>
                                </div>
                                <div>
                                    <strong>Archived:</strong> <?php echo date('M d, Y H:i', strtotime($selected_conversation_info['archived_at'])); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="chat-messages" id="chat-messages" style="background-color: #f8f9fa;">
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
                                <div class="no-messages">No messages found in this archived conversation.</div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="chat-input-area" style="background-color: #f1f5f9; border-top: 1px solid #cbd5e0;">
                            <div style="text-align: center; padding: 15px; color: #64748b; font-style: italic;">
                                <i class="fas fa-lock"></i> This conversation is archived and cannot be modified.
                            </div>
                        </div>
                    </div>
                    
                <?php elseif (!empty($conversations)): ?>
                    <!-- ARCHIVED CONVERSATIONS TABLE (with clickable rows) -->
                    <table class="archived-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>With</th>
                                <th>Messages</th>
                                <th>Started</th>
                                <th>Last Activity</th>
                                <th>Archived</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conversations as $conv): ?>
                                <tr>
                                    <td class="archived-conversation-id">
                                        #<?php echo $conv['conversation_id']; ?>
                                    </td>
                                    <td class="archived-conversation-with">
                                        <?php echo $is_head ? htmlspecialchars($conv['initiator_name']) : htmlspecialchars($contact['head']); ?>
                                    </td>
                                    <td>
                                        <span class="archived-message-count">
                                            <?php echo $conv['message_count']; ?> messages
                                        </span>
                                    </td>
                                    <td class="archived-date">
                                        <?php echo date('M d, Y H:i', strtotime($conv['conversation_start'])); ?>
                                    </td>
                                    <td class="archived-date">
                                        <?php echo date('M d, Y H:i', strtotime($conv['last_message_time'])); ?>
                                    </td>
                                    <td class="archived-date">
                                        <?php echo date('M d, Y H:i', strtotime($conv['archived_at'])); ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>&view=archived&conversation_id=<?php echo $conv['conversation_id']; ?>"
                                        class="view-btn" style="color: #2b6cb0; text-decoration: none; font-size: 13px;">
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-archived-message">
                        No archived conversations found. Archived conversations will appear here.
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- ACTIVE CONVERSATIONS VIEW -->
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
                            <form method="POST" class="chat-input-form" id="chatForm">
                                <input type="hidden" name="conversation_id" value="<?php echo $selected_conversation_id; ?>">
                                <?php if ($user_id == $selected_conversation_info['initiated_by']): ?>
                                    <!-- Regular user sending to head -->
                                    <textarea class="chat-input" name="message" id="messageInput" placeholder="Type your message here..." required></textarea>
                                    <button type="submit" name="send_message" class="chat-send-btn">📤</button>
                                <?php else: ?>
                                    <!-- Head replying to user -->
                                    <textarea class="chat-input" name="reply_message" id="replyInput" placeholder="Type your reply here..." required></textarea>
                                    <button type="submit" name="send_reply" class="chat-send-btn">↩️</button>
                                <?php endif; ?>
                            </form>
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
                                    AND is_archived = 0
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
                                    <textarea id="message" name="message" placeholder="Type your message here..." required></textarea>
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
        chatForm.addEventListener('submit', function(e) {
            // DON'T prevent default - let the form submit normally
            // This avoids the "0" message problem
            const messageInput = document.getElementById('messageInput') || document.getElementById('replyInput');
            const message = messageInput ? messageInput.value.trim() : '';
            
            // Simple validation
            if (!message || message === '') {
                e.preventDefault();
                alert('Please enter a message');
                return false;
            }
            
            // Allow normal form submission
            return true;
        });
        
        // Still allow Enter key to submit, but don't prevent default
        const messageInput = document.getElementById('messageInput') || document.getElementById('replyInput');
        if (messageInput) {
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    chatForm.submit();
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
    
    // Simple message refresh for active conversation
    setInterval(function() {
        if (window.location.href.includes('conversation_id=') && !window.location.href.includes('view=archived')) {
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