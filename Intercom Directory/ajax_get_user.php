<?php
// ajax_get_user.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'conn.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!isset($_GET['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit;
}

$user_id = (int)$_GET['user_id'];

// Get user data
$sql = "SELECT user_id, username, email, full_name, role_id, 
               division_id, department_id, unit_id, office_id
        FROM users 
        WHERE user_id = ?";
        
$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    exit;
}

$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Execute error: ' . $stmt->error]);
    exit;
}

$result = $stmt->get_result();
if ($result === false) {
    echo json_encode(['success' => false, 'message' => 'Get result error: ' . $stmt->error]);
    exit;
}

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo json_encode(['success' => true, 'user' => $user]);
} else {
    echo json_encode(['success' => false, 'message' => 'User not found']);
}

$stmt->close();
// Don't close $conn here as it might be used elsewhere
?>