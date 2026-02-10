<?php
require_once 'conn.php';

if(isset($_GET['department_id'])) {
    $department_id = (int)$_GET['department_id'];
    
    // Using SQL Server compatible query
    $sql = "SELECT unit_id, unit_name FROM units WHERE department_id = ? AND status = 'active'";
    $params = array($department_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    $options = '<option value="">Select Unit</option>';
    if($stmt) {
        while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $options .= '<option value="'.$row['unit_id'].'">'.$row['unit_name'].'</option>';
        }
        sqlsrv_free_stmt($stmt);
    }
    echo $options;
}
?>