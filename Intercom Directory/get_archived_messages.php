<?php
require_once 'conn.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'No conversation ID']);
    exit;
}

// Check permissions and get messages
$result = getArchivedMessages($conn, $conversation_id, $user_id);
header('Content-Type: application/json');
echo json_encode($result);