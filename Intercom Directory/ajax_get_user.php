<?php
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

// Get user data - SQL Server compatible
$sql = "SELECT user_id, username, email, full_name, role_id, 
               division_id, department_id, unit_id, office_id
        FROM users 
        WHERE user_id = ?";
        
$params = array($user_id);
$stmt = sqlsrv_query($conn, $sql, $params);

if ($stmt === false) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . print_r(sqlsrv_errors(), true)]);
    exit;
}

$user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if ($user) {
    echo json_encode(['success' => true, 'user' => $user]);
} else {
    echo json_encode(['success' => false, 'message' => 'User not found']);
}

sqlsrv_free_stmt($stmt);
// Don't close $conn here as it might be used elsewhere
?>