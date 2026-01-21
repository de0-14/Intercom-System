<?php
require_once 'conn.php';

// Get the current script name for URL generation
$current_script = basename($_SERVER['PHP_SELF']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: homepage.php');
    exit;
}

$number_id = (int)$_GET['id'];
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$edit_feedback_id = isset($_GET['edit_feedback']) ? (int)$_GET['edit_feedback'] : null;
$edit_message_id = isset($_GET['edit_message']) ? (int)$_GET['edit_message'] : null;
$current_edit_feedback = null;
$current_edit_message = null;

// Fetch contact details with organizational hierarchy
$contact_query = "
    SELECT 
        n.*,
        d.division_name,
        dept.department_name,
        u.unit_name,
        o.office_name,
        d.status as division_status,
        dept.status as department_status,
        u.status as unit_status,
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
    LEFT JOIN units u ON n.unit_id = u.unit_id
    LEFT JOIN departments dept2 ON u.department_id = dept2.department_id
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

// Determine organization name and type
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

// Handle Feedback Submission and Editing
$feedback_error = '';
$feedback_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_feedback'])) {
        if (!$user_id) {
            $feedback_error = "You must be logged in to submit feedback.";
        } else {
            $rating = (int)$_POST['rating'];
            $comment = trim($_POST['comment']);
            
            // Check if user already submitted feedback for this number
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
                // Insert feedback
                $insert_feedback = $conn->prepare("INSERT INTO feedback (number_id, user_id, rating, comment, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                $insert_feedback->bind_param("iiis", $number_id, $user_id, $rating, $comment);
                
                if ($insert_feedback->execute()) {
                    $feedback_success = "Thank you for your feedback!";
                    $_POST = array(); // Clear form
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
            $feedback_id = (int)$_POST['feedback_id'];
            $rating = (int)$_POST['rating'];
            $comment = trim($_POST['comment']);
            
            // Verify the feedback belongs to the current user
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
                // Update feedback
                $update_feedback = $conn->prepare("UPDATE feedback SET rating = ?, comment = ?, updated_at = NOW() WHERE feedback_id = ? AND user_id = ?");
                $update_feedback->bind_param("isii", $rating, $comment, $feedback_id, $user_id);
                
                if ($update_feedback->execute()) {
                    $feedback_success = "Feedback updated successfully!";
                    $edit_feedback_id = null; // Exit edit mode
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
            $feedback_id = (int)$_POST['feedback_id'];
            
            // Verify the feedback belongs to the current user
            $verify_feedback = $conn->prepare("SELECT feedback_id FROM feedback WHERE feedback_id = ? AND user_id = ?");
            $verify_feedback->bind_param("ii", $feedback_id, $user_id);
            $verify_feedback->execute();
            $verify_feedback->store_result();
            
            if ($verify_feedback->num_rows === 0) {
                $feedback_error = "You can only delete your own feedback.";
            } else {
                // Delete feedback
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

// Handle Message Submission, Editing, and Deletion
$message_error = '';
$message_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle new message
    if (isset($_POST['send_message'])) {
        if (!$user_id) {
            $message_error = "You must be logged in to send a message.";
        } else {
            $message = trim($_POST['message']);
            
            if (empty($message)) {
                $message_error = "Please enter a message.";
            } elseif (strlen($message) > 1000) {
                $message_error = "Message is too long (max 1000 characters).";
            } else {
                // Get sender's username
                $sender_stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ?");
                $sender_stmt->bind_param("i", $user_id);
                $sender_stmt->execute();
                $sender_result = $sender_stmt->get_result();
                $sender = $sender_result->fetch_assoc();
                $sender_name = $sender['username'];
                $sender_stmt->close();
                
                // Insert message
                $insert_message = $conn->prepare("INSERT INTO messages (sender_id, receiver_head, number_id, message, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                $insert_message->bind_param("isis", $user_id, $contact['head'], $number_id, $message);
                
                if ($insert_message->execute()) {
                    $message_success = "Message sent to " . htmlspecialchars($contact['head']) . "!";
                    $_POST['message'] = ''; // Clear message field
                } else {
                    $message_error = "Failed to send message. Please try again.";
                }
                $insert_message->close();
            }
        }
    }
    
    // Handle message update
    if (isset($_POST['update_message'])) {
        if (!$user_id) {
            $message_error = "You must be logged in to update a message.";
        } else {
            $message_id = (int)$_POST['message_id'];
            $message_text = trim($_POST['message_text']);
            
            // Verify the message belongs to the current user
            $verify_message = $conn->prepare("SELECT message_id FROM messages WHERE message_id = ? AND sender_id = ?");
            $verify_message->bind_param("ii", $message_id, $user_id);
            $verify_message->execute();
            $verify_message->store_result();
            
            if ($verify_message->num_rows === 0) {
                $message_error = "You can only edit your own messages.";
            } elseif (empty($message_text)) {
                $message_error = "Please enter a message.";
            } elseif (strlen($message_text) > 1000) {
                $message_error = "Message is too long (max 1000 characters).";
            } else {
                // Update message
                $update_message = $conn->prepare("UPDATE messages SET message = ?, updated_at = NOW() WHERE message_id = ? AND sender_id = ?");
                $update_message->bind_param("sii", $message_text, $message_id, $user_id);
                
                if ($update_message->execute()) {
                    $message_success = "Message updated successfully!";
                    $edit_message_id = null; // Exit edit mode
                } else {
                    $message_error = "Failed to update message. Please try again.";
                }
                $update_message->close();
            }
            $verify_message->close();
        }
    }
    
    // Handle message deletion
    if (isset($_POST['delete_message'])) {
        if (!$user_id) {
            $message_error = "You must be logged in to delete a message.";
        } else {
            $message_id = (int)$_POST['message_id'];
            
            // Verify the message belongs to the current user
            $verify_message = $conn->prepare("SELECT message_id FROM messages WHERE message_id = ? AND sender_id = ?");
            $verify_message->bind_param("ii", $message_id, $user_id);
            $verify_message->execute();
            $verify_message->store_result();
            
            if ($verify_message->num_rows === 0) {
                $message_error = "You can only delete your own messages.";
            } else {
                // Delete message
                $delete_message = $conn->prepare("DELETE FROM messages WHERE message_id = ? AND sender_id = ?");
                $delete_message->bind_param("ii", $message_id, $user_id);
                
                if ($delete_message->execute()) {
                    $message_success = "Message deleted successfully!";
                } else {
                    $message_error = "Failed to delete message. Please try again.";
                }
                $delete_message->close();
            }
            $verify_message->close();
        }
    }
}

// If editing feedback, fetch the specific feedback
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

// If editing message, fetch the specific message
if ($edit_message_id && $user_id) {
    $edit_message_stmt = $conn->prepare("SELECT * FROM messages WHERE message_id = ? AND sender_id = ?");
    $edit_message_stmt->bind_param("ii", $edit_message_id, $user_id);
    $edit_message_stmt->execute();
    $edit_message_result = $edit_message_stmt->get_result();
    
    if ($edit_message_result->num_rows === 1) {
        $current_edit_message = $edit_message_result->fetch_assoc();
    } else {
        $message_error = "You can only edit your own messages.";
        $edit_message_id = null;
    }
    $edit_message_stmt->close();
}

// Fetch feedback for this number
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

// Calculate average rating
$avg_rating_query = "SELECT AVG(rating) as avg_rating, COUNT(*) as total_feedbacks FROM feedback WHERE number_id = ?";
$avg_stmt = $conn->prepare($avg_rating_query);
$avg_stmt->bind_param("i", $number_id);
$avg_stmt->execute();
$avg_result = $avg_stmt->get_result();
$avg_data = $avg_result->fetch_assoc();
$avg_rating = $avg_data['avg_rating'] ? round($avg_data['avg_rating'], 1) : 0;
$total_feedbacks = $avg_data['total_feedbacks'];

// Check if current user has already submitted feedback
$user_feedback = null;
if ($user_id) {
    $user_feedback_stmt = $conn->prepare("SELECT * FROM feedback WHERE number_id = ? AND user_id = ?");
    $user_feedback_stmt->bind_param("ii", $number_id, $user_id);
    $user_feedback_stmt->execute();
    $user_feedback_result = $user_feedback_stmt->get_result();
    $user_feedback = $user_feedback_result->fetch_assoc();
    $user_feedback_stmt->close();
}

// Fetch recent messages for this number (user's own messages)
$user_messages = [];
if ($user_id) {
    $messages_query = "
        SELECT m.* 
        FROM messages m 
        WHERE m.number_id = ? AND m.sender_id = ?
        ORDER BY m.created_at DESC
        LIMIT 10
    ";
    $messages_stmt = $conn->prepare($messages_query);
    $messages_stmt->bind_param("ii", $number_id, $user_id);
    $messages_stmt->execute();
    $messages_result = $messages_stmt->get_result();
    $user_messages = $messages_result->fetch_all(MYSQLI_ASSOC);
    $messages_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($contact['numbers']); ?> - Contact Details</title>
    <style>
        /* --- GENERAL --- */
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

        /* --- HEADER --- */
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

        /* --- MAIN CONTENT --- */
        .content {
            flex: 1;
            margin-top: 100px;
            padding: 20px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }

        /* --- CONTACT CARD --- */
        .contact-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 30px;
        }

        .contact-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }

        .contact-title {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .contact-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #2b6cb0, #1f4f8b);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: bold;
        }

        .contact-title-text h1 {
            color: #2b6cb0;
            font-size: 28px;
            margin-bottom: 5px;
        }

        .contact-title-text .description {
            color: #718096;
            font-size: 16px;
            font-weight: 500;
        }

        .back-button {
            background-color: #2b6cb0;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }

        .back-button:hover {
            background-color: #1f4f8b;
            transform: translateY(-2px);
        }

        /* --- CONTACT DETAILS --- */
        .contact-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .detail-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #2b6cb0;
        }

        .detail-section h3 {
            color: #2d3748;
            margin-bottom: 15px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-section h3::before {
            content: "•";
            color: #2b6cb0;
            font-size: 24px;
        }

        .detail-item {
            margin-bottom: 12px;
        }

        .detail-label {
            font-weight: 600;
            color: #4a5568;
            font-size: 14px;
            margin-bottom: 4px;
        }

        .detail-value {
            color: #2d3748;
            font-size: 16px;
            font-weight: 500;
        }

        .head-value {
            color: #2b6cb0;
            font-size: 20px;
            font-weight: 600;
        }

        .org-badge {
            display: inline-block;
            padding: 4px 12px;
            background-color: #bee3f8;
            color: #2c5282;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 8px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            margin-left: 8px;
        }

        .status-active {
            background-color: #c6f6d5;
            color: #276749;
        }

        .status-decommissioned {
            background-color: #fed7d7;
            color: #9b2c2c;
        }

        /* --- FEEDBACK SECTION --- */
        .feedback-section, .messaging-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            padding: 30px;
            margin-bottom: 30px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
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
            gap: 15px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .average-rating {
            font-size: 36px;
            font-weight: 700;
            color: #2b6cb0;
        }

        .total-feedbacks {
            color: #718096;
            font-size: 14px;
        }

        /* Star Rating System */
        .star-rating {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
            gap: 5px;
        }

        .star-rating input {
            display: none;
        }

        .star-rating label {
            font-size: 32px;
            color: #e2e8f0;
            cursor: pointer;
            transition: color 0.2s;
        }

        .star-rating input:checked ~ label,
        .star-rating label:hover,
        .star-rating label:hover ~ label {
            color: #ffc107;
        }

        .star-rating.readonly {
            cursor: default;
        }

        .star-rating.readonly label {
            cursor: default;
        }

        .star-rating.readonly label:hover {
            color: inherit;
        }

        /* Feedback Form */
        .feedback-form, .message-edit-form {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
            border: 2px solid #2b6cb0;
        }

        .feedback-form.editing, .message-edit-form.editing {
            background: #fff3cd;
            border-color: #ffc107;
        }

        .feedback-form h3, .message-edit-form h3 {
            color: #2b6cb0;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .feedback-form.editing h3, .message-edit-form.editing h3 {
            color: #856404;
        }

        .feedback-form h3::before {
            content: "✍️";
        }

        .feedback-form.editing h3::before {
            content: "📝";
        }

        .message-edit-form h3::before {
            content: "✉️";
        }

        .message-edit-form.editing h3::before {
            content: "📝";
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
            padding: 15px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 14px;
            resize: vertical;
            min-height: 100px;
        }

        textarea:focus {
            outline: none;
            border-color: #2b6cb0;
            box-shadow: 0 0 0 3px rgba(43,108,176,0.1);
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .submit-btn {
            background-color: #2b6cb0;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 12px 24px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .submit-btn:hover {
            background-color: #1f4f8b;
            transform: translateY(-2px);
        }

        .update-btn {
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 12px 24px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .update-btn:hover {
            background-color: #218838;
            transform: translateY(-2px);
        }

        .cancel-btn {
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 12px 24px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }

        .cancel-btn:hover {
            background-color: #5a6268;
            transform: translateY(-2px);
        }

        .delete-btn {
            background-color: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 12px 24px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .delete-btn:hover {
            background-color: #c82333;
            transform: translateY(-2px);
        }

        .edit-btn {
            background-color: #17a2b8;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }

        .edit-btn:hover {
            background-color: #138496;
            transform: translateY(-2px);
        }

        /* Feedback List */
        .feedback-list, .message-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .feedback-item, .message-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #2b6cb0;
            position: relative;
        }

        .feedback-item.own-feedback, .message-item.own-message {
            background: #e8f4ff;
            border-left-color: #007bff;
        }

        .feedback-header, .message-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .feedback-user, .message-info {
            font-weight: 600;
            color: #2b6cb0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .own-badge {
            background-color: #007bff;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }

        .feedback-date, .message-date {
            color: #718096;
            font-size: 14px;
        }

        .feedback-comment, .message-content {
            color: #4a5568;
            line-height: 1.6;
            margin-bottom: 10px;
        }

        .feedback-actions-small, .message-actions-small {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        /* --- MESSAGING SECTION --- */
        .message-form {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .message-info {
            font-size: 14px;
            margin-bottom: 5px;
        }

        .message-to {
            font-weight: 600;
            color: #2b6cb0;
        }

        /* --- MESSAGES --- */
        .error-message {
            background-color: #fed7d7;
            color: #9b2c2c;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #feb2b2;
        }

        .success-message {
            background-color: #c6f6d5;
            color: #276749;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #9ae6b4;
        }

        .info-message {
            background-color: #bee3f8;
            color: #2c5282;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #90cdf4;
        }

        .no-messages {
            text-align: center;
            padding: 30px;
            color: #718096;
            font-style: italic;
        }

        /* --- FOOTER --- */
        .footer {
            background-color: #07417f;
            color: #fff;
            text-align: center;
            padding: 18px 10px;
            font-size: 14px;
            margin-top: auto;
        }

        /* --- RESPONSIVE --- */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                padding: 15px;
                text-align: center;
                gap: 15px;
            }
            
            .header .logo span {
                font-size: 1.3rem;
            }
            
            .contact-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .contact-details {
                grid-template-columns: 1fr;
            }
            
            .section-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .rating-summary {
                flex-direction: column;
                text-align: center;
            }
            
            .form-actions,
            .feedback-actions-small,
            .message-actions-small {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .content {
                padding: 15px;
            }
            
            .contact-card,
            .feedback-section,
            .messaging-section {
                padding: 20px;
            }
            
            .contact-title-text h1 {
                font-size: 24px;
            }
            
            .star-rating label {
                font-size: 28px;
            }
            
            .edit-btn, .delete-btn {
                padding: 8px 12px;
                font-size: 12px;
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
            <li><a href="createpage.php">Create page</a></li>
            <li><a href="editpage.php">Edit page</a></li>
            <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
        <?php else: ?>
            <li><a href="login.php">Login</a></li>
        <?php endif; ?>
    </ul>
</div>

<div class="content">
    <!-- Contact Details Card -->
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
                    <div class="detail-value" style="font-size: 24px; font-weight: bold; color: #2b6cb0;">
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
                        <div class="detail-value" style="color: #718096; font-style: italic;">
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

        <?php if ($edit_feedback_id && $current_edit_feedback): ?>
            <!-- Edit Feedback Form -->
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
            <!-- New Feedback Form -->
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
                <div class="star-rating readonly" style="display: inline-flex; vertical-align: middle; margin-left: 10px;">
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
            <h3 style="margin: 30px 0 20px; color: #2d3748;">Recent Feedback</h3>
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
                                    <span style="font-size: 11px; color: #a0aec0;">(edited)</span>
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
                                    <button type="submit" name="delete_feedback" class="delete-btn" 
                                            onclick="return confirm('Are you sure you want to delete your feedback? This action cannot be undone.');"
                                            style="padding: 6px 12px; font-size: 14px;">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif (!$user_feedback && !$edit_feedback_id): ?>
            <div class="info-message" style="text-align: center; padding: 30px;">
                No feedback yet. Be the first to share your experience!
            </div>
        <?php endif; ?>
    </div>

    <!-- Messaging Section -->
    <div class="messaging-section">
        <div class="section-header">
            <h2>Send Message to Head</h2>
        </div>

        <?php if ($message_error): ?>
            <div class="error-message"><?php echo $message_error; ?></div>
        <?php endif; ?>
        
        <?php if ($message_success): ?>
            <div class="success-message"><?php echo $message_success; ?></div>
        <?php endif; ?>

        <?php if ($user_id): ?>
            <?php if ($edit_message_id && $current_edit_message): ?>
                <!-- Edit Message Form -->
                <div class="message-edit-form editing">
                    <h3>Edit Your Message</h3>
                    <form method="POST">
                        <input type="hidden" name="message_id" value="<?php echo $current_edit_message['message_id']; ?>">
                        
                        <div class="form-group">
                            <label for="edit-message-text">Message to <?php echo htmlspecialchars($contact['head']); ?></label>
                            <textarea id="edit-message-text" name="message_text" placeholder="Edit your message..." required><?php echo htmlspecialchars($current_edit_message['message']); ?></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="update_message" class="update-btn">Update Message</button>
                            <button type="submit" name="delete_message" class="delete-btn" 
                                    onclick="return confirm('Are you sure you want to delete this message? This action cannot be undone.');">
                                Delete Message
                            </button>
                            <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>" class="cancel-btn">Cancel</a>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- New Message Form -->
                <div class="message-form">
                    <form method="POST">
                        <div class="form-group">
                            <label for="message">Message to <?php echo htmlspecialchars($contact['head']); ?></label>
                            <textarea id="message" name="message" placeholder="Type your message here..." required><?php echo isset($_POST['message']) ? htmlspecialchars($_POST['message']) : ''; ?></textarea>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" name="send_message" class="submit-btn">Send Message</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- User's Messages -->
            <?php if (!empty($user_messages)): ?>
                <h3 style="margin: 30px 0 20px; color: #2d3748;">Your Recent Messages</h3>
                <div class="message-list">
                    <?php foreach ($user_messages as $message): ?>
                        <div class="message-item own-message">
                            <div class="message-header">
                                <div class="message-info">
                                    To: <?php echo htmlspecialchars($contact['head']); ?>
                                    <span class="own-badge">Your Message</span>
                                </div>
                                <div class="message-date">
                                    <?php echo date('M d, Y H:i', strtotime($message['created_at'])); ?>
                                    <?php if ($message['updated_at'] != $message['created_at']): ?>
                                        <span style="font-size: 11px; color: #a0aec0;">(edited)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="message-content"><?php echo nl2br(htmlspecialchars($message['message'])); ?></div>
                            
                            <?php if (!$edit_message_id): ?>
                                <div class="message-actions-small">
                                    <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>&edit_message=<?php echo $message['message_id']; ?>" class="edit-btn">Edit</a>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="message_id" value="<?php echo $message['message_id']; ?>">
                                        <button type="submit" name="delete_message" class="delete-btn" 
                                                onclick="return confirm('Are you sure you want to delete this message? This action cannot be undone.');"
                                                style="padding: 6px 12px; font-size: 14px;">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif (!$edit_message_id): ?>
                <div class="no-messages">
                    You haven't sent any messages to <?php echo htmlspecialchars($contact['head']); ?> yet.
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="info-message">
                Please <a href="login.php">login</a> to send a message to <?php echo htmlspecialchars($contact['head']); ?>.
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="footer">
    © 2026 Intercom Directory. All rights reserved.<br>
    Developed by TNTS Programming Students JT.DP.RR
</div>

<script>
    // Star rating interaction
    document.addEventListener('DOMContentLoaded', function() {
        const starRatings = document.querySelectorAll('.star-rating:not(.readonly)');
        
        starRatings.forEach(rating => {
            const stars = rating.querySelectorAll('label');
            const inputs = rating.querySelectorAll('input');
            
            stars.forEach((star, index) => {
                star.addEventListener('mouseenter', () => {
                    // Highlight stars up to this one
                    for (let i = stars.length - 1; i >= index; i--) {
                        stars[i].style.color = '#ffc107';
                    }
                });
                
                star.addEventListener('mouseleave', () => {
                    // Reset to selected state
                    inputs.forEach((input, i) => {
                        stars[i].style.color = input.checked ? '#ffc107' : '#e2e8f0';
                    });
                });
                
                star.addEventListener('click', () => {
                    // Reset all colors first
                    stars.forEach(s => s.style.color = '#e2e8f0');
                    // Highlight selected and previous stars
                    for (let i = stars.length - 1; i >= index; i--) {
                        stars[i].style.color = '#ffc107';
                    }
                });
            });
            
            // Initialize colors based on selected input
            inputs.forEach((input, i) => {
                if (input.checked) {
                    stars[i].style.color = '#ffc107';
                }
            });
        });
        
        // Character counter for new message
        const messageTextarea = document.getElementById('message');
        if (messageTextarea) {
            const charCounter = document.createElement('div');
            charCounter.style.fontSize = '12px';
            charCounter.style.color = '#718096';
            charCounter.style.textAlign = 'right';
            charCounter.style.marginTop = '5px';
            messageTextarea.parentNode.appendChild(charCounter);
            
            function updateCharCounter() {
                const length = messageTextarea.value.length;
                charCounter.textContent = `${length}/1000 characters`;
                if (length > 1000) {
                    charCounter.style.color = '#e53e3e';
                } else {
                    charCounter.style.color = '#718096';
                }
            }
            
            messageTextarea.addEventListener('input', updateCharCounter);
            updateCharCounter();
        }
        
        // Character counter for edit message
        const editMessageTextarea = document.getElementById('edit-message-text');
        if (editMessageTextarea) {
            const editCharCounter = document.createElement('div');
            editCharCounter.style.fontSize = '12px';
            editCharCounter.style.color = '#718096';
            editCharCounter.style.textAlign = 'right';
            editCharCounter.style.marginTop = '5px';
            editMessageTextarea.parentNode.appendChild(editCharCounter);
            
            function updateEditCharCounter() {
                const length = editMessageTextarea.value.length;
                editCharCounter.textContent = `${length}/1000 characters`;
                if (length > 1000) {
                    editCharCounter.style.color = '#e53e3e';
                } else {
                    editCharCounter.style.color = '#718096';
                }
            }
            
            editMessageTextarea.addEventListener('input', updateEditCharCounter);
            updateEditCharCounter();
        }
        
        // Character counter for feedback
        const feedbackTextarea = document.getElementById('comment');
        if (feedbackTextarea) {
            const feedbackCounter = document.createElement('div');
            feedbackCounter.style.fontSize = '12px';
            feedbackCounter.style.color = '#718096';
            feedbackCounter.style.textAlign = 'right';
            feedbackCounter.style.marginTop = '5px';
            feedbackTextarea.parentNode.appendChild(feedbackCounter);
            
            function updateFeedbackCounter() {
                const length = feedbackTextarea.value.length;
                feedbackCounter.textContent = `${length} characters`;
            }
            
            feedbackTextarea.addEventListener('input', updateFeedbackCounter);
            updateFeedbackCounter();
        }
        
        // Character counter for edit feedback
        const editFeedbackTextarea = document.getElementById('edit-comment');
        if (editFeedbackTextarea) {
            const editFeedbackCounter = document.createElement('div');
            editFeedbackCounter.style.fontSize = '12px';
            editFeedbackCounter.style.color = '#718096';
            editFeedbackCounter.style.textAlign = 'right';
            editFeedbackCounter.style.marginTop = '5px';
            editFeedbackTextarea.parentNode.appendChild(editFeedbackCounter);
            
            function updateEditFeedbackCounter() {
                const length = editFeedbackTextarea.value.length;
                editFeedbackCounter.textContent = `${length} characters`;
            }
            
            editFeedbackTextarea.addEventListener('input', updateEditFeedbackCounter);
            updateEditFeedbackCounter();
        }
        
        // Auto-scroll to appropriate section when editing
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('edit_feedback')) {
            const feedbackSection = document.querySelector('.feedback-section');
            if (feedbackSection) {
                setTimeout(() => {
                    feedbackSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }
        }
        
        if (urlParams.has('edit_message')) {
            const messagingSection = document.querySelector('.messaging-section');
            if (messagingSection) {
                setTimeout(() => {
                    messagingSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 100);
            }
        }
        
        // Debug: Log all edit button clicks
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                console.log('Edit button clicked. URL:', this.href);
                // Uncomment the line below to prevent navigation for debugging
                // e.preventDefault();
            });
        });
        
        // Add click handler for edit buttons to show the URL in alert
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                console.log('Edit button URL:', this.getAttribute('href'));
            });
        });
    });
</script>

</body>
</html>
