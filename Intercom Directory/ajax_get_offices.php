<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'conn.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    exit;
}

// Get unit_id from GET or POST
$unit_id = null;
if (isset($_GET['unit_id'])) {
    $unit_id = (int)$_GET['unit_id'];
} elseif (isset($_POST['unit_id'])) {
    $unit_id = (int)$_POST['unit_id'];
}

if ($unit_id) {
    // Using SQL Server compatible query
    $sql = "SELECT office_id, office_name 
            FROM offices 
            WHERE unit_id = ? AND status = 'active' 
            ORDER BY office_name";
    $params = array($unit_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    $options = '<option value="">Select Office</option>';
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $options .= '<option value="' . $row['office_id'] . '">' . 
                        htmlspecialchars($row['office_name']) . '</option>';
        }
        sqlsrv_free_stmt($stmt);
    }
    echo $options;
} else {
    echo '<option value="">Select Office</option>';
}
?>