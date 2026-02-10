<?php
require_once 'conn.php';
require_once 'admin_archive_functions.php';

session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header('Content-Type: application/json');
    echo json_encode(['count' => 0, 'error' => 'Not authorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

// Get notification count
$admin_notifications_count = getAdminUnreadChatsCount($conn, $user_id);

// Get latest chat request for notification
$latest_request = null;
$admin_chat_requests = getAdminChatRequests($conn, $user_id);
if (!empty($admin_chat_requests)) {
    $latest_request = $admin_chat_requests[0];
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'count' => $admin_notifications_count,
    'latest' => $latest_request ? [
        'user_name' => $latest_request['full_name'],
        'time' => $latest_request['first_message_time']
    ] : null
]);
?>