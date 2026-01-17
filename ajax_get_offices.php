<?php
require_once 'conn.php';

if(isset($_POST['unit_id'])) {
    $unit_id = (int)$_POST['unit_id'];
    $offices = getOfficesByUnit($conn, $unit_id);
    
    $options = '<option value="">Select Office</option>';
    foreach($offices as $office) {
        $options .= '<option value="'.$office['office_id'].'">'.$office['office_name'].'</option>';
    }
    echo $options;
}
?>