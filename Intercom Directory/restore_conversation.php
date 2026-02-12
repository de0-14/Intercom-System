<?php
require_once 'conn.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;
$number_id = isset($_POST['number_id']) ? (int)$_POST['number_id'] : 0;

if (!$conversation_id || !$number_id) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

// Restore the conversation
$result = restoreArchivedConversation($conn, $conversation_id, $number_id, $user_id);
header('Content-Type: application/json');
echo json_encode($result);