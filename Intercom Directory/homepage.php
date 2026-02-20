<?php
require_once 'conn.php';
updateAllUsersActivity($conn);

// DEBUG: Check if connection works
if (!$conn) {
    die("NO CONNECTION!");
}

// Get current user ID and role
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$is_admin = isAdmin();
$user_role_id = isset($_SESSION['role_id']) ? $_SESSION['role_id'] : null;

// Get all contact numbers where this user is the head_user_id
$user_number_ids = [];
$user_numbers_count = 0;

if ($user_id) {
    // Get all numbers where this user is the head_user_id
    $sql = "SELECT number_id FROM numbers WHERE head_user_id = ?";
    $stmt = sqlsrv_query($conn, $sql, array($user_id));
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $user_number_ids[$row['number_id']] = true;
            $user_numbers_count++;
        }
        sqlsrv_free_stmt($stmt);
    }
}

function getAllContactNumbers($conn)
{
    // Get ALL numbers first to see what we have
    $sql = "SELECT COUNT(*) as total FROM numbers";
    $stmt = sqlsrv_query($conn, $sql);
    $total = 0;
    if ($stmt) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        $total = $row['total'] ?? 0;
        sqlsrv_free_stmt($stmt);
    }

    // Now get all numbers with their organizational info and unread counts
    $sql = "
        SELECT 
            n.number_id,
            n.numbers as contact_number,
            n.email as contact_email,
            n.description,
            n.head_user_id,
            n.division_id,
            n.department_id,
            n.unit_id,
            n.office_id,
            
            -- Head user info
            u_head.email as head_user_email,
            u_head.full_name as head_user_name,
            
            -- Division info
            d.division_name,
            d.status as division_status,
            
            -- Department info
            dept.department_name,
            dept.status as department_status,
            d2.division_name as dept_division_name,
            
            -- Unit info
            u.unit_name,
            u.status as unit_status,
            dept2.department_name as unit_department_name,
            d3.division_name as unit_division_name,
            
            -- Office info
            o.office_name,
            o.status as office_status,
            u2.unit_name as office_unit_name,
            dept3.department_name as office_department_name,
            d4.division_name as office_division_name,
            
            -- Feedback ratings
            ISNULL(f.avg_rating, 0) as avg_rating,
            ISNULL(f.total_feedbacks, 0) as total_feedbacks
            
        FROM numbers n
        
        -- Head user
        LEFT JOIN users u_head ON n.head_user_id = u_head.user_id
        
        -- Division (if exists)
        LEFT JOIN divisions d ON n.division_id = d.division_id
        
        -- Department (if exists) with its division
        LEFT JOIN departments dept ON n.department_id = dept.department_id
        LEFT JOIN divisions d2 ON dept.division_id = d2.division_id
        
        -- Unit (if exists) with its department and division
        LEFT JOIN units u ON n.unit_id = u.unit_id
        LEFT JOIN departments dept2 ON u.department_id = dept2.department_id
        LEFT JOIN divisions d3 ON dept2.division_id = d3.division_id
        
        -- Office (if exists) with its unit, department and division
        LEFT JOIN offices o ON n.office_id = o.office_id
        LEFT JOIN units u2 ON o.unit_id = u2.unit_id
        LEFT JOIN departments dept3 ON u2.department_id = dept3.department_id
        LEFT JOIN divisions d4 ON dept3.division_id = d4.division_id
        
        -- Feedback
        LEFT JOIN (
            SELECT 
                number_id, 
                AVG(CAST(rating as float)) as avg_rating, 
                COUNT(*) as total_feedbacks 
            FROM feedback 
            GROUP BY number_id
        ) f ON n.number_id = f.number_id
        
        ORDER BY 
            CASE 
                WHEN n.division_id IS NOT NULL THEN 1
                WHEN n.department_id IS NOT NULL THEN 2
                WHEN n.unit_id IS NOT NULL THEN 3
                WHEN n.office_id IS NOT NULL THEN 4
                ELSE 5
            END,
            COALESCE(d.division_name, dept.department_name, u.unit_name, o.office_name),
            n.description
    ";

    $stmt = sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        return [];
    }

    $numbers = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        // Determine unit type and name
        if (!is_null($row['division_id']) && $row['division_id'] != '') {
            $row['unit_type'] = 'Division';
            $row['unit_name'] = $row['division_name'] ?? 'Unknown Division';
            $row['status'] = $row['division_status'] ?? 'unknown';
            $row['parent_division'] = null;
        } elseif (!is_null($row['department_id']) && $row['department_id'] != '') {
            $row['unit_type'] = 'Department';
            $row['unit_name'] = $row['department_name'] ?? 'Unknown Department';
            $row['status'] = $row['department_status'] ?? 'unknown';
            $row['parent_division'] = $row['dept_division_name'] ?? null;
        } elseif (!is_null($row['unit_id']) && $row['unit_id'] != '') {
            $row['unit_type'] = 'Unit';
            $row['unit_name'] = $row['unit_name'] ?? 'Unknown Unit';
            $row['status'] = $row['unit_status'] ?? 'unknown';
            $row['parent_division'] = $row['unit_division_name'] ?? null;
        } elseif (!is_null($row['office_id']) && $row['office_id'] != '') {
            $row['unit_type'] = 'Office';
            $row['unit_name'] = $row['office_name'] ?? 'Unknown Office';
            $row['status'] = $row['office_status'] ?? 'unknown';
            $row['parent_division'] = $row['office_division_name'] ?? null;
        } else {
            $row['unit_type'] = 'Unknown';
            $row['unit_name'] = 'Unknown Unit';
            $row['status'] = 'unknown';
            $row['parent_division'] = null;
        }

        $numbers[] = $row;
    }

    sqlsrv_free_stmt($stmt);
    return $numbers;
}

$onlineAdminCount = getOnlineAdmins($conn);
$allNumbers = getAllContactNumbers($conn);

// Get unread counts for each number if user is logged in
$number_unread_counts = [];
if ($user_id) {
    foreach ($allNumbers as $number) {
        // Check if user is the head of this contact
        if ($number['head_user_id'] == $user_id) {
            // Get unread messages where this user is the receiver (as head)
            $sql = "
                SELECT COUNT(*) as unread_count 
                FROM messages m
                INNER JOIN conversations c ON m.conversation_id = c.conversation_id
                WHERE c.number_id = ? 
                AND m.receiver_id = ? 
                AND m.is_read = 0
                AND m.is_archived = 0
                AND c.is_archived = 0
            ";
            $stmt = sqlsrv_query($conn, $sql, array($number['number_id'], $user_id));
            if ($stmt) {
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                $number_unread_counts[$number['number_id']] = $row['unread_count'] ?? 0;
                sqlsrv_free_stmt($stmt);
            } else {
                $number_unread_counts[$number['number_id']] = 0;
            }
        } else {
            // For regular users: Check if they have any conversations with this contact
            // where they have unread messages from the head
            $sql = "
                SELECT COUNT(*) as unread_count 
                FROM messages m
                INNER JOIN conversations c ON m.conversation_id = c.conversation_id
                WHERE c.number_id = ? 
                AND c.initiated_by = ?
                AND m.sender_id = ? 
                AND m.receiver_id = ?
                AND m.is_read = 0
                AND m.is_archived = 0
                AND c.is_archived = 0
            ";
            $stmt = sqlsrv_query($conn, $sql, array($number['number_id'], $user_id, $number['head_user_id'], $user_id));
            if ($stmt) {
                $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
                $number_unread_counts[$number['number_id']] = $row['unread_count'] ?? 0;
                sqlsrv_free_stmt($stmt);
            } else {
                $number_unread_counts[$number['number_id']] = 0;
            }
        }
    }
}

$head_contacts = [];
$total_head_unread = 0;
$user_unread = 0;
$user_chats = [];

// Separate user's numbers and other numbers
$user_numbers = []; // Numbers where user is head_user_id
$regularNumbers = []; // All other numbers

if ($user_id) {
    foreach ($allNumbers as $number) {
        if (isset($user_number_ids[$number['number_id']])) {
            $user_numbers[] = $number;
        } else {
            $regularNumbers[] = $number;
        }
    }

    // Get head contacts for notifications
    $head_contacts = getHeadContacts($conn, $user_id);
    $total_head_unread = getHeadUnreadCount($conn, $user_id);

    if (!$is_admin) {
        $user_unread = getUnreadAdminMessageCount($conn, $user_id, false);
        $user_chats = getUserChatsWithAllAdmins($conn, $user_id);
    } else {
        $user_unread = 0;
        $user_chats = [];
    }
} else {
    // If not logged in, all numbers go to regularNumbers
    $regularNumbers = $allNumbers;
}

$admin_notifications_count = 0;
$admin_chat_requests = [];

if ($is_admin && $user_id) {
    $admin_notifications_count = getAdminUnreadChatsCount($conn, $user_id);
    $admin_chat_requests = getAdminChatRequests($conn, $user_id);
}

// If user has numbers they're connected to, show them in a pinned section
$has_user_numbers = !empty($user_numbers);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Contact Directory</title>
    <style>
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

        .contact-directory {
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        .contact-directory h2 {
            color: #2b6cb0;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }

        /* Filter and Sort Container */
        .filter-sort-container {
            background-color: white;
            position: center;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }

        /* Filter Buttons */
        .filter-buttons {
            display: flex;
            flex-wrap: wrap;
            position: center;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 20px;
        }

        .filter-btn {
            padding: 10px 20px;
            border: 1px solid #e2e8f0;
            background-color: white;
            border-radius: 30px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .filter-btn.active {
            background-color: #2b6cb0;
            color: white;
            border-color: #2b6cb0;
        }

        .filter-btn:hover {
            background-color: #edf2f7;
        }

        .filter-btn.active:hover {
            background-color: #1f4f8b;
        }

        .count-badge {
            background-color: #e2e8f0;
            color: #2d3748;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .filter-btn.active .count-badge {
            background-color: white;
            color: #2b6cb0;
        }

        /* Sort Section */
        .sort-section {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .sort-label {
            font-size: 14px;
            color: #4a5568;
            font-weight: 600;
        }

        .sort-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .sort-btn {
            padding: 8px 20px;
            border: 1px solid #e2e8f0;
            background-color: #f8f9fa;
            border-radius: 30px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
            font-weight: 500;
        }

        .sort-btn.active {
            background-color: #38a169;
            color: white;
            border-color: #38a169;
        }

        .sort-btn:hover {
            background-color: #e2e8f0;
        }

        .sort-btn.active:hover {
            background-color: #2f855a;
        }

        /* Search Filter */
        .search-filter {
            background-color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .search-filter input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ced4da;
            border-radius: 30px;
            font-size: 14px;
        }

        .search-filter input:focus {
            outline: none;
            border-color: #2b6cb0;
            box-shadow: 0 0 0 3px rgba(43, 108, 176, 0.1);
        }

        .contact-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .contact-table th {
            background-color: #2b6cb0;
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }

        .contact-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
        }

        .contact-table tr:hover {
            background-color: #f7fafc;
        }

        .contact-table tr:last-child td {
            border-bottom: none;
        }

        .unit-type {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .type-division {
            background-color: #bee3f8;
            color: #2c5282;
        }

        .type-department {
            background-color: #c6f6d5;
            color: #276749;
        }

        .type-unit {
            background-color: #fed7d7;
            color: #9b2c2c;
        }

        .type-office {
            background-color: #fefcbf;
            color: #744210;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background-color: #c6f6d5;
            color: #276749;
            border: 1px solid #9ae6b4;
        }

        .status-decommissioned {
            background-color: #fed7d7;
            color: #9b2c2c;
            border: 1px solid #feb2b2;
        }

        .status-inactive {
            background-color: #e2e8f0;
            color: #4a5568;
            border: 1px solid #cbd5e0;
        }

        .rating-container {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .star-rating-small {
            display: flex;
            gap: 2px;
        }

        .star {
            color: #e2e8f0;
            font-size: 14px;
        }

        .star.filled {
            color: #ffc107;
        }

        .rating-value {
            font-size: 12px;
            color: #718096;
            min-width: 40px;
        }

        .contact-number {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #2d3748;
        }

        .contact-head {
            color: #4a5568;
            font-style: italic;
        }

        .contact-description {
            color: #718096;
            font-size: 14px;
        }

        .parent-info {
            font-size: 12px;
            color: #a0aec0;
        }

        .no-contacts {
            text-align: center;
            padding: 40px;
            color: #718096;
            font-style: italic;
        }

        .footer {
            background-color: #07417f;
            color: #fff;
            text-align: center;
            padding: 18px 10px;
            font-size: 14px;
            margin-top: auto;
        }

        .clickable-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .clickable-row:hover {
            background-color: #f0f8ff;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header-section h2 {
            margin: 0;
            flex: 1;
        }

        .admin-online-container {
            position: relative;
            display: inline-block;
        }

        .admin-circle-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2b6cb0, #1f4f8b);
            color: white;
            border: 3px solid white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            box-shadow: 0 3px 10px rgba(43, 108, 176, 0.3);
            transition: all 0.3s;
            position: relative;
        }

        .admin-circle-btn:hover {
            background: linear-gradient(135deg, #1f4f8b, #153a6e);
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(43, 108, 176, 0.4);
        }

        .admin-circle-btn::after {
            content: '';
            position: absolute;
            top: 5px;
            right: 5px;
            width: 10px;
            height: 10px;
            background-color: #38a169;
            border-radius: 50%;
            border: 2px solid white;
        }

        .admin-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 300px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            margin-top: 15px;
            padding: 15px;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s;
        }

        .admin-online-container:hover .admin-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .admin-dropdown h4 {
            color: #2b6cb0;
            margin: 0 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 16px;
        }

        .admin-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .admin-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f7fafc;
        }

        .admin-item:last-child {
            border-bottom: none;
        }

        .admin-name {
            color: #2d3748;
            font-weight: 500;
        }

        .admin-status {
            color: #718096;
            font-size: 12px;
            background: #f7fafc;
            padding: 3px 8px;
            border-radius: 4px;
        }

        .no-admins {
            text-align: center;
            color: #a0aec0;
            font-style: italic;
            padding: 20px;
        }

        .admin-footer {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e2e8f0;
        }

        .current-user {
            color: #2b6cb0;
            font-weight: 600;
            font-size: 13px;
            text-align: center;
        }

        .admin-chat-container {
            position: relative;
            display: inline-block;
            margin-left: 10px;
        }

        .admin-chat-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #38a169, #2f855a);
            color: white;
            border: 3px solid white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 20px;
            box-shadow: 0 3px 10px rgba(56, 161, 105, 0.3);
            transition: all 0.3s;
            position: relative;
        }

        .admin-chat-btn:hover {
            background: linear-gradient(135deg, #2f855a, #276749);
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(56, 161, 105, 0.4);
        }

        .admin-chat-btn::after {
            content: '';
            position: absolute;
            top: 5px;
            right: 5px;
            width: 10px;
            height: 10px;
            background-color: #2b6cb0;
            border-radius: 50%;
            border: 2px solid white;
        }

        .admin-chat-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
            margin-top: 15px;
            padding: 15px;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s;
        }

        .admin-chat-container:hover .admin-chat-dropdown {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .admin-chat-dropdown h4 {
            color: #2b6cb0;
            margin: 0 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 16px;
        }

        .admin-chat-list {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 15px;
        }

        .admin-chat-item {
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

        .admin-chat-item:hover {
            background-color: #f7fafc;
            border-color: #e2e8f0;
        }

        .admin-chat-avatar {
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

        .admin-chat-info {
            flex: 1;
        }

        .admin-chat-name {
            font-weight: 500;
            display: block;
            margin-bottom: 3px;
            font-size: 14px;
        }

        .admin-chat-preview {
            font-size: 12px;
            color: #718096;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .admin-chat-time {
            font-size: 11px;
            color: #a0aec0;
        }

        .new-chat-btn {
            display: block;
            padding: 10px;
            background-color: #2b6cb0;
            color: white;
            border-radius: 6px;
            text-decoration: none;
            text-align: center;
            font-weight: 500;
            margin-top: 10px;
            transition: background-color 0.2s;
        }

        .new-chat-btn:hover {
            background-color: #1f4f8b;
        }

        .chat-notification {
            background-color: #fff5f5;
            color: #742a2a;
            padding: 8px;
            border-radius: 4px;
            font-size: 13px;
            text-align: center;
            border: 1px solid #fed7d7;
            margin-top: 10px;
        }

        .chat-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #e53e3e;
            color: white;
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 10px;
            min-width: 20px;
            text-align: center;
            font-weight: bold;
            border: 2px solid white;
        }

        .admin-select-list {
            max-height: 200px;
            overflow-y: auto;
            margin-bottom: 15px;
        }

        .admin-select-item {
            display: flex;
            align-items: center;
            padding: 8px;
            border-radius: 6px;
            margin-bottom: 5px;
            text-decoration: none;
            color: #2d3748;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .admin-select-item:hover {
            background-color: #f7fafc;
            border-color: #e2e8f0;
        }

        .admin-select-name {
            font-weight: 500;
            font-size: 14px;
        }

        .contact-email {
            color: #666;
            font-size: 12px;
            display: block;
            margin-top: 3px;
            word-break: break-all;
        }

        .contact-email:before {
            content: "📧 ";
            margin-right: 3px;
        }

        /* User numbers section */
        .user-numbers-section {
            margin-bottom: 30px;
            background: linear-gradient(135deg, #e6f3ff 0%, #d4e7ff 100%);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            border: 1px solid #93c5fd;
        }

        .user-numbers-section h3 {
            color: #1e40af;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid #93c5fd;
        }

        .user-badge {
            display: inline-block;
            padding: 4px 8px;
            background-color: #1e40af;
            color: white;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            margin-left: 8px;
        }

        .user-contact-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 15px;
        }

        .user-contact-table th {
            background-color: #1e40af;
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
        }

        .user-contact-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            background-color: #f8fafc;
        }

        .user-contact-table tr {
            background-color: #eff6ff;
        }

        .user-contact-table tr:hover {
            background-color: #dbeafe;
        }

        .user-contact-table tr:last-child td {
            border-bottom: none;
        }

        .user-contact-row {
            cursor: pointer;
            transition: all 0.2s;
        }

        .user-contact-row:hover {
            background-color: #bfdbfe !important;
        }

        .status-user-active {
            background-color: #bfdbfe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        /* Admin Notification Styles */
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

        /* NEW STYLES FOR NOTIFICATION SYSTEM */
        .unread-indicator {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            background-color: #e53e3e;
            color: white;
            border-radius: 50%;
            font-size: 12px;
            font-weight: bold;
            margin-left: 8px;
            animation: pulse 2s infinite;
            box-shadow: 0 0 0 2px white;
        }

        .number-cell {
            position: relative;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }

        .unread-badge-small {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            background-color: #e53e3e;
            color: white;
            border-radius: 10px;
            font-size: 10px;
            font-weight: bold;
            padding: 0 4px;
            margin-left: 5px;
            animation: pulse 1.5s infinite;
        }

        .unread-filter-btn {
            background-color: #e53e3e;
            color: white;
            border-color: #c53030;
        }

        .unread-filter-btn.active {
            background-color: #c53030;
        }

        .unread-filter-btn .count-badge {
            background-color: white;
            color: #e53e3e;
        }

        .unread-filter-btn.active .count-badge {
            background-color: white;
            color: #c53030;
        }

        .notification-toast-container {
            position: fixed;
            top: 120px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 350px;
        }

        .notification-toast {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            border-left: 4px solid #e53e3e;
            animation: slideIn 0.3s ease-out;
            cursor: pointer;
            transition: transform 0.2s;
            border: 1px solid #e2e8f0;
        }

        .notification-toast:hover {
            transform: translateX(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.25);
        }

        .notification-toast-title {
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .notification-toast-message {
            color: #4a5568;
            font-size: 13px;
        }

        .notification-toast-time {
            font-size: 11px;
            color: #a0aec0;
            margin-top: 5px;
            text-align: right;
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

        ul.nav li a.active {
            background-color: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                padding: 15px;
                text-align: center;
            }

            .header .logo span {
                font-size: 1.3rem;
            }

            .contact-table {
                display: block;
                overflow-x: auto;
            }

            .filter-buttons {
                flex-direction: column;
            }

            .sort-buttons {
                flex-direction: column;
            }

            .filter-btn {
                width: 100%;
                justify-content: space-between;
            }

            .sort-section {
                flex-direction: column;
                align-items: flex-start;
            }

            .sort-buttons {
                width: 100%;
            }

            .sort-btn {
                flex: 1;
                text-align: center;
            }

            .header-section {
                flex-direction: column;
                align-items: flex-start;
            }

            .admin-online-container,
            .admin-chat-container,
            .admin-notification-container {
                align-self: flex-end;
            }

            .admin-dropdown,
            .admin-chat-dropdown,
            .notification-dropdown {
                width: 280px;
                right: -50px;
            }

            .admin-circle-btn,
            .admin-chat-btn,
            .admin-notification-btn {
                width: 45px;
                height: 45px;
                font-size: 16px;
            }

            .notification-toast-container {
                width: 90%;
                right: 5%;
            }
        }

        @media (max-width: 480px) {

            .admin-dropdown,
            .admin-chat-dropdown,
            .notification-dropdown {
                width: 250px;
                right: -30px;
            }
        }
    </style>
</head>

<body>

    <div class="header">
        <div class="logo">
            <a href="homepage.php" alt="Hospital Logo">
                <img src="hospitalLogo.png">
            </a>
            <span>DAVAO REGIONAL MEDICAL CENTER</span>
        </div>
        <ul class="nav">
            <li><a href="homepage.php" class="active">Homepage</a></li>
            <?php if (isLoggedIn()): ?>
                <?php if (isAdmin()): ?>
                    <li><a href="createpage.php">Create page</a></li>
                    <li><a href="editpage.php">Edit page</a></li>
                    <li>
                        <a href="adminpanel.php" class="notification-indicator" id="operatorPanelLink">
                            Operator Panel
                            <span class="nav-notification-badge" id="operatorPanelBadge" style="display: none;">0</span>
                        </a>
                    </li>
                <?php else: ?>
                    <li><a href="adminchat.php" id="adminChatNavLink">Chat with an Operator <span id="adminChatUnreadBadge"
                                class="nav-notification-badge"
                                style="<?php echo $user_unread > 0 ? 'display:inline-block' : 'display:none'; ?>"><?php echo $user_unread; ?></span></a>
                    </li>
                <?php endif; ?>
                <li><a href="profilepage.php">Profile</a></li>
                <li><a href="logout.php">Logout (<?php echo getUserName(); ?>)</a></li>
            <?php else: ?>
                <li><a href="login.php">Login</a></li>
            <?php endif; ?>
        </ul>

        <?php if ($is_admin && $user_id): ?>
            <div class="admin-notification-container">
                <button class="admin-notification-btn" id="adminNotificationBtn">
                    🔔
                    <span class="notification-bell-badge" id="notificationBellBadge" style="display: none;">0</span>
                </button>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <h4>📨 New Chat Requests (<span id="adminNotificationCount">0</span>)</h4>
                    </div>
                    <div class="notification-list" id="adminNotificationList">
                        <?php if (!empty($admin_chat_requests)): ?>
                            <?php foreach ($admin_chat_requests as $request):
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
                                            <span
                                                class="notification-time"><?php echo time_ago($request['first_message_time']); ?></span>
                                            <?php if ($request['message_count'] > 1): ?>
                                                <span class="message-count-badge"><?php echo $request['message_count']; ?>
                                                    messages</span>
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

    <!-- Notification Toast Container -->
    <div id="notificationToastContainer" class="notification-toast-container"></div>

    <div class="content">
        <div class="contact-directory">
            <h2>Hospital Contact Directory</h2>
            <div class="header-section">
                <h2 style="margin: 0; flex: 1;"></h2>

                <!-- Show user's connected numbers badge if they have any -->
                <?php if ($has_user_numbers): ?>
                    <div style="position: relative; display: inline-block; margin-right: 10px;">
                        <div
                            style="position: absolute; top: -8px; right: -8px; background-color: #1e40af; color: white; font-size: 12px; padding: 3px 8px; border-radius: 12px; min-width: 20px; text-align: center; font-weight: bold; border: 2px solid white; z-index: 1001; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                            <?php echo count($user_numbers); ?>
                        </div>
                        <div
                            style="padding: 8px 12px; background: linear-gradient(135deg, #1e40af, #1e3a8a); color: white; border-radius: 6px; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 8px;">
                            <span>👤</span> My Numbers
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($user_id && !$is_admin): ?>
                    <?php if (!empty($head_contacts) && $total_head_unread > 0): ?>
                        <div style="position: relative; display: inline-block; margin-right: 10px;">
                            <div
                                style="position: absolute; top: -8px; right: -8px; background-color: #e53e3e; color: white; font-size: 12px; padding: 3px 8px; border-radius: 12px; min-width: 20px; text-align: center; font-weight: bold; border: 2px solid white; z-index: 1001; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                <?php echo $total_head_unread; ?>
                            </div>
                            <div
                                style="padding: 8px 12px; background-color: #38a169; color: white; border-radius: 6px; font-size: 14px; font-weight: 500;">
                                <span class="pinned-icon">📌</span> My Numbers
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="admin-online-container">
                    <button class="admin-circle-btn" id="adminOnlineBtn">
                        <?php echo $onlineAdminCount; ?>
                    </button>
                    <div class="admin-dropdown" id="adminDropdown">
                        <h4>Currently Online (<?php echo $onlineAdminCount; ?>)</h4>
                        <div class="admin-list">
                            <?php
                            $timeout = 300;
                            $onlineTime = time() - $timeout;

                            $sql = "SELECT username, role_id, last_activity FROM users 
                                WHERE role_id = 1 
                                AND last_activity > ? 
                                ORDER BY username";

                            $params = array($onlineTime);
                            $stmt = sqlsrv_query($conn, $sql, $params);

                            if ($stmt === false) {
                                echo '<div class="no-admins">Error loading admins</div>';
                            } else {
                                $hasAdmins = false;
                                while ($admin = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                                    $hasAdmins = true;
                                    $lastActivity = $admin['last_activity'];
                                    $timeAgo = time() - $lastActivity;
                                    $minutesAgo = floor($timeAgo / 60);

                                    echo '<div class="admin-item">';
                                    echo '<span class="admin-name">' . htmlspecialchars($admin['username']) . '</span>';
                                    echo '<span class="admin-status">';
                                    if ($minutesAgo < 1) {
                                        echo 'Just now';
                                    } elseif ($minutesAgo == 1) {
                                        echo '1 min ago';
                                    } else {
                                        echo $minutesAgo . ' mins ago';
                                    }
                                    echo '</span>';
                                    echo '</div>';
                                }

                                sqlsrv_free_stmt($stmt);

                                if (!$hasAdmins) {
                                    echo '<div class="no-admins">No admins currently online</div>';
                                }
                            }
                            ?>
                        </div>
                        <div class="admin-footer">
                            <?php
                            if (isAdmin()) {
                                echo '<div class="current-user">You: ' . htmlspecialchars($_SESSION['username'] ?? '') . '</div>';
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <?php if (isLoggedIn() && !isAdmin()): ?>
                    <div class="admin-chat-container">
                        <button class="admin-chat-btn" id="adminChatBtn">
                            💬
                            <span class="chat-badge" id="adminChatUnreadBadge"
                                style="<?php echo $user_unread > 0 ? 'display:flex' : 'display:none'; ?>"><?php echo $user_unread; ?></span>
                        </button>
                        <div class="admin-chat-dropdown" id="adminChatDropdown">
                            <h4>Chat with Admin</h4>
                            <div class="admin-chat-list" id="adminChatList">
                                <?php
                                if ($user_id) {
                                    if (!empty($user_chats)):
                                        foreach ($user_chats as $chat):
                                            ?>
                                            <a href="adminchat.php?chat_id=<?php echo $chat['chat_id']; ?>" class="admin-chat-item">
                                                <div class="admin-chat-avatar">
                                                    <?php echo strtoupper(substr($chat['full_name'], 0, 1)); ?>
                                                </div>
                                                <div class="admin-chat-info">
                                                    <span class="admin-chat-name">Admin
                                                        <?php echo htmlspecialchars($chat['full_name']); ?></span>
                                                    <span
                                                        class="admin-chat-preview"><?php echo htmlspecialchars(substr($chat['last_message'] ?? 'No messages yet', 0, 30)); ?></span>
                                                </div>
                                                <div class="admin-chat-time">
                                                    <?php if (isset($chat['last_message_time'])): ?>
                                                        <?php
                                                        if ($chat['last_message_time'] instanceof DateTime) {
                                                            echo $chat['last_message_time']->format('H:i');
                                                        } else {
                                                            echo date('H:i', strtotime($chat['last_message_time']));
                                                        }
                                                        ?>
                                                    <?php endif; ?>
                                                </div>
                                            </a>
                                            <?php
                                        endforeach;
                                        ?>
                                        <a href="adminchat.php?new=1" class="new-chat-btn">Start New Chat with Different Admin</a>
                                        <?php
                                    else:
                                        $admins = getAvailableAdmins($conn);
                                        if (!empty($admins)):
                                            ?>
                                            <div class="admin-select-list">
                                                <?php foreach ($admins as $admin): ?>
                                                    <a href="adminchat.php?start_chat=<?php echo $admin['user_id']; ?>"
                                                        class="admin-select-item">
                                                        <div class="admin-chat-avatar">
                                                            <?php echo strtoupper(substr($admin['full_name'], 0, 1)); ?>
                                                        </div>
                                                        <span
                                                            class="admin-select-name"><?php echo htmlspecialchars($admin['full_name']); ?></span>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php
                                        else:
                                            ?>
                                            <div style="text-align: center; color: #a0aec0; padding: 20px;">
                                                No admins available
                                            </div>
                                            <?php
                                        endif;
                                    endif;
                                }
                                ?>
                            </div>
                            <?php if ($user_unread > 0): ?>
                                <div class="chat-notification">
                                    You have <?php echo $user_unread; ?> unread message(s)
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php
            // Calculate counts for each category
            $totalContacts = count($allNumbers);
            $divisionsCount = 0;
            $departmentsCount = 0;
            $unitsCount = 0;
            $officesCount = 0;
            $activeCount = 0;
            $decommissionedCount = 0;
            $unreadCount = 0;

            if ($user_id) {
                foreach ($allNumbers as $number) {
                    if ($number['head_user_id'] == $user_id) {
                        if (isset($number_unread_counts[$number['number_id']]) && $number_unread_counts[$number['number_id']] > 0) {
                            $unreadCount++;
                        }
                    }
                }
            }

            foreach ($allNumbers as $number) {
                switch ($number['unit_type']) {
                    case 'Division':
                        $divisionsCount++;
                        break;
                    case 'Department':
                        $departmentsCount++;
                        break;
                    case 'Unit':
                        $unitsCount++;
                        break;
                    case 'Office':
                        $officesCount++;
                        break;
                }
                if (isset($number['status'])) {
                    if ($number['status'] === 'active')
                        $activeCount++;
                    elseif ($number['status'] === 'decommissioned')
                        $decommissionedCount++;
                }
                if (isset($number_unread_counts[$number['number_id']]) && $number_unread_counts[$number['number_id']] > 0) {
                    $unreadCount++;
                }
            }

            // Include user numbers in the stats
            $userNumbersCount = count($user_numbers);
            ?>

            <!-- Filter and Sort Section -->
            <div class="filter-sort-container">
                <!-- Filter Buttons with Counts -->
                <div class="filter-buttons">
                    <button class="filter-btn active" onclick="filterTable('all')">
                        All Contacts <span class="count-badge"><?php echo $totalContacts; ?></span>
                    </button>
                    <button class="filter-btn" onclick="filterTable('division')">
                        Divisions <span class="count-badge"><?php echo $divisionsCount; ?></span>
                    </button>
                    <button class="filter-btn" onclick="filterTable('department')">
                        Departments <span class="count-badge"><?php echo $departmentsCount; ?></span>
                    </button>
                    <button class="filter-btn" onclick="filterTable('unit')">
                        Units <span class="count-badge"><?php echo $unitsCount; ?></span>
                    </button>
                    <button class="filter-btn" onclick="filterTable('office')">
                        Offices <span class="count-badge"><?php echo $officesCount; ?></span>
                    </button>
                    <button class="filter-btn" onclick="filterTable('active')">
                        Active <span class="count-badge"><?php echo $activeCount; ?></span>
                    </button>
                    <button class="filter-btn" onclick="filterTable('decommissioned')">
                        Decommissioned <span class="count-badge"><?php echo $decommissionedCount; ?></span>
                    </button>
                    <?php if (isLoggedIn()): ?>
                        <button class="filter-btn <?php echo $unreadCount > 0 ? 'unread-filter-btn' : ''; ?>"
                            onclick="filterTable('unread')" id="unreadFilterBtn">
                            <?php echo $unreadCount > 0 ? '🔴' : '○'; ?> Unread <span class="count-badge"
                                id="unreadCountBadge"><?php echo $unreadCount; ?></span>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Sort Options -->
                <div class="sort-section">
                    <span class="sort-label">Sort by:</span>
                    <div class="sort-buttons">
                        <button class="sort-btn active" onclick="sortTable('default')">Default</button>
                        <button class="sort-btn" onclick="sortTable('rating')">Ranking</button>
                    </div>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="search-filter">
                <input type="text" id="searchInput"
                    placeholder="Search by number, head, description, unit, or status..." onkeyup="filterSearch()">
            </div>

            <!-- Display user's connected numbers in a pinned section -->
            <?php if ($has_user_numbers): ?>
                <div class="user-numbers-section">
                    <h3>
                        <span>👤</span> My Numbers
                        <span class="notification-badge"><?php echo count($user_numbers); ?></span>
                    </h3>
                    <table class="user-contact-table">
                        <thead>
                            <tr>
                                <th>Contact Number & Email</th>
                                <th>Description</th>
                                <th>Type</th>
                                <th>Unit/Department Name</th>
                                <th>Rating</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="user-numbers-tbody">
                            <?php foreach ($user_numbers as $contact):
                                $typeClass = '';
                                switch ($contact['unit_type']) {
                                    case 'Division':
                                        $typeClass = 'type-division';
                                        break;
                                    case 'Department':
                                        $typeClass = 'type-department';
                                        break;
                                    case 'Unit':
                                        $typeClass = 'type-unit';
                                        break;
                                    case 'Office':
                                        $typeClass = 'type-office';
                                        break;
                                }
                                $statusClass = 'status-active';
                                $statusText = 'Active';

                                $avgRating = $contact['avg_rating'] ?? 0;
                                $starHTML = '';
                                $fullStars = floor($avgRating);
                                $hasHalfStar = ($avgRating - $fullStars) >= 0.5;

                                for ($i = 1; $i <= 5; $i++) {
                                    if ($i <= $fullStars) {
                                        $starHTML .= '<span class="star filled">★</span>';
                                    } elseif ($i == $fullStars + 1 && $hasHalfStar) {
                                        $starHTML .= '<span class="star filled">★</span>';
                                    } else {
                                        $starHTML .= '<span class="star">★</span>';
                                    }
                                }

                                $email = !empty($contact['contact_email']) ? $contact['contact_email'] :
                                    (!empty($contact['head_user_email']) ? $contact['head_user_email'] : 'N/A');
                                $unread = $number_unread_counts[$contact['number_id']] ?? 0;
                                ?>
                                <tr class="user-contact-row clickable-row" data-id="<?php echo $contact['number_id']; ?>"
                                    data-type="<?php echo strtolower($contact['unit_type']); ?>" data-status="active"
                                    data-rating="<?php echo $avgRating; ?>"
                                    data-number-id="<?php echo $contact['number_id']; ?>">
                                    <td class="contact-number">
                                        <div class="number-cell">
                                            <?php echo htmlspecialchars($contact['contact_number']); ?>
                                            <?php if (isset($number_unread_counts[$contact['number_id']]) && $number_unread_counts[$contact['number_id']] > 0): ?>
                                                <span class="unread-badge-small"
                                                    id="unread-badge-<?php echo $contact['number_id']; ?>">
                                                    <?php echo $number_unread_counts[$contact['number_id']]; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="contact-email">
                                            <?php echo htmlspecialchars($email); ?>
                                        </span>
                                        <span class="user-badge">YOU</span>
                                    </td>
                                    <td class="contact-description"><?php echo htmlspecialchars($contact['description']); ?>
                                    </td>
                                    <td>
                                        <span
                                            class="unit-type <?php echo $typeClass; ?>"><?php echo $contact['unit_type']; ?></span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($contact['unit_name']); ?>
                                        <?php if ($contact['parent_division'] && $contact['unit_type'] != 'Division'): ?>
                                            <div class="parent-info">Under:
                                                <?php echo htmlspecialchars($contact['parent_division']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="rating-container">
                                            <div class="star-rating-small">
                                                <?php echo $starHTML; ?>
                                            </div>
                                            <span class="rating-value"><?php echo number_format($avgRating, 1); ?>
                                                (<?php echo $contact['total_feedbacks']; ?>)</span>
                                        </div>
                                    </td>
                                    <td><span class="status-badge status-user-active">Active</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="font-size: 12px; color: #4b5563; text-align: right; padding-top: 10px;">
                        These are the contact numbers assigned to your account
                    </div>
                </div>
            <?php endif; ?>

            <!-- Regular Contacts Section -->
            <div class="regular-contact-section">
                <h3><?php echo $has_user_numbers ? 'Other Contacts' : 'All Contacts'; ?></h3>

                <?php if ($totalContacts > 0): ?>
                    <table class="contact-table" id="contacts-table">
                        <thead>
                            <tr>
                                <th>Contact Number & Email</th>
                                <th>Description</th>
                                <th>Type</th>
                                <th>Unit/Department Name</th>
                                <th>Rating</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="contacts-tbody">
                            <?php foreach ($regularNumbers as $contact):
                                $typeClass = '';
                                switch ($contact['unit_type']) {
                                    case 'Division':
                                        $typeClass = 'type-division';
                                        break;
                                    case 'Department':
                                        $typeClass = 'type-department';
                                        break;
                                    case 'Unit':
                                        $typeClass = 'type-unit';
                                        break;
                                    case 'Office':
                                        $typeClass = 'type-office';
                                        break;
                                }
                                $statusClass = 'status-inactive';
                                $statusText = 'Unknown';
                                if (isset($contact['status'])) {
                                    if ($contact['status'] === 'active') {
                                        $statusClass = 'status-active';
                                        $statusText = 'Active';
                                    } elseif ($contact['status'] === 'decommissioned') {
                                        $statusClass = 'status-decommissioned';
                                        $statusText = 'Decommissioned';
                                    }
                                }

                                $avgRating = $contact['avg_rating'] ?? 0;
                                $starHTML = '';
                                $fullStars = floor($avgRating);
                                $hasHalfStar = ($avgRating - $fullStars) >= 0.5;

                                for ($i = 1; $i <= 5; $i++) {
                                    if ($i <= $fullStars) {
                                        $starHTML .= '<span class="star filled">★</span>';
                                    } elseif ($i == $fullStars + 1 && $hasHalfStar) {
                                        $starHTML .= '<span class="star filled">★</span>';
                                    } else {
                                        $starHTML .= '<span class="star">★</span>';
                                    }
                                }

                                $email = !empty($contact['contact_email']) ? $contact['contact_email'] :
                                    (!empty($contact['head_user_email']) ? $contact['head_user_email'] : 'N/A');
                                $unread = $number_unread_counts[$contact['number_id']] ?? 0;
                                ?>
                                <tr class="contact-row <?php echo isLoggedIn() ? 'clickable-row' : 'non-clickable'; ?>" <?php if (isLoggedIn()): ?> data-id="<?php echo $contact['number_id']; ?>" <?php endif; ?>
                                    data-type="<?php echo strtolower($contact['unit_type']); ?>"
                                    data-status="<?php echo isset($contact['status']) ? $contact['status'] : 'unknown'; ?>"
                                    data-rating="<?php echo $avgRating; ?>"
                                    data-number-id="<?php echo $contact['number_id']; ?>">
                                    <td class="contact-number">
                                        <div class="number-cell">
                                            <?php echo htmlspecialchars($contact['contact_number']); ?>
                                            <?php if (isset($number_unread_counts[$contact['number_id']]) && $number_unread_counts[$contact['number_id']] > 0): ?>
                                                <span class="unread-badge-small"
                                                    id="unread-badge-<?php echo $contact['number_id']; ?>">
                                                    <?php echo $number_unread_counts[$contact['number_id']]; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="contact-email">
                                            <?php echo htmlspecialchars($email); ?>
                                        </span>
                                    </td>
                                    <td class="contact-description"><?php echo htmlspecialchars($contact['description']); ?>
                                    </td>
                                    <td><span
                                            class="unit-type <?php echo $typeClass; ?>"><?php echo $contact['unit_type']; ?></span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($contact['unit_name']); ?>
                                        <?php if ($contact['parent_division'] && $contact['unit_type'] != 'Division'): ?>
                                            <div class="parent-info">Under:
                                                <?php echo htmlspecialchars($contact['parent_division']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="rating-container">
                                            <div class="star-rating-small">
                                                <?php echo $starHTML; ?>
                                            </div>
                                            <span class="rating-value"><?php echo number_format($avgRating, 1); ?>
                                                (<?php echo $contact['total_feedbacks']; ?>)</span>
                                        </div>
                                    </td>
                                    <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-contacts">
                        No contact numbers have been added yet.
                        <?php if (isLoggedIn()): ?><a href="createpage.php">Add your first contact</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="footer">
        © 2026 Intercom Directory. All rights reserved.<br>
        Developed by TNTS Programming Students JT.DP.RR
    </div>

    <script>
        // Global variables
        let currentSort = 'default';
        let originalRows = [];
        let currentFilter = 'all';
        let lastNotificationCheck = Date.now() / 1000;
        let shownToastIds = new Set();
        let isAdmin = <?php echo $is_admin ? 'true' : 'false'; ?>;
        let userId = <?php echo $user_id ?: 'null'; ?>;

        // Add CSS for highlighting rows with unread messages immediately
        const style = document.createElement('style');
        style.textContent = `
            .contact-row.has-unread,
            .user-contact-row.has-unread {
                background-color: rgba(229, 62, 62, 0.05) !important;
                border-left: 3px solid #e53e3e !important;
            }
            
            .contact-row.has-unread:hover,
            .user-contact-row.has-unread:hover {
                background-color: rgba(229, 62, 62, 0.1) !important;
            }
            
            .unread-badge-small {
                animation: pulse 1.5s infinite;
            }
            
            .unread-filter-btn {
                background-color: #e53e3e !important;
                color: white !important;
            }
        `;
        document.head.appendChild(style);

        document.addEventListener('DOMContentLoaded', function () {
            const tbody = document.getElementById('contacts-tbody');
            if (tbody) {
                originalRows = Array.from(tbody.querySelectorAll('.contact-row'));
            }

            // Click handlers for rows
            const clickableRows = document.querySelectorAll('.clickable-row');
            clickableRows.forEach(row => {
                row.addEventListener('click', function () {
                    const contactId = this.getAttribute('data-id');
                    if (contactId) {
                        window.location.href = `numpage.php?id=${contactId}`;
                    }
                });
            });

            // User table click handler
            const userTable = document.querySelector('.user-contact-table');
            if (userTable) {
                userTable.addEventListener('click', function (e) {
                    const row = e.target.closest('.clickable-row');
                    if (row) {
                        const contactId = row.getAttribute('data-id');
                        if (contactId) {
                            window.location.href = `numpage.php?id=${contactId}`;
                        }
                    }
                });
            }

            // Dropdown functionality
            setupDropdowns();

            // Start notification checking for logged-in users
            if (userId) {
                startNotificationChecking();
                
                // Start unread count polling for regular users
                if (!isAdmin) {
                    setTimeout(updateUnreadCounts, 1000);
                    setInterval(updateUnreadCounts, 5000);
                }
            }
        });

        function setupDropdowns() {
            // Admin online dropdown
            const adminBtn = document.getElementById('adminOnlineBtn');
            const adminDropdown = document.getElementById('adminDropdown');

            if (adminBtn && adminDropdown) {
                adminBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    toggleDropdown(adminDropdown);
                });
            }

            // Admin chat dropdown
            const chatBtn = document.getElementById('adminChatBtn');
            const chatDropdown = document.getElementById('adminChatDropdown');

            if (chatBtn && chatDropdown) {
                chatBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    toggleDropdown(chatDropdown);
                });
            }

            // Admin notification dropdown
            const notificationBtn = document.getElementById('adminNotificationBtn');
            const notificationDropdown = document.getElementById('notificationDropdown');

            if (notificationBtn && notificationDropdown) {
                notificationBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    checkNotifications();
                    toggleDropdown(notificationDropdown);
                });
            }

            document.addEventListener('click', function (e) {
                closeAllDropdowns();
            });
        }

        function toggleDropdown(dropdown) {
            if (dropdown.style.opacity === '1') {
                dropdown.style.opacity = '0';
                dropdown.style.visibility = 'hidden';
                dropdown.style.transform = 'translateY(-10px)';
            } else {
                closeAllDropdowns();
                dropdown.style.opacity = '1';
                dropdown.style.visibility = 'visible';
                dropdown.style.transform = 'translateY(0)';
            }
        }

        function closeAllDropdowns() {
            const dropdowns = ['adminDropdown', 'adminChatDropdown', 'notificationDropdown'];
            dropdowns.forEach(id => {
                const dropdown = document.getElementById(id);
                if (dropdown) {
                    dropdown.style.opacity = '0';
                    dropdown.style.visibility = 'hidden';
                    dropdown.style.transform = 'translateY(-10px)';
                }
            });
        }

        function filterSearch() {
            const input = document.getElementById('searchInput').value.toLowerCase();
            const rows = document.querySelectorAll('.contact-row, .user-contact-row');

            rows.forEach(row => {
                let match = false;
                const cells = row.querySelectorAll('td');

                cells.forEach(td => {
                    if (td.innerText.toLowerCase().includes(input)) match = true;
                });

                if (match && passesCurrentFilter(row)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function passesCurrentFilter(row) {
            if (row.classList.contains('user-contact-row')) {
                return true;
            }

            const rowType = row.getAttribute('data-type');
            const rowStatus = row.getAttribute('data-status');
            const rowNumberId = row.getAttribute('data-number-id');
            const unreadBadge = document.getElementById(`unread-badge-${rowNumberId}`);
            const hasUnread = unreadBadge && unreadBadge.style.display !== 'none' && parseInt(unreadBadge.textContent) > 0;

            if (currentFilter === 'all') return true;
            if (currentFilter === 'unread') return hasUnread;
            if (currentFilter === 'active' || currentFilter === 'decommissioned') {
                return rowStatus === currentFilter;
            }
            return rowType === currentFilter;
        }

        function filterTable(type) {
            currentFilter = type;
            const buttons = document.querySelectorAll('.filter-btn');

            buttons.forEach(btn => btn.classList.remove('active'));

            buttons.forEach(btn => {
                const onclickAttr = btn.getAttribute('onclick');
                if (onclickAttr && onclickAttr.includes("'" + type + "'")) {
                    btn.classList.add('active');
                }
            });

            const rows = document.querySelectorAll('.contact-row, .user-contact-row');
            rows.forEach(row => {
                if (passesCurrentFilter(row)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            applySort(currentSort);
        }

        function sortTable(sortType) {
            const sortButtons = document.querySelectorAll('.sort-btn');
            sortButtons.forEach(btn => btn.classList.remove('active'));

            sortButtons.forEach(btn => {
                const onclickAttr = btn.getAttribute('onclick');
                if (onclickAttr && onclickAttr.includes("'" + sortType + "'")) {
                    btn.classList.add('active');
                }
            });

            currentSort = sortType;
            applySort(sortType);
        }

        function applySort(sortType) {
            const tbody = document.getElementById('contacts-tbody');
            if (!tbody) return;

            const visibleRows = Array.from(tbody.querySelectorAll('.contact-row:not([style*="display: none"])'));

            if (sortType === 'default') {
                const allRows = Array.from(tbody.querySelectorAll('.contact-row'));

                const positionMap = new Map();
                originalRows.forEach((row, index) => {
                    const id = row.getAttribute('data-id');
                    if (id) positionMap.set(id, index);
                });

                visibleRows.sort((a, b) => {
                    const idA = a.getAttribute('data-id');
                    const idB = b.getAttribute('data-id');
                    const posA = positionMap.get(idA) || 0;
                    const posB = positionMap.get(idB) || 0;
                    return posA - posB;
                });

                visibleRows.forEach(row => tbody.appendChild(row));
                return;
            }

            visibleRows.sort((a, b) => {
                switch (sortType) {
                    case 'rating':
                        const ratingA = parseFloat(a.getAttribute('data-rating'));
                        const ratingB = parseFloat(b.getAttribute('data-rating'));
                        return ratingB - ratingA;
                    default:
                        return 0;
                }
            });

            visibleRows.forEach(row => tbody.appendChild(row));
        }

        function setupNotificationPermission() {
            if (!window.Notification) return;
            
            const requestPermission = function() {
                if (Notification.permission === 'default') {
                    Notification.requestPermission();
                }
                document.removeEventListener('click', requestPermission);
            };
            
            document.addEventListener('click', requestPermission, { once: true });
        }
        
        setupNotificationPermission();

        // ===========================================
        // DYNAMIC NOTIFICATION SYSTEM
        // ===========================================

        let notificationCheckInterval;
        let lastSeenNotifications = new Set();

        function startNotificationChecking() {
            if (notificationCheckInterval) {
                clearInterval(notificationCheckInterval);
            }
            checkNotifications();
            notificationCheckInterval = setInterval(checkNotifications, 3000);
        }

        function checkNotifications() {
            if (!userId) return;

            fetch('check_notifications.php?t=' + Date.now())
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateAllNotifications(data);
                        checkForNewToasts(data);
                    }
                })
                .catch(error => console.error('Notification check error:', error));
        }

        function updateAllNotifications(data) {
            const allNotifications = data.notifications || [];

            const adminRequests = allNotifications.filter(n => n.type === 'admin_chat_request');
            const adminMessages = allNotifications.filter(n => n.type === 'admin_chat_message');
            const headMessages = allNotifications.filter(n => n.type === 'head_message');
            const adminReplies = allNotifications.filter(n => n.type === 'admin_reply');

            if (isAdmin) {
                const operatorPanelCount = adminRequests.length;
                updateOperatorPanelBadge(operatorPanelCount);

                const totalAdminNotifications = adminRequests.length + adminMessages.length;
                updateBellBadge(totalAdminNotifications);

                updateAdminNotificationDropdown([...adminRequests, ...adminMessages]);
            } else {
                const adminReplyCount = adminReplies.reduce((sum, n) => sum + (n.unread_count || 0), 0);
                updateAdminChatBadge(adminReplyCount);
                updateUnreadFilterButton(headMessages);
            }
        }

        function updateOperatorPanelBadge(count) {
            const badge = document.getElementById('operatorPanelBadge');
            if (!badge) return;

            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }

        function updateBellBadge(totalUnread) {
            const bellBadge = document.getElementById('notificationBellBadge');
            if (bellBadge) {
                if (totalUnread > 0) {
                    bellBadge.textContent = totalUnread;
                    bellBadge.style.display = 'inline-block';
                } else {
                    bellBadge.style.display = 'none';
                }
            }

            const adminCountSpan = document.getElementById('adminNotificationCount');
            if (adminCountSpan) {
                adminCountSpan.textContent = totalUnread;
            }
        }

        function updateAdminNotificationDropdown(notifications) {
            const notificationList = document.getElementById('adminNotificationList');
            if (!notificationList) return;

            if (notifications.length === 0) {
                notificationList.innerHTML = '<div class="no-notifications">No new messages</div>';
                return;
            }

            let html = '';
            const uniqueNotifs = new Map();

            notifications.forEach(notif => {
                const key = notif.type === 'admin_chat_request'
                    ? `req_${notif.user_id}`
                    : `msg_${notif.chat_id}`;

                if (!uniqueNotifs.has(key)) {
                    uniqueNotifs.set(key, notif);
                }
            });

            uniqueNotifs.forEach(notif => {
                let chatLink = '';
                let displayName = '';
                let timeStr = '';
                let badgeText = '';

                if (notif.type === 'admin_chat_request') {
                    chatLink = notif.chat_id
                        ? `adminpanel.php?chat_id=${notif.chat_id}`
                        : `adminpanel.php?start_chat=${notif.user_id}`;
                    displayName = notif.full_name || 'User';
                    timeStr = timeAgoFromString(notif.first_message_time);
                    badgeText = notif.message_count > 1 ? `${notif.message_count} messages` : 'New message';
                } else {
                    chatLink = `adminpanel.php?chat_id=${notif.chat_id}`;
                    displayName = notif.full_name || 'User';
                    timeStr = timeAgoFromString(notif.last_message_time);
                    badgeText = notif.unread_count > 1 ? `${notif.unread_count} messages` : 'New message';
                }

                html += `
                    <a href="${chatLink}" class="notification-item">
                        <div class="notification-avatar">${escapeHtml(displayName.charAt(0).toUpperCase())}</div>
                        <div class="notification-info">
                            <div class="notification-name">${escapeHtml(displayName)}</div>
                            <div class="notification-meta">
                                <span class="notification-time">${timeStr}</span>
                                <span class="message-count-badge">${badgeText}</span>
                            </div>
                        </div>
                    </a>
                `;
            });

            notificationList.innerHTML = html;
        }

        function updateAdminChatBadge(count) {
            const adminChatLink = document.getElementById('adminChatNavLink');
            if (adminChatLink) {
                let badge = adminChatLink.querySelector('.nav-notification-badge');

                if (count > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'nav-notification-badge';
                        adminChatLink.appendChild(badge);
                    }
                    badge.textContent = count;
                    badge.style.display = 'inline-block';
                } else if (badge) {
                    badge.style.display = 'none';
                }
            }

            const chatBadge = document.getElementById('adminChatUnreadBadge');
            if (chatBadge) {
                if (count > 0) {
                    chatBadge.textContent = count;
                    chatBadge.style.display = 'flex';
                } else {
                    chatBadge.style.display = 'none';
                }
            }
        }

        function updateUnreadFilterButton(headMessages) {
            const unreadFilterBtn = document.getElementById('unreadFilterBtn');
            const unreadCountBadge = document.getElementById('unreadCountBadge');

            if (!unreadFilterBtn || !unreadCountBadge) return;

            const headUnreadCount = headMessages.length;

            unreadCountBadge.textContent = headUnreadCount;

            if (headUnreadCount > 0) {
                unreadFilterBtn.innerHTML = `🔴 Unread <span class="count-badge" id="unreadCountBadge">${headUnreadCount}</span>`;
                unreadFilterBtn.classList.add('unread-filter-btn');
            } else {
                unreadFilterBtn.innerHTML = `○ Unread <span class="count-badge" id="unreadCountBadge">0</span>`;
                unreadFilterBtn.classList.remove('unread-filter-btn');
            }
        }

        function checkForNewToasts(data) {
            const allNotifications = data.notifications || [];

            let relevantNotifications;
            if (isAdmin) {
                relevantNotifications = allNotifications.filter(n =>
                    n.type === 'admin_chat_request' || n.type === 'admin_chat_message'
                );
            } else {
                relevantNotifications = allNotifications.filter(n =>
                    n.type === 'head_message' || n.type === 'admin_reply'
                );
            }

            relevantNotifications.forEach(notif => {
                let notifId;
                if (notif.type === 'admin_chat_request') {
                    notifId = `toast_req_${notif.user_id}_${notif.first_message_time}`;
                } else if (notif.type === 'admin_chat_message') {
                    notifId = `toast_msg_${notif.chat_id}_${notif.last_message_time}`;
                } else if (notif.type === 'head_message') {
                    notifId = `toast_head_${notif.number_id}_${notif.last_message_time}`;
                } else {
                    notifId = `toast_reply_${notif.chat_id}_${notif.last_message_time}`;
                }

                if (!shownToastIds.has(notifId)) {
                    shownToastIds.add(notifId);
                    showNotificationToast(notif);

                    if (shownToastIds.size > 100) {
                        const iterator = shownToastIds.values();
                        shownToastIds.delete(iterator.next().value);
                    }
                }
            });
        }

        function showNotificationToast(notif) {
            const container = document.getElementById('notificationToastContainer');
            if (!container) return;

            const toasts = container.children;
            if (toasts.length >= 3) {
                toasts[0].remove();
            }

            const toast = document.createElement('div');
            toast.className = 'notification-toast';

            let title = '';
            let message = '';
            let link = '';

            if (notif.type === 'admin_chat_request') {
                title = `📨 New chat request from ${escapeHtml(notif.full_name || 'User')}`;
                message = notif.message_count > 1 ? `${notif.message_count} messages` : 'New message';
                link = notif.chat_id ? `adminpanel.php?chat_id=${notif.chat_id}` : `adminpanel.php?start_chat=${notif.user_id}`;
            } else if (notif.type === 'admin_chat_message') {
                title = `💬 New message from ${escapeHtml(notif.full_name || 'User')}`;
                message = notif.unread_count > 1 ? `${notif.unread_count} messages` : 'New message';
                link = `adminpanel.php?chat_id=${notif.chat_id}`;
            } else if (notif.type === 'head_message') {
                title = `📞 New message for ${escapeHtml(notif.contact_number || 'contact')}`;
                message = notif.unread_count > 1 ? `${notif.unread_count} messages` : 'New message';
                link = `numpage.php?id=${notif.number_id}`;
            } else if (notif.type === 'admin_reply') {
                title = `👤 Reply from Operator ${escapeHtml(notif.admin_name || '')}`;
                message = notif.unread_count > 1 ? `${notif.unread_count} messages` : 'New message';
                link = `adminchat.php?chat_id=${notif.chat_id}`;
            }

            toast.innerHTML = `
                <div class="notification-toast-title">${title}</div>
                <div class="notification-toast-message">${message}</div>
                <div class="notification-toast-time">Just now</div>
            `;

            toast.addEventListener('click', () => {
                window.location.href = link;
            });

            container.appendChild(toast);

            setTimeout(() => {
                toast.style.animation = 'slideIn 0.3s reverse';
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        function timeAgoFromString(dateString) {
            if (!dateString) return 'recently';
            try {
                const date = new Date(dateString);
                const now = new Date();
                const seconds = Math.floor((now - date) / 1000);

                if (seconds < 60) return 'just now';
                if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
                if (seconds < 86400) return Math.floor(seconds / 3600) + ' hours ago';
                return Math.floor(seconds / 86400) + ' days ago';
            } catch (e) {
                return 'recently';
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        if (!document.getElementById('notificationToastContainer')) {
            const toastContainer = document.createElement('div');
            toastContainer.id = 'notificationToastContainer';
            toastContainer.className = 'notification-toast-container';
            document.body.appendChild(toastContainer);
        }

        // ===========================================
        // REAL-TIME UNREAD MESSAGE UPDATES
        // ===========================================

        function updateUnreadCounts() {
            if (!userId || isAdmin) return;

            console.log('Checking for unread messages...');

            fetch('check_notifications.php?t=' + Date.now())
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const headMessages = (data.notifications || []).filter(n =>
                            n.type === 'head_message'
                        );

                        const unreadMap = new Map();
                        headMessages.forEach(notif => {
                            if (notif.number_id) {
                                unreadMap.set(notif.number_id, notif.unread_count || 1);
                            }
                        });

                        let totalUnread = 0;

                        document.querySelectorAll('[data-number-id]').forEach(row => {
                            const numberId = row.getAttribute('data-number-id');
                            const numberCell = row.querySelector('.number-cell');

                            if (numberCell) {
                                let badge = numberCell.querySelector('.unread-badge-small');

                                if (unreadMap.has(numberId)) {
                                    totalUnread++;

                                    if (!badge) {
                                        badge = document.createElement('span');
                                        badge.className = 'unread-badge-small';
                                        badge.id = `unread-badge-${numberId}`;
                                        numberCell.appendChild(badge);
                                    }

                                    badge.textContent = unreadMap.get(numberId);
                                    badge.style.display = 'inline-flex';
                                    row.classList.add('has-unread');
                                } else {
                                    if (badge) {
                                        badge.style.display = 'none';
                                    }
                                    row.classList.remove('has-unread');
                                }
                            }
                        });

                        const unreadFilterBtn = document.getElementById('unreadFilterBtn');
                        const unreadCountBadge = document.getElementById('unreadCountBadge');

                        if (unreadFilterBtn && unreadCountBadge) {
                            unreadCountBadge.textContent = totalUnread;

                            if (totalUnread > 0) {
                                unreadFilterBtn.innerHTML = `🔴 Unread <span class="count-badge" id="unreadCountBadge">${totalUnread}</span>`;
                                unreadFilterBtn.classList.add('unread-filter-btn');
                            } else {
                                unreadFilterBtn.innerHTML = `○ Unread <span class="count-badge" id="unreadCountBadge">0</span>`;
                                unreadFilterBtn.classList.remove('unread-filter-btn');
                            }
                        }

                        if (!isAdmin) {
                            const adminReplies = (data.notifications || []).filter(n =>
                                n.type === 'admin_reply'
                            );
                            const adminReplyCount = adminReplies.reduce((sum, n) => sum + (n.unread_count || 0), 0);

                            const adminChatBadge = document.getElementById('adminChatUnreadBadge');
                            if (adminChatBadge) {
                                if (adminReplyCount > 0) {
                                    adminChatBadge.textContent = adminReplyCount;
                                    adminChatBadge.style.display = 'flex';
                                } else {
                                    adminChatBadge.style.display = 'none';
                                }
                            }
                        }
                    }
                })
                .catch(error => console.error('Error updating unread counts:', error));
        }

        // Update when page becomes visible
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && userId && !isAdmin) {
                updateUnreadCounts();
            }
        });

        // Also update unread counts when checkNotifications runs (for regular users)
        const originalCheckNotifications = checkNotifications;
        checkNotifications = function() {
            originalCheckNotifications();
            if (!isAdmin) {
                setTimeout(updateUnreadCounts, 500);
            }
        };
    </script>
</body>

</html>