<?php
ini_set('display_errors', 0);
error_reporting(0);
ob_start();

require_once 'conn.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'submit':
        $number_id = (int)($_POST['number_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        
        if (!$number_id) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Invalid contact']);
            exit();
        }
        
        if ($rating < 1 || $rating > 5) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Please select a valid rating (1-5 stars)']);
            exit();
        }
        
        if (empty($comment)) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Please enter a comment']);
            exit();
        }
        
        // Check if already submitted
        $check_sql = "SELECT feedback_id FROM feedback WHERE number_id = ? AND user_id = ?";
        $check_params = array($number_id, $user_id);
        $check_stmt = sqlsrv_query($conn, $check_sql, $check_params);
        
        if ($check_stmt && sqlsrv_has_rows($check_stmt)) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'You have already submitted feedback for this contact']);
            exit();
        }
        sqlsrv_free_stmt($check_stmt);
        
        // Insert feedback
        $insert_sql = "INSERT INTO feedback (number_id, user_id, rating, comment, created_at, updated_at) 
                       VALUES (?, ?, ?, ?, GETDATE(), GETDATE())";
        $insert_params = array($number_id, $user_id, $rating, $comment);
        $insert_stmt = sqlsrv_prepare($conn, $insert_sql, $insert_params);
        
        if ($insert_stmt && sqlsrv_execute($insert_stmt)) {
            // Get updated average rating
            $avg_sql = "SELECT AVG(CAST(rating as DECIMAL(10,2))) as avg_rating, COUNT(*) as total 
                        FROM feedback WHERE number_id = ?";
            $avg_stmt = sqlsrv_query($conn, $avg_sql, array($number_id));
            $avg_data = sqlsrv_fetch_array($avg_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($avg_stmt);
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Feedback submitted successfully!',
                'avg_rating' => round($avg_data['avg_rating'] ?? 0, 1),
                'total_feedbacks' => $avg_data['total'] ?? 0
            ]);
            exit();
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Failed to submit feedback']);
            exit();
        }
        break;
        
    case 'update':
        $feedback_id = (int)($_POST['feedback_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        $number_id = (int)($_POST['number_id'] ?? 0);
        
        if (!$feedback_id || !$number_id) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Invalid feedback']);
            exit();
        }
        
        if ($rating < 1 || $rating > 5) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Please select a valid rating (1-5 stars)']);
            exit();
        }
        
        if (empty($comment)) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Please enter a comment']);
            exit();
        }
        
        // Verify ownership
        $verify_sql = "SELECT feedback_id FROM feedback WHERE feedback_id = ? AND user_id = ?";
        $verify_params = array($feedback_id, $user_id);
        $verify_stmt = sqlsrv_query($conn, $verify_sql, $verify_params);
        
        if (!$verify_stmt || !sqlsrv_has_rows($verify_stmt)) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'You can only edit your own feedback']);
            exit();
        }
        sqlsrv_free_stmt($verify_stmt);
        
        // Update feedback
        $update_sql = "UPDATE feedback SET rating = ?, comment = ?, updated_at = GETDATE() 
                       WHERE feedback_id = ? AND user_id = ?";
        $update_params = array($rating, $comment, $feedback_id, $user_id);
        $update_stmt = sqlsrv_prepare($conn, $update_sql, $update_params);
        
        if ($update_stmt && sqlsrv_execute($update_stmt)) {
            // Get updated average rating
            $avg_sql = "SELECT AVG(CAST(rating as DECIMAL(10,2))) as avg_rating, COUNT(*) as total 
                        FROM feedback WHERE number_id = ?";
            $avg_stmt = sqlsrv_query($conn, $avg_sql, array($number_id));
            $avg_data = sqlsrv_fetch_array($avg_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($avg_stmt);
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Feedback updated successfully!',
                'avg_rating' => round($avg_data['avg_rating'] ?? 0, 1),
                'total_feedbacks' => $avg_data['total'] ?? 0
            ]);
            exit();
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Failed to update feedback']);
            exit();
        }
        break;
        
    case 'delete':
        $feedback_id = (int)($_POST['feedback_id'] ?? 0);
        $number_id = (int)($_POST['number_id'] ?? 0);
        
        if (!$feedback_id || !$number_id) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Invalid feedback']);
            exit();
        }
        
        // Verify ownership
        $verify_sql = "SELECT feedback_id FROM feedback WHERE feedback_id = ? AND user_id = ?";
        $verify_params = array($feedback_id, $user_id);
        $verify_stmt = sqlsrv_query($conn, $verify_sql, $verify_params);
        
        if (!$verify_stmt || !sqlsrv_has_rows($verify_stmt)) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'You can only delete your own feedback']);
            exit();
        }
        sqlsrv_free_stmt($verify_stmt);
        
        // Delete feedback
        $delete_sql = "DELETE FROM feedback WHERE feedback_id = ? AND user_id = ?";
        $delete_params = array($feedback_id, $user_id);
        $delete_stmt = sqlsrv_prepare($conn, $delete_sql, $delete_params);
        
        if ($delete_stmt && sqlsrv_execute($delete_stmt)) {
            // Get updated average rating
            $avg_sql = "SELECT AVG(CAST(rating as DECIMAL(10,2))) as avg_rating, COUNT(*) as total 
                        FROM feedback WHERE number_id = ?";
            $avg_stmt = sqlsrv_query($conn, $avg_sql, array($number_id));
            $avg_data = sqlsrv_fetch_array($avg_stmt, SQLSRV_FETCH_ASSOC);
            sqlsrv_free_stmt($avg_stmt);
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Feedback deleted successfully!',
                'avg_rating' => round($avg_data['avg_rating'] ?? 0, 1),
                'total_feedbacks' => $avg_data['total'] ?? 0
            ]);
            exit();
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Failed to delete feedback']);
            exit();
        }
        break;
        
    default:
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        exit();
}
?>