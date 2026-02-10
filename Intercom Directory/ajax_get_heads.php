<?php
require_once 'conn.php';
require_once 'config.php';

// Function to get heads based on create type using role_id
function getHeadsByCreateType($conn, $createType = '') {
    $heads = [];
    
    switch($createType) {
        case 'division':
            $sql = "SELECT user_id, username, full_name, role_id 
                    FROM users 
                    WHERE status = 'active' 
                    AND role_id IN (3)
                    ORDER BY full_name";
            break;
            
        case 'department':
            $sql = "SELECT user_id, username, full_name, role_id 
                    FROM users 
                    WHERE status = 'active' 
                    AND role_id IN (3, 4)
                    ORDER BY full_name";
            break;
            
        case 'unit':
            $sql = "SELECT user_id, username, full_name, role_id 
                    FROM users 
                    WHERE status = 'active' 
                    AND role_id IN (3, 4, 5)
                    ORDER BY full_name";
            break;
            
        case 'office':
            $sql = "SELECT user_id, username, full_name, role_id 
                    FROM users 
                    WHERE status = 'active' 
                    AND role_id IN (3, 4, 5, 6)
                    ORDER BY full_name";
            break;
            
        default:
            // Show all active users
            $sql = "SELECT user_id, username, full_name, role_id 
                    FROM users 
                    WHERE status = 'active' 
                    ORDER BY full_name";
    }
    
    $stmt = sqlsrv_query($conn, $sql);
    if($stmt) {
        while($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // Add role name for display
            $roleNames = [
                1 => 'Admin',
                2 => 'MCC',
                3 => 'Division Head',
                4 => 'Department Head',
                5 => 'Unit Head',
                6 => 'Office Head',
                7 => 'Staff'
            ];
            $row['role_name'] = $roleNames[$row['role_id']] ?? 'Unknown';
            $heads[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    
    return $heads;
}

// Get type from request
$type = $_GET['type'] ?? '';

// Get heads based on type
$heads = getHeadsByCreateType($conn, $type);

// Return JSON response
header('Content-Type: application/json');
echo json_encode(['success' => true, 'heads' => $heads]);
exit;
?>