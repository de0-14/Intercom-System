<?php
require_once 'conn.php';

if(isset($_POST['division_id'])) {
    $division_id = (int)$_POST['division_id'];
    $departments = getDepartmentsByDivision($conn, $division_id);
    
    $options = '<option value="">Select Department</option>';
    foreach($departments as $dept) {
        $options .= '<option value="'.$dept['department_id'].'">'.$dept['department_name'].'</option>';
    }
    echo $options;
}
?>