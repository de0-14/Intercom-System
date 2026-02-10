<?php
require_once 'conn.php';
session_start();

if(!isset($_SESSION['user_id'])) {
    echo json_encode(['unread' => 0]);
    exit();
}

$user_id = $_SESSION['user_id'];
$is_admin = isAdmin();
$total_unread = 0;

// Admin notifications
if($is_admin) {
    $admin_unread = getUnreadAdminMessageCount($conn, $user_id, true);
    $total_unread += $admin_unread;
    
    // Head notifications (if admin is also a head)
    $head_contacts = getHeadContacts($conn, $user_id);
    if(!empty($head_contacts)) {
        $head_unread = getHeadUnreadCount($conn, $user_id);
        $total_unread += $head_unread;
    }
} else {
    // Regular user notifications
    $admin_unread = getUnreadAdminMessageCount($conn, $user_id, false);
    $total_unread += $admin_unread;
    
    // Head notifications (if user is a head)
    $head_contacts = getHeadContacts($conn, $user_id);
    if(!empty($head_contacts)) {
        $head_unread = getHeadUnreadCount($conn, $user_id);
        $total_unread += $head_unread;
    }
}

echo json_encode(['unread' => $total_unread]);
?>