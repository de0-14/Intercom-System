<?php
require_once 'conn.php';

if(isset($_POST['department_id'])) {
    $department_id = (int)$_POST['department_id'];
    $units = getUnitsByDepartment($conn, $department_id);
    
    $options = '<option value="">Select Unit</option>';
    foreach($units as $unit) {
        $options .= '<option value="'.$unit['unit_id'].'">'.$unit['unit_name'].'</option>';
    }
    echo $options;
}
?>