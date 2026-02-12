<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'conn.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    exit;
}

// Get division_id from GET or POST
$division_id = null;
if (isset($_GET['division_id'])) {
    $division_id = (int)$_GET['division_id'];
} elseif (isset($_POST['division_id'])) {
    $division_id = (int)$_POST['division_id'];
}

if ($division_id) {
    // Using SQL Server compatible query
    $sql = "SELECT department_id, department_name 
            FROM departments 
            WHERE division_id = ? AND status = 'active' 
            ORDER BY department_name";
    $params = array($division_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    $options = '<option value="">Select Department</option>';
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $options .= '<option value="' . $row['department_id'] . '">' . 
                        htmlspecialchars($row['department_name']) . '</option>';
        }
        sqlsrv_free_stmt($stmt);
    }
    echo $options;
} else {
    echo '<option value="">Select Department</option>';
}
?>