<?php
require_once 'conn.php';
require_once 'archive_functions.php';

date_default_timezone_set('Asia/Manila');

// Set MySQL timezone to match PHP
if ($conn) {
    $conn->query("SET time_zone = '+08:00'");
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
$edit_error = '';
$edit_success = '';
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
$is_editing = isset($_GET['edit']) && $_GET['edit'] == 'true';

// Check if this is an AJAX request
$is_ajax = isset($_POST['ajax']) && $_POST['ajax'] === 'true';

// Get unread admin message count
if ($user_id) {
    $user_unread = getUnreadAdminMessageCount($conn, $user_id, false); // false = not admin
} else {
    $user_unread = 0;
}

// Get admin notification data (for admins)
$admin_notifications_count = 0;
$admin_chat_requests = [];
$is_admin = isAdmin();

if ($is_admin && $user_id) {
    $admin_notifications_count = getAdminUnreadChatsCount($conn, $user_id);
    $admin_chat_requests = getAdminChatRequests($conn, $user_id);
}

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
                n.head as head_name,
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
            JOIN numbers n ON ca.number_id = n.number_id
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
        // VIEW ACTIVE CONVERSATIONS - FIXED to include head name
        $conversations_query = "
            SELECT 
                c.conversation_id,
                c.number_id,
                c.initiated_by,
                c.created_at as conversation_start,
                c.last_activity,
                u.username as initiator_name,
                u.full_name as initiator_full_name,
                n.head as head_name,
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
            JOIN numbers n ON c.number_id = n.number_id
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
    
    // Load selected conversation (only active ones) - FIXED VERSION
    if ($selected_conversation_id && !$view_archived) {
        // First, let's get the head user ID for this contact
        $head_user_id = getHeadUserId($conn, $number_id);
        
        $access_check = $conn->prepare("
            SELECT 
                c.*, 
                u.username as initiator_name, 
                u.full_name as initiator_full_name,
                n.head as head_name,
                n.head_user_id,
                CASE 
                    WHEN c.initiated_by = ? THEN 'user'
                    WHEN ? = n.head_user_id THEN 'head'
                    ELSE 'other'
                END as user_role
            FROM conversations c
            JOIN users u ON c.initiated_by = u.user_id
            JOIN numbers n ON c.number_id = n.number_id
            WHERE c.conversation_id = ? 
            AND c.is_archived = 0
            AND (
                c.initiated_by = ? 
                OR ? = n.head_user_id
                OR ? = c.initiated_by
            )
        ");
        $access_check->bind_param("iiiiii", 
            $user_id,                     // For CASE comparison
            $user_id,                     // For CASE comparison  
            $selected_conversation_id,    // conversation_id
            $user_id,                     // initiated_by check
            $head_user_id,                // head_user_id check
            $user_id                      // initiated_by check (alternative)
        );
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
            $selected_conversation_info = null;
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
        SELECT 
            ca.*, 
            u.username as initiator_name, 
            u.full_name as initiator_full_name,
            n.head as head_name
        FROM conversations_archive ca
        JOIN users u ON ca.initiated_by = u.user_id
        JOIN numbers n ON ca.number_id = n.number_id
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
        $selected_conversation_info = null;
    }
    $access_check->close();
}

// Handle POST requests for feedback, messages, and contact editing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle contact information update (HEAD ONLY)
    if (isset($_POST['update_contact'])) {
        if (!$user_id) {
            $edit_error = "You must be logged in to edit contact information.";
        } elseif (!$is_head) {
            $edit_error = "Only the head of this contact can edit its information.";
        } else {
            $contact_number = trim($_POST['contact_number'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $status = $_POST['status'] ?? 'active';
            
            if (empty($contact_number)) {
                $edit_error = "Contact number is required.";
            } elseif (empty($description)) {
                $edit_error = "Description is required.";
            } else {
                $update_sql = "UPDATE numbers SET numbers = ?, description = ?, status = ? WHERE number_id = ? AND head_user_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("sssii", $contact_number, $description, $status, $number_id, $user_id);
                
                if ($update_stmt->execute()) {
                    $edit_success = "Contact information updated successfully!";
                    
                    // If AJAX request, return minimal response
                    if ($is_ajax) {
                        echo json_encode(['success' => true, 'message' => 'Contact information updated successfully!']);
                        exit;
                    }
                    
                    // Reload contact data
                    $stmt = $conn->prepare($contact_query);
                    $stmt->bind_param("i", $number_id);
                    $stmt->execute();
                    $contact_result = $stmt->get_result();
                    $contact = $contact_result->fetch_assoc();
                    $stmt->close();
                    
                    // Update organization info
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
                } else {
                    $edit_error = "Failed to update contact information. Please try again.";
                    if ($is_ajax) {
                        echo json_encode(['success' => false, 'error' => 'Failed to update contact information.']);
                        exit;
                    }
                }
                $update_stmt->close();
            }
        }
    }
    
    // Handle feedback submission
    if (isset($_POST['submit_feedback']) || (isset($_POST['ajax']) && $_POST['ajax'] === 'true' && isset($_POST['rating']) && isset($_POST['comment']))) {
        // Note: For AJAX, we need to check if it's a feedback submission by looking for rating/comment
        if (!$user_id) {
            $feedback_error = "You must be logged in to submit feedback.";
            if ($is_ajax) {
                echo json_encode(['success' => false, 'error' => $feedback_error]);
                exit;
            }
        } else {
            $rating = (int)($_POST['rating'] ?? 0);
            $comment = trim($_POST['comment'] ?? '');
            
            $check_feedback = $conn->prepare("SELECT feedback_id FROM feedback WHERE number_id = ? AND user_id = ?");
            $check_feedback->bind_param("ii", $number_id, $user_id);
            $check_feedback->execute();
            $check_feedback->store_result();
            
            if ($check_feedback->num_rows > 0) {
                $feedback_error = "You have already submitted feedback for this contact.";
                if ($is_ajax) {
                    echo json_encode(['success' => false, 'error' => $feedback_error]);
                    exit;
                }
            } elseif ($rating < 1 || $rating > 5) {
                $feedback_error = "Please select a valid rating (1-5 stars).";
                if ($is_ajax) {
                    echo json_encode(['success' => false, 'error' => $feedback_error]);
                    exit;
                }
            } elseif (empty($comment)) {
                $feedback_error = "Please enter a comment.";
                if ($is_ajax) {
                    echo json_encode(['success' => false, 'error' => $feedback_error]);
                    exit;
                }
            } else {
                $insert_feedback = $conn->prepare("INSERT INTO feedback (number_id, user_id, rating, comment, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                $insert_feedback->bind_param("iiis", $number_id, $user_id, $rating, $comment);
                
                if ($insert_feedback->execute()) {
                    // If AJAX request, return JSON
                    if ($is_ajax) {
                        echo json_encode(['success' => true, 'message' => 'Feedback submitted successfully!']);
                        exit;
                    } else {
                        // For non-AJAX, just set success message
                        $feedback_success = "Thank you for your feedback!";
                        // Don't redirect with hash - just reload current page
                        header("Location: $current_script?id=$number_id");
                        exit;
                    }
                } else {
                    $feedback_error = "Failed to submit feedback. Please try again.";
                    if ($is_ajax) {
                        echo json_encode(['success' => false, 'error' => 'Failed to submit feedback.']);
                        exit;
                    }
                }
                $insert_feedback->close();
            }
            $check_feedback->close();
        }
    }
    
    // Handle feedback update
    if (isset($_POST['update_feedback']) || (isset($_POST['ajax']) && $_POST['ajax'] === 'true' && isset($_POST['feedback_id']) && isset($_POST['rating']) && isset($_POST['comment']))) {
        if (!$user_id) {
            $feedback_error = "You must be logged in to update feedback.";
            if ($is_ajax) {
                echo json_encode(['success' => false, 'error' => $feedback_error]);
                exit;
            }
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
                if ($is_ajax) {
                    echo json_encode(['success' => false, 'error' => $feedback_error]);
                    exit;
                }
            } elseif ($rating < 1 || $rating > 5) {
                $feedback_error = "Please select a valid rating (1-5 stars).";
                if ($is_ajax) {
                    echo json_encode(['success' => false, 'error' => $feedback_error]);
                    exit;
                }
            } elseif (empty($comment)) {
                $feedback_error = "Please enter a comment.";
                if ($is_ajax) {
                    echo json_encode(['success' => false, 'error' => $feedback_error]);
                    exit;
                }
            } else {
                $update_feedback = $conn->prepare("UPDATE feedback SET rating = ?, comment = ?, updated_at = NOW() WHERE feedback_id = ? AND user_id = ?");
                $update_feedback->bind_param("isii", $rating, $comment, $feedback_id, $user_id);
                
                if ($update_feedback->execute()) {
                    // If AJAX request, return JSON
                    if ($is_ajax) {
                        echo json_encode(['success' => true, 'message' => 'Feedback updated successfully!']);
                        exit;
                    } else {
                        $feedback_success = "Feedback updated successfully!";
                        $edit_feedback_id = null;
                        // Don't redirect with hash
                        header("Location: $current_script?id=$number_id");
                        exit;
                    }
                } else {
                    $feedback_error = "Failed to update feedback. Please try again.";
                    if ($is_ajax) {
                        echo json_encode(['success' => false, 'error' => 'Failed to update feedback.']);
                        exit;
                    }
                }
                $update_feedback->close();
            }
            $verify_feedback->close();
        }
    }
    
    // Handle feedback deletion
    if (isset($_POST['delete_feedback']) || (isset($_POST['ajax']) && $_POST['ajax'] === 'true' && isset($_POST['delete_feedback']) && isset($_POST['feedback_id']))) {
        if (!$user_id) {
            $feedback_error = "You must be logged in to delete feedback.";
            if ($is_ajax) {
                echo json_encode(['success' => false, 'error' => $feedback_error]);
                exit;
            }
        } else {
            $feedback_id = (int)($_POST['feedback_id'] ?? 0);
            
            $verify_feedback = $conn->prepare("SELECT feedback_id FROM feedback WHERE feedback_id = ? AND user_id = ?");
            $verify_feedback->bind_param("ii", $feedback_id, $user_id);
            $verify_feedback->execute();
            $verify_feedback->store_result();
            
            if ($verify_feedback->num_rows === 0) {
                $feedback_error = "You can only delete your own feedback.";
                if ($is_ajax) {
                    echo json_encode(['success' => false, 'error' => $feedback_error]);
                    exit;
                    }
                } else {
                    $delete_feedback = $conn->prepare("DELETE FROM feedback WHERE feedback_id = ? AND user_id = ?");
                    $delete_feedback->bind_param("ii", $feedback_id, $user_id);
                    
                    if ($delete_feedback->execute()) {
                        if ($is_ajax) {
                            echo json_encode(['success' => true, 'message' => 'Feedback deleted successfully!']);
                            exit;
                        } else {
                            $feedback_success = "Feedback deleted successfully!";
                            header("Location: $current_script?id=$number_id");
                            exit;
                        }
                    } else {
                        $feedback_error = "Failed to delete feedback. Please try again.";
                        if ($is_ajax) {
                            echo json_encode(['success' => false, 'error' => 'Failed to delete feedback.']);
                            exit;
                        }
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
                                
                                // Update conversation activity
                                if (updateConversationActivity($conn, $conversation_id)) {
                                    error_log("Successfully updated last_activity for conversation $conversation_id");
                                } else {
                                    error_log("FAILED to update last_activity for conversation $conversation_id");
                                }

                                if ($is_ajax) {
                                    echo json_encode(['success' => true, 'message' => 'Message sent!', 'conversation_id' => $conversation_id]);
                                    exit;
                                } else {
                                    header("Location: $current_script?id=$number_id&conversation_id=$conversation_id");
                                    exit;
                                }
                            } else {
                                $message_error = "Failed to send message. SQL Error: " . $conn->error;
                                if ($is_ajax) {
                                    echo json_encode(['success' => false, 'error' => $message_error]);
                                    exit;
                                }
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
                        
                        if ($is_ajax) {
                            echo json_encode(['success' => true, 'message' => 'Reply sent!']);
                            exit;
                        } else {
                            header("Location: $current_script?id=$number_id&conversation_id=$conversation_id");
                            exit;
                        }
                    } else {
                        $message_error = "Failed to send reply. Error: " . $conn->error;
                        if ($is_ajax) {
                            echo json_encode(['success' => false, 'error' => $message_error]);
                            exit;
                        }
                    }
                    $insert_reply->close();
                } else {
                    $message_error = "Conversation not found or archived.";
                    if ($is_ajax) {
                        echo json_encode(['success' => false, 'error' => $message_error]);
                        exit;
                    }
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

// If this is an AJAX request that hasn't been handled yet, return an error
if ($is_ajax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid AJAX request']);
    exit;
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

    /* Notification Container Styles */
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

    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.1); }
        100% { transform: scale(1); }
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
        display: flex;
        flex-direction: column;
        gap: 30px;
    }

    /* ===== SECTIONS LAYOUT ===== */
    .contact-card,
    .feedback-section,
    .messaging-section {
        width: 100%;
        margin-bottom: 30px;
        position: relative;
        z-index: 1;
    }

    /* Ensure chat section stays at bottom */
    .messaging-section {
        order: 3;
        margin-top: 20px;
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

    /* ===== EDIT FORM STYLES ===== */
    .edit-form-section {
        background-color: #f7fafc;
        padding: 25px 30px;
        border-bottom: 1px solid #e2e8f0;
    }

    .edit-form-section h3 {
        color: #2b6cb0;
        margin-bottom: 20px;
        font-size: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #e2e8f0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .edit-form {
        margin-top: 15px;
    }

    .form-row {
        display: flex;
        gap: 20px;
        margin-bottom: 15px;
    }

    .form-group {
        flex: 1;
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 600;
        color: #4a5568;
        font-size: 14px;
    }

    .form-control {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 15px;
        transition: all 0.2s;
    }

    .form-control:focus {
        outline: none;
        border-color: #2b6cb0;
        box-shadow: 0 0 0 3px rgba(43, 108, 176, 0.1);
    }

    select.form-control {
        height: 42px;
        cursor: pointer;
    }

    .form-actions {
        display: flex;
        gap: 10px;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid #e2e8f0;
    }

    .edit-btn {
        background-color: #38a169;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        transition: all 0.2s;
    }

    .edit-btn:hover {
        background-color: #2f855a;
        transform: translateY(-1px);
    }

    .cancel-btn {
        background-color: #718096;
        color: white;
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        transition: all 0.2s;
    }

    .cancel-btn:hover {
        background-color: #4a5568;
    }

    .head-badge {
        display: inline-block;
        padding: 4px 10px;
        background-color: #38a169;
        color: white;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 10px;
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

    .edit-btn-small {
        color: #2b6cb0;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
    }

    .edit-btn-small:hover {
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
    
    /* User Profile Hover Tooltip - FIXED TO SHOW AT BOTTOM */
    .message-sender {
        position: relative;
        cursor: help;
        text-decoration: underline dotted;
        text-decoration-thickness: 1px;
        text-underline-offset: 2px;
    }

    .user-profile-tooltip {
        display: none;
        position: absolute;
        top: 100%;  /* Changed from bottom: 100% to top: 100% */
        left: 0;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        padding: 15px;
        width: 300px;
        z-index: 1000;
        font-size: 13px;
        line-height: 1.5;
        margin-top: 10px;  /* Added margin to create space */
    }

    .message-sender:hover .user-profile-tooltip {
        display: block;
    }

    .user-profile-header {
        display: flex;
        align-items: center;
        margin-bottom: 12px;
        padding-bottom: 10px;
        border-bottom: 1px solid #e2e8f0;
    }

    .user-profile-avatar {
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

    .user-profile-name {
        font-weight: 600;
        color: #2d3748;
        font-size: 14px;
    }

    .user-profile-email {
        color: #718096;
        font-size: 12px;
        margin-top: 2px;
    }

    .user-profile-details {
        display: grid;
        gap: 8px;
    }

    .profile-detail-row {
        display: flex;
        justify-content: space-between;
    }

    .profile-detail-label {
        font-weight: 600;
        color: #4a5568;
        min-width: 100px;
    }

    .profile-detail-value {
        color: #2d3748;
        text-align: right;
        flex: 1;
    }

    .user-profile-divider {
        margin: 10px 0;
        border: none;
        border-top: 1px solid #e2e8f0;
    }

    .user-profile-head-info {
        background-color: #f0f9ff;
        border-left: 3px solid #2b6cb0;
        padding: 8px 10px;
        border-radius: 4px;
        margin-top: 10px;
        font-size: 12px;
    }

    .head-indicator {
        background-color: #38a169;
        color: white;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        margin-left: 5px;
    }

    .no-head-info {
        color: #a0aec0;
        font-style: italic;
        font-size: 12px;
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

        .form-row {
            flex-direction: column;
            gap: 0;
        }
        
        .user-profile-tooltip {
            width: 250px;
            left: -100px;
            top: 100%;
            margin-top: 5px;
        }
    }

    /* Toast Notifications */
    .toast-notification {
        position: fixed;
        top: 100px;
        right: 20px;
        background: #2b6cb0;
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        z-index: 9999;
        animation: slideIn 0.3s ease;
        max-width: 400px;
        font-size: 14px;
    }

    .toast-success {
        background: #38a169 !important;
    }

    .toast-error {
        background: #e53e3e !important;
    }

    .toast-close {
        background: none;
        border: none;
        color: white;
        font-size: 20px;
        cursor: pointer;
        padding: 0;
        margin: 0;
        line-height: 1;
    }

    .toast-close:hover {
        opacity: 0.8;
    }

    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
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
                <li>
                    <a href="adminpanel.php" class="notification-indicator">
                        Admin Panel 
                        <?php if ($admin_notifications_count > 0): ?>
                            <span class="nav-notification-badge"><?php echo $admin_notifications_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php else: ?>
                <li><a href="adminchat.php">Chat with Admin <?php echo $user_unread > 0 ? "($user_unread)" : ""; ?></a></li>
            <?php endif; ?>
            <li><a href="profilepage.php">Profile</a></li>
            <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
        <?php endif; ?>
    </ul>
    
    <?php if($is_admin && $user_id): ?>
    <div class="admin-notification-container">
        <button class="admin-notification-btn" id="adminNotificationBtn">
            🔔
            <?php if($admin_notifications_count > 0): ?>
                <span class="notification-bell-badge"><?php echo $admin_notifications_count; ?></span>
            <?php endif; ?>
        </button>
        <div class="notification-dropdown" id="notificationDropdown">
            <div class="notification-header">
                <h4>📨 New Chat Requests (<?php echo $admin_notifications_count; ?>)</h4>
            </div>
            <div class="notification-list">
                <?php if(!empty($admin_chat_requests)): ?>
                    <?php foreach($admin_chat_requests as $request): 
                        $chat_id = getAdminChatWithUser($conn, $user_id, $request['user_id']);
                        $chat_link = $chat_id ? "adminpanel.php?chat_id=$chat_id" : "adminpanel.php?start_chat=" . $request['user_id'];
                    ?>
                    <a href="<?php echo $chat_link; ?>" class="notification-item">
                        <div class="notification-avatar">
                            <?php echo strtoupper(substr($request['full_name'], 0, 1)); ?>
                        </div>
                        <div class="notification-info">
                            <div class="notification-name"><?php echo htmlspecialchars($request['full_name']); ?></div>
                            <div class="notification-meta">
                                <span class="notification-time"><?php echo time_ago($request['first_message_time']); ?></span>
                                <?php if($request['message_count'] > 1): ?>
                                    <span class="message-count-badge"><?php echo $request['message_count']; ?> messages</span>
                                <?php else: ?>
                                    <span class="message-count-badge">New message</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-notifications">
                        No new chat requests
                    </div>
                <?php endif; ?>
            </div>
            <div class="notification-footer">
                <a href="adminpanel.php" class="view-all-btn">View All Chats</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="content">
    <!-- Contact Card -->
    <div class="contact-card">
        <!-- Edit Form Section (for Head Users) -->
        <?php if ($is_head && $is_editing): ?>
            <div class="edit-form-section">
                <?php if ($edit_error): ?>
                    <div class="error-message"><?php echo $edit_error; ?></div>
                <?php endif; ?>
                
                <?php if ($edit_success): ?>
                    <div class="success-message"><?php echo $edit_success; ?></div>
                <?php endif; ?>
                
                <h3>✏️ Edit Contact Information</h3>
                <form method="POST" class="edit-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="contact_number">Contact Number *</label>
                            <input type="text" id="contact_number" name="contact_number" class="form-control" 
                                   value="<?php echo htmlspecialchars($contact['numbers']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="active" <?php echo $contact['status'] == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="decommissioned" <?php echo $contact['status'] == 'decommissioned' ? 'selected' : ''; ?>>Decommissioned</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">Description *</label>
                        <textarea id="description" name="description" class="form-control" rows="3" required><?php echo htmlspecialchars($contact['description']); ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" name="update_contact" class="edit-btn">Save Changes</button>
                        <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>" class="cancel-btn">Cancel</a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        
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
            <div style="display: flex; gap: 10px; align-items: center;">
                <?php if ($is_head && !$is_editing): ?>
                    <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>&edit=true" class="edit-btn">✏️ Edit Contact</a>
                <?php endif; ?>
                <a href="homepage.php" class="back-button">← Back to Directory</a>
            </div>
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
                            <?php if ($is_head): ?>
                                <span class="head-badge">You are the Head</span>
                            <?php endif; ?>
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
            <div class="feedback-form editing" id="edit-feedback-form">
                <h3>Edit Your Feedback</h3>
                <form method="POST" id="update-feedback-form" data-action="update" data-feedback-id="<?php echo $current_edit_feedback['feedback_id']; ?>">
                    <input type="hidden" name="feedback_id" value="<?php echo $current_edit_feedback['feedback_id']; ?>">
                    
                    <div class="form-group">
                        <label>Rating</label>
                        <div class="star-rating" id="edit-rating-stars">
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
                        <button type="submit" name="update_feedback" class="update-btn" id="update-feedback-btn">Update Feedback</button>
                        <button type="button" name="delete_feedback" class="delete-btn" id="delete-feedback-btn" 
                                onclick="confirmDeleteFeedback(<?php echo $current_edit_feedback['feedback_id']; ?>)">
                            Delete Feedback
                        </button>
                        <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>" class="cancel-btn">Cancel</a>
                    </div>
                </form>
            </div>
                <?php elseif (!$user_feedback && $user_id): ?>
                    <div class="feedback-form" id="new-feedback-form">
            <h3>Leave Your Feedback</h3>
            <form method="POST" id="submit-feedback-form" data-action="submit">
                <div class="form-group">
                    <label>Rating</label>
                    <div class="star-rating" id="rating-stars">
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
                    <button type="submit" name="submit_feedback" class="submit-btn" id="submit-feedback-btn">Submit Feedback</button>
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
                                <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>&edit_feedback=<?php echo $feedback['feedback_id']; ?>" class="edit-btn-small">Edit</a>
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
                                    with <?php echo htmlspecialchars($selected_conversation_info['head_name'] ?? $contact['head']); ?>
                                <?php else: ?>
                                    with <?php echo htmlspecialchars($selected_conversation_info['initiator_name'] ?? 'User'); ?>
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
                                
                                // Get user info for tooltip
                                $user_info = getUserHierarchyInfo($conn, $msg['sender_user_id']);
                                $sender_email = !empty($user_info['email']) ? $user_info['email'] : 'No email';
                                $sender_role = $user_info['role_name'] ?? 'Unknown';
                                $is_sender_head = $user_info['is_head'] ?? false;
                            ?>
                                <div class="message-bubble <?php echo $is_sent ? 'message-sent' : 'message-received'; ?>">
                                    <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                    <div class="message-info">
                                        <span class="message-sender">
                                            <?php echo htmlspecialchars($msg['sender_name']); ?>
                                            <div class="user-profile-tooltip">
                                                <div class="user-profile-header">
                                                    <div class="user-profile-avatar">
                                                        <?php echo strtoupper(substr($msg['sender_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="user-profile-name"><?php echo htmlspecialchars($msg['sender_name']); ?></div>
                                                        <div class="user-profile-email"><?php echo htmlspecialchars($sender_email); ?></div>
                                                    </div>
                                                </div>
                                                <div class="user-profile-details">
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Role:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($sender_role); ?></span>
                                                    </div>
                                                    <?php if ($user_info['division']): ?>
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Division:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($user_info['division']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($user_info['department']): ?>
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Department:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($user_info['department']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($user_info['unit']): ?>
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Unit:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($user_info['unit']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($user_info['office']): ?>
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Office:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($user_info['office']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <?php if ($is_sender_head): ?>
                                                    <hr class="user-profile-divider">
                                                    <div class="user-profile-head-info">
                                                        <strong>👑 Head Status:</strong> This user is the head of 
                                                        <?php echo htmlspecialchars($user_info['current_unit'] ?? 'their unit'); ?>
                                                        <?php if ($user_info['unit_type']): ?>
                                                            (<?php echo htmlspecialchars($user_info['unit_type']); ?>)
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif (!empty($user_info['head_info'])): ?>
                                                    <hr class="user-profile-divider">
                                                    <div class="user-profile-head-info">
                                                        <strong>👤 Reports to:</strong> 
                                                        <?php echo htmlspecialchars($user_info['head_info']['head_name'] ?? 'Unknown'); ?>
                                                        <?php if (!empty($user_info['head_info']['head_email'])): ?>
                                                            <br><small>Email: <?php echo htmlspecialchars($user_info['head_info']['head_email']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <hr class="user-profile-divider">
                                                    <div class="no-head-info">
                                                        No head information available
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </span>
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
                                        <?php echo $is_head ? htmlspecialchars($conv['initiator_name']) : htmlspecialchars($conv['head_name'] ?? $contact['head']); ?>
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
                                <?php if (isset($selected_conversation_info)): ?>
                                    <?php if ($user_id == $selected_conversation_info['initiated_by']): ?>
                                        Chat with <?php echo htmlspecialchars($selected_conversation_info['head_name'] ?? $contact['head']); ?>
                                    <?php else: ?>
                                        Chat with <?php echo htmlspecialchars($selected_conversation_info['initiator_name'] ?? 'User'); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Chat with <?php echo htmlspecialchars($contact['head']); ?>
                                <?php endif; ?>
                            </h3>
                            <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>" class="chat-back-btn">← Back to Conversations</a>
                        </div>
                        
                        <div class="chat-messages" id="chat-messages">
                            <?php foreach ($conversation_messages as $msg): 
                                $is_sent = ($msg['sender_id'] == $user_id);
                                
                                // Get user info for tooltip
                                $user_info = getUserHierarchyInfo($conn, $msg['sender_user_id']);
                                $sender_email = !empty($user_info['email']) ? $user_info['email'] : 'No email';
                                $sender_role = $user_info['role_name'] ?? 'Unknown';
                                $is_sender_head = $user_info['is_head'] ?? false;
                            ?>
                                <div class="message-bubble <?php echo $is_sent ? 'message-sent' : 'message-received'; ?>">
                                    <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                    <div class="message-info">
                                        <span class="message-sender">
                                            <?php echo htmlspecialchars($msg['sender_name']); ?>
                                            <div class="user-profile-tooltip">
                                                <div class="user-profile-header">
                                                    <div class="user-profile-avatar">
                                                        <?php echo strtoupper(substr($msg['sender_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="user-profile-name"><?php echo htmlspecialchars($msg['sender_name']); ?></div>
                                                        <div class="user-profile-email"><?php echo htmlspecialchars($sender_email); ?></div>
                                                    </div>
                                                </div>
                                                <div class="user-profile-details">
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Role:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($sender_role); ?></span>
                                                    </div>
                                                    <?php if ($user_info['division']): ?>
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Division:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($user_info['division']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($user_info['department']): ?>
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Department:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($user_info['department']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($user_info['unit']): ?>
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Unit:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($user_info['unit']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                    <?php if ($user_info['office']): ?>
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Office:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($user_info['office']); ?></span>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <?php if ($is_sender_head): ?>
                                                    <hr class="user-profile-divider">
                                                    <div class="user-profile-head-info">
                                                        <strong>👑 Head Status:</strong> This user is the head of 
                                                        <?php echo htmlspecialchars($user_info['current_unit'] ?? 'their unit'); ?>
                                                        <?php if ($user_info['unit_type']): ?>
                                                            (<?php echo htmlspecialchars($user_info['unit_type']); ?>)
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif (!empty($user_info['head_info'])): ?>
                                                    <hr class="user-profile-divider">
                                                    <div class="user-profile-head-info">
                                                        <strong>👤 Reports to:</strong> 
                                                        <?php echo htmlspecialchars($user_info['head_info']['head_name'] ?? 'Unknown'); ?>
                                                        <?php if (!empty($user_info['head_info']['head_email'])): ?>
                                                            <br><small>Email: <?php echo htmlspecialchars($user_info['head_info']['head_email']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <hr class="user-profile-divider">
                                                    <div class="no-head-info">
                                                        No head information available
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </span>
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
                                            Chat with <?php echo htmlspecialchars($conv['head_name'] ?? $contact['head']); ?>
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
    // Auto-scroll chat
    const chatMessages = document.getElementById('chat-messages');
    if (chatMessages) chatMessages.scrollTop = chatMessages.scrollHeight;
    
    // ===========================================
    // 1. Handle Enter key for chat
    // ===========================================
    document.querySelectorAll('.messaging-section textarea, .message-form textarea').forEach(textarea => {
        // Find the send button in the same form
        const form = textarea.closest('form');
        if (!form) return;
        
        const sendButton = form.querySelector('.chat-send-btn, button[type="submit"]');
        if (!sendButton) return;
        
        // Enter key handler
        textarea.addEventListener('keydown', function(e) {
            // Check if Enter was pressed without Shift
            if ((e.key === 'Enter' || e.which === 13 || e.keyCode === 13) && !e.shiftKey) {
                e.preventDefault(); // Stop new line
                
                // Only send if there's text
                if (this.value.trim() !== '') {
                    // Visual feedback
                    this.style.borderColor = '#38a169';
                    sendButton.style.backgroundColor = '#38a169';
                    
                    // Click the send button
                    setTimeout(() => sendButton.click(), 10);
                    
                    // Reset colors
                    setTimeout(() => {
                        this.style.borderColor = '';
                        sendButton.style.backgroundColor = '';
                    }, 200);
                }
            }
        });
    });
    
    // ===========================================
    // 2. SIMPLIFIED AJAX FEEDBACK SYSTEM
    // ===========================================
    
    // Submit new feedback
    const submitFeedbackForm = document.getElementById('submit-feedback-form');
    if (submitFeedbackForm) {
        submitFeedbackForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitFeedback(this);
        });
    }
    
    // Update existing feedback
    const updateFeedbackForm = document.getElementById('update-feedback-form');
    if (updateFeedbackForm) {
        updateFeedbackForm.addEventListener('submit', function(e) {
            e.preventDefault();
            updateFeedback(this);
        });
    }
    
    // Function to submit new feedback
    function submitFeedback(form) {
        const formData = new FormData(form);
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        
        // Show loading state
        submitBtn.textContent = 'Submitting...';
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.7';
        
        // Add AJAX flag AND the form field name
        formData.append('ajax', 'true');
        formData.append('submit_feedback', 'true'); // Add this line
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                // Reload only the page, not the section
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast(data.error || 'Failed to submit feedback', 'error');
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
                submitBtn.style.opacity = '';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to submit feedback. Please try again.', 'error');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
            submitBtn.style.opacity = '';
        });
    }

    // Function to update feedback
    function updateFeedback(form) {
        const formData = new FormData(form);
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        
        // Show loading state
        submitBtn.textContent = 'Updating...';
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.7';
        
        // Add AJAX flag AND the form field name
        formData.append('ajax', 'true');
        formData.append('update_feedback', 'true'); // Add this line
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                // Reload the page
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast(data.error || 'Failed to update feedback', 'error');
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
                submitBtn.style.opacity = '';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to update feedback. Please try again.', 'error');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
            submitBtn.style.opacity = '';
        });
    }

    // Function to delete feedback
    function deleteFeedback(feedbackId) {
        if (!confirm('Are you sure you want to delete your feedback? This action cannot be undone.')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('ajax', 'true');
        formData.append('delete_feedback', 'true');
        formData.append('feedback_id', feedbackId);
        
        showToast('Deleting feedback...', 'info');
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                // Reload the page
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast(data.error || 'Failed to delete feedback', 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Failed to delete feedback. Please try again.', 'error');
        });
    }
    
    // Global delete function
    window.confirmDeleteFeedback = function(feedbackId) {
        deleteFeedback(feedbackId);
    };
    
    // Toast notification function
    function showToast(message, type = 'info') {
        // Remove existing toast
        const existingToast = document.querySelector('.toast-notification');
        if (existingToast) {
            existingToast.remove();
        }
        
        // Create toast
        const toast = document.createElement('div');
        toast.className = `toast-notification toast-${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                ${message}
            </div>
            <button class="toast-close">&times;</button>
        `;
        
        // Add to page
        document.body.appendChild(toast);
        
        // Close on click
        const closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', () => {
            toast.remove();
        });
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (toast.parentNode) {
                toast.style.transition = 'all 0.3s ease';
                toast.style.transform = 'translateX(100%)';
                toast.style.opacity = '0';
                setTimeout(() => {
                    if (toast.parentNode) toast.remove();
                }, 300);
            }
        }, 5000);
    }
    
    // Fix edit links to NOT add hash
    document.querySelectorAll('.edit-btn-small').forEach(link => {
        link.addEventListener('click', function(e) {
            // Allow normal navigation, don't prevent default
            // The hash will be handled by PHP query parameter
        });
    });
    
    // Notification button functionality
    const notificationBtn = document.getElementById('adminNotificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    if (notificationBtn && notificationDropdown) {
        notificationBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (notificationDropdown.style.opacity === '1') {
                notificationDropdown.style.opacity = '0';
                notificationDropdown.style.visibility = 'hidden';
                notificationDropdown.style.transform = 'translateY(-10px)';
            } else {
                notificationDropdown.style.opacity = '1';
                notificationDropdown.style.visibility = 'visible';
                notificationDropdown.style.transform = 'translateY(0)';
            }
        });
    }
    
    // Close notification dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (notificationBtn && notificationDropdown) {
            if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                notificationDropdown.style.opacity = '0';
                notificationDropdown.style.visibility = 'hidden';
                notificationDropdown.style.transform = 'translateY(-10px)';
            }
        }
    });
    
    // Auto-refresh for active chat
    <?php if ($selected_conversation_id && !$view_archived): ?>
    setInterval(() => location.reload(), 30000);
    <?php endif; ?>
    
    // Auto-check for new admin notifications (every 10 seconds)
    <?php if ($is_admin && $user_id): ?>
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
                        showToast(data.latest?.user_name || 'New chat request', 'info');
                    }
                } else {
                    if(badge) badge.style.display = 'none';
                    if(headerBadge) headerBadge.style.display = 'none';
                    if(headerCount) headerCount.textContent = '📨 New Chat Requests (0)';
                }
            })
            .catch(error => console.error('Error checking notifications:', error));
    }
    
    // Check for notifications every 10 seconds
    setInterval(checkAdminNotifications, 10000);
    
    // Request notification permission
    if (Notification.permission === "default") {
        Notification.requestPermission();
    }
    <?php endif; ?>
});
</script>
</body>
</html>