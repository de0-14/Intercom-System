<?php
require_once 'conn.php';
require_once 'archive_functions.php';
require_once 'admin_archive_functions.php';

archiveAllInactiveChatsOnLoad($conn);

function formatDateForDisplay($date) {
    if ($date instanceof DateTime) {
        return $date->format('M d, Y H:i');
    } elseif (is_string($date)) {
        return date('M d, Y H:i', strtotime($date));
    } else {
        return 'Invalid date';
    }
}

function formatDateShort($date) {
    if ($date instanceof DateTime) {
        return $date->format('M d');
    } elseif (is_string($date)) {
        return date('M d', strtotime($date));
    } else {
        return 'Invalid date';
    }
}

function formatDateLong($date) {
    if ($date instanceof DateTime) {
        return $date->format('M d, Y');
    } elseif (is_string($date)) {
        return date('M d, Y', strtotime($date));
    } else {
        return 'Invalid date';
    }
}

date_default_timezone_set('Asia/Manila');
ini_set('date.timezone', 'Asia/Manila');

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

$current_script = basename($_SERVER['PHP_SELF']);

if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: homepage.php');
    exit;
}

// Initialize all variables
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

// Get unread admin message count
if ($user_id) {
    $user_unread = getUnreadAdminMessageCount($conn, $user_id, false);
} else {
    $user_unread = 0;
}

// Get admin notification data
$admin_notifications_count = 0;
$admin_chat_requests = [];
$is_admin = isAdmin();

if ($is_admin && $user_id) {
    $admin_notifications_count = getAdminUnreadChatsCount($conn, $user_id);
    $admin_chat_requests = getAdminChatRequests($conn, $user_id);
}

$view_archived = isset($_GET['view']) && $_GET['view'] === 'archived';

// Get head user ID
$head_user_id = getHeadUserId($conn, $number_id);
$is_head = ($head_user_id == $user_id);

// ==========================================
// ARCHIVE SYSTEM
// ==========================================
if ($user_id) {
    if (!$view_archived) {
        $thirty_minutes_ago = date('Y-m-d H:i:s', strtotime('-30 minutes'));
        
        $old_convs_sql = "
            SELECT conversation_id 
            FROM conversations 
            WHERE number_id = ? 
            AND is_archived = 0
            AND last_activity < ?
        ";
        
        $old_stmt = sqlsrv_prepare($conn, $old_convs_sql, array($number_id, $thirty_minutes_ago));
        if ($old_stmt && sqlsrv_execute($old_stmt)) {
            while ($conv = sqlsrv_fetch_array($old_stmt, SQLSRV_FETCH_ASSOC)) {
                $conversation_id = $conv['conversation_id'];
                if (archiveConversationImmediately($conn, $conversation_id)) {
                    $archived_this_load++;
                }
            }
        }
        sqlsrv_free_stmt($old_stmt);
    }
    
    $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
    if ($is_head) {
        $removed_this_load = removeOldArchivedConversations($conn, $number_id, null, $seven_days_ago);
    } else {
        $removed_this_load = removeOldArchivedConversations($conn, $number_id, $user_id, $seven_days_ago);
    }
}

// Check archived count
if ($user_id) {
    if ($is_head) {
        $archived_count_sql = "SELECT COUNT(*) as archive_count FROM conversations_archive WHERE number_id = ?";
        $archived_count_params = array($number_id);
    } else {
        $archived_count_sql = "SELECT COUNT(*) as archive_count FROM conversations_archive WHERE number_id = ? AND initiated_by = ?";
        $archived_count_params = array($number_id, $user_id);
    }
    
    $archived_count_stmt = sqlsrv_query($conn, $archived_count_sql, $archived_count_params);
    if ($archived_count_stmt) {
        $archive_data = sqlsrv_fetch_array($archived_count_stmt, SQLSRV_FETCH_ASSOC);
        $archived_conversations_count = $archive_data['archive_count'] ?? 0;
    }
    sqlsrv_free_stmt($archived_count_stmt);
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

$stmt = sqlsrv_prepare($conn, $contact_query, array($number_id));
if (!$stmt || !sqlsrv_execute($stmt)) {
    die(print_r(sqlsrv_errors(), true));
}

$contact = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if (!$contact) {
    header('Location: homepage.php');
    exit;
}
sqlsrv_free_stmt($stmt);

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

// Load conversations
$selected_conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : null;

if ($user_id) {
    if ($view_archived) {
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
        
        $conv_params = array($number_id);
        if (!$is_head) {
            $conv_params[] = $user_id;
        }
    } else {
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
        
        $conv_params = array($number_id);
        if (!$is_head) {
            $conv_params[] = $user_id;
        }
    }
    
    $conv_stmt = sqlsrv_prepare($conn, $conversations_query, $conv_params);
    if ($conv_stmt && sqlsrv_execute($conv_stmt)) {
        $conversations = array();
        while ($row = sqlsrv_fetch_array($conv_stmt, SQLSRV_FETCH_ASSOC)) {
            $conversations[] = $row;
        }
    }
    sqlsrv_free_stmt($conv_stmt);
    
    // Load selected conversation messages
    if ($selected_conversation_id && !$view_archived) {
        $access_check_sql = "
            SELECT 
                c.*, 
                u.username as initiator_name, 
                u.full_name as initiator_full_name,
                n.head as head_name,
                n.head_user_id
            FROM conversations c
            JOIN users u ON c.initiated_by = u.user_id
            JOIN numbers n ON c.number_id = n.number_id
            WHERE c.conversation_id = ? 
            AND c.is_archived = 0
            AND (c.initiated_by = ? OR ? = n.head_user_id)
        ";
        
        $access_check_params = array($selected_conversation_id, $user_id, $user_id);
        $access_check = sqlsrv_query($conn, $access_check_sql, $access_check_params);
        
        if ($access_check && sqlsrv_has_rows($access_check)) {
            $selected_conversation_info = sqlsrv_fetch_array($access_check, SQLSRV_FETCH_ASSOC);
            
            $messages_query = "
                SELECT m.*, u.username, u.full_name
                FROM messages m
                JOIN users u ON m.sender_id = u.user_id
                WHERE m.conversation_id = ?
                AND m.is_archived = 0
                ORDER BY m.created_at ASC
            ";
            $messages_stmt = sqlsrv_query($conn, $messages_query, array($selected_conversation_id));
            if ($messages_stmt) {
                $conversation_messages = array();
                while ($row = sqlsrv_fetch_array($messages_stmt, SQLSRV_FETCH_ASSOC)) {
                    $conversation_messages[] = $row;
                }
                sqlsrv_free_stmt($messages_stmt);
            }
        } else {
            $selected_conversation_id = null;
            $selected_conversation_info = null;
        }
        sqlsrv_free_stmt($access_check);
    }
    
    // Load selected archived conversation
    if ($selected_conversation_id && $view_archived) {
        $access_check_sql = "
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
        ";
        
        $access_check_params = array($selected_conversation_id);
        if (!$is_head) {
            $access_check_params[] = $user_id;
        }
        
        $access_check = sqlsrv_query($conn, $access_check_sql, $access_check_params);
        
        if ($access_check && sqlsrv_has_rows($access_check)) {
            $selected_conversation_info = sqlsrv_fetch_array($access_check, SQLSRV_FETCH_ASSOC);
            
            $messages_query = "
                SELECT ma.*, u.username, u.full_name
                FROM messages_archive ma
                JOIN users u ON ma.sender_id = u.user_id
                WHERE ma.conversation_id = ?
                ORDER BY ma.created_at ASC
            ";
            $messages_stmt = sqlsrv_query($conn, $messages_query, array($selected_conversation_id));
            if ($messages_stmt) {
                $conversation_messages = array();
                while ($row = sqlsrv_fetch_array($messages_stmt, SQLSRV_FETCH_ASSOC)) {
                    $conversation_messages[] = $row;
                }
                sqlsrv_free_stmt($messages_stmt);
            }
        } else {
            $selected_conversation_id = null;
            $selected_conversation_info = null;
        }
        sqlsrv_free_stmt($access_check);
    }
}

// Load feedback data
$feedback_query = "
    SELECT TOP 10 f.*, u.username 
    FROM feedback f 
    JOIN users u ON f.user_id = u.user_id 
    WHERE f.number_id = ? 
    ORDER BY f.updated_at DESC, f.created_at DESC
";
$feedback_stmt = sqlsrv_query($conn, $feedback_query, array($number_id));
if ($feedback_stmt) {
    $feedbacks = array();
    while ($row = sqlsrv_fetch_array($feedback_stmt, SQLSRV_FETCH_ASSOC)) {
        $feedbacks[] = $row;
    }
    sqlsrv_free_stmt($feedback_stmt);
}

// Get average rating
$avg_rating_query = "SELECT AVG(CAST(rating as DECIMAL(10,2))) as avg_rating, COUNT(*) as total_feedbacks FROM feedback WHERE number_id = ?";
$avg_stmt = sqlsrv_query($conn, $avg_rating_query, array($number_id));
if ($avg_stmt) {
    $avg_data = sqlsrv_fetch_array($avg_stmt, SQLSRV_FETCH_ASSOC);
    $avg_rating = round($avg_data['avg_rating'] ?? 0, 1);
    $total_feedbacks = $avg_data['total_feedbacks'] ?? 0;
    sqlsrv_free_stmt($avg_stmt);
}

// Get user feedback
if ($user_id) {
    $user_feedback_query = "SELECT * FROM feedback WHERE number_id = ? AND user_id = ?";
    $user_feedback_stmt = sqlsrv_query($conn, $user_feedback_query, array($number_id, $user_id));
    if ($user_feedback_stmt && sqlsrv_has_rows($user_feedback_stmt)) {
        $user_feedback = sqlsrv_fetch_array($user_feedback_stmt, SQLSRV_FETCH_ASSOC);
    }
    sqlsrv_free_stmt($user_feedback_stmt);
}

// Check if editing feedback
if ($edit_feedback_id && $user_id) {
    $edit_feedback_sql = "SELECT * FROM feedback WHERE feedback_id = ? AND user_id = ?";
    $edit_feedback_params = array($edit_feedback_id, $user_id);
    $edit_feedback_stmt = sqlsrv_query($conn, $edit_feedback_sql, $edit_feedback_params);
    
    if ($edit_feedback_stmt && sqlsrv_has_rows($edit_feedback_stmt)) {
        $current_edit_feedback = sqlsrv_fetch_array($edit_feedback_stmt, SQLSRV_FETCH_ASSOC);
    } else {
        $feedback_error = "You can only edit your own feedback.";
        $edit_feedback_id = null;
    }
    sqlsrv_free_stmt($edit_feedback_stmt);
}

// REMOVE ALL POST HANDLERS - they will be handled by AJAX now
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($contact['numbers']); ?> - Contact Details</title>
    <style>
    /* ===== COMPLETE CSS - KEEP YOUR EXISTING CSS HERE ===== */
    * { box-sizing: border-box; margin:0; padding:0; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    body { min-height: 100vh; display: flex; flex-direction: column; background-color: #edf4fc; }
    .header { position: fixed; top: 0; left: 0; width: 100%; background-color: #07417f; color: white; padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-bottom: 3px solid #2b6cb0; }
    .header .logo { display: flex; align-items: center; gap: 15px; }
    .header .logo img { width: 55px; height: 55px; object-fit: contain; }
    .header .logo span { font-size: 1.5rem; font-weight: 700; color: white; text-shadow: 0 1px 2px rgba(0,0,0,0.2); }
    ul.nav { display: flex; list-style: none; gap: 8px; }
    ul.nav li a { display: block; color: white; text-decoration: none; padding: 10px 18px; font-weight: 600; border-radius: 6px; transition: all 0.2s; }
    ul.nav li a:hover { background-color: rgba(255,255,255,0.2); }
    ul.nav li a.active { background-color: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); }
    .admin-notification-container { position: relative; display: inline-block; margin-left: 10px; }
    .admin-notification-btn { width: 50px; height: 50px; border-radius: 50%; background: linear-gradient(135deg, #e53e3e, #c53030); color: white; border: 3px solid white; cursor: pointer; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 20px; box-shadow: 0 3px 10px rgba(229,62,62,0.3); transition: all 0.3s; position: relative; }
    .admin-notification-btn:hover { background: linear-gradient(135deg, #c53030, #9b2c2c); transform: scale(1.05); box-shadow: 0 5px 15px rgba(229,62,62,0.4); }
    .notification-dropdown { position: absolute; top: 100%; right: 0; width: 350px; background: white; border-radius: 8px; box-shadow: 0 5px 20px rgba(0,0,0,0.15); margin-top: 15px; padding: 0; z-index: 1000; opacity: 0; visibility: hidden; transform: translateY(-10px); transition: all 0.3s; }
    .admin-notification-container:hover .notification-dropdown { opacity: 1; visibility: visible; transform: translateY(0); }
    .notification-header { padding: 15px; background: #2b6cb0; color: white; border-radius: 8px 8px 0 0; }
    .notification-header h4 { margin: 0; color: white; font-size: 16px; display: flex; align-items: center; gap: 8px; }
    .notification-list { max-height: 400px; overflow-y: auto; padding: 10px; }
    .notification-item { display: flex; align-items: center; padding: 12px; border-radius: 8px; margin-bottom: 8px; border: 1px solid #e2e8f0; transition: all 0.2s; text-decoration: none; color: inherit; }
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
    .notification-indicator { position: relative; }
    .nav-notification-badge { background-color: #e53e3e; color: white; font-size: 11px; padding: 2px 6px; border-radius: 10px; min-width: 18px; text-align: center; margin-left: 5px; animation: pulse 2s infinite; display: inline-block; }
    @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.1); } 100% { transform: scale(1); } }
    .content { flex: 1; margin-top: 100px; padding: 20px; max-width: 1200px; margin-left: auto; margin-right: auto; width: 100%; display: flex; flex-direction: column; gap: 30px; }
    .contact-card, .feedback-section, .messaging-section { width: 100%; margin-bottom: 30px; position: relative; z-index: 1; }
    .messaging-section { order: 3; margin-top: 20px; }
    .contact-card { background: white; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin-bottom: 30px; overflow: hidden; }
    .contact-header { display: flex; justify-content: space-between; align-items: center; padding: 25px 30px; background: linear-gradient(135deg, #2b6cb0 0%, #1f4f8b 100%); color: white; }
    .contact-title { display: flex; align-items: center; gap: 20px; }
    .contact-icon { width: 70px; height: 70px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: bold; color: #2b6cb0; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
    .contact-title-text h1 { font-size: 32px; margin-bottom: 5px; color: white; }
    .description { font-size: 16px; opacity: 0.9; }
    .back-button { color: white; text-decoration: none; padding: 10px 20px; background: rgba(255,255,255,0.2); border-radius: 6px; font-weight: 600; transition: all 0.3s; }
    .back-button:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); }
    .contact-details { padding: 30px; }
    .detail-section { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #e2e8f0; }
    .detail-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    .detail-section h3 { color: #2b6cb0; margin-bottom: 20px; font-size: 20px; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; }
    .detail-item { display: flex; margin-bottom: 15px; align-items: center; }
    .detail-label { width: 200px; font-weight: 600; color: #4a5568; font-size: 15px; }
    .detail-value { flex: 1; color: #2d3748; font-size: 16px; }
    .contact-number { font-size: 24px; font-weight: 700; color: #2b6cb0; }
    .head-value { font-size: 20px; font-weight: 600; color: #2b6cb0; }
    .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; margin-left: 10px; }
    .status-active { background-color: #c6f6d5; color: #22543d; }
    .status-decommissioned { background-color: #fed7d7; color: #742a2a; }
    .org-badge { display: inline-block; padding: 4px 12px; background-color: #e2e8f0; color: #4a5568; border-radius: 4px; font-size: 12px; font-weight: 600; margin-right: 10px; }
    .no-org { color: #a0aec0; font-style: italic; }
    .edit-form-section { background-color: #f7fafc; padding: 25px 30px; border-bottom: 1px solid #e2e8f0; }
    .edit-form-section h3 { color: #2b6cb0; margin-bottom: 20px; font-size: 20px; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; display: flex; align-items: center; gap: 10px; }
    .edit-form { margin-top: 15px; }
    .form-row { display: flex; gap: 20px; margin-bottom: 15px; }
    .form-group { flex: 1; margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #4a5568; font-size: 14px; }
    .form-control { width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 15px; transition: all 0.2s; }
    .form-control:focus { outline: none; border-color: #2b6cb0; box-shadow: 0 0 0 3px rgba(43,108,176,0.1); }
    select.form-control { height: 42px; cursor: pointer; }
    .form-actions { display: flex; gap: 10px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0; }
    .edit-btn { background-color: #38a169; color: white; padding: 10px 20px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; transition: all 0.2s; }
    .edit-btn:hover { background-color: #2f855a; transform: translateY(-1px); }
    .cancel-btn { background-color: #718096; color: white; padding: 10px 20px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; transition: all 0.2s; }
    .cancel-btn:hover { background-color: #4a5568; }
    .head-badge { display: inline-block; padding: 4px 10px; background-color: #38a169; color: white; border-radius: 4px; font-size: 12px; font-weight: 600; margin-left: 10px; }
    .error-message { background-color: #fed7d7; color: #742a2a; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #e53e3e; }
    .success-message { background-color: #c6f6d5; color: #22543d; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #38a169; }
    .info-message { background-color: #e8f4fd; color: #084298; padding: 15px; border-radius: 6px; margin-bottom: 20px; text-align: center; }
    .info-message a { color: #2b6cb0; font-weight: 600; text-decoration: none; }
    .info-message a:hover { text-decoration: underline; }
    .feedback-section { background: white; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin-bottom: 30px; padding: 30px; }
    .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #e2e8f0; }
    .section-header h2 { color: #2b6cb0; font-size: 24px; }
    .rating-summary { display: flex; align-items: center; gap: 20px; }
    .average-rating { font-size: 48px; font-weight: 700; color: #2b6cb0; }
    .star-rating { display: flex; flex-direction: row-reverse; justify-content: flex-end; }
    .star-rating input { display: none; }
    .star-rating label { font-size: 24px; color: #e2e8f0; cursor: pointer; transition: color 0.2s; }
    .star-rating input:checked ~ label, .star-rating label:hover, .star-rating label:hover ~ label { color: #ffc107; }
    .star-rating.readonly label { cursor: default; }
    .star-rating.readonly input:checked ~ label { color: #ffc107; }
    .total-feedbacks { color: #718096; font-size: 14px; margin-top: 5px; }
    .feedback-form { background-color: #f7fafc; padding: 25px; border-radius: 8px; margin-bottom: 30px; border: 1px solid #e2e8f0; }
    .feedback-form.editing { background-color: #fff5f5; border: 1px solid #fed7d7; }
    .feedback-form h3 { color: #2b6cb0; margin-bottom: 20px; font-size: 20px; }
    textarea { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 16px; resize: vertical; min-height: 120px; font-family: inherit; }
    textarea:focus { outline: none; border-color: #2b6cb0; box-shadow: 0 0 0 3px rgba(43,108,176,0.1); }
    .inline-rating { display: inline-flex; margin-left: 10px; vertical-align: middle; }
    .submit-btn, .update-btn, .delete-btn, .cancel-btn { padding: 10px 20px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; font-size: 14px; transition: all 0.2s; }
    .submit-btn, .update-btn { background-color: #2b6cb0; color: white; }
    .submit-btn:hover, .update-btn:hover { background-color: #1f4f8b; }
    .delete-btn { background-color: #e53e3e; color: white; }
    .delete-btn:hover { background-color: #c53030; }
    .small-btn { padding: 4px 10px !important; font-size: 12px !important; }
    .recent-feedback-title { color: #2b6cb0; margin: 25px 0 15px 0; font-size: 18px; padding-bottom: 10px; border-bottom: 1px solid #e2e8f0; }
    .feedback-list { margin-top: 20px; }
    .feedback-item { background-color: #f7fafc; padding: 20px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #e2e8f0; }
    .feedback-item.own-feedback { background-color: #f0f9ff; border-color: #b6d4fe; }
    .feedback-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .feedback-user { font-weight: 600; color: #2d3748; display: flex; align-items: center; gap: 10px; }
    .own-badge { background-color: #2b6cb0; color: white; font-size: 11px; padding: 2px 8px; border-radius: 12px; }
    .feedback-date { color: #718096; font-size: 13px; }
    .edited-badge { color: #a0aec0; font-size: 12px; font-style: italic; }
    .feedback-comment { color: #4a5568; line-height: 1.6; font-size: 15px; }
    .feedback-actions-small { margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0; display: flex; gap: 10px; }
    .edit-btn-small { color: #2b6cb0; text-decoration: none; font-size: 14px; font-weight: 500; }
    .edit-btn-small:hover { text-decoration: underline; }
    .centered-message { text-align: center; padding: 30px !important; }
    .messaging-section { background: white; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); padding: 30px; }
    .chat-messages { height: 400px; overflow-y: auto; padding: 20px; background-color: #f7fafc; border-bottom: 1px solid #e2e8f0; }
    .chat-input-area { padding: 15px; background: white; border-top: 1px solid #e2e8f0; }
    .chat-input-form { display: flex; gap: 10px; align-items: flex-end; }
    .chat-input { flex: 1; min-height: 60px; max-height: 120px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: vertical; font-family: inherit; }
    .chat-send-btn { width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; font-size: 18px; padding: 0; background-color: #2b6cb0; color: white; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.2s; }
    .chat-send-btn:hover { background-color: #1f4f8b; }
    .message-bubble { max-width: 70%; margin-bottom: 15px; padding: 12px 16px; border-radius: 18px; position: relative; clear: both; word-wrap: break-word; }
    .message-sent { background-color: #2b6cb0; color: white; float: right; border-bottom-right-radius: 4px; }
    .message-received { background-color: white; color: #2d3748; float: left; border: 1px solid #e2e8f0; border-bottom-left-radius: 4px; }
    .message-text { line-height: 1.5; margin-bottom: 5px; }
    .message-info { display: flex; justify-content: space-between; font-size: 11px; opacity: 0.8; }
    .message-sent .message-info { color: rgba(255,255,255,0.8); }
    .message-received .message-info { color: #718096; }
    .chat-header { background-color: #2b6cb0; color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
    .chat-header h3 { margin: 0; color: white; font-size: 16px; }
    .chat-back-btn { color: white; text-decoration: none; font-size: 14px; font-weight: 500; }
    .chat-back-btn:hover { text-decoration: underline; }
    .no-messages { text-align: center; color: #a0aec0; font-style: italic; padding: 40px 20px; }
    .chat-status-info { background: #f8f9fa; padding: 10px 15px; border-radius: 5px; margin-bottom: 15px; font-size: 13px; color: #4a5568; }
    .conversation-item { display: block; padding: 15px; background-color: white; border: 1px solid #e2e8f0; border-radius: 6px; text-decoration: none; color: inherit; transition: all 0.2s; margin-bottom: 10px; }
    .conversation-item:hover, .conversation-item.active { background-color: #f7fafc; border-color: #2b6cb0; }
    .conversation-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
    .conversation-with { font-weight: 600; color: #2b6cb0; }
    .conversation-date { color: #718096; font-size: 13px; }
    .conversation-preview { color: #4a5568; font-size: 14px; margin-bottom: 8px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .conversation-meta { display: flex; justify-content: space-between; color: #a0aec0; font-size: 12px; }
    .conversations-list { max-height: 300px; overflow-y: auto; margin-bottom: 20px; }
    .message-sender { position: relative; cursor: help; text-decoration: underline dotted; text-decoration-thickness: 1px; text-underline-offset: 2px; }
    .user-profile-tooltip { display: none; position: absolute; top: 100%; left: 0; background: white; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); padding: 15px; width: 300px; z-index: 1000; font-size: 13px; line-height: 1.5; margin-top: 10px; }
    .message-sender:hover .user-profile-tooltip { display: block; }
    .user-profile-header { display: flex; align-items: center; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #e2e8f0; }
    .user-profile-avatar { width: 40px; height: 40px; border-radius: 50%; background: #2b6cb0; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 12px; font-size: 16px; }
    .user-profile-name { font-weight: 600; color: #2d3748; font-size: 14px; }
    .user-profile-email { color: #718096; font-size: 12px; margin-top: 2px; }
    .user-profile-details { display: grid; gap: 8px; }
    .profile-detail-row { display: flex; justify-content: space-between; }
    .profile-detail-label { font-weight: 600; color: #4a5568; min-width: 100px; }
    .profile-detail-value { color: #2d3748; text-align: right; flex: 1; }
    .user-profile-divider { margin: 10px 0; border: none; border-top: 1px solid #e2e8f0; }
    .user-profile-head-info { background-color: #f0f9ff; border-left: 3px solid #2b6cb0; padding: 8px 10px; border-radius: 4px; margin-top: 10px; font-size: 12px; }
    .head-indicator { background-color: #38a169; color: white; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600; margin-left: 5px; }
    .no-head-info { color: #a0aec0; font-style: italic; font-size: 12px; }
    .archived-table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
    .archived-table th { background-color: #2b6cb0; color: white; padding: 12px 15px; text-align: left; font-weight: 600; font-size: 14px; }
    .archived-table td { padding: 12px 15px; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
    .archived-table tr:last-child td { border-bottom: none; }
    .archived-table tr:hover { background-color: #f7fafc; }
    .archived-conversation-id { color: #4a5568; font-weight: 500; }
    .archived-conversation-with { color: #2b6cb0; font-weight: 500; }
    .archived-message-count { background-color: #e2e8f0; color: #4a5568; padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: 500; }
    .archived-date { color: #718096; font-size: 13px; }
    .no-archived-message { text-align: center; padding: 40px 20px; color: #a0aec0; font-style: italic; background: white; border-radius: 8px; border: 1px solid #e2e8f0; }
    .auto-process-notice { background-color: #e8f4fd; border: 1px solid #b6d4fe; color: #084298; padding: 10px 15px; border-radius: 6px; font-size: 14px; text-align: center; margin-bottom: 20px; }
    .view-toggle-section { margin-bottom: 20px; }
    .view-toggle-buttons { display: flex; gap: 10px; margin-bottom: 10px; }
    .view-toggle-btn { padding: 10px 20px; background-color: #f7fafc; border: 2px solid #e2e8f0; border-radius: 8px; text-decoration: none; color: #4a5568; font-weight: 500; display: flex; align-items: center; gap: 8px; transition: all 0.3s; }
    .view-toggle-btn:hover { background-color: #edf2f7; border-color: #cbd5e0; }
    .view-toggle-btn.active { background-color: #2b6cb0; border-color: #2b6cb0; color: white; }
    .archive-count-badge { background-color: #e53e3e; color: white; font-size: 12px; padding: 2px 8px; border-radius: 12px; min-width: 20px; text-align: center; }
    .footer { background-color: #07417f; color: #fff; text-align: center; padding: 18px 10px; font-size: 14px; margin-top: auto; }
    .chat-container.archived-view .message-bubble { opacity: 0.9; }
    .chat-container.archived-view .message-sent { background-color: #4a5568; }
    .chat-container.archived-view .message-received { background-color: #e2e8f0; color: #2d3748; }
    .view-btn { display: inline-block; padding: 4px 10px; background-color: #e2e8f0; color: #4a5568; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 500; transition: all 0.2s; }
    .view-btn:hover { background-color: #cbd5e0; color: #2d3748; }
    .toast-notification { position: fixed; top: 100px; right: 20px; background: #2b6cb0; color: white; padding: 12px 20px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; justify-content: space-between; gap: 15px; z-index: 9999; animation: slideIn 0.3s ease; max-width: 400px; font-size: 14px; }
    .toast-success { background: #38a169 !important; }
    .toast-error { background: #e53e3e !important; }
    .toast-close { background: none; border: none; color: white; font-size: 20px; cursor: pointer; padding: 0; margin: 0; line-height: 1; }
    .toast-close:hover { opacity: 0.8; }
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    @media (max-width: 768px) {
        .header { flex-direction: column; padding: 15px; text-align: center; }
        .header .logo span { font-size: 1.3rem; }
        .content { margin-top: 140px; padding: 15px; }
        .contact-header { flex-direction: column; gap: 15px; text-align: center; }
        .contact-title { flex-direction: column; text-align: center; }
        .detail-item { flex-direction: column; align-items: flex-start; }
        .detail-label { width: 100%; margin-bottom: 5px; }
        .section-header { flex-direction: column; align-items: flex-start; gap: 15px; }
        .rating-summary { width: 100%; justify-content: space-between; }
        .form-actions { flex-direction: column; }
        .view-toggle-buttons { flex-direction: column; }
        .archived-table { display: block; overflow-x: auto; }
        .form-row { flex-direction: column; gap: 0; }
        .user-profile-tooltip { width: 250px; left: -100px; top: 100%; margin-top: 5px; }
    }
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
                <li>
                    <a href="adminpanel.php" class="notification-indicator">
                        Operator Panel 
                        <?php if ($admin_notifications_count > 0): ?>
                            <span class="nav-notification-badge"><?php echo $admin_notifications_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php else: ?>
                <li><a href="adminchat.php">Chat with an Operator <?php echo $user_unread > 0 ? "($user_unread)" : ""; ?></a></li>
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

<!-- ============ PAGE CONFIGURATION - JSON DATA BLOCK ============ -->
<script id="page-config" type="application/json">
{
    "numberId": <?php echo json_encode($number_id); ?>,
    "userId": <?php echo json_encode($user_id ?? 0); ?>,
    "isHead": <?php echo json_encode($is_head); ?>,
    "isAdmin": <?php echo json_encode($is_admin); ?>,
    "viewArchived": <?php echo json_encode($view_archived); ?>,
    "selectedConversationId": <?php echo json_encode($selected_conversation_id ?? 0); ?>
}
</script>

<div class="content">
    <!-- ============ CONTACT CARD ============ -->
    <div class="contact-card">
        <?php if ($is_head && $is_editing): ?>
        <div class="edit-form-section">
            <?php if ($edit_error): ?>
                <div class="error-message"><?php echo $edit_error; ?></div>
            <?php endif; ?>
            <?php if ($edit_success): ?>
                <div class="success-message"><?php echo $edit_success; ?></div>
            <?php endif; ?>
            
            <h3>✏️ Edit Contact Information</h3>
            <form method="POST" class="edit-form" id="edit-contact-form">
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
                    <div class="detail-value contact-number"><?php echo htmlspecialchars($contact['numbers'] ?? 'N/A'); ?></div>
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
                        <div class="detail-value no-org">No organization assigned</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============ FEEDBACK SECTION ============ -->
    <div class="feedback-section">
        <div class="section-header">
            <h2>User Feedback</h2>
            <div class="rating-summary">
                <div class="average-rating" id="avg-rating"><?php echo $avg_rating; ?>/5</div>
                <div>
                    <div class="star-rating readonly" id="avg-stars">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <input type="radio" id="avg-star-<?php echo $i; ?>" value="<?php echo $i; ?>" <?php echo round($avg_rating) == $i ? 'checked' : ''; ?> disabled>
                            <label for="avg-star-<?php echo $i; ?>">★</label>
                        <?php endfor; ?>
                    </div>
                    <div class="total-feedbacks" id="total-feedbacks">Based on <?php echo $total_feedbacks; ?> reviews</div>
                </div>
            </div>
        </div>

        <div id="feedback-messages"></div>

        <!-- Feedback Form -->
        <?php if ($edit_feedback_id && $current_edit_feedback): ?>
            <div class="feedback-form editing" id="edit-feedback-form">
                <h3>Edit Your Feedback</h3>
                <form id="feedback-edit-form">
                    <input type="hidden" name="feedback_id" value="<?php echo $current_edit_feedback['feedback_id']; ?>">
                    <input type="hidden" name="number_id" value="<?php echo $number_id; ?>">
                    
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
                        <button type="submit" class="update-btn">Update Feedback</button>
                        <button type="button" class="delete-btn" id="delete-feedback-btn">Delete Feedback</button>
                        <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>" class="cancel-btn">Cancel</a>
                    </div>
                </form>
            </div>
        <?php elseif (!$user_feedback && $user_id): ?>
            <div class="feedback-form" id="new-feedback-form">
                <h3>Leave Your Feedback</h3>
                <form id="feedback-submit-form">
                    <input type="hidden" name="number_id" value="<?php echo $number_id; ?>">
                    
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
                        <button type="submit" class="submit-btn">Submit Feedback</button>
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
            <div class="feedback-list" id="feedback-list">
                <?php foreach ($feedbacks as $feedback): 
                    $is_own_feedback = ($user_id && $feedback['user_id'] == $user_id);
                ?>
                    <div class="feedback-item <?php echo $is_own_feedback ? 'own-feedback' : ''; ?>" data-feedback-id="<?php echo $feedback['feedback_id']; ?>">
                        <div class="feedback-header">
                            <div class="feedback-user">
                                <?php echo htmlspecialchars($feedback['username']); ?>
                                <?php if ($is_own_feedback): ?>
                                    <span class="own-badge">Your Feedback</span>
                                <?php endif; ?>
                            </div>
                            <div class="feedback-date">
                                <?php echo formatDateLong($feedback['updated_at']); ?>
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
                                <button class="delete-btn small-btn delete-feedback-btn" data-feedback-id="<?php echo $feedback['feedback_id']; ?>">Delete</button>
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

    <!-- ============ MESSAGING SECTION ============ -->
    <div class="messaging-section">
        <div class="section-header">
            <h2>Chat with <?php echo htmlspecialchars($contact['head']); ?></h2>
        </div>

        <div id="message-messages"></div>

        <?php if ($user_id): ?>
            <div class="chat-status-info">
                <strong>Chat Status:</strong> 
                You are <?php echo $is_head ? 'the HEAD' : 'a USER'; ?> of this contact.
                <?php if ($selected_conversation_id): ?>
                    Viewing conversation #<?php echo $selected_conversation_id; ?>
                <?php endif; ?>
            </div>

            <!-- View Toggle -->
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
                <!-- ARCHIVED VIEW -->
                <?php if ($selected_conversation_id && $selected_conversation_info): ?>
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
                                    (Archived: <?php echo formatDateForDisplay($selected_conversation_info['archived_at']); ?>)
                                </small>
                            </h3>
                            <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>&view=archived" class="chat-back-btn">← Back to Archive</a>
                        </div>
                        
                        <div class="archive-info-bar" style="background: #e8f4fd; padding: 10px 15px; border-bottom: 1px solid #b6d4fe;">
                            <div style="display: flex; justify-content: space-between; font-size: 13px;">
                                <div>
                                    <strong>Started:</strong> <?php echo formatDateForDisplay($selected_conversation_info['created_at']); ?>
                                    | <strong>Last Activity:</strong> <?php echo formatDateForDisplay($selected_conversation_info['last_activity']); ?>
                                    | <strong>Archived:</strong> <?php echo formatDateForDisplay($selected_conversation_info['archived_at']); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="chat-messages" id="chat-messages" style="background-color: #f8f9fa;">
                            <?php foreach ($conversation_messages as $msg): 
                                $is_sent = ($msg['sender_id'] == $user_id);
                                $user_info = getUserHierarchyInfo($conn, $msg['sender_id']);
                            ?>
                                <div class="message-bubble <?php echo $is_sent ? 'message-sent' : 'message-received'; ?>">
                                    <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                    <div class="message-info">
                                        <span class="message-sender">
                                            <?php echo htmlspecialchars($msg['full_name']); ?>
                                            <div class="user-profile-tooltip">
                                                <div class="user-profile-header">
                                                    <div class="user-profile-avatar"><?php echo strtoupper(substr($msg['full_name'], 0, 1)); ?></div>
                                                    <div>
                                                        <div class="user-profile-name"><?php echo htmlspecialchars($msg['full_name']); ?></div>
                                                        <div class="user-profile-email"><?php echo htmlspecialchars($user_info['email'] ?? 'No email'); ?></div>
                                                    </div>
                                                </div>
                                                <div class="user-profile-details">
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Role:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($user_info['role_name'] ?? 'Unknown'); ?></span>
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
                                                <?php if ($user_info['is_head'] ?? false): ?>
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
                                                    <div class="no-head-info">No head information available</div>
                                                <?php endif; ?>
                                            </div>
                                        </span>
                                        <span class="message-time"><?php echo formatDateForDisplay($msg['created_at']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="chat-input-area" style="background-color: #f1f5f9; border-top: 1px solid #cbd5e0;">
                            <div style="text-align: center; padding: 15px; color: #64748b; font-style: italic;">
                                <i class="fas fa-lock"></i> This conversation is archived and cannot be modified.
                            </div>
                        </div>
                    </div>
                <?php elseif (!empty($conversations)): ?>
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
                                    <td class="archived-conversation-id">#<?php echo $conv['conversation_id']; ?></td>
                                    <td class="archived-conversation-with">
                                        <?php echo $is_head ? htmlspecialchars($conv['initiator_name']) : htmlspecialchars($conv['head_name'] ?? $contact['head']); ?>
                                    </td>
                                    <td><span class="archived-message-count"><?php echo $conv['message_count']; ?> messages</span></td>
                                    <td class="archived-date"><?php echo formatDateForDisplay($conv['conversation_start']); ?></td>
                                    <td class="archived-date"><?php echo formatDateForDisplay($conv['last_message_time']); ?></td>
                                    <td class="archived-date"><?php echo formatDateForDisplay($conv['archived_at']); ?></td>
                                    <td>
                                        <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>&view=archived&conversation_id=<?php echo $conv['conversation_id']; ?>" class="view-btn">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-archived-message">No archived conversations found.</div>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- ACTIVE CONVERSATIONS VIEW -->
                <?php if ($selected_conversation_id && $selected_conversation_info): ?>
                    <div class="chat-container">
                        <div class="chat-header">
                            <h3 id="chat-header-title">
                                <?php if ($user_id == $selected_conversation_info['initiated_by']): ?>
                                    Chat with <?php echo htmlspecialchars($selected_conversation_info['head_name'] ?? $contact['head']); ?>
                                <?php else: ?>
                                    Chat with <?php echo htmlspecialchars($selected_conversation_info['initiator_name'] ?? 'User'); ?>
                                <?php endif; ?>
                            </h3>
                            <a href="<?php echo $current_script; ?>?id=<?php echo $number_id; ?>" class="chat-back-btn">← Back to Conversations</a>
                        </div>
                        
                        <div class="chat-messages" id="chat-messages">
                            <?php 
                            $max_message_id = 0;
                            foreach ($conversation_messages as $msg): 
                                $is_sent = ($msg['sender_id'] == $user_id);
                                $user_info = getUserHierarchyInfo($conn, $msg['sender_id']);
                                $max_message_id = max($max_message_id, $msg['message_id']);
                            ?>
                                <div class="message-bubble <?php echo $is_sent ? 'message-sent' : 'message-received'; ?>" data-message-id="<?php echo $msg['message_id']; ?>">
                                    <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></div>
                                    <div class="message-info">
                                        <span class="message-sender">
                                            <?php echo htmlspecialchars($msg['full_name']); ?>
                                            <div class="user-profile-tooltip">
                                                <div class="user-profile-header">
                                                    <div class="user-profile-avatar"><?php echo strtoupper(substr($msg['full_name'], 0, 1)); ?></div>
                                                    <div>
                                                        <div class="user-profile-name"><?php echo htmlspecialchars($msg['full_name']); ?></div>
                                                        <div class="user-profile-email"><?php echo htmlspecialchars($user_info['email'] ?? 'No email'); ?></div>
                                                    </div>
                                                </div>
                                                <div class="user-profile-details">
                                                    <div class="profile-detail-row">
                                                        <span class="profile-detail-label">Role:</span>
                                                        <span class="profile-detail-value"><?php echo htmlspecialchars($user_info['role_name'] ?? 'Unknown'); ?></span>
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
                                                <?php if ($user_info['is_head'] ?? false): ?>
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
                                                    <div class="no-head-info">No head information available</div>
                                                <?php endif; ?>
                                            </div>
                                        </span>
                                        <span class="message-time"><?php echo formatDateForDisplay($msg['created_at']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="chat-input-area">
                            <form class="chat-input-form" id="chat-form">
                                <input type="hidden" name="conversation_id" id="conversation_id" value="<?php echo $selected_conversation_id; ?>">
                                <input type="hidden" name="number_id" id="number_id" value="<?php echo $number_id; ?>">
                                <input type="hidden" id="last_message_id" value="<?php echo $max_message_id; ?>">
                                
                                <?php if ($user_id == $selected_conversation_info['initiated_by']): ?>
                                    <textarea class="chat-input" id="message-input" placeholder="Type your message here..." required></textarea>
                                    <button type="submit" class="chat-send-btn" id="send-message-btn">📤</button>
                                <?php else: ?>
                                    <textarea class="chat-input" id="reply-input" placeholder="Type your reply here..." required></textarea>
                                    <button type="submit" class="chat-send-btn" id="send-reply-btn">↩️</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                    
                    <script id="conversation-config" type="application/json">
                    {
                        "conversationId": <?php echo json_encode($selected_conversation_id); ?>,
                        "userId": <?php echo json_encode($user_id); ?>,
                        "lastMessageId": <?php echo json_encode($max_message_id); ?>
                    }
                    </script>
                    
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
                                        <?php echo formatDateShort($conv['last_message_time'] ?: $conv['conversation_start']); ?>
                                    </div>
                                </div>
                                <?php 
                                $preview_sql = "SELECT TOP 1 message FROM messages WHERE conversation_id = ? AND is_archived = 0 ORDER BY created_at DESC";
                                $preview_params = array($conv['conversation_id']);
                                $preview_stmt = sqlsrv_query($conn, $preview_sql, $preview_params);
                                $last_message = $preview_stmt ? sqlsrv_fetch_array($preview_stmt, SQLSRV_FETCH_ASSOC) : false;
                                if ($preview_stmt) sqlsrv_free_stmt($preview_stmt);
                                ?>
                                <div class="conversation-preview">
                                    <?php echo htmlspecialchars($last_message['message'] ?? 'No messages yet'); ?>
                                </div>
                                <div class="conversation-meta">
                                    <span><?php echo $conv['message_count']; ?> messages</span>
                                    <span>Started <?php echo formatDateLong($conv['conversation_start']); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (!$selected_conversation_id): ?>
                        <div class="message-form">
                            <h3>Start New Conversation</h3>
                            <form id="new-conversation-form">
                                <input type="hidden" name="number_id" value="<?php echo $number_id; ?>">
                                <div class="form-group">
                                    <label for="new-message">Message to <?php echo htmlspecialchars($contact['head']); ?></label>
                                    <textarea id="new-message" name="message" placeholder="Type your message here..." required></textarea>
                                </div>
                                <div class="form-actions">
                                    <button type="submit" class="submit-btn" id="start-conversation-btn">Start Conversation</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="message-form">
                        <h3>Start New Conversation</h3>
                        <form id="new-conversation-form">
                            <input type="hidden" name="number_id" value="<?php echo $number_id; ?>">
                            <div class="form-group">
                                <label for="new-message">Message to <?php echo htmlspecialchars($contact['head']); ?></label>
                                <textarea id="new-message" name="message" placeholder="Type your message here..." required></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="submit-btn" id="start-conversation-btn">Start Conversation</button>
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
    'use strict';
    console.log('NumPage Full AJAX Loaded');

    // ===========================================
    // GET CORRECT BASE PATH DYNAMICALLY
    // ===========================================
    const basePath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/') + 1);
    console.log('Base path:', basePath); // Should show "/Intercom Directory/"

    // ----- LOAD PAGE CONFIG -----
    let config;
    try {
        config = JSON.parse(document.getElementById('page-config').textContent);
    } catch (e) {
        console.error('Failed to load page config:', e);
        return;
    }

    const numberId = config.numberId;
    const userId = config.userId;
    const isHead = config.isHead;
    const isAdmin = config.isAdmin;
    const viewArchived = config.viewArchived;
    const selectedConversationId = config.selectedConversationId;

    // ===========================================
    // 1. TOAST NOTIFICATION SYSTEM
    // ===========================================
    function showToast(message, type = 'info') {
        const existingToast = document.querySelector('.toast-notification');
        if (existingToast) existingToast.remove();
        
        const toast = document.createElement('div');
        toast.className = `toast-notification toast-${type}`;
        toast.innerHTML = `
            <div class="toast-content">${message}</div>
            <button class="toast-close">&times;</button>
        `;
        
        document.body.appendChild(toast);
        
        toast.querySelector('.toast-close').addEventListener('click', () => toast.remove());
        
        setTimeout(() => {
            if (toast.parentNode) {
                toast.style.transition = 'all 0.3s ease';
                toast.style.transform = 'translateX(100%)';
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }
        }, 5000);
    }

    // ===========================================
    // 2. FEEDBACK SYSTEM (AJAX)
    // ===========================================
    
    // Submit new feedback
    const feedbackForm = document.getElementById('feedback-submit-form');
    if (feedbackForm) {
        feedbackForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'submit');
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Submitting...';
            submitBtn.disabled = true;
            
            fetch(basePath + 'feedback_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    document.getElementById('avg-rating').textContent = data.avg_rating + '/5';
                    document.getElementById('total-feedbacks').textContent = `Based on ${data.total_feedbacks} reviews`;
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error, 'error');
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to submit feedback', 'error');
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });
    }
    
    // Update feedback
    const editFeedbackForm = document.getElementById('feedback-edit-form');
    if (editFeedbackForm) {
        editFeedbackForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'update');
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Updating...';
            submitBtn.disabled = true;
            
            fetch(basePath + 'feedback_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    document.getElementById('avg-rating').textContent = data.avg_rating + '/5';
                    document.getElementById('total-feedbacks').textContent = `Based on ${data.total_feedbacks} reviews`;
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error, 'error');
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to update feedback', 'error');
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });
        
        // Delete feedback button
        document.getElementById('delete-feedback-btn')?.addEventListener('click', function() {
            if (!confirm('Are you sure you want to delete your feedback? This action cannot be undone.')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('feedback_id', document.querySelector('input[name="feedback_id"]').value);
            formData.append('number_id', document.querySelector('input[name="number_id"]').value);
            
            const btn = this;
            const originalText = btn.textContent;
            btn.textContent = 'Deleting...';
            btn.disabled = true;
            
            fetch(basePath + 'feedback_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    document.getElementById('avg-rating').textContent = data.avg_rating + '/5';
                    document.getElementById('total-feedbacks').textContent = `Based on ${data.total_feedbacks} reviews`;
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error, 'error');
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to delete feedback', 'error');
                btn.textContent = originalText;
                btn.disabled = false;
            });
        });
    }
    
    // Delete feedback from list
    document.querySelectorAll('.delete-feedback-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const feedbackId = this.dataset.feedbackId;
            if (!confirm('Are you sure you want to delete your feedback?')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('feedback_id', feedbackId);
            formData.append('number_id', numberId);
            
            const originalText = this.textContent;
            this.textContent = 'Deleting...';
            this.disabled = true;
            
            fetch(basePath + 'feedback_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    document.getElementById('avg-rating').textContent = data.avg_rating + '/5';
                    document.getElementById('total-feedbacks').textContent = `Based on ${data.total_feedbacks} reviews`;
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast(data.error, 'error');
                    this.textContent = originalText;
                    this.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to delete feedback', 'error');
                this.textContent = originalText;
                this.disabled = false;
            });
        });
    });

    // ===========================================
    // 3. MESSAGING SYSTEM (AJAX)
    // ===========================================
    
    // Start new conversation
    const newConversationForm = document.getElementById('new-conversation-form');
    if (newConversationForm) {
        newConversationForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const messageInput = this.querySelector('textarea');
            if (!messageInput.value.trim()) {
                showToast('Please enter a message', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('number_id', numberId);
            formData.append('message', messageInput.value.trim());
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Sending...';
            submitBtn.disabled = true;
            
            fetch(basePath + 'send_message.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => {
                        window.location.href = `numpage.php?id=${numberId}&conversation_id=${data.conversation_id}`;
                    }, 1000);
                } else {
                    showToast(data.error, 'error');
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to send message', 'error');
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        });
    }
    
    // Send message in active conversation
    const chatForm = document.getElementById('chat-form');
    if (chatForm) {
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const isUserSending = !!document.getElementById('send-message-btn');
            const messageInput = isUserSending ? 
                document.getElementById('message-input') : 
                document.getElementById('reply-input');
            
            if (!messageInput || !messageInput.value.trim()) {
                showToast('Please enter a message', 'error');
                return;
            }
            
            const conversationId = document.getElementById('conversation_id').value;
            
            const formData = new FormData();
            formData.append('conversation_id', conversationId);
            formData.append('number_id', numberId);
            
            let url, btn;
            if (isUserSending) {
                formData.append('message', messageInput.value.trim());
                url = basePath + 'send_message.php';
                btn = document.getElementById('send-message-btn');
            } else {
                formData.append('reply_message', messageInput.value.trim());
                url = basePath + 'send_reply.php';
                btn = document.getElementById('send-reply-btn');
            }
            
            console.log('Sending to:', url);
            
            const originalText = btn.textContent;
            btn.textContent = '📤';
            btn.disabled = true;
            
            const originalMessage = messageInput.value;
            messageInput.value = '';
            messageInput.style.height = 'auto';
            
            fetch(url, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Response:', data);
                if (data.success && data.message_data) {
                    // Append the message
                    appendMessage(data.message_data);
                    
                    // Update last_message_id in both places
                    document.getElementById('last_message_id').value = data.message_data.message_id;
                    
                    // Update the conversation config to prevent polling from fetching it again
                    const configEl = document.getElementById('conversation-config');
                    if (configEl) {
                        let convConfig = JSON.parse(configEl.textContent);
                        convConfig.lastMessageId = data.message_data.message_id;
                        configEl.textContent = JSON.stringify(convConfig);
                        lastMessageId = data.message_data.message_id;
                    }
                    
                    showToast('Message sent!', 'success');
                } else {
                    showToast(data.error || 'Failed to send message', 'error');
                    messageInput.value = originalMessage;
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                showToast('Could not connect to server', 'error');
                messageInput.value = originalMessage;
            })
            .finally(() => {
                btn.textContent = originalText;
                btn.disabled = false;
                messageInput.focus();
            });
        });
    }
    

    function appendMessage(msg) {
        const chatMessages = document.getElementById('chat-messages');
        if (!chatMessages) return;
        
        // CRITICAL: Check if message already exists
        if (document.querySelector(`.message-bubble[data-message-id="${msg.message_id}"]`)) {
            console.log('Message already exists, skipping:', msg.message_id);
            return;
        }
        
        const isSent = msg.is_sent;
        const messageDiv = document.createElement('div');
        messageDiv.className = `message-bubble ${isSent ? 'message-sent' : 'message-received'}`;
        messageDiv.setAttribute('data-message-id', msg.message_id);
        messageDiv.innerHTML = `
            <div class="message-text">${escapeHtml(msg.message).replace(/\n/g, '<br>')}</div>
            <div class="message-info">
                <span class="message-sender">${escapeHtml(msg.sender_name)}</span>
                <span class="message-time">${formatTime(msg.created_at)}</span>
            </div>
        `;
        
        chatMessages.appendChild(messageDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Format time
    function formatTime(datetime) {
        const d = new Date(datetime);
        return d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }
    
    // ===========================================
    // 4. MESSAGE POLLING (every 3 seconds)
    // ===========================================
    let pollInterval = null;
    let lastMessageId = 0;
    
    function startPolling() {
        const configEl = document.getElementById('conversation-config');
        if (!configEl) {
            console.log('No conversation config found');
            return;
        }
        
        let convConfig;
        try {
            convConfig = JSON.parse(configEl.textContent);
        } catch (e) {
            console.error('Failed to parse conversation config');
            return;
        }
        
        const conversationId = convConfig.conversationId;
        lastMessageId = convConfig.lastMessageId || 0;
        
        if (!conversationId || viewArchived) {
            console.log('Polling not started - no conversation or archived view');
            return;
        }
        
        console.log('Starting polling for conversation:', conversationId, 'last ID:', lastMessageId);
        
        function fetchMessages() {
            const url = basePath + 'get_messages.php?conversation_id=' + conversationId + '&last_message_id=' + lastMessageId;
            console.log('Polling URL:', url);
            
            fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.messages && data.messages.length > 0) {
                        console.log('Received', data.messages.length, 'new messages');
                        data.messages.forEach(msg => {
                            appendMessage(msg);
                            lastMessageId = Math.max(lastMessageId, msg.message_id);
                        });
                        // Update the config with new lastMessageId
                        convConfig.lastMessageId = lastMessageId;
                        configEl.textContent = JSON.stringify(convConfig);
                    }
                })
                .catch(error => {
                    console.error('Polling error:', error.message);
                });
        }
        
        fetchMessages();
        pollInterval = setInterval(fetchMessages, 3000);
        console.log('✅ Message polling started');
    }
    
    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
            console.log('⏸️ Polling stopped');
        }
    }
    
    if (selectedConversationId && !viewArchived) {
        startPolling();
    }
    
    window.addEventListener('beforeunload', stopPolling);
    
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
        } else {
            if (selectedConversationId && !viewArchived && !pollInterval) {
                startPolling();
            }
        }
    });
    
    // ===========================================
    // 5. ENTER KEY HANDLER
    // ===========================================
    document.querySelectorAll('.chat-input, #new-message').forEach(textarea => {
        textarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                const form = this.closest('form');
                if (form) {
                    // Visual feedback
                    this.style.borderColor = '#38a169';
                    setTimeout(() => {
                        this.style.borderColor = '';
                    }, 200);
                    
                    // Trigger form submit
                    form.requestSubmit();
                }
            }
        });
        
        // Auto-resize
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
    });
    
    // ===========================================
    // 6. NOTIFICATION SYSTEM (admin only)
    // ===========================================
    const notificationBtn = document.getElementById('adminNotificationBtn');
    const notificationDropdown = document.getElementById('notificationDropdown');
    
    if (notificationBtn && notificationDropdown) {
        notificationBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const visible = notificationDropdown.style.opacity === '1';
            notificationDropdown.style.opacity = visible ? '0' : '1';
            notificationDropdown.style.visibility = visible ? 'hidden' : 'visible';
            notificationDropdown.style.transform = visible ? 'translateY(-10px)' : 'translateY(0)';
        });
        
        document.addEventListener('click', function(e) {
            if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                notificationDropdown.style.opacity = '0';
                notificationDropdown.style.visibility = 'hidden';
                notificationDropdown.style.transform = 'translateY(-10px)';
            }
        });
    }
    
    // Auto-scroll chat
    const chatMessages = document.getElementById('chat-messages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    // Focus textarea
    setTimeout(() => {
        const textarea = document.querySelector('.chat-input, #new-message');
        if (textarea) textarea.focus();
    }, 100);
});
</script>

<!-- Admin notification checker (separate script) -->
<?php if ($is_admin && $user_id): ?>
<script>
(function() {
    function checkAdminNotifications() {
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
    
    setInterval(checkAdminNotifications, 10000);
    // Only request notification permission on user interaction
    document.addEventListener('click', function initNotification() {
        if (Notification.permission === 'default') {
            Notification.requestPermission();
        }
        document.removeEventListener('click', initNotification);
    }, { once: true });
})();
</script>
<?php endif; ?>

</body>
</html>