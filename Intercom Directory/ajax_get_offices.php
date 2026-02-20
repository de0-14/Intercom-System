<?php
require_once 'conn.php';

if(isset($_GET['unit_id'])) {
    $unit_id = (int)$_GET['unit_id'];
    
    // Using SQL Server compatible query
    $sql = "SELECT office_id, office_name FROM offices WHERE unit_id = ? AND status = 'active'";
    $params = array($unit_id);
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    $options = '<option value="">Select Office</option>';
    if($stmt) {
        while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $options .= '<option value="'.$row['office_id'].'">'.$row['office_name'].'</option>';
        }
        sqlsrv_free_stmt($stmt);
    }
    echo $options;
}
?>