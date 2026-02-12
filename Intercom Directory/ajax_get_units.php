<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'conn.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    exit;
}

// Get department_id from GET or POST
$department_id = null;
if (isset($_GET['department_id'])) {
    $department_id = (int)$_GET['department_id'];
} elseif (isset($_POST['department_id'])) {
    $department_id = (int)$_POST['department_id'];
}

if ($department_id) {
    // Using SQL Server compatible query
    $sql = "SELECT unit_id, unit_name 
            FROM units 
            WHERE department_id = ? AND status = 'active' 
            ORDER BY unit_name";
    $params = array($department_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    $options = '<option value="">Select Unit</option>';
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $options .= '<option value="' . $row['unit_id'] . '">' . 
                        htmlspecialchars($row['unit_name']) . '</option>';
        }
        sqlsrv_free_stmt($stmt);
    }
    echo $options;
} else {
    echo '<option value="">Select Unit</option>';
}
?>