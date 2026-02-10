<?php
require_once 'conn.php';

if(isset($_GET['division_id'])) {
    $division_id = (int)$_GET['division_id'];
    
    // Using SQL Server compatible query
    $sql = "SELECT department_id, department_name FROM departments WHERE division_id = ? AND status = 'active'";
    $params = array($division_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    $options = '<option value="">Select Department</option>';
    if($stmt) {
        while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $options .= '<option value="'.$row['department_id'].'">'.$row['department_name'].'</option>';
        }
        sqlsrv_free_stmt($stmt);
    }
    echo $options;
}
?>